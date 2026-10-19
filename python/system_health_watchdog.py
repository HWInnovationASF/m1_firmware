"""Lightweight process and main-loop health supervision for py_multi."""

import json
import os
import sys
import threading
import time
from datetime import datetime


HEALTH_CHECK_INTERVAL = 5.0
HEALTH_STATUS_INTERVAL = 30.0
HEALTH_STARTUP_GRACE = 20.0
HEALTH_MIN_MAIN_STALL = 300.0
HEALTH_MAX_MAIN_STALL = 3600.0
HEALTH_SECONDS_PER_DEVICE = 45.0
HEALTH_BASE_STALL = 180.0
SYSTEM_HEALTH_PATH = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    "system_health.json",
)


def adaptive_stall_limit(device_count):
    """Return a conservative main-loop ceiling without changing Modbus timeouts."""
    calculated = HEALTH_BASE_STALL + (HEALTH_SECONDS_PER_DEVICE * max(0, int(device_count)))
    return min(HEALTH_MAX_MAIN_STALL, max(HEALTH_MIN_MAIN_STALL, calculated))


def _process_state(children):
    return {
        name: {
            "pid": process.pid,
            "alive": process.is_alive(),
            "exitcode": process.exitcode,
        }
        for name, process in children
    }


def _write_state(status, reason, children, main_heartbeat, main_stall_limit, health_path):
    payload = {
        "status": status,
        "reason": reason,
        "updated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "updated_ts": int(time.time()),
        "main_heartbeat_age_s": round(max(0.0, time.monotonic() - main_heartbeat.value), 1),
        "main_stall_limit_s": round(float(main_stall_limit.value), 1),
        "processes": _process_state(children),
    }
    temporary = health_path + f".tmp.{os.getpid()}"
    try:
        with open(temporary, "w", encoding="utf-8") as output:
            json.dump(payload, output, ensure_ascii=False, separators=(",", ":"))
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, health_path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def _watch(children, main_heartbeat, main_stall_limit, error_logger, health_path):
    started = time.monotonic()
    last_snapshot = 0.0
    while True:
        now = time.monotonic()
        dead = []
        if now - started >= HEALTH_STARTUP_GRACE:
            dead = [
                f"{name}(exit={process.exitcode})"
                for name, process in children
                if not process.is_alive()
            ]

        heartbeat_age = max(0.0, now - main_heartbeat.value)
        stall_limit = max(HEALTH_MIN_MAIN_STALL, float(main_stall_limit.value))
        if dead:
            reason = "critical child stopped: " + ", ".join(dead)
        elif heartbeat_age > stall_limit:
            reason = f"main loop stalled for {heartbeat_age:.1f}s (limit {stall_limit:.1f}s)"
        else:
            reason = ""

        if reason:
            try:
                _write_state("restarting", reason, children, main_heartbeat, main_stall_limit, health_path)
            except Exception as error:
                print(f"[System Health] status write failed: {error}", file=sys.stderr, flush=True)
            print(f"[System Health] {reason}; exiting for systemd restart", file=sys.stderr, flush=True)
            if error_logger is not None:
                try:
                    error_logger(reason)
                except Exception:
                    pass
            time.sleep(0.2)
            os._exit(70)

        if now - last_snapshot >= HEALTH_STATUS_INTERVAL:
            try:
                _write_state("healthy", "", children, main_heartbeat, main_stall_limit, health_path)
            except Exception as error:
                print(f"[System Health] status write failed: {error}", file=sys.stderr, flush=True)
            last_snapshot = now
        time.sleep(HEALTH_CHECK_INTERVAL)


def start_health_watchdog(
    children,
    main_heartbeat,
    main_stall_limit,
    error_logger=None,
    health_path=SYSTEM_HEALTH_PATH,
):
    """Start the daemon supervisor and return its Thread instance."""
    thread = threading.Thread(
        target=_watch,
        args=(children, main_heartbeat, main_stall_limit, error_logger, health_path),
        name="system-health-watchdog",
        daemon=True,
    )
    thread.start()
    return thread
