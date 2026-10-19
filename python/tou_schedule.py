import json
import os
from datetime import datetime


TOU_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "tou_schedule.json")
_cache_mtime = None
_cache_data = {}


def _normalise_time(value, field_name):
    text = str(value or "").strip()
    for pattern in ("%H:%M:%S", "%H:%M"):
        try:
            return datetime.strptime(text, pattern).strftime("%H:%M:%S")
        except ValueError:
            pass
    raise ValueError(f"{field_name} must use HH:MM:SS")


def normalise_tou(data, now=None):
    if not isinstance(data, dict):
        raise ValueError("data must be an object keyed by year")
    now = now or datetime.now()
    allowed_years = {now.year, now.year + 1}
    output = {}
    for year_key, year_data in data.items():
        try:
            year = int(year_key)
        except (TypeError, ValueError):
            continue
        if year not in allowed_years or not isinstance(year_data, dict):
            continue
        on_peak = year_data.get("on_peak", {})
        if not isinstance(on_peak, dict):
            raise ValueError(f"{year}: on_peak must be an object")
        days = sorted({
            int(day) for day in on_peak.get("days", [])
            if str(day).isdigit() and 1 <= int(day) <= 7
        })
        if not days:
            raise ValueError(f"{year}: on_peak.days must contain 1-7")
        holidays = []
        for value in year_data.get("holidays", []):
            text = str(value).strip()
            try:
                parsed = datetime.strptime(text, "%Y-%m-%d")
            except ValueError:
                continue
            if parsed.year == year and text not in holidays:
                holidays.append(text)
        output[str(year)] = {
            "on_peak": {
                "days": days,
                "start_time": _normalise_time(on_peak.get("start_time"), "start_time"),
                "end_time": _normalise_time(on_peak.get("end_time"), "end_time"),
            },
            "holidays": sorted(holidays),
        }
    if not output:
        raise ValueError("no valid current/next-year TOU data")
    return output


def save_tou_schedule(data):
    global _cache_mtime, _cache_data
    schedule = normalise_tou(data)
    temp_file = TOU_FILE + ".tmp"
    with open(temp_file, "w", encoding="utf-8") as output:
        json.dump(schedule, output, ensure_ascii=False, indent=2)
        output.flush()
        os.fsync(output.fileno())
    os.replace(temp_file, TOU_FILE)
    os.chmod(TOU_FILE, 0o644)
    _cache_mtime = os.path.getmtime(TOU_FILE)
    _cache_data = schedule
    return {
        "success": True,
        "file": os.path.basename(TOU_FILE),
        "years": sorted(schedule),
        "holiday_count": sum(len(item["holidays"]) for item in schedule.values()),
    }


def load_tou_schedule():
    global _cache_mtime, _cache_data
    try:
        mtime = os.path.getmtime(TOU_FILE)
    except OSError:
        _cache_mtime = None
        _cache_data = {}
        return {}
    if mtime == _cache_mtime:
        return _cache_data
    try:
        with open(TOU_FILE, "r", encoding="utf-8") as source:
            loaded = json.load(source)
        _cache_data = loaded if isinstance(loaded, dict) else {}
        _cache_mtime = mtime
    except (OSError, ValueError):
        _cache_data = {}
    return _cache_data


def get_tou_state(now=None):
    now = now or datetime.now()
    year_data = load_tou_schedule().get(str(now.year))
    if not isinstance(year_data, dict):
        return None
    on_peak = year_data.get("on_peak", {})
    start_time = datetime.strptime(on_peak["start_time"], "%H:%M:%S").time()
    end_time = datetime.strptime(on_peak["end_time"], "%H:%M:%S").time()
    current_time = now.time()
    in_time = (
        start_time <= current_time < end_time
        if start_time <= end_time
        else current_time >= start_time or current_time < end_time
    )
    is_holiday = now.strftime("%Y-%m-%d") in set(year_data.get("holidays", []))
    weekday_enabled = now.isoweekday() in set(on_peak.get("days", []))
    return {
        "on_peak": bool(is_holiday or (weekday_enabled and in_time)),
        "holiday": is_holiday,
        "weekday_enabled": weekday_enabled,
        "in_time": in_time,
        "start_time": on_peak["start_time"],
        "end_time": on_peak["end_time"],
    }
