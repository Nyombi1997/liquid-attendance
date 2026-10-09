"""Service de synchronisation des pointeuses ZKTeco, en temps réel.

Processus principal du conteneur `worker` (lancé par run.sh) :
- Un thread par pointeuse active (table devices, active = 1), qui garde une
  seule connexion ouverte : ces appareils n'acceptent souvent qu'un client.
- Sur cette connexion : synchronisation complète au démarrage puis toutes les
  FULL_SYNC_INTERVAL secondes (utilisateurs, tous les pointages, horloge),
  et entre deux, écoute en direct des badges (live_capture de pyzk).
- Chaque badge reçu est inséré tout de suite dans punches (doublons ignorés
  grâce à la clé unique), puis la journée de l'agent est recalculée (au plus
  une fois toutes les 20 s par agent).
- Calcul global des derniers jours toutes les SYNC_INTERVAL secondes, en
  filet de sécurité.
- Chaque nuit à 03:00, l'horloge de chaque pointeuse est remise à l'heure de
  la VM : c'est la seule écriture faite sur les appareils. Rien n'y est
  jamais effacé ni modifié d'autre.

Les employés absents de la plateforme sont créés (le nom de la pointeuse sert
de nom initial ; ensuite seul `device_name` est mis à jour, sauf si le nom
est encore le nom provisoire « Agent <numéro> »).
"""
import json
import os
import signal
import subprocess
import sys
import threading
import time
from datetime import datetime, timedelta

import pymysql
from zk import ZK


def env_float(name, default):
    try:
        return float(os.environ.get(name) or default)
    except ValueError:
        return float(default)


FULL_SYNC_INTERVAL = env_float("FULL_SYNC_INTERVAL", 1800)  # synchro complète sur la connexion ouverte
COMPUTE_INTERVAL = env_float("SYNC_INTERVAL", 300)          # calcul global (filet de sécurité)
COMPUTE_DAYS = int(env_float("COMPUTE_DAYS", 7))
HEARTBEAT_INTERVAL = env_float("HEARTBEAT_INTERVAL", 300)   # contrôle de la connexion (lecture de l'horloge)
CLOCK_SET_AT = os.environ.get("CLOCK_SET_AT", "03:00").strip()  # « off » pour désactiver
CLOCK_WINDOW_HOURS = 3       # si la pointeuse était injoignable à 03:00, rattrapage jusqu'à 06:00
LIVE_TIMEOUT = 10            # live_capture rend la main (None) après 10 s sans badge
RECOMPUTE_EVERY = 20         # au plus un recalcul toutes les 20 s par agent
RETRY_DELAYS = (10, 30, 60, 300)
STABLE_AFTER = 120           # une connexion tenue 2 min remet le délai de reconnexion à 10 s
DEVICE_RELOAD = 60           # relecture de la table devices (ajout, désactivation, changement d'IP)
INITIAL_WAIT = 600           # attente max. des premières synchros avant le premier calcul global
PHP = os.environ.get("PHP_BIN", "php")
COMPUTE_SCRIPT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "bin", "compute.php")

stop = threading.Event()


def log(msg):
    print(f"[sync {datetime.now():%Y-%m-%d %H:%M:%S}] {msg}", flush=True)


def now():
    return datetime.now()


def db():
    return pymysql.connect(
        host=os.environ.get("DB_HOST", "db"),
        port=int(os.environ.get("DB_PORT", "3306")),
        user=os.environ["DB_USER"],
        password=os.environ["DB_PASSWORD"],
        database=os.environ["DB_NAME"],
        charset="utf8mb4",
        autocommit=False,
    )


def load_devices():
    conn = db()
    try:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute("SELECT id, name, ip, port, comm_key, usage_type FROM devices WHERE active = 1 ORDER BY id")
            devices = cur.fetchall()
        conn.commit()
        return list(devices)
    finally:
        conn.close()


def connect_device(dev):
    """Connexion TCP, puis UDP en secours (le protocole qui marche est gardé)."""
    last = None
    for udp in (False, True):
        try:
            return ZK(dev["ip"], port=dev["port"], timeout=15, password=dev["comm_key"],
                      force_udp=udp, ommit_ping=True).connect()
        except Exception as exc:  # noqa: BLE001
            last = exc
    raise last


def safe(fn):
    try:
        val = fn()
        return str(val).strip() if val else None
    except Exception:  # noqa: BLE001
        return None


def clock_offset(zk):
    """Écart en secondes : heure pointeuse - heure de la VM (les pointeuses donnent l'heure à la seconde)."""
    server_now = now().replace(microsecond=0)
    return int((zk.get_time() - server_now).total_seconds())


def describe(exc):
    if isinstance(exc, pymysql.Error):
        kind = "base de données"
    elif isinstance(exc, OSError) or "Network" in type(exc).__name__:
        kind = "réseau"
    else:
        kind = "pointeuse"
    return f"{kind} — {type(exc).__name__}: {exc}"[:250]


def clock_due(last_date):
    """Vrai pendant la fenêtre de remise à l'heure, une fois par nuit."""
    if CLOCK_SET_AT.lower() in ("", "off", "non", "0"):
        return False
    try:
        hour, minute = (int(x) for x in CLOCK_SET_AT.split(":"))
    except ValueError:
        return False
    current = now()
    start = current.replace(hour=hour, minute=minute, second=0, microsecond=0)
    return last_date != current.date() and start <= current < start + timedelta(hours=CLOCK_WINDOW_HOURS)


def run_compute(args):
    try:
        res = subprocess.run([PHP, COMPUTE_SCRIPT, *args], timeout=1800, check=False)
        if res.returncode != 0:
            log(f"ERREUR calcul ({' '.join(args)}) : code de sortie {res.returncode}")
    except Exception as exc:  # noqa: BLE001
        log(f"ERREUR calcul ({' '.join(args)}) : {type(exc).__name__}: {exc}")


class Recompute:
    """Recalculs demandés après les badges, regroupés par agent."""

    def __init__(self):
        self.lock = threading.Lock()
        self.pending = {}   # device_user_id -> (première date, dernière date)
        self.last_run = {}  # device_user_id -> instant du dernier recalcul (monotonic)

    def add(self, uid, day):
        with self.lock:
            first, last = self.pending.get(uid, (day, day))
            self.pending[uid] = (min(first, day), max(last, day))

    def take_due(self):
        """Agents à recalculer maintenant, avec la période couverte."""
        t = time.monotonic()
        with self.lock:
            due = [uid for uid in self.pending if t - self.last_run.get(uid, -RECOMPUTE_EVERY) >= RECOMPUTE_EVERY]
            if not due:
                return None
            days = [self.pending.pop(uid) for uid in due]
            for uid in due:
                self.last_run[uid] = t
        # La veille aussi : un badge du matin peut clore un horaire de nuit.
        first = min(d[0] for d in days) - timedelta(days=1)
        return sorted(due), first, max(d[1] for d in days)


def compute_loop(recompute, first_syncs):
    """Calculs PHP, un seul à la fois : global périodique et recalculs par agent."""
    deadline = time.monotonic() + INITIAL_WAIT
    for ev in first_syncs:
        while not stop.is_set() and not ev.is_set() and time.monotonic() < deadline:
            ev.wait(1)
    if stop.is_set():
        return
    # Le premier calcul global doit passer avant les recalculs par agent :
    # sur une base vide, c'est lui qui calcule l'historique.
    run_compute([f"--days={COMPUTE_DAYS}"])
    next_global = time.monotonic() + COMPUTE_INTERVAL
    while not stop.wait(1):
        if time.monotonic() >= next_global:
            run_compute([f"--days={COMPUTE_DAYS}"])
            next_global = time.monotonic() + COMPUTE_INTERVAL
        batch = recompute.take_due()
        if batch:
            uids, first, last = batch
            run_compute([f"--employee-device-id={','.join(uids)}", f"--from={first:%Y-%m-%d}", f"--to={last:%Y-%m-%d}"])


class DeviceWorker(threading.Thread):
    """Connexion permanente à une pointeuse : synchro complète + badges en direct."""

    def __init__(self, dev, recompute):
        super().__init__(name=f"pointeuse-{dev['id']}", daemon=True)
        self.dev = dev
        self.recompute = recompute
        self.halt = threading.Event()       # pointeuse désactivée ou modifiée
        self.first_sync = threading.Event()  # première tentative terminée (réussie ou non)
        self.names = {}
        self.last_clock_date = None
        self.next_full = self.next_beat = 0.0

    @property
    def label(self):
        return f"{self.dev['name']} ({self.dev['ip']})"

    def stopping(self):
        return stop.is_set() or self.halt.is_set()

    def pause(self, seconds):
        end = time.monotonic() + seconds
        while not self.stopping() and time.monotonic() < end:
            stop.wait(min(1, end - time.monotonic()))

    def run(self):
        conn = None
        attempt = 0
        while not self.stopping():
            zk = None
            connected_at = None
            try:
                if conn is None:
                    conn = db()
                else:
                    conn.ping(reconnect=True)
                zk = connect_device(self.dev)
                log(f"{self.label} : connectée ({'TCP' if getattr(zk, 'tcp', True) else 'UDP'})")
                self.full_sync(zk, conn)
                connected_at = time.monotonic()
                attempt = 0
                self.first_sync.set()
                self.session(zk, conn)
            except Exception as exc:  # noqa: BLE001
                self.first_sync.set()
                if self.stopping():
                    break
                if connected_at is not None and time.monotonic() - connected_at >= STABLE_AFTER:
                    attempt = 0
                delay = RETRY_DELAYS[min(attempt, len(RETRY_DELAYS) - 1)]
                attempt += 1
                msg = describe(exc)
                log(f"ERREUR {self.label} : {msg}. Nouvelle tentative dans {delay} s")
                conn = self.mark_error(conn, msg)
                self.pause(delay)
            finally:
                if zk is not None:
                    self.close(zk)
        if conn is not None:
            try:
                conn.close()
            except Exception:  # noqa: BLE001
                pass
        log(f"{self.label} : connexion fermée")

    def close(self, zk):
        try:
            zk.end_live_capture = True
            zk.disconnect()
        except Exception:  # noqa: BLE001
            pass

    def mark_error(self, conn, msg):
        """Enregistre l'erreur dans devices ; rend la connexion à la base (ou None)."""
        try:
            if conn is None:
                conn = db()
            conn.rollback()
            conn.ping(reconnect=True)
            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE devices SET last_sync_at = NOW(), last_sync_ok = 0, last_error = %s WHERE id = %s",
                    (msg, self.dev["id"]),
                )
            conn.commit()
            return conn
        except Exception as exc:  # noqa: BLE001
            log(f"ERREUR {self.label} : impossible d'enregistrer l'erreur en base ({type(exc).__name__}: {exc})")
            try:
                conn.close()
            except Exception:  # noqa: BLE001
                pass
            return None

    # -- Connexion ouverte -------------------------------------------------

    def maintenance_due(self):
        t = time.monotonic()
        return t >= self.next_full or t >= self.next_beat or clock_due(self.last_clock_date)

    def session(self, zk, conn):
        """Écoute en direct ; en sort quand une tâche est due, puis y revient."""
        while not self.stopping():
            if clock_due(self.last_clock_date):
                self.set_clock(zk, conn)
            if time.monotonic() >= self.next_full:
                self.full_sync(zk, conn)
            elif time.monotonic() >= self.next_beat:
                self.heartbeat(zk, conn)
            if self.stopping():
                return
            self.capture(zk, conn)

    def capture(self, zk, conn):
        # Sortie propre : end_live_capture, puis la boucle de pyzk se termine
        # d'elle-même (désinscription des événements avant toute autre commande).
        for att in zk.live_capture(new_timeout=LIVE_TIMEOUT):
            if att is not None:
                self.on_badge(conn, att)
            if self.stopping() or self.maintenance_due():
                zk.end_live_capture = True

    def on_badge(self, conn, att):
        uid = str(att.user_id).strip()
        if not uid or att.timestamp is None:
            return
        try:
            conn.ping(reconnect=True)
            with conn.cursor() as cur:
                cur.execute("SELECT 1 FROM employees WHERE device_user_id = %s", (uid,))
                if cur.fetchone() is None:
                    dname = self.names.get(uid) or f"Agent {uid}"
                    cur.execute(
                        "INSERT IGNORE INTO employees (device_user_id, name, device_name) VALUES (%s, %s, %s)",
                        (uid, dname, dname),
                    )
                    log(f"{self.label} : nouvel employé {uid} ({dname})")
                new = cur.execute(
                    "INSERT IGNORE INTO punches (device_id, device_user_id, punched_at, verify_status, punch_type) "
                    "VALUES (%s, %s, %s, %s, %s)",
                    (self.dev["id"], uid, att.timestamp, att.status, att.punch),
                )
                cur.execute("UPDATE devices SET last_event_at = NOW() WHERE id = %s", (self.dev["id"],))
            conn.commit()
        except pymysql.Error as exc:
            # Le badge reste sur la pointeuse : il sera repris à la prochaine synchro complète.
            log(f"ERREUR {self.label} : badge {uid} non enregistré, base indisponible ({exc}). "
                "Il sera repris à la prochaine synchro complète")
            try:
                conn.rollback()
            except Exception:  # noqa: BLE001
                pass
            return
        log(f"{self.label} : badge {uid} à {att.timestamp:%H:%M:%S}" + ("" if new else " (déjà enregistré)"))
        if new and self.dev.get("usage_type", "presence") == "presence":
            self.recompute.add(uid, att.timestamp.date())

    def full_sync(self, zk, conn):
        start = time.time()
        offset = clock_offset(zk)
        info = {
            "model": safe(zk.get_device_name),
            "serial": safe(zk.get_serialnumber),
            "firmware": safe(zk.get_firmware_version),
        }
        users = zk.get_users() or []
        records = zk.get_attendance() or []

        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            # Employés : création si inconnu, mise à jour du nom côté pointeuse ;
            # le nom affiché n'est remplacé que s'il est encore provisoire.
            names = {}
            for u in users:
                uid = str(u.user_id).strip()
                if not uid:
                    continue
                dname = (u.name or "").strip() or f"Agent {uid}"
                names[uid] = dname
                cur.execute(
                    "INSERT INTO employees (device_user_id, name, device_name) VALUES (%s, %s, %s) "
                    "ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), "
                    "name = IF(name = CONCAT('Agent ', device_user_id), VALUES(name), name)",
                    (uid, dname, dname),
                )

            rows = [
                (self.dev["id"], str(r.user_id).strip(), r.timestamp, r.status, r.punch)
                for r in records
                if r.timestamp is not None and str(r.user_id).strip()
            ]
            inserted = 0
            for i in range(0, len(rows), 1000):
                chunk = rows[i:i + 1000]
                inserted += cur.executemany(
                    "INSERT IGNORE INTO punches (device_id, device_user_id, punched_at, verify_status, punch_type) "
                    "VALUES (%s, %s, %s, %s, %s)",
                    chunk,
                ) or 0

            cur.execute(
                "UPDATE devices SET last_sync_at = NOW(), last_sync_ok = 1, last_error = NULL, "
                "clock_offset_sec = %s, user_count = %s, log_count = %s, "
                "model = COALESCE(%s, model), serial_number = COALESCE(%s, serial_number), "
                "firmware = COALESCE(%s, firmware) WHERE id = %s",
                (offset, len(users), len(records), info["model"], info["serial"], info["firmware"], self.dev["id"]),
            )
        conn.commit()
        self.names = names
        t = time.monotonic()
        self.next_full = t + FULL_SYNC_INTERVAL
        self.next_beat = t + HEARTBEAT_INTERVAL
        log(f"{self.label} : synchro complète, {len(users)} utilisateurs, {len(records)} pointages, "
            f"{inserted} nouveaux, horloge {offset:+d} s, {time.time() - start:.1f} s")

    def heartbeat(self, zk, conn):
        """Vérifie que la connexion répond (une coupure silencieuse ne lève aucune erreur en écoute)."""
        offset = clock_offset(zk)
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            cur.execute(
                "UPDATE devices SET last_sync_ok = 1, last_error = NULL, clock_offset_sec = %s WHERE id = %s",
                (offset, self.dev["id"]),
            )
        conn.commit()
        self.next_beat = time.monotonic() + HEARTBEAT_INTERVAL

    def set_clock(self, zk, conn):
        """Remise à l'heure nocturne : seule écriture autorisée sur les appareils."""
        self.last_clock_date = now().date()  # une seule tentative par nuit
        before = clock_offset(zk)
        zk.set_time(now())
        after = clock_offset(zk)
        log(f"{self.label} : horloge remise à l'heure de la VM, écart avant {before:+d} s, après {after:+d} s")
        conn.ping(reconnect=True)
        with conn.cursor() as cur:
            cur.execute("UPDATE devices SET clock_offset_sec = %s WHERE id = %s", (after, self.dev["id"]))
            cur.execute(
                "INSERT INTO audit_log (user_id, action, details, ip) VALUES (NULL, %s, %s, %s)",
                ("horloge_pointeuse_reglee",
                 json.dumps({"pointeuse": self.dev["name"], "ip": self.dev["ip"],
                             "ecart_avant_s": before, "ecart_apres_s": after}, ensure_ascii=False),
                 "worker"),
            )
        conn.commit()


def signature(dev):
    return (dev["ip"], int(dev["port"]), int(dev["comm_key"]))


def main():
    def on_signal(signum, _frame):
        log(f"signal {signum} reçu, arrêt en cours")
        stop.set()

    signal.signal(signal.SIGTERM, on_signal)
    signal.signal(signal.SIGINT, on_signal)

    log(f"démarrage : badges en temps réel, synchro complète toutes les {FULL_SYNC_INTERVAL:.0f} s, "
        f"calcul global toutes les {COMPUTE_INTERVAL:.0f} s sur {COMPUTE_DAYS} jours, "
        f"horloges remises à l'heure à {CLOCK_SET_AT}")
    recompute = Recompute()
    workers = {}
    computer = None

    while not stop.is_set():
        try:
            devices = load_devices()
        except Exception as exc:  # noqa: BLE001
            log(f"ERREUR lecture des pointeuses en base : {type(exc).__name__}: {exc}")
            devices = None

        if devices is not None:
            wanted = {d["id"]: d for d in devices}
            for dev_id, w in list(workers.items()):
                d = wanted.get(dev_id)
                if d is None or signature(d) != signature(w.dev):
                    if not w.halt.is_set():
                        log(f"{w.label} : désactivée ou modifiée, fermeture de la connexion")
                        w.halt.set()
                else:
                    w.dev.update(name=d["name"], usage_type=d["usage_type"])
                if not w.is_alive():
                    del workers[dev_id]
            for dev_id, d in wanted.items():
                if dev_id not in workers:
                    workers[dev_id] = DeviceWorker(d, recompute)
                    workers[dev_id].start()
            if computer is None:
                computer = threading.Thread(target=compute_loop, name="calcul", daemon=True,
                                            args=(recompute, [w.first_sync for w in workers.values()]))
                computer.start()

        stop.wait(DEVICE_RELOAD)

    for w in workers.values():
        w.join(timeout=LIVE_TIMEOUT + 5)
    if computer is not None:
        computer.join(timeout=5)
    log("arrêt terminé")
    return 0


if __name__ == "__main__":
    sys.exit(main())
