import json
import os
import re
import time
from datetime import datetime

from battery_control_log import record_battery_command


LOG_DIR = os.path.join(
    os.path.dirname(os.path.abspath(__file__)), "automation_simulation_logs"
)
_LOG_PATTERN = re.compile(r"^automation_simulation_(\d{4}-\d{2}-\d{2})\.txt(?:\.gz)?$")
_LAST_BATTERY_COMMANDS = {}


def _number_or_value(value):
    try:
        return round(float(value), 3)
    except (TypeError, ValueError):
        return value


def _compact_input(snapshot, config):
    merged = {}
    for block in snapshot:
        if not isinstance(block, dict):
            continue
        for device_id, values in block.items():
            if isinstance(values, dict):
                merged.setdefault(str(device_id), {}).update(values)

    main_meter = str(config.get("main_meter", ""))
    result = {
        "main_meter": {
            "device_id": main_meter,
            "p_t": merged.get(main_meter, {}).get("p_t")
        },
        "battery": {},
        "inverter": {"p_out": {}, "p_max": {}, "control_enabled": {}},
        "ev": {}
    }
    battery_fields = (
        "p_t", "p_max", "soc", "p_max_cha", "p_max_dis",
        "e_p_cha", "e_p_dis"
    )
    for device_id, control in (config.get("devices", {}) or {}).items():
        if not isinstance(control, dict):
            continue
        values = merged.get(str(device_id), {})
        role = control.get("role")
        if role == "battery":
            result["battery"][device_id] = {
                key: values.get(key) for key in battery_fields if key in values
            }
            result["battery"][device_id]["control_enabled"] = bool(
                control.get("control_enabled", False)
            )
        elif role == "solar_inverter":
            result["inverter"]["control_enabled"][device_id] = bool(
                control.get("control_enabled", False)
            )
            if "p_out" in values:
                result["inverter"]["p_out"][device_id] = values.get("p_out")
            if "p_max" in values:
                result["inverter"]["p_max"][device_id] = values.get("p_max")
        elif role == "ev":
            result["ev"][device_id] = {
                key: values.get(key) for key in ("p_t", "p_out", "p_max") if key in values
            }
    return result


def _maintain_logs(now):
    """Keep only the single current snapshot file."""
    os.makedirs(LOG_DIR, exist_ok=True)
    current_filename = f"automation_simulation_{now:%Y-%m-%d}.txt"
    try:
        for filename in os.listdir(LOG_DIR):
            if not _LOG_PATTERN.match(filename) or filename == current_filename:
                continue
            os.unlink(os.path.join(LOG_DIR, filename))
    except Exception as error:
        print(f"Automation simulation log maintenance error: {error}")


def record_automation_simulation(snapshot, config, calculation_text):
    """Atomically replace the latest simulation snapshot without any command."""
    now = datetime.now()
    os.makedirs(LOG_DIR, exist_ok=True)
    path = os.path.join(LOG_DIR, f"automation_simulation_{now:%Y-%m-%d}.txt")
    state_match = re.search(r"STATE\s+\d+\s+\[([^\]]+)\]", calculation_text)
    state = state_match.group(1) if state_match else "UNKNOWN"
    input_data = _compact_input(snapshot, config)
    automation_state = "ON" if config.get("enabled", False) else "OFF"
    separator = "=" * 78
    entry = (
        f"{separator}\n"
        f"{now:%Y-%m-%d %H:%M:%S}\n"
        f"Mode: SIMULATION ONLY (Automation {automation_state} - Modbus write blocked)\n"
        f"Source: AUTOMATION_CONTROL_CYCLE\n"
        f"State: [{state}]\n"
        f"Input:\n{json.dumps(input_data, ensure_ascii=False, indent=2, default=str)}\n"
        f"Calculation / Proposed command:\n{calculation_text.strip()}\n\n"
    )
    temporary = path + f".tmp.{os.getpid()}"
    try:
        with open(temporary, "w", encoding="utf-8") as log_file:
            log_file.write(entry)
            log_file.flush()
            os.fsync(log_file.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, path)
        _maintain_logs(now)
        return path
    except Exception as error:
        try:
            if os.path.exists(temporary):
                os.unlink(temporary)
        except OSError:
            pass
        print(f"Automation simulation log error: {error}")
        return None


def record_simulated_battery_commands(snapshot, config, calculation_text):
    """Persist changed Battery proposals for Automation-vs-MQTT auditing."""
    try:
        compact = _compact_input(snapshot, config)
        battery_devices = compact.get("battery", {})
        if not isinstance(battery_devices, dict):
            battery_devices = {}
        state_match = re.search(r"STATE\s+\d+\s+\[([^\]]+)\]", calculation_text)
        state = state_match.group(1) if state_match else "UNKNOWN"
        command_pattern = re.compile(
            r"^\[SIMULATION\] WOULD WRITE\s+(\S+)\s+(\w+)="
            r"(-?\d+(?:\.\d+)?)\s*kW;\s*current=([^;]+);\s*reason=(.*)$",
            re.MULTILINE,
        )
        active_devices = set()
        logged = 0
        for index, match in enumerate(command_pattern.finditer(calculation_text)):
            device_id, parameter, target_text, current_text, reason = match.groups()
            if device_id not in battery_devices or parameter != "p_max":
                continue
            target = _number_or_value(target_text)
            current = _number_or_value(current_text.strip())
            fingerprint = (target, current, state)
            active_devices.add(device_id)
            if _LAST_BATTERY_COMMANDS.get(device_id) == fingerprint:
                continue
            battery = battery_devices.get(device_id, {})
            context = {
                "mode": "simulation",
                "state": state,
                "main_meter": compact.get("main_meter", {}).get("device_id"),
                "main_power": _number_or_value(compact.get("main_meter", {}).get("p_t")),
                "battery_power": _number_or_value(battery.get("p_t")),
                "current_p_max": _number_or_value(battery.get("p_max")),
                "soc": _number_or_value(battery.get("soc")),
                "control_enabled": bool(battery.get("control_enabled", False)),
            }
            record_battery_command(
                "automation_simulation", device_id, parameter, target,
                current, None, reason.strip(),
                f"S{int(time.time() * 1000)}{index:02d}", context,
            )
            _LAST_BATTERY_COMMANDS[device_id] = fingerprint
            logged += 1

        # The same target must be logged again if Battery first returned to a
        # hold state and the condition becomes active in a later cycle.
        for device_id in list(_LAST_BATTERY_COMMANDS):
            if device_id not in active_devices:
                _LAST_BATTERY_COMMANDS.pop(device_id, None)
        return logged
    except Exception as error:
        print(f"Automation Battery comparison log error: {error}")
        return 0
