import fcntl
import gzip
import os
import re
import shutil
from datetime import datetime, timedelta


LOG_ERROR_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "log_err")
HOT_LOG_DAYS = 2
LOG_RETENTION_DAYS = 90
_LOG_FILE_PATTERN = re.compile(r"^.+_event_(\d{8})\.txt(?:\.gz)?$")


def _compress_text_log(path):
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


def maintain_error_logs(root_path=None, now=None, force=False):
    """Keep two calendar days as TXT, gzip older error logs, retain 90 days."""
    root = os.path.abspath(root_path or LOG_ERROR_ROOT)
    os.makedirs(root, exist_ok=True)
    lock_path = os.path.join(root, ".maintenance.lock")
    stamp_path = os.path.join(root, ".maintenance_stamp")
    current = now or datetime.now()
    today_text = current.strftime("%Y-%m-%d")
    try:
        with open(lock_path, "a+", encoding="utf-8") as lock_file:
            lock_mode = fcntl.LOCK_EX if force else (fcntl.LOCK_EX | fcntl.LOCK_NB)
            try:
                fcntl.flock(lock_file.fileno(), lock_mode)
            except BlockingIOError:
                return False

            if not force:
                try:
                    with open(stamp_path, "r", encoding="utf-8") as stamp_file:
                        if stamp_file.read().strip() == today_text:
                            return True
                except OSError:
                    pass

            plain_cutoff = current.date() - timedelta(days=HOT_LOG_DAYS - 1)
            retention_cutoff = current.date() - timedelta(days=LOG_RETENTION_DAYS - 1)
            for directory, _, filenames in os.walk(root):
                for filename in filenames:
                    match = _LOG_FILE_PATTERN.match(filename)
                    if not match:
                        continue
                    try:
                        file_date = datetime.strptime(match.group(1), "%Y%m%d").date()
                    except ValueError:
                        continue
                    path = os.path.join(directory, filename)
                    if file_date < retention_cutoff:
                        os.unlink(path)
                    elif filename.endswith(".txt") and file_date < plain_cutoff:
                        _compress_text_log(path)

            stamp_temp = stamp_path + f".tmp.{os.getpid()}"
            with open(stamp_temp, "w", encoding="utf-8") as stamp_file:
                stamp_file.write(today_text)
                stamp_file.flush()
                os.fsync(stamp_file.fileno())
            os.chmod(stamp_temp, 0o644)
            os.replace(stamp_temp, stamp_path)
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
            return True
    except Exception as error:
        print(f"log_err maintenance error: {error}")
        return False


if __name__ == "__main__":
    if maintain_error_logs(force=True):
        print("log_err maintenance complete")
    else:
        raise SystemExit("log_err maintenance failed")
