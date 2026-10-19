import fcntl
import gzip
import os
import re
import shutil
import zipfile
from datetime import datetime, timedelta


LOG_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "log_action")
LOG5_HOT_DAYS = 2
LOG1_HOT_DAYS = 1
LOG_RETENTION_DAYS = 90
_LOG_PATTERN = re.compile(
    r"^(?P<base>.+_(?P<kind>log5|log1)_(?P<stamp>\d{8}(?:\d{4})?))"
    r"(?P<suffix>\.txt(?:\.gz)?|\.zip)$"
)


def _copy_content(path, target):
    if path.endswith(".zip"):
        with zipfile.ZipFile(path, "r") as archive:
            members = [name for name in archive.namelist() if name.endswith(".txt")]
            if not members:
                raise ValueError(f"No text log in {path}")
            with archive.open(members[0], "r") as source:
                shutil.copyfileobj(source, target, length=1024 * 1024)
    elif path.endswith(".gz"):
        with gzip.open(path, "rb") as source:
            shutil.copyfileobj(source, target, length=1024 * 1024)
    else:
        with open(path, "rb") as source:
            shutil.copyfileobj(source, target, length=1024 * 1024)


def _ordered_sources(variants):
    return sorted(variants, key=lambda path: (0 if path.endswith(".zip") else 1 if path.endswith(".gz") else 2))


def _merge_as_plain(base_path, variants):
    target_path = base_path + ".txt"
    if variants == [target_path]:
        return
    temp_path = target_path + f".tmp.{os.getpid()}"
    try:
        with open(temp_path, "wb") as target:
            for source_path in _ordered_sources(variants):
                _copy_content(source_path, target)
            target.flush()
            os.fsync(target.fileno())
        os.chmod(temp_path, 0o644)
        os.replace(temp_path, target_path)
        for source_path in variants:
            if source_path != target_path and os.path.exists(source_path):
                os.unlink(source_path)
    finally:
        if os.path.exists(temp_path):
            os.unlink(temp_path)


def _merge_as_gzip(base_path, variants):
    target_path = base_path + ".txt.gz"
    if variants == [target_path]:
        return
    temp_path = target_path + f".tmp.{os.getpid()}"
    try:
        with gzip.open(temp_path, "wb", compresslevel=6) as target:
            for source_path in _ordered_sources(variants):
                _copy_content(source_path, target)
        with gzip.open(temp_path, "rb") as check_file:
            check_file.read(1)
        os.chmod(temp_path, 0o644)
        os.replace(temp_path, target_path)
        for source_path in variants:
            if source_path != target_path and os.path.exists(source_path):
                os.unlink(source_path)
    finally:
        if os.path.exists(temp_path):
            os.unlink(temp_path)


def maintain_log_action(root_path=None, now=None, force=False):
    """Merge legacy TXT/ZIP fragments, compress closed logs, retain 90 days."""
    root = os.path.abspath(root_path or LOG_ROOT)
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

            retention_cutoff = current.date() - timedelta(days=LOG_RETENTION_DAYS - 1)
            log5_plain_cutoff = current.date() - timedelta(days=LOG5_HOT_DAYS - 1)
            log1_plain_cutoff = current.date() - timedelta(days=LOG1_HOT_DAYS - 1)
            for directory, _, filenames in os.walk(root):
                groups = {}
                metadata = {}
                for filename in filenames:
                    match = _LOG_PATTERN.match(filename)
                    if not match:
                        continue
                    base_path = os.path.join(directory, match.group("base"))
                    groups.setdefault(base_path, []).append(os.path.join(directory, filename))
                    metadata[base_path] = (match.group("kind"), match.group("stamp")[:8])

                for base_path, variants in groups.items():
                    kind, day_stamp = metadata[base_path]
                    try:
                        file_date = datetime.strptime(day_stamp, "%Y%m%d").date()
                    except ValueError:
                        continue
                    if file_date < retention_cutoff:
                        for path in variants:
                            if os.path.exists(path):
                                os.unlink(path)
                        continue
                    plain_cutoff = log5_plain_cutoff if kind == "log5" else log1_plain_cutoff
                    if file_date >= plain_cutoff:
                        _merge_as_plain(base_path, variants)
                    else:
                        _merge_as_gzip(base_path, variants)

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
        print(f"log_action maintenance error: {error}")
        return False


if __name__ == "__main__":
    if maintain_log_action(force=True):
        print("log_action maintenance complete")
    else:
        raise SystemExit("log_action maintenance failed")
