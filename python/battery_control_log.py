import fcntl
import gzip
import json
import os
import re
import shutil
import time
from datetime import datetime, timedelta


LOG_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "battery_control_logs")
HOT_LOG_DAYS = 2
LOG_RETENTION_DAYS = 90
_LOG_FILE_PATTERN = re.compile(
    r"^battery_(?:control|action)_(\d{4}-\d{2}-\d{2})\.(?:jsonl|txt)(?:\.gz)?$"
)
_MAINTENANCE_LOCK = os.path.join(LOG_DIR, ".maintenance.lock")
_MAINTENANCE_STAMP = os.path.join(LOG_DIR, ".maintenance_stamp")


def _compress_log_file(path):
    """Create a verified gzip archive atomically, then remove the plain log."""
    archive_path = path + ".gz"
    temp_path = archive_path + f".tmp.{os.getpid()}"
    try:
        with open(path, "rb") as source, gzip.open(temp_path, "wb", compresslevel=6) as target:
            shutil.copyfileobj(source, target, length=1024 * 1024)
        with gzip.open(temp_path, "rb") as check_file:
            check_file.read(1)
        os.chmod(temp_path, 0o644)
        os.replace(temp_path, archive_path)
        os.unlink(path)
    finally:
        if os.path.exists(temp_path):
            os.unlink(temp_path)


def maintain_battery_logs(now=None, force=False):
    """Keep two days plain, gzip older logs, and delete anything over 90 days."""
    os.makedirs(LOG_DIR, exist_ok=True)
    current = now or datetime.now()
    today_text = current.strftime("%Y-%m-%d")
    try:
        with open(_MAINTENANCE_LOCK, "a+", encoding="utf-8") as lock_file:
            lock_mode = fcntl.LOCK_EX if force else (fcntl.LOCK_EX | fcntl.LOCK_NB)
            try:
                fcntl.flock(lock_file.fileno(), lock_mode)
            except BlockingIOError:
                return

            if not force:
                try:
                    with open(_MAINTENANCE_STAMP, "r", encoding="utf-8") as stamp_file:
                        if stamp_file.read().strip() == today_text:
                            return
                except OSError:
                    pass

            plain_cutoff = current.date() - timedelta(days=HOT_LOG_DAYS - 1)
            retention_cutoff = current.date() - timedelta(days=LOG_RETENTION_DAYS - 1)
            for filename in os.listdir(LOG_DIR):
                match = _LOG_FILE_PATTERN.match(filename)
                if not match:
                    continue
                try:
                    file_date = datetime.strptime(match.group(1), "%Y-%m-%d").date()
                except ValueError:
                    continue
                path = os.path.join(LOG_DIR, filename)
                if file_date < retention_cutoff:
                    os.unlink(path)
                elif (filename.endswith(".jsonl") or filename.endswith(".txt")) and file_date < plain_cutoff:
                    _compress_log_file(path)

            stamp_temp = _MAINTENANCE_STAMP + f".tmp.{os.getpid()}"
            with open(stamp_temp, "w", encoding="utf-8") as stamp_file:
                stamp_file.write(today_text)
                stamp_file.flush()
                os.fsync(stamp_file.fileno())
            os.chmod(stamp_temp, 0o644)
            os.replace(stamp_temp, _MAINTENANCE_STAMP)
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
    except Exception as error:
        print(f"Battery control log maintenance error: {error}")


def record_battery_command(source, device_id, parameter, requested, actual=None,
                           success=None, reason="", cmd_id="", context=None):
    """Append one Battery control command to a date-partitioned JSONL file."""
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        now = datetime.now()
        path = os.path.join(LOG_DIR, f"battery_control_{now:%Y-%m-%d}.jsonl")
        record = {
            "timestamp": int(time.time()),
            "datetime": now.strftime("%Y-%m-%d %H:%M:%S"),
            "source": str(source),
            "cmd_id": str(cmd_id),
            "device_id": str(device_id),
            "parameter": str(parameter),
            "requested": requested,
            "actual": actual,
            "success": success,
            "reason": str(reason or ""),
            "context": context if isinstance(context, dict) else {}
        }
        with open(path, "a", encoding="utf-8") as log_file:
            fcntl.flock(log_file.fileno(), fcntl.LOCK_EX)
            log_file.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")) + "\n")
            log_file.flush()
            os.fsync(log_file.fileno())
            fcntl.flock(log_file.fileno(), fcntl.LOCK_UN)
        os.chmod(path, 0o644)
        maintain_battery_logs(now)
    except Exception as error:
        print(f"Battery control daily log error: {error}")


def _battery_action_reason(reason, now=None, solar_power=0.0):
    """Classify an action from TOU first, then early Solar production."""
    current = now or datetime.now()
    text = str(reason or "").lower()
    try:
        from tou_schedule import get_tou_state
        tou_state = get_tou_state(current)
        if isinstance(tou_state, dict):
            if tou_state.get("on_peak"):
                return "on peak"
            on_peak_start = datetime.strptime(
                str(tou_state.get("start_time", "09:00:00")), "%H:%M:%S"
            ).time()
            try:
                producing = float(solar_power or 0) >= 0.1
            except (TypeError, ValueError):
                producing = False
            if producing and current.time() < on_peak_start:
                return "solar"
            return "off peak"
    except Exception:
        pass

    # Preserve a safe fallback if TOU data is temporarily unavailable.
    if "solar" in text or "main export" in text:
        return "solar"
    if any(token in text for token in (
        "on-peak", "on peak", "peak shaving", "fast peak",
        "scheduled discharge", "reduce discharge"
    )):
        return "on peak"
    return "off peak"


def record_battery_action(p_max, e_p_cha=None, e_p_dis=None, reason="", solar_power=0.0):
    """Append one successful Battery p_max action to a daily tab-separated text file."""
    try:
        os.makedirs(LOG_DIR, exist_ok=True)
        now = datetime.now()
        path = os.path.join(LOG_DIR, f"battery_action_{now:%Y-%m-%d}.txt")
        needs_header = not os.path.exists(path) or os.path.getsize(path) == 0
        values = (
            now.strftime("%Y-%m-%d %H:%M:%S"),
            "" if p_max is None else str(p_max),
            "" if e_p_cha is None else str(e_p_cha),
            "" if e_p_dis is None else str(e_p_dis),
            _battery_action_reason(reason, now, solar_power),
        )
        with open(path, "a", encoding="utf-8") as log_file:
            fcntl.flock(log_file.fileno(), fcntl.LOCK_EX)
            if needs_header and log_file.tell() == 0:
                log_file.write("datetime\tp_max\te_p_cha\te_p_dis\treason\n")
            log_file.write("\t".join(values) + "\n")
            log_file.flush()
            os.fsync(log_file.fileno())
            fcntl.flock(log_file.fileno(), fcntl.LOCK_UN)
        os.chmod(path, 0o644)
        maintain_battery_logs(now)
    except Exception as error:
        print(f"Battery action text log error: {error}")


if __name__ == "__main__":
    maintain_battery_logs(force=True)
    print("Battery control log maintenance complete")
