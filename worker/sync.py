"""Récupération des pointages et des utilisateurs des pointeuses ZKTeco.

Lecture seule : rien n'est modifié ni effacé sur les appareils.
- Met à jour l'état de chaque pointeuse (modèle, horloge, compteurs).
- Crée les employés absents de la plateforme (le nom de la pointeuse sert de
  nom initial ; ensuite seul `device_name` est mis à jour, jamais `name`).
- Insère les nouveaux pointages (doublons ignorés grâce à la clé unique).
"""
import os
import sys
import time
from datetime import datetime

import pymysql
from zk import ZK


def log(msg):
    print(f"[sync {datetime.now():%Y-%m-%d %H:%M:%S}] {msg}", flush=True)


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


def sync_device(conn, dev):
    zk = connect_device(dev)
    try:
        server_now = datetime.now()
        device_now = zk.get_time()
        offset = int((device_now - server_now).total_seconds())

        info = {
            "model": safe(zk.get_device_name),
            "serial": safe(zk.get_serialnumber),
            "firmware": safe(zk.get_firmware_version),
        }
        users = zk.get_users() or []
        records = zk.get_attendance() or []
    finally:
        try:
            zk.disconnect()
        except Exception:  # noqa: BLE001
            pass

    with conn.cursor() as cur:
        # Employés : création si inconnu, mise à jour du nom côté pointeuse uniquement
        for u in users:
            uid = str(u.user_id).strip()
            if not uid:
                continue
            dname = (u.name or "").strip() or f"Agent {uid}"
            cur.execute(
                "INSERT INTO employees (device_user_id, name, device_name) VALUES (%s, %s, %s) "
                "ON DUPLICATE KEY UPDATE device_name = VALUES(device_name)",
                (uid, dname, dname),
            )

        rows = [
            (dev["id"], str(r.user_id).strip(), r.timestamp, r.status, r.punch)
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
            )

        cur.execute(
            "UPDATE devices SET last_sync_at = NOW(), last_sync_ok = 1, last_error = NULL, "
            "clock_offset_sec = %s, user_count = %s, log_count = %s, "
            "model = COALESCE(%s, model), serial_number = COALESCE(%s, serial_number), "
            "firmware = COALESCE(%s, firmware) WHERE id = %s",
            (offset, len(users), len(records), info["model"], info["serial"], info["firmware"], dev["id"]),
        )
    conn.commit()
    return len(users), len(records), inserted, offset


def safe(fn):
    try:
        val = fn()
        return str(val).strip() if val else None
    except Exception:  # noqa: BLE001
        return None


def main():
    conn = db()
    with conn.cursor(pymysql.cursors.DictCursor) as cur:
        cur.execute("SELECT id, name, ip, port, comm_key FROM devices WHERE active = 1 ORDER BY id")
        devices = cur.fetchall()
    conn.commit()

    errors = 0
    for dev in devices:
        start = time.time()
        try:
            users, logs, new, offset = sync_device(conn, dev)
            log(f"{dev['name']} ({dev['ip']}) : {users} utilisateurs, {logs} pointages, "
                f"{new} nouveaux, horloge {offset:+d} s, {time.time() - start:.1f} s")
        except Exception as exc:  # noqa: BLE001
            errors += 1
            conn.rollback()
            msg = f"{type(exc).__name__}: {exc}"[:250]
            log(f"ERREUR {dev['name']} ({dev['ip']}) : {msg}")
            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE devices SET last_sync_at = NOW(), last_sync_ok = 0, last_error = %s WHERE id = %s",
                    (msg, dev["id"]),
                )
            conn.commit()
    conn.close()
    return 1 if errors and errors == len(devices) else 0


if __name__ == "__main__":
    sys.exit(main())
