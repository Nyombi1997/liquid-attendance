"""Faux module `zk` (pyzk) pour tester worker/sync.py sans pointeuse.

Utilisé par tests/test_sync.py (PYTHONPATH=tests/fakezk). Hors de l'image
Docker (.dockerignore). Chaque appareil simulé est décrit dans DEVICES[ip] :
utilisateurs, pointages, badges à envoyer en direct, pannes à provoquer.

Comme les vrais appareils, un FakeDevice :
- refuse une deuxième connexion simultanée ;
- refuse toute commande pendant live_capture (il faut d'abord en sortir
  proprement avec end_live_capture = True) ;
- rend None à chaque délai d'attente sans badge.
"""
import threading
import time
from collections import namedtuple
from datetime import datetime, timedelta

User = namedtuple("User", "uid user_id name")
Attendance = namedtuple("Attendance", "user_id timestamp status punch uid")

DROP = "coupure"          # dans FakeDevice.live : coupe la connexion
TICK = 0.02               # durée simulée d'un délai d'attente de live_capture

DEVICES = {}
LOCK = threading.Lock()


class ZKNetworkError(Exception):
    pass


class ZKErrorResponse(Exception):
    pass


class FakeDevice:
    def __init__(self, users=(), records=(), clock_offset=0):
        self.users = [User(i + 1, str(uid), name) for i, (uid, name) in enumerate(users)]
        self.records = list(records)
        self.clock_offset = timedelta(seconds=clock_offset)
        self.live = []                # badges (Attendance) ou DROP, envoyés un par un
        self.fail_connect = 0         # nombre de connexions à refuser
        self.connected = 0
        self.max_connected = 0
        self.connections = 0
        self.violations = []          # commandes envoyées pendant l'écoute
        self.capture_starts = 0
        self.capture_clean_exits = 0
        self.full_reads = 0
        self.set_time_calls = []
        self.attempts = []            # instants des tentatives de connexion (TCP, la première de chaque essai)

    def badge(self, user_id, when=None, status=1, punch=0):
        att = Attendance(str(user_id), when or datetime.now().replace(microsecond=0), status, punch, 0)
        self.records.append(att)
        self.live.append(att)
        return att


class ZK:
    def __init__(self, ip, port=4370, timeout=60, password=0, force_udp=False, ommit_ping=False, **_):
        self.ip = ip
        self.tcp = not force_udp
        self.dev = DEVICES.get(ip)
        self.is_connect = False
        self.capturing = False
        self.end_live_capture = False

    def connect(self):
        dev = self.dev
        with LOCK:
            if dev is None:
                raise ZKNetworkError(f"can't reach device (ping {self.ip})")
            if self.tcp:
                dev.attempts.append(time.monotonic())
            if dev.fail_connect > 0:
                dev.fail_connect -= 1
                raise ZKNetworkError("can't reach device (timed out)")
            if dev.connected:
                raise ZKErrorResponse("Unauthenticated: device already in use")
            dev.connected += 1
            dev.connections += 1
            dev.max_connected = max(dev.max_connected, dev.connected)
        self.is_connect = True
        return self

    def _cmd(self, name):
        if not self.is_connect:
            raise ZKNetworkError("instance are not connected.")
        if self.capturing:
            self.dev.violations.append(name)
            raise ZKErrorResponse(f"{name} pendant live_capture")

    def disconnect(self):
        if self.is_connect:
            with LOCK:
                self.dev.connected -= 1
            self.is_connect = False
        return True

    def get_time(self):
        self._cmd("get_time")
        return (datetime.now() + self.dev.clock_offset).replace(microsecond=0)

    def set_time(self, timestamp):
        self._cmd("set_time")
        self.dev.set_time_calls.append(timestamp)
        self.dev.clock_offset = timestamp - datetime.now()
        return True

    def get_device_name(self):
        self._cmd("get_device_name")
        return "SF400/ID"

    def get_serialnumber(self):
        self._cmd("get_serialnumber")
        return "FAKE" + self.ip.split(".")[-1]

    def get_firmware_version(self):
        self._cmd("get_firmware_version")
        return "Ver 6.60"

    def get_users(self):
        self._cmd("get_users")
        return list(self.dev.users)

    def get_attendance(self):
        self._cmd("get_attendance")
        self.dev.full_reads += 1
        return list(self.dev.records)

    def live_capture(self, new_timeout=10):
        self.get_users()
        self.capturing = True
        self.dev.capture_starts += 1
        self.end_live_capture = False
        while not self.end_live_capture:
            if not self.dev.live:
                time.sleep(TICK)
                yield None
                continue
            item = self.dev.live.pop(0)
            if item == DROP:
                self.capturing = False
                self.disconnect()
                raise ConnectionResetError(104, "Connection reset by peer")
            yield item
        self.capturing = False
        self.dev.capture_clean_exits += 1
