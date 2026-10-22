"""Tests du service de synchronisation, sans pointeuse ni base :
    python3 tests/test_sync.py

Utilise le faux module zk (tests/fakezk) et une fausse base en mémoire à la
place de pymysql. Le calcul PHP n'est pas lancé : les appels sont relevés.
Vérifie : badges en direct, synchro complète sur la même connexion, sortie
propre de live_capture, coupure et reconnexion avec délai croissant, une
pointeuse en panne qui ne bloque pas les autres, désactivation, recalculs
regroupés (un appel au plus toutes les RECOMPUTE_EVERY s), calcul global dans
un thread à part, indicateur « en écoute », remise à l'heure au démarrage
(écart > 30 s) et nocturne, pointeuses lentes (F16 : délai long, journal
détaillé), tentatives espacées de 30 min après 5 échecs, battement de cœur,
sauvegarde (faux mariadb-dump) et conservation de 14 jours.
"""
import json
import os
import signal
import sys
import threading
import time
import types
from datetime import date, datetime, timedelta

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "tests", "fakezk"))
sys.path.insert(0, os.path.join(ROOT, "worker"))
os.environ.update(DB_USER="test", DB_PASSWORD="test", DB_NAME="test")


# -- Fausse base (seulement les requêtes de sync.py) --------------------------

class FakeDB:
    def __init__(self):
        self.lock = threading.RLock()
        self.devices = {}
        self.employees = {}
        self.punches = {}
        self.audit = []
        self.settings = {}
        self.down = False

    def add_device(self, dev_id, ip, name, usage="presence"):
        self.devices[dev_id] = dict(id=dev_id, name=name, ip=ip, port=4370, comm_key=0, usage_type=usage, active=1,
                                    last_sync_ok=None, last_error=None, last_event_at=None, clock_offset_sec=None,
                                    live_since="ancien", full_syncs=0)


class Error(Exception):
    pass


class OperationalError(Error):
    pass


class Cursor:
    def __init__(self, store):
        self.db = store
        self.result = []

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def fetchall(self):
        return self.result

    def fetchone(self):
        return self.result[0] if self.result else None

    def executemany(self, sql, rows):
        return sum(self.execute(sql, r) for r in rows)

    def execute(self, sql, p=()):
        d = self.db
        if d.down:
            raise OperationalError(2003, "Can't connect to MySQL server")
        with d.lock:
            self.result = []
            if sql.startswith("SELECT id, name, ip, port, comm_key, usage_type FROM devices"):
                self.result = [{k: dev[k] for k in ("id", "name", "ip", "port", "comm_key", "usage_type")}
                               for dev in sorted(d.devices.values(), key=lambda x: x["id"]) if dev["active"]]
                return len(self.result)
            if sql.startswith("SELECT 1 FROM employees"):
                self.result = [(1,)] if p[0] in d.employees else []
                return len(self.result)
            if sql.startswith("INSERT INTO employees") and "ON DUPLICATE KEY" in sql:
                uid, name, dname = p
                emp = d.employees.setdefault(uid, {"name": name, "device_name": dname})
                emp["device_name"] = dname
                if emp["name"] == f"Agent {uid}":
                    emp["name"] = name
                return 1
            if sql.startswith("INSERT IGNORE INTO employees"):
                if p[0] in d.employees:
                    return 0
                d.employees[p[0]] = {"name": p[1], "device_name": p[2]}
                return 1
            if sql.startswith("INSERT IGNORE INTO punches"):
                key = (p[0], p[1], p[2])
                if key in d.punches:
                    return 0
                d.punches[key] = p
                return 1
            if sql.startswith("INSERT INTO settings"):
                d.settings[p[0]] = p[1]
                return 1
            if sql.startswith("INSERT INTO audit_log"):
                d.audit.append(p)
                return 1
            if sql.startswith("UPDATE devices SET last_sync_at = NOW(), last_sync_ok = 1"):
                dev = d.devices[p[-1]]
                dev.update(last_sync_ok=1, last_error=None, clock_offset_sec=p[0], full_syncs=dev["full_syncs"] + 1)
                return 1
            if sql.startswith("UPDATE devices SET last_sync_at = NOW(), last_sync_ok = 0"):
                assert "live_since = NULL" in sql
                d.devices[p[1]].update(last_sync_ok=0, last_error=p[0], live_since=None)
                return 1
            if sql.startswith("UPDATE devices SET live_since = NULL WHERE live_since IS NOT NULL"):
                for dev in d.devices.values():
                    dev["live_since"] = None
                return 1
            if sql.startswith("UPDATE devices SET live_since = "):
                d.devices[p[0]]["live_since"] = datetime.now() if "NOW()" in sql else None
                return 1
            if sql.startswith("UPDATE devices SET last_event_at"):
                d.devices[p[0]]["last_event_at"] = datetime.now()
                return 1
            if sql.startswith("UPDATE devices SET last_sync_ok = 1, last_error = NULL, clock_offset_sec"):
                d.devices[p[1]].update(last_sync_ok=1, last_error=None, clock_offset_sec=p[0])
                return 1
            if sql.startswith("UPDATE devices SET clock_offset_sec"):
                d.devices[p[1]]["clock_offset_sec"] = p[0]
                return 1
        raise AssertionError(f"requête inattendue : {sql}")


class Connection:
    def __init__(self, store):
        self.db = store

    def cursor(self, _cls=None):
        return Cursor(self.db)

    def ping(self, reconnect=False):
        if self.db.down:
            raise OperationalError(2003, "Can't connect to MySQL server")

    def commit(self):
        pass

    def rollback(self):
        pass

    def close(self):
        pass


DB = FakeDB()
fake_pymysql = types.ModuleType("pymysql")
fake_pymysql.Error = Error
fake_pymysql.err = types.SimpleNamespace(Error=Error, OperationalError=OperationalError)
fake_pymysql.cursors = types.SimpleNamespace(DictCursor=object)
fake_pymysql.connect = lambda **_: Connection(DB)
sys.modules["pymysql"] = fake_pymysql

import zk as fakezk  # noqa: E402
import sync  # noqa: E402

assert fakezk.__file__.startswith(os.path.join(ROOT, "tests", "fakezk")), "le vrai pyzk a été chargé"

# Délais raccourcis pour le test
sync.FULL_SYNC_INTERVAL = 1.5
sync.HEARTBEAT_INTERVAL = 0.7
sync.COMPUTE_INTERVAL = 1.0
sync.RETRY_DELAYS = (0.2, 0.4, 0.6, 1.0)
sync.STABLE_AFTER = 5
sync.DEVICE_RELOAD = 0.3
sync.RECOMPUTE_EVERY = 0.8
sync.CLOCK_SET_AT = "off"
sync.BACKUP_AT = "off"
sync.MAX_FAILURES = 5
sync.FAILED_RETRY_DELAY = 2.5

COMPUTES = []
SLOW_GLOBAL = threading.Event()   # calcul global lent (doit ne pas bloquer les recalculs)
FAIL_NEXT = threading.Event()     # prochain recalcul par agent en échec


def fake_compute(args):
    COMPUTES.append((time.monotonic(), list(args)))
    if args[0].startswith("--days=") and SLOW_GLOBAL.is_set():
        time.sleep(2.5)
    if args[0].startswith("--employee-device-id=") and FAIL_NEXT.is_set():
        FAIL_NEXT.clear()
        return False
    return True


sync.run_compute = fake_compute

FLEET_RUNS = []
sync.FLEET_TASKS_INTERVAL = 0.5
sync.run_fleet_tasks = lambda: FLEET_RUNS.append(time.monotonic()) or True
per_agent_calls = lambda since=0: [(t, a) for t, a in COMPUTES[since:] if a[0].startswith("--employee-device-id=")]  # noqa: E731

fail = 0


def check(name, cond, detail=""):
    global fail
    if cond:
        print(f"ok   {name}")
    else:
        fail += 1
        print(f"ÉCHEC {name} {detail}")


def wait_for(cond, timeout=8.0):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if cond():
            return True
        time.sleep(0.02)
    return False


# -- Tests unitaires ---------------------------------------------------------

def test_clock_window():
    real_now, real_at = sync.now, sync.CLOCK_SET_AT
    sync.CLOCK_SET_AT = "03:00"
    try:
        cases = [((2, 59), None, False), ((3, 0), None, True), ((5, 59), None, True), ((6, 0), None, False),
                 ((3, 30), date(2026, 10, 10), False), ((3, 30), date(2026, 10, 9), True)]
        ok = True
        for (h, m), last, expected in cases:
            sync.now = lambda h=h, m=m: datetime(2026, 10, 10, h, m, 5)
            ok &= sync.clock_due(last) is expected
        sync.CLOCK_SET_AT = "off"
        ok &= sync.clock_due(None) is False
        check("fenêtre de remise à l'heure (03:00–06:00, une fois par nuit, désactivable)", ok)
    finally:
        sync.now, sync.CLOCK_SET_AT = real_now, real_at


def test_recompute_queue():
    r = sync.Recompute()
    r.add("7", date(2026, 10, 9))
    r.add("7", date(2026, 10, 9))
    r.add("8", date(2026, 10, 8))
    first = r.take()
    check("file de recalcul : tous les agents en attente, veille incluse",
          first == (["7", "8"], date(2026, 10, 7), date(2026, 10, 9)), str(first))
    check("file vidée après lecture", r.take() is None and not r.wake.is_set())


def test_backup():
    import gzip
    import shutil
    import tempfile
    work = tempfile.mkdtemp(prefix="la-test-backup-")
    fake = os.path.join(work, "mariadb-dump")
    # Faux mariadb-dump : vérifie le mot de passe (échappé) dans le fichier d'options
    with open(fake, "w") as f:
        f.write('#!/bin/sh\n'
                'grep -qF \'password="te\\"st"\' "${1#--defaults-extra-file=}" || { echo "mot de passe" >&2; exit 2; }\n'
                '[ "$FAIL_DUMP" = 1 ] && { echo "Access denied" >&2; exit 2; }\n'
                'for last; do :; done\n'
                'echo "-- dump de $last"\n')
    os.chmod(fake, 0o755)
    real = (sync.BACKUP_DIR, sync.MARIADB_DUMP, sync.now, os.environ["DB_PASSWORD"])
    sync.BACKUP_DIR, sync.MARIADB_DUMP = os.path.join(work, "backups"), fake
    os.environ["DB_PASSWORD"] = 'te"st'
    sync.now = lambda: datetime(2026, 10, 22, 2, 0, 3)
    try:
        os.makedirs(sync.BACKUP_DIR)
        for d in (7, 8, 9, 10, 21):  # 8 oct. = aujourd'hui - 14 jours : supprimée
            open(os.path.join(sync.BACKUP_DIR, f"liquid_2026-10-{d:02d}.sql.gz"), "w").close()
        open(os.path.join(sync.BACKUP_DIR, "autre.txt"), "w").close()
        path = sync.run_backup()
        with gzip.open(path, "rt") as f:
            content = f.read()
        check("sauvegarde : dump compressé, mot de passe passé par fichier d'options",
              os.path.basename(path) == "liquid_2026-10-22.sql.gz" and content.strip() == "-- dump de test", content)
        check("sauvegarde : 14 jours conservés",
              sorted(os.listdir(sync.BACKUP_DIR)) == ["autre.txt", "liquid_2026-10-09.sql.gz", "liquid_2026-10-10.sql.gz",
                                                       "liquid_2026-10-21.sql.gz", "liquid_2026-10-22.sql.gz"],
              str(sorted(os.listdir(sync.BACKUP_DIR))))
        check("sauvegarde : état enregistré", DB.settings.get("backup_last_file") == "liquid_2026-10-22.sql.gz"
              and DB.settings.get("backup_last_error") == "", str(DB.settings))
        os.environ["FAIL_DUMP"] = "1"
        sync.now = lambda: datetime(2026, 10, 23, 2, 0, 3)
        try:
            sync.run_backup()
            failed = False
        except RuntimeError as exc:
            failed = "Access denied" in str(exc)
        check("sauvegarde en échec : erreur claire, aucun fichier partiel",
              failed and not any(n.startswith("liquid_2026-10-23") for n in os.listdir(sync.BACKUP_DIR)))
        check("fenêtre de sauvegarde (02:00, une fois par nuit)",
              sync.due_at("02:00", None, 3) and not sync.due_at("02:00", date(2026, 10, 23), 3)
              and not sync.due_at("off", None, 3))
    finally:
        os.environ.pop("FAIL_DUMP", None)
        sync.BACKUP_DIR, sync.MARIADB_DUMP, sync.now, os.environ["DB_PASSWORD"] = real
        shutil.rmtree(work, ignore_errors=True)


# -- Scénario complet (sync.main dans le thread principal) ---------------------

def scenario():
    a = fakezk.DEVICES["10.0.4.18"] = fakezk.FakeDevice(
        users=[("1", "MUKENDI Jean"), ("2", "")],
        records=[fakezk.Attendance("1", datetime(2026, 10, 8, 7, 55), 1, 0, 1)],
        clock_offset=125,
    )
    b = fakezk.DEVICES["10.0.4.20"] = fakezk.FakeDevice(users=[("1", "MUKENDI Jean")])
    c = fakezk.DEVICES["10.0.4.5"] = fakezk.FakeDevice(users=[("1", "MUKENDI Jean")])
    c.fail_connect = 10 ** 6  # NOC : injoignable pendant tout le test
    DB.add_device(1, "10.0.4.18", "5e entrée")
    DB.add_device(2, "10.0.4.20", "5e sortie")
    DB.add_device(5, "10.0.4.5", "NOC", usage="acces")
    try:
        check("première synchro complète des pointeuses joignables",
              wait_for(lambda: DB.devices[1]["full_syncs"] >= 1 and DB.devices[2]["full_syncs"] >= 1))
        check("employés importés, nom provisoire pour un nom vide",
              DB.employees.get("1", {}).get("name") == "MUKENDI Jean" and DB.employees.get("2", {}).get("name") == "Agent 2",
              str(DB.employees))
        check("historique importé", (1, "1", datetime(2026, 10, 8, 7, 55)) in DB.punches)
        check("premier calcul global après les premières synchros",
              wait_for(lambda: any(args == ["--days=7"] for _, args in COMPUTES)), str(COMPUTES))
        check("battement de cœur du service enregistré",
              "worker_heartbeat_at" in DB.settings and "worker_started_at" in DB.settings, str(DB.settings))
        check("horloge remise à l'heure au démarrage (écart de 125 s > 30 s)",
              wait_for(lambda: len(a.set_time_calls) == 1, 2) and abs(DB.devices[1]["clock_offset_sec"]) <= 1,
              str(a.set_time_calls))
        check("horloge non touchée au démarrage si l'écart est faible", b.set_time_calls == [], str(b.set_time_calls))
        boot = [json.loads(x[1]) for x in DB.audit]
        check("remise à l'heure du démarrage journalisée (écart avant et après)",
              any(x["ip"] == "10.0.4.18" and x["moment"] == "au démarrage" and 124 <= x["ecart_avant_s"] <= 125
                  and abs(x["ecart_apres_s"]) <= 1 for x in boot), str(boot))
        check("pointeuse lente (F16) : délai long et journal détaillé",
              wait_for(lambda: c.options is not None, 2) and c.options == (sync.SLOW_TIMEOUT, True)
              and a.options == (sync.DEVICE_TIMEOUT, False), f"{c.options} {a.options}")
        check("écoute en direct signalée (live_since), ancien état effacé au démarrage",
              wait_for(lambda: isinstance(DB.devices[1]["live_since"], datetime)
                       and isinstance(DB.devices[2]["live_since"], datetime), 2)
              and DB.devices[5]["live_since"] is None, str({k: v["live_since"] for k, v in DB.devices.items()}))

        # Badges en direct, dont un agent inconnu
        n0 = len(COMPUTES)
        t1 = datetime.now().replace(microsecond=0)
        a.badge("1", t1)
        a.badge("99", t1)
        check("badge en direct enregistré tout de suite", wait_for(lambda: (1, "1", t1) in DB.punches, 2))
        check("agent inconnu créé au badge", wait_for(lambda: "99" in DB.employees, 2)
              and DB.employees["99"]["name"] == "Agent 99")
        check("dernier badge reçu (last_event_at)", DB.devices[1]["last_event_at"] is not None)
        check("recalcul demandé dans le délai (RECOMPUTE_EVERY)",
              wait_for(lambda: {"1", "99"} <= {u for _, a_ in per_agent_calls(n0) for u in a_[0].split("=")[1].split(",")},
                       sync.RECOMPUTE_EVERY + 0.5),
              str(COMPUTES[n0:]))
        per_agent = [a_ for _, a_ in per_agent_calls(n0)]
        check("recalcul : veille et jour même",
              per_agent and per_agent[0][1] == f"--from={t1.date() - timedelta(days=1):%Y-%m-%d}"
              and per_agent[-1][2] == f"--to={t1.date():%Y-%m-%d}", str(per_agent))

        # Rafale de badges (plusieurs agents) : au plus un appel PHP par délai
        time.sleep(sync.RECOMPUTE_EVERY)
        n1 = len(COMPUTES)
        for i in range(8):
            a.badge(str(1 + i % 3), t1 + timedelta(seconds=i + 1))
            time.sleep(0.1)
        time.sleep(sync.RECOMPUTE_EVERY * 1.5)
        calls = per_agent_calls(n1)
        gaps = [round(y[0] - x[0], 2) for x, y in zip(calls, calls[1:])]
        agents = {u for _, a_ in calls for u in a_[0].split("=")[1].split(",")}
        check("rafale regroupée : un appel au plus par délai, tous les agents",
              1 <= len(calls) <= 3 and all(g >= sync.RECOMPUTE_EVERY - 0.05 for g in gaps) and agents == {"1", "2", "3"},
              f"{len(calls)} appels, écarts {gaps}, agents {agents}")

        # Calcul global lent : les recalculs par agent continuent (thread à part)
        SLOW_GLOBAL.set()
        n2 = len(COMPUTES)
        check("calcul global lancé", wait_for(lambda: any(a_[0] == "--days=7" for _, a_ in COMPUTES[n2:]), 3))
        time.sleep(0.1)
        a.badge("2", t1 + timedelta(seconds=20))
        check("recalcul rapide pendant un calcul global lent",
              wait_for(lambda: any(a_[0] == "--employee-device-id=2" for _, a_ in per_agent_calls(n2)),
                       sync.RECOMPUTE_EVERY + 1.0), str(COMPUTES[n2:]))
        SLOW_GLOBAL.clear()

        # Recalcul en échec : les agents repartent dans la file
        time.sleep(sync.RECOMPUTE_EVERY)
        FAIL_NEXT.set()
        n3 = len(COMPUTES)
        a.badge("3", t1 + timedelta(seconds=30))
        check("recalcul en échec relancé au délai suivant",
              wait_for(lambda: len([1 for _, a_ in per_agent_calls(n3) if "3" in a_[0].split("=")[1].split(",")]) >= 2,
                       sync.RECOMPUTE_EVERY * 3), str(COMPUTES[n3:]))

        # Accès salle : badge enregistré mais pas de recalcul ; ici NOC injoignable
        check("pointeuse injoignable : erreur claire enregistrée",
              wait_for(lambda: DB.devices[5]["last_sync_ok"] == 0) and "réseau" in (DB.devices[5]["last_error"] or ""),
              str(DB.devices[5]["last_error"]))
        check("après 5 échecs de suite : tentatives espacées (30 min en production)",
              wait_for(lambda: len(c.attempts) >= 7, 10), str(len(c.attempts)))
        gaps = [round(y - x, 2) for x, y in zip(c.attempts, c.attempts[1:])]
        check("délais : croissants puis FAILED_RETRY_DELAY à partir du 6e essai",
              all(g < 1.5 for g in gaps[:5]) and all(g >= sync.FAILED_RETRY_DELAY - 0.1 for g in gaps[5:6]),
              str(gaps))

        # Synchro complète périodique sur la même connexion
        syncs = DB.devices[1]["full_syncs"]
        check("synchro complète périodique", wait_for(lambda: DB.devices[1]["full_syncs"] > syncs, 4))
        check("même connexion conservée (pas de reconnexion)", a.connections == 1, f"{a.connections} connexions")
        check("sortie propre de live_capture avant les commandes", a.capture_clean_exits >= 1 and not a.violations,
              f"sorties={a.capture_clean_exits} violations={a.violations}")

        # Coupure réseau : reconnexion, rattrapage par synchro complète
        a.live.append(fakezk.DROP)
        check("coupure détectée et enregistrée", wait_for(lambda: DB.devices[1]["last_sync_ok"] == 0, 3))
        check("coupure : plus « en écoute »", DB.devices[1]["live_since"] is None)
        a.fail_connect = 6  # 3 essais refusés (TCP puis UDP à chaque essai)
        check("reconnexion après coupure", wait_for(lambda: a.connections >= 2 and DB.devices[1]["last_sync_ok"] == 1, 6))
        tries = a.attempts[-4:]
        gaps = [round(y - x, 2) for x, y in zip(tries, tries[1:])]
        check("délai de reconnexion croissant", len(gaps) == 3 and gaps[0] < gaps[1] < gaps[2], str(gaps))
        t2 = datetime.now().replace(microsecond=0) + timedelta(seconds=30)
        a.records.append(fakezk.Attendance("2", t2, 1, 0, 2))  # badgé pendant la coupure
        check("badge manqué pendant la coupure rattrapé", wait_for(lambda: (1, "2", t2) in DB.punches, 4))
        t3 = t2 + timedelta(seconds=1)
        b.badge("1", t3)
        check("les autres pointeuses continuent pendant la panne du NOC", wait_for(lambda: (2, "1", t3) in DB.punches, 2))

        # Remise à l'heure nocturne (fenêtre ouverte maintenant)
        sync.CLOCK_SET_AT = f"{datetime.now():%H:%M}"
        b.clock_offset = timedelta(seconds=-77)
        check("horloge remise à l'heure", wait_for(lambda: len(a.set_time_calls) == 2 and len(b.set_time_calls) == 1, 4))
        logged = lambda: [json.loads(p[1]) for p in DB.audit  # noqa: E731
                          if json.loads(p[1])["ip"] == "10.0.4.20" and json.loads(p[1])["moment"] == "nocturne"]
        check("écart avant et après journalisé",
              wait_for(lambda: logged() and -78 <= logged()[0]["ecart_avant_s"] <= -76
                       and abs(logged()[0]["ecart_apres_s"]) <= 1, 2)
              and abs(DB.devices[2]["clock_offset_sec"]) <= 1, str(DB.audit))
        time.sleep(1.5)
        check("une seule remise à l'heure par nuit", len(a.set_time_calls) == 2 and len(b.set_time_calls) == 1,
              f"{a.set_time_calls} {b.set_time_calls}")
        check("aucune connexion simultanée sur un appareil",
              a.max_connected == 1 and b.max_connected == 1, f"{a.max_connected} {b.max_connected}")
        check("aucune commande pendant l'écoute", not a.violations and not b.violations, f"{a.violations} {b.violations}")

        # Désactivation : la connexion est fermée
        DB.devices[2]["active"] = 0
        check("pointeuse désactivée : connexion fermée", wait_for(lambda: b.connected == 0, 4))
        check("pointeuse désactivée : plus « en écoute »", wait_for(lambda: DB.devices[2]["live_since"] is None, 2))
    finally:
        # Arrêt comme « docker compose down » : SIGTERM au processus
        os.kill(os.getpid(), signal.SIGTERM)


test_clock_window()
test_recompute_queue()
test_backup()
driver = threading.Thread(target=scenario, daemon=True)
driver.start()
start = time.monotonic()
sync.main()
driver.join(5)
elapsed = time.monotonic() - start
check("tâches du parc automobile lancées régulièrement", len(FLEET_RUNS) >= 2, str(len(FLEET_RUNS)))
check("arrêt propre sur SIGTERM, en quelques secondes", sync.stop.is_set() and elapsed < 60 and not driver.is_alive(),
      f"{elapsed:.1f} s")
print(f"\n{fail} échec(s)" if fail else "\nTous les tests passent.")
sys.exit(1 if fail else 0)
