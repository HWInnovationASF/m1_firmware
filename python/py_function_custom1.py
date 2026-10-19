import json
import time
import os
import math
from datetime import datetime, timedelta
import re
from collections import deque
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from statistics import median

from py_function_inc1 import main_m1
from battery_control_log import record_battery_command
from tou_schedule import get_tou_state

_automation_last_run = 0
_automation_last_commands = {}
_automation_data_cache = {}
_automation_data_seen = {}
_automation_commands_loaded = False
_automation_state_mtime = None
_automation_peak_exceeded_since = None
_automation_soc_samples = {}
_automation_state_file = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    'automation_last_commands.json'
)

def _time_in_window(current_time, start_text, duration_hours):
    start_time = datetime.strptime(str(start_text), '%H:%M').time()
    current_minutes = current_time.hour * 60 + current_time.minute + current_time.second / 60
    start_minutes = start_time.hour * 60 + start_time.minute
    elapsed_minutes = (current_minutes - start_minutes) % (24 * 60)
    return elapsed_minutes < max(0.0, float(duration_hours)) * 60

def _time_in_range(current_time, start_text, end_text):
    """Return True for a half-open HH:MM range, including ranges across midnight."""
    start_time = datetime.strptime(str(start_text), '%H:%M').time()
    end_time = datetime.strptime(str(end_text), '%H:%M').time()
    if start_time <= end_time:
        return start_time <= current_time < end_time
    return current_time >= start_time or current_time < end_time

def _normalise_soc(value):
    """Quantise SoC deterministically without binary floating-point noise."""
    try:
        return float(Decimal(str(value)).quantize(Decimal('0.1'), rounding=ROUND_HALF_UP))
    except (InvalidOperation, TypeError, ValueError):
        return float(value)

def _stable_soc(device_id, value):
    """Reject a one-cycle SoC spike without inventing a midpoint from 2 reads."""
    normalised = _normalise_soc(value)
    samples = _automation_soc_samples.setdefault(device_id, deque(maxlen=3))
    if not 0.0 <= normalised <= 100.0:
        return normalised
    samples.append(normalised)
    if len(samples) < 3:
        return samples[0]
    return _normalise_soc(median(samples))

def _anti_backflow_battery_step(
    deficit_kw, battery_power, soc, min_kw, max_kw, soc_max
):
    """Apply PHP STATE 1.1/1.2 to one Battery and return remaining deficit."""
    deficit_kw = max(0.0, float(deficit_kw))
    battery_power = max(float(min_kw), min(float(max_kw), float(battery_power)))
    soc = _normalise_soc(soc)
    if soc <= 0.0 or not 0.0 <= soc <= 100.0:
        return None, deficit_kw, ['Battery offline or invalid SoC']

    target = battery_power
    actions = []

    # STATE 1.1: reduce actual Battery discharge before requesting Charge.
    if target > 0.0 and deficit_kw > 0.0:
        discharge_cut = min(target, deficit_kw)
        target -= discharge_cut
        deficit_kw -= discharge_cut
        actions.append(f'reduce Battery discharge by {discharge_cut:.1f} kW')

    # STATE 1.2: use the remaining charging room to absorb the surplus.
    if deficit_kw > 0.0 and soc < soc_max:
        charge_room = abs(float(min_kw) - target)
        charge_add = min(charge_room, deficit_kw)
        target -= charge_add
        deficit_kw -= charge_add
        actions.append(f'increase Battery charge by {charge_add:.1f} kW')

    # Stop a charge that is still active when Max SoC has been reached.
    if soc >= soc_max and target < 0.0:
        target = 0.0
        actions.append(f'Max SoC {soc_max:.1f}%: stop Battery charge')

    target = max(float(min_kw), min(float(max_kw), target))
    return target, max(0.0, deficit_kw), actions

def _peak_shaving_state2_target(
    main_power, battery_power, soc, min_kw, max_kw,
    soc_min, peak_min_kw, peak_max_kw, reserve_soc
):
    """Return the PHP STATE 2 target and mode, or (None, None)."""
    main_power = float(main_power)
    battery_power = float(battery_power)
    soc = _normalise_soc(soc)
    peak_min_kw = max(0.0, float(peak_min_kw))
    peak_max_kw = max(peak_min_kw, float(peak_max_kw))
    peak_avg_kw = (peak_min_kw + peak_max_kw) / 2.0
    reserve_soc = max(float(soc_min), min(100.0, float(reserve_soc)))

    if main_power > peak_max_kw and soc > float(soc_min):
        target = min(
            float(max_kw),
            max(0.0, battery_power + (main_power - peak_avg_kw))
        )
        return target, 'peak_shaving'

    if (
        peak_min_kw <= main_power <= peak_max_kw
        and battery_power > 0.0
        and soc >= reserve_soc
    ):
        return max(float(min_kw), min(float(max_kw), battery_power)), 'deadband'

    return None, None

def _safe_inverter_recovery_target(
    main_power, grid_max_kw, current_total_cap, installed_total_cap,
    inverter_output, hour
):
    """Return the aggregate Solar cap for PHP Safe Inverter Ramp Recovery.

    None means hold the current cap. A returned value is always an increase;
    anti-backflow curtailment remains exclusively owned by STATE 1.
    """
    main_power = float(main_power)
    grid_max_kw = float(grid_max_kw)
    current_total_cap = max(0.0, float(current_total_cap))
    installed_total_cap = max(0.0, float(installed_total_cap))
    inverter_output = max(0.0, float(inverter_output))
    hour = int(hour)

    if (
        installed_total_cap <= 0.0
        or current_total_cap >= installed_total_cap - 0.1
    ):
        return None, 'all Inverters already at configured Rated kW'

    headroom = main_power - grid_max_kw
    unused_cap = max(0.0, current_total_cap - inverter_output)

    # Outside Solar operating hours, clear a stale limit so it is not carried
    # into the following morning.
    if hour < 7 or hour > 17:
        return installed_total_cap, 'outside 07:00-17:59: clear stale Solar limit'
    if headroom > 50.0:
        return installed_total_cap, f'Main headroom {headroom:.1f} kW > 50.0 kW'
    if unused_cap > 100.0:
        return installed_total_cap, f'unused Solar cap {unused_cap:.1f} kW > 100.0 kW'
    if headroom > 15.0:
        ramp_kw = min(30.0, headroom * 0.5)
        return min(installed_total_cap, current_total_cap + ramp_kw), (
            f'Main headroom {headroom:.1f} kW: recover by up to {ramp_kw:.1f} kW'
        )

    return None, f'Main headroom {headroom:.1f} kW <= 15.0 kW: hold Solar cap'

def _battery_change_command(
    target_power, actual_power, soc, min_kw, max_kw, soc_min, soc_max,
    fast_bypass=False, max_step_kw=20.0, threshold_kw=10.0,
    fast_threshold_kw=5.0
):
    """Apply PHP Section 4 safeguards and Section 5 change detection."""
    min_kw = float(min_kw)
    max_kw = max(min_kw, float(max_kw))
    actual_power = float(actual_power)
    soc = _normalise_soc(soc)
    target = max(min_kw, min(max_kw, float(target_power)))

    # Section 4: hard SoC safeguards are applied before change detection.
    if soc >= float(soc_max) and target < 0.0:
        target = 0.0
    if soc <= float(soc_min) and target > 0.0:
        target = 0.0

    diff = target - actual_power

    # A Battery that is physically charging at Max SoC must be stopped now,
    # without waiting for either the normal threshold or ramp.
    if soc >= float(soc_max) and actual_power < 0.0:
        command = max(0.0, target)
        return round(command, 1), target, 'Max SoC immediate Charge stop'

    # STATE 1, STATE 2 and Dynamic Charge are PHP fast-bypass modes.
    if fast_bypass:
        if abs(diff) >= float(fast_threshold_kw):
            return round(target, 1), target, (
                f'Fast bypass: difference {diff:.1f} kW '
                f'>= {float(fast_threshold_kw):.1f} kW'
            )
        return None, target, (
            f'Fast bypass hold: difference {diff:.1f} kW '
            f'< {float(fast_threshold_kw):.1f} kW'
        )

    if abs(diff) < float(threshold_kw):
        return None, target, (
            f'Normal hold: difference {diff:.1f} kW '
            f'< {float(threshold_kw):.1f} kW'
        )

    max_step_kw = max(0.1, float(max_step_kw))
    if abs(diff) <= max_step_kw:
        command = target
        detail = f'Normal direct command: difference {diff:.1f} kW'
    else:
        command = actual_power + (max_step_kw if diff > 0.0 else -max_step_kw)
        command = max(min_kw, min(max_kw, command))
        detail = (
            f'Normal ramp: difference {diff:.1f} kW, '
            f'maximum step {max_step_kw:.1f} kW'
        )
    return round(command, 1), target, detail

def _proportional_inverter_curtailment(
    inverter_rows, target_total_kw, minimum_total_kw=10.0
):
    """Calculate each Inverter p_max from its share of actual Solar p_out.

    Rated kW is only a hard upper bound. It is deliberately not used as the
    weighting base, matching the PHP per-Inverter curtailment calculation.
    """
    rows = []
    for row in inverter_rows:
        rows.append({
            **row,
            'p_out': max(0.0, float(row.get('p_out', 0) or 0)),
            'rated_kw': max(0.0, float(row.get('rated_kw', 0) or 0))
        })
    output_total = sum(row['p_out'] for row in rows)
    if output_total <= 0.0:
        return [], 0.0, 0.0

    target_total = max(
        0.0,
        min(output_total, max(float(minimum_total_kw), float(target_total_kw)))
    )
    curtail_ratio = min(1.0, max(0.05, target_total / output_total))
    commands = []
    for row in rows:
        if row['p_out'] <= 0.0:
            continue
        target = max(1.0, float(math.floor(row['p_out'] * curtail_ratio)))
        # Rated is a safety ceiling only; actual p_out determines the share.
        if row['rated_kw'] > 0.0:
            target = min(row['rated_kw'], target)
        commands.append((row['device_id'], target))
    return commands, output_total, curtail_ratio

def _battery_strategy_target(
    now, main_power, soc, current_limit, min_kw, max_kw,
    soc_min, soc_max, strategy, battery_power=0.0
):
    """Return signed p_max target: negative=Charge, positive=Discharge."""
    soc = _normalise_soc(soc)
    current_limit = max(min_kw, min(max_kw, float(current_limit)))
    if soc >= soc_max and current_limit < 0:
        current_limit = 0.0

    capacity_kwh = max(0.1, float(strategy.get('capacity_kwh', 240)))
    off_duration = max(0.1, float(strategy.get('off_peak_duration_hours', 3)))
    off_start = str(strategy.get('off_peak_start', '06:00'))
    configured_charge_max = max(0.0, float(strategy.get('max_charge_kw', 100)))
    tou_state = get_tou_state(now)
    strategy_holidays = strategy.get('holiday_dates', [])
    if not isinstance(strategy_holidays, list):
        strategy_holidays = []
    is_strategy_holiday = now.strftime('%Y-%m-%d') in {
        str(item) for item in strategy_holidays
    }

    if tou_state is not None:
        is_tou_holiday = bool(tou_state.get('holiday'))
        is_holiday = is_tou_holiday or is_strategy_holiday
        weekday_enabled = bool(tou_state.get('weekday_enabled'))
        # Weekends and configured holidays use the Off-Peak/Off-Day strategy.
        # Only enabled working days may enter the configured On-Peak window.
        on_tou_day = weekday_enabled and not is_holiday
    else:
        on_tou_day = now.weekday() < 5 and not is_strategy_holiday

    # Web-configured TOU rules are evaluated before the all-time support band.
    # This prevents the 10/30 kW support rule from cancelling a scheduled action.
    # Time-based rules are part of Battery Load Strategy and always follow
    # its single parent enable switch.  A second TOU enable flag caused the
    # visible strategy to be ON while its configured schedule was skipped.
    if bool(strategy.get('enabled', True)):
        # Rule 0: dynamic charge during the morning window so SoC reaches its
        # configured target by the end of the window.
        if on_tou_day and _time_in_window(now.time(), off_start, off_duration):
            target_soc = max(soc_min, min(soc_max, float(strategy.get('dynamic_charge_target_soc', 95))))
            if soc >= target_soc:
                return (0.0, f'Dynamic charge target reached: SoC {soc:.1f}% >= {target_soc:.1f}%')
            start_dt = datetime.combine(now.date(), datetime.strptime(off_start, '%H:%M').time())
            if now < start_dt:
                start_dt -= timedelta(days=1)
            end_dt = start_dt + timedelta(hours=off_duration)
            hours_left = max((end_dt - now).total_seconds() / 3600.0, 0.05)
            efficiency = max(0.01, min(1.0, float(strategy.get('charge_efficiency_percent', 90)) / 100.0))
            energy_needed = ((target_soc - soc) / 100.0 * capacity_kwh) / efficiency
            raw_charge = -(energy_needed / hours_left)
            round_step = max(0.1, float(strategy.get('charge_step_kw', 10)))
            target = math.floor(raw_charge / round_step) * round_step
            target = max(min_kw, -configured_charge_max, target)
            return (
                min(0.0, target),
                f'Dynamic charge {off_start}+{off_duration:g}h: SoC {soc:.1f}% -> {target_soc:.1f}%, '
                f'{energy_needed:.1f} kWh/{hours_left:.2f}h, target {target:.1f} kW'
            )

        # Rule 1: daytime control band equivalent to TOU on[1].
        day_start = str(strategy.get('day_control_start', '09:00'))
        day_end = str(strategy.get('day_control_end', '11:30'))
        if on_tou_day and _time_in_range(now.time(), day_start, day_end):
            day_min_soc = max(soc_min, min(100.0, float(strategy.get('day_min_soc', 80))))
            grid_trigger = float(strategy.get('day_grid_trigger_kw', 50))
            actual_grid_load = float(main_power) + float(battery_power or 0.0)
            if soc < day_min_soc or actual_grid_load <= grid_trigger:
                return (
                    0.0,
                    f'Daytime rule standby: SoC {soc:.1f}/{day_min_soc:.1f}%, '
                    f'grid reference {actual_grid_load:.1f}/{grid_trigger:.1f} kW'
                )
            p_min = float(strategy.get('day_main_min_kw', 40))
            p_max = max(p_min, float(strategy.get('day_main_max_kw', 70)))
            p_avg = max(p_min, min(p_max, float(strategy.get('day_main_avg_kw', 55))))
            day_cap = min(max_kw, max(0.0, float(strategy.get('day_discharge_kw', 20))))
            if main_power > p_max:
                target = min(day_cap, max(0.0, current_limit + (main_power - p_avg)))
                return (target, f'Daytime control above {p_max:g} kW: target {target:.1f}/{day_cap:.1f} kW')
            if main_power >= p_min:
                return (current_limit, f'Daytime Main deadband {p_min:g}-{p_max:g} kW: hold p_max')
            target = max(0.0, current_limit - (p_min - main_power))
            return (target, f'Daytime control below {p_min:g} kW: reduce discharge to {target:.1f} kW')

        # Rule 2: scheduled evening discharge, ramping by the configured step
        # until the rule target or the Battery device limit is reached.
        discharge_start = str(strategy.get('discharge_start', '19:00'))
        discharge_duration = max(0.1, float(strategy.get('discharge_duration_hours', 3)))
        scheduled_min_soc = max(
            soc_min,
            min(100.0, float(strategy.get('scheduled_discharge_min_soc', 10)))
        )
        scheduled_discharge_kw = max(
            0.0,
            min(max_kw, float(strategy.get('scheduled_discharge_kw', 50)))
        )
        if on_tou_day and _time_in_window(now.time(), discharge_start, discharge_duration):
            if soc <= scheduled_min_soc or scheduled_discharge_kw <= 0:
                return (0.0, f'Scheduled discharge standby: SoC {soc:.1f}/{scheduled_min_soc:.1f}%')
            start_dt = datetime.combine(now.date(), datetime.strptime(discharge_start, '%H:%M').time())
            if now < start_dt:
                start_dt -= timedelta(days=1)
            end_dt = start_dt + timedelta(hours=discharge_duration)
            remaining_hours = max((end_dt - now).total_seconds() / 3600.0, 1 / 60)
            remaining_energy = max(0.0, (soc - soc_min) / 100.0 * capacity_kwh)
            discharge_step = max(0.1, float(strategy.get('discharge_step_kw', 10)))
            target = min(scheduled_discharge_kw, current_limit + discharge_step)
            return (
                max(min_kw, target),
                f'Scheduled discharge ramp {target:.1f}/{scheduled_discharge_kw:.1f} kW '
                f'(step {discharge_step:.1f} kW, {remaining_energy:.1f} kWh over {remaining_hours:.2f} h, '
                f'previous {current_limit:.1f} kW, main {main_power:.1f} kW, '
                f'SoC {soc:.1f}%, schedule Min SoC {scheduled_min_soc:.1f}%)'
            )

        # Web-configured off-day rule: do not schedule Battery output below the configured
        # reserve; otherwise keep Main inside the off-day deadband.
        if not on_tou_day:
            off_min_soc = max(soc_min, min(100.0, float(strategy.get('off_day_min_soc', 85))))
            if soc <= off_min_soc:
                return (0.0, f'Off-day standby: SoC {soc:.1f}% <= {off_min_soc:.1f}%')
            off_min = float(strategy.get('off_day_main_min_kw', 10))
            off_max = max(off_min, float(strategy.get('off_day_main_max_kw', 40)))
            off_avg = max(off_min, min(off_max, float(strategy.get('off_day_main_avg_kw', 25))))
            off_cap = min(max_kw, max(0.0, float(strategy.get('off_day_discharge_kw', 0))))
            if main_power > off_max:
                target = min(off_cap, max(0.0, current_limit + (main_power - off_avg)))
                return (target, f'Off-day Main above {off_max:g} kW: target {target:.1f}/{off_cap:.1f} kW')
            if main_power >= off_min:
                return (current_limit, f'Off-day Main deadband {off_min:g}-{off_max:g} kW: hold p_max')
            target = max(0.0, current_limit - (off_min - main_power))
            return (target, f'Off-day Main below {off_min:g} kW: reduce discharge to {target:.1f} kW')

        # Reset the TOU target to zero when a working
        # day is outside every configured discharge rule, so output cannot
        # remain latched after 18:00 or after the scheduled end time.
        if current_limit > 0:
            return (0.0, 'Outside working-day TOU discharge window: return p_max to 0 kW')

    # Maintain the Main Meter charging band at all times.  These two limits
    # come directly from Battery Load Strategy on the web config:
    # - below min_l_kw: add charging power to bring Main power back up
    # - at/above min_h_kw: stop Battery charging (hysteresis upper boundary)
    # Peak/SOC safety conditions still take precedence in the caller.
    min_l_kw = float(strategy.get('min_l_kw', 10))
    min_h_kw = max(min_l_kw, float(strategy.get('min_h_kw', 30)))
    if main_power < min_l_kw and soc < soc_max:
        current_charge = max(0.0, -current_limit)
        charge_target = min(
            abs(min_kw),
            configured_charge_max,
            current_charge + (min_l_kw - main_power)
        )
        return (
            -charge_target,
            f'All-time Main support below {min_l_kw:g} kW '
            f'(main {main_power:.1f} kW, target charge {charge_target:.1f} kW)'
        )

    if main_power >= min_h_kw and current_limit < 0:
        return (
            0.0,
            f'All-time charge stop at {min_h_kw:g} kW '
            f'(main {main_power:.1f} kW)'
        )

    if soc <= soc_min and current_limit > 0:
        return (0.0, f'Min SoC {soc_min}%: stop discharging')
    return (current_limit, 'Battery strategy hold current p_max')

def _load_automation_commands():
    global _automation_commands_loaded, _automation_last_commands, _automation_state_mtime
    try:
        state_mtime = os.path.getmtime(_automation_state_file)
    except OSError:
        state_mtime = None
    if _automation_commands_loaded and state_mtime == _automation_state_mtime:
        return
    _automation_commands_loaded = True
    _automation_state_mtime = state_mtime
    try:
        with open(_automation_state_file, 'r', encoding='utf-8') as state_file:
            saved = json.load(state_file)
        if isinstance(saved, dict):
            _automation_last_commands.update({
                str(device_id): float(value)
                for device_id, value in saved.items()
            })
            print(f'Automation restored last p_max: {_automation_last_commands}')
    except FileNotFoundError:
        pass
    except Exception as error:
        print(f'Automation state load failed: {error}')

def _save_automation_commands():
    temp_file = _automation_state_file + '.tmp'
    try:
        with open(temp_file, 'w', encoding='utf-8') as state_file:
            json.dump(_automation_last_commands, state_file, ensure_ascii=False, indent=2)
            state_file.flush()
            os.fsync(state_file.fileno())
        os.replace(temp_file, _automation_state_file)
    except Exception as error:
        print(f'Automation state save failed: {error}')
        try:
            if os.path.exists(temp_file):
                os.remove(temp_file)
        except OSError:
            pass

class m1_custom1:
    def __init__(self, json_input={}):
        self.main_m1_ini = main_m1()
        # Automatically set each key as an instance attribute
        for key, value in self._validate_json(json_input).items():
            # print(f"{key}: {value}")
            setattr(self, key, value)
        self.serial_port_rs485 = '/dev/ttyACM0'
        
    def show(self):
        for key, value in self.__dict__.items():
            print(f"{key}: {value}")

    def _get_gateway_lock(self, device):
        """Return the IP:port lock shared with meter polling, when available."""
        locks = getattr(self, 'gateway_locks', None)
        if locks is None or not isinstance(device, dict):
            return None
        ip_address = str(device.get('ipaddress', '')).strip()
        if not ip_address:
            return None
        try:
            tcp_port = int(device.get('port', 502) or 502)
        except (TypeError, ValueError):
            tcp_port = 502
        try:
            return locks.get(f'{ip_address}:{tcp_port}')
        except Exception:
            return None
         
    def _validate_json(self, json_input):
        """Check if input is valid JSON and return as dict."""
        if json_input is None:
            return {}
        
        if isinstance(json_input, dict):
            # Already a Python dict, fine
            return json_input
        elif isinstance(json_input, str):
            try:
                return json.loads(json_input)
            except json.JSONDecodeError:
                raise ValueError("Invalid JSON string")
        else:
            raise TypeError("Input must be a JSON string or dict")
   
    def inverter_automation(self, m_arr):
        try:
            print("===================================================")
            now_time = datetime.now().strftime("%H%M")
            if True and "0730" <= now_time <= "1730":
                # your code here
                WSData_arr = {}
                for outer in m_arr:
                    for key, val in outer.items():
                        WSData_arr.setdefault(key, {}).update(val)
                
                # print(f'=====WSData_arr: {WSData_arr}')
                p_arr = WSData_arr
                if 'PM210001' in p_arr:
                    if 'p_t' in p_arr['PM210001']:
                        IN_arr = [in_key for in_key in p_arr if in_key in p_arr and re.search(r'IN', in_key)]
                        p_max_arr = {"IN159012": 50,"IN159013": 50,"IN159014": 50,"IN204010": 100,"IN205011": 100,"IN206012": 100,"IN207013": 100,"IN208011": 100,"IN203001": 100}
                        # p_max_arr = {"IN159012": 50,"IN159013": 50,"IN159014": 50,"IN159015": 150,"IN159016": 150,"IN159017": 50,"IN159018": 150,"IN204010": 100,"IN205011": 100,"IN206012": 100,"IN207013": 100,"IN208011": 100,"IN203001": 100}
                        p_PM = round(float(p_arr['PM210001']['p_t']),2)

                        print(f'===p_para: {p_PM}')
                        # print(f'===IN_arr: {IN_arr}')
                        dvl_arr = json.loads(self.devicelist)
                        rgl_arr = json.loads(self.registerlist)
                        # print(f'===DVL: {dvl_arr}')
                        # print(f'===p_max_arr: {p_max_arr}')
                        sum_IN = 0
                        
                        for i_IN, this_IN in enumerate(IN_arr):
                            dvl_model = dvl_arr[this_IN]['model']
                            dvl_ip = dvl_arr[this_IN]['ipaddress']
                            dvl_port = dvl_arr[this_IN]['port']
                            dvl_id = dvl_arr[this_IN]['deviceID']
                            dvl_mode = dvl_arr[this_IN]['mode']
                            
                            if this_IN in p_arr:
                                if 'p_out' not in p_arr[this_IN] or 'p_max' not in p_arr[this_IN]:
                                    continue
                                check_huawei = 1 if dvl_model == "sun2000-50ktl" or re.search(r'sun2000', dvl_model) else 0
                                if check_huawei == 1:
                                    p_out=round(float(p_arr[this_IN]['p_out']),1)
                                    p_max=round(float(p_arr[this_IN]['p_max']),1)
                                else:
                                    p_out=round(float(p_arr[this_IN]['p_out']),2)
                                    p_max=round(float(p_arr[this_IN]['p_max']),2)
                                
                                print(f'{this_IN}|{dvl_model}|p_out:{p_out}, p_max:{p_max}')
                                sum_IN += p_out
                                
                                if p_PM > 10:
                                    # print(f'p_PM:{p_PM}, p_arr:{p_arr[this_IN]}')
                                    # rgl_model = rgl_arr[dvl_model]
                                    # for reg, info in rgl_model.items():
                                    #     if info.get("name") == "p_max":
                                    #         print(f'{dvl_model}==={reg}==={info.get("multiply")}')
                                                    
                                    if p_out>=p_max or p_out==round(p_out,0):
                                        if check_huawei and p_max==p_max_arr[this_IN]:
                                            continue
                                        else:
                                            p_max_new = p_out+p_PM
                                            if p_max_new > p_max_arr[this_IN]:
                                                p_max_new=p_max_arr[this_IN]
                                            p_max_new=int(p_max_new)
                                            if 1:
                                                # print(f'== Write 485 [{this_IN}]: p_max_new = {p_max_new}')
                                                rgl_model = self.main_m1_ini.get_register_map(rgl_arr[dvl_model], 'read_holding_tcp')
                                                instruments = self.main_m1_ini.tcpconfig(dvl_ip,int(dvl_id),int(dvl_port),1) 
                                                for reg, info in rgl_model.items():
                                                    if info.get("name") == "p_max":
                                                        rgl_multiply = float(info.get("multiply")) if "multiply" in info else 1
                                                        # print(f'=== Destination all:{reg} == { int(p_max_new/ rgl_multiply) }')
                                                        icc = 0
                                                        while icc < 3:
                                                            icc+=1
                                                            if self.main_m1_ini.writemodbustcp(instruments,int(reg),int(p_max_new/ rgl_multiply),1):
                                                                print("====[{}]Success==write:{}===".format(icc,int(p_max_new/ rgl_multiply)))
                                                                icc=3
                                                                time.sleep(1)
                                                instruments.close()
                                                if check_huawei == 1:
                                                    break
                                if p_PM < -10:
                                    p_max_new = p_out+p_PM
                                    if p_out > 1:
                                        p_max_new = p_out+p_PM
                                    if p_max_new < 1:
                                        p_max_new = 1
                                    p_max_new = int(p_max_new)
                                    if p_out > p_max_new:
                                        print(f'== Write 485 [{this_IN}]: p_max_new = {p_max_new}')
                                        rgl_model = self.main_m1_ini.get_register_map(rgl_arr[dvl_model], 'read_holding_tcp')
                                        instruments = self.main_m1_ini.tcpconfig(dvl_ip,int(dvl_id),int(dvl_port),1) 
                                        for reg, info in rgl_model.items():
                                            if info.get("name") == "p_max":
                                                rgl_multiply = float(info.get("multiply")) if "multiply" in info else 1
                                                # print(f'=== Destination all:{reg} == { int(p_max_new/ rgl_multiply) }')
                                                icc = 0
                                                while icc < 3:
                                                    icc+=1
                                                    if self.main_m1_ini.writemodbustcp(instruments,int(reg),int(p_max_new/ rgl_multiply),1):
                                                        print("====[{}]Success==write:{}===".format(icc,int(p_max_new/ rgl_multiply)))
                                                        icc=3
                                                        time.sleep(1)
                                        instruments.close()
                                        break
                        print('==============================================')
                        print(f'Power Main  : {round(p_PM,2)} kW')
                        print(f'Sum inverter: {round(sum_IN,2)} kW')
                        print(f'Total Power : {round(p_PM+sum_IN,2)} kW')
            print('==================================================================')
            print(f'All workers finished is [{str(int(time.time())-self.start_time)}s]')
            # print(f'===M_ARR {m_arr}')
            
            print(f'===para_arr {self.para_arr}')
            print("==================================================================")
        except Exception as e:
            print(f'Except custom1 as {e}')
    
    def _automation(self, m_arr):
        global _automation_last_run, _automation_last_commands
        global _automation_data_cache, _automation_data_seen
        global _automation_peak_exceeded_since
        try:
            print("===================_automation================================")
            config = self._validate_json(getattr(self, 'automationconfig', {}))
            dry_run = bool(config.get('_dry_run', False))
            if not config.get('enabled', False) and not dry_run:
                print('Automation disabled by config')
                return
            if dry_run:
                print('Automation SIMULATION ONLY: calculation enabled; all writes blocked')
            _load_automation_commands()

            now = datetime.now()
            legacy_schedule = config.get('schedule', {})
            schedules = config.get('schedules', {})
            if not isinstance(schedules, dict):
                schedules = {}
            soc_limits = config.get('battery_soc_limits', {})
            if not isinstance(soc_limits, dict):
                soc_limits = {}
            soc_min_limit = max(0.0, min(100.0, float(soc_limits.get('min', 10))))
            soc_max_limit = max(soc_min_limit, min(100.0, float(soc_limits.get('max', 95))))
            battery_strategy = config.get('battery_strategy', {})
            if not isinstance(battery_strategy, dict):
                battery_strategy = {}
            battery_strategy_enabled = bool(battery_strategy.get('enabled', True))

            def role_in_schedule(role):
                schedule_key = 'battery' if role == 'battery' else 'solar_inverter'
                schedule = schedules.get(schedule_key, legacy_schedule)
                if not isinstance(schedule, dict) or not schedule.get('enabled', False):
                    return True
                if now.weekday() >= 5 and not schedule.get('weekend_enabled', False):
                    return False
                start_time = datetime.strptime(schedule.get('start', '00:00'), '%H:%M').time()
                end_time = datetime.strptime(schedule.get('end', '23:59'), '%H:%M').time()
                current_time = now.time()
                return (start_time <= current_time <= end_time) if start_time <= end_time else (current_time >= start_time or current_time <= end_time)

            interval = max(0, int(config.get('control_interval_sec', 0)))
            if interval > 0 and time.time() - _automation_last_run < interval:
                print('Automation skipped: waiting for control interval')
                return
            _automation_last_run = time.time()

            p_arr = {}
            for outer in m_arr:
                for key, val in outer.items():
                    p_arr.setdefault(key, {}).update(val)

            # CML groups finish at different times and m_arr is periodically
            # cleared after status delivery. Keep only recent values so one
            # partial cycle does not make a controllable device disappear.
            cache_now = time.time()
            cache_ttl = 30
            for device_id, values in p_arr.items():
                if not isinstance(values, dict):
                    continue
                cached = _automation_data_cache.setdefault(device_id, {})
                seen = _automation_data_seen.setdefault(device_id, {})
                for parameter, value in values.items():
                    cached[parameter] = value
                    seen[parameter] = cache_now
            for device_id, values in list(_automation_data_cache.items()):
                seen = _automation_data_seen.get(device_id, {})
                for parameter in list(values):
                    if cache_now - seen.get(parameter, 0) > cache_ttl:
                        values.pop(parameter, None)
                        seen.pop(parameter, None)
                if values:
                    p_arr.setdefault(device_id, {}).update(
                        {key: value for key, value in values.items() if key not in p_arr.get(device_id, {})}
                    )

            main_meter = config.get('main_meter', '')
            if main_meter not in p_arr or 'p_t' not in p_arr[main_meter]:
                print(f'Automation skipped: Main Meter {main_meter} has no p_t')
                return

            p_main = round(float(p_arr[main_meter]['p_t']), 2)
            import_threshold = abs(float(config.get('import_threshold_kw', 10)))
            system_limit = config.get('system_power_limit', {})
            if not isinstance(system_limit, dict):
                system_limit = {}
            system_limit_enabled = bool(system_limit.get('enabled', False))
            system_limit_kw = max(0.0, float(system_limit.get('max_kw', 0)))
            # Peak response may be configured as immediate. A short delay can
            # still be used when the site needs protection from start-up spikes.
            peak_delay_sec = max(0.0, min(30.0, float(system_limit.get('delay_sec', 1))))
            export_threshold = float(config.get('export_threshold_kw', -10))
            devices_config = config.get('devices', {})
            if not isinstance(devices_config, dict):
                devices_config = {}
            solar_power = 0.0
            battery_power = 0.0
            for device_id, control in devices_config.items():
                data = p_arr.get(device_id, {})
                if control.get('role') == 'solar_inverter':
                    solar_power += float(data.get('p_out', data.get('p_t', 0)) or 0)
                elif control.get('role') == 'battery':
                    battery_power += float(data.get('p_t', 0) or 0)
            # PM210001 is configured as the facility Total Consumption meter.
            # Solar and Battery are already included in this value, so adding
            # them again would double-count power and understate Peak headroom.
            total_consumption = round(max(0.0, p_main), 2)
            dvl_arr = self._validate_json(self.devicelist)
            rgl_arr = self._validate_json(self.registerlist)
            controlled = []

            for device_id, control in devices_config.items():
                # Simulation follows the exact same device-control permission
                # as live Automation. The only behavioural difference is at
                # the final Modbus write boundary.
                if not control.get('control_enabled', False):
                    continue
                if control.get('role') not in ('solar_inverter', 'battery', 'ev'):
                    continue
                # PHP keeps every Solar inverter available to STATE 1 and Safe
                # Recovery at all times.  Only the optional EV role retains a
                # UI schedule gate.
                if control.get('role') == 'ev' and not role_in_schedule(control.get('role')):
                    print(f'Automation skip {device_id}: outside {control.get("role")} schedule')
                    continue
                if device_id not in dvl_arr or device_id not in p_arr:
                    continue
                model = dvl_arr[device_id].get('model', '')
                holding_registers = self.main_m1_ini.get_register_map(rgl_arr.get(model, {}), 'read_holding_tcp')
                writable = next(((reg, info) for reg, info in holding_registers.items() if info.get('name') == 'p_max'), None)
                role = control.get('role')
                if writable is None:
                    print(f'Automation skip {device_id}: writable p_max not supported')
                    continue
                if 'p_max' not in p_arr[device_id]:
                    reg, info = writable
                    instrument = None
                    try:
                        device = dvl_arr[device_id]
                        instrument = self.main_m1_ini.tcpconfig(
                            device['ipaddress'], int(device['deviceID']), int(device['port']), 1
                        )
                        gateway_lock = self._get_gateway_lock(device)
                        if gateway_lock is not None:
                            with gateway_lock:
                                readback = self.main_m1_ini.readmodbustcp(instrument, int(reg), 1, 3)
                        else:
                            readback = self.main_m1_ini.readmodbustcp(instrument, int(reg), 1, 3)
                        if isinstance(readback, dict) and 'data' in readback:
                            raw_values = json.loads(readback['data'])
                            raw_value = int(raw_values[0])
                            if 'sign16' in str(info.get('type', '')).lower() and raw_value >= 0x8000:
                                raw_value -= 0x10000
                            p_max_value = round(
                                raw_value * float(info.get('multiply', 1) or 1),
                                int(info.get('decimal', 3) or 3)
                            )
                            p_arr[device_id]['p_max'] = p_max_value
                            _automation_data_cache.setdefault(device_id, {})['p_max'] = p_max_value
                            _automation_data_seen.setdefault(device_id, {})['p_max'] = cache_now
                            print(f'Automation DEBUG [{device_id}] direct p_max read={p_max_value}')
                    except Exception as error:
                        print(f'Automation DEBUG [{device_id}] direct p_max read failed: {error}')
                    finally:
                        if instrument is not None:
                            instrument.close()
                if role == 'battery':
                    if 'p_max' not in p_arr[device_id] or 'soc' not in p_arr[device_id]:
                        print(f'Automation skip {device_id}: battery p_max/soc not supported')
                        continue
                elif 'p_out' not in p_arr[device_id] or 'p_max' not in p_arr[device_id]:
                    print(f'Automation skip {device_id}: p_out/p_max not supported')
                    continue
                controlled.append((int(control.get('priority', 100)), device_id, control, writable))

            controlled.sort(key=lambda item: item[0])
            def control_kw(control, data, key, default=0.0):
                reference = str(control.get(f'{key}_ref', '') or '')
                if reference and reference in data:
                    try:
                        return float(data[reference])
                    except (TypeError, ValueError):
                        pass
                return float(control.get(f'{key}_kw', default))

            print(
                f'Main Meter {main_meter}: {p_main} kW, controllable devices: {len(controlled)}, '
                f'consumption={total_consumption} kW, '
                f'system_limit={"ON" if system_limit_enabled else "OFF"}:{system_limit_kw} kW'
            )

            def write_limit(
                device_id, writable, new_limit, current_limit=None,
                reason='balance', force=False, parameter='p_max'
            ):
                new_limit = round(float(new_limit), 1)
                command_key = device_id if parameter == 'p_max' else f'{device_id}:{parameter}'
                previous = _automation_last_commands.get(command_key)
                if (
                    not force
                    and current_limit is not None
                    and abs(new_limit - float(current_limit)) < 0.1
                ):
                    _automation_last_commands[command_key] = new_limit
                    if previous is None or abs(new_limit - previous) >= 0.1:
                        _save_automation_commands()
                    print(f'Automation DEBUG [{device_id}] no {parameter} write: target={new_limit} equals current={current_limit}')
                    return False
                if (
                    not force
                    and current_limit is None
                    and previous is not None
                    and abs(new_limit - previous) < 0.1
                ):
                    print(f'Automation DEBUG [{device_id}] no {parameter} write: target={new_limit} equals last command={previous}')
                    return False
                reg, info = writable
                multiply = float(info.get('multiply', 1) or 1)
                raw_value = int(round(new_limit / multiply))
                device = dvl_arr[device_id]
                device_data = p_arr.get(device_id, {})
                device_role = str(devices_config.get(device_id, {}).get('role', '') or '')
                state_match = re.search(r'(STATE\s+\d+)', str(reason), re.IGNORECASE)
                control_log_context = {
                    'mode': 'executed',
                    'state': state_match.group(1).upper() if state_match else 'AUTOMATION',
                    'role': device_role,
                    'parameter': parameter,
                    'unit': 'kvar' if parameter == 'q_max' else 'kW',
                    'main_meter': main_meter,
                    'main_power': p_main,
                    'main_reactive_power': p_arr.get(main_meter, {}).get('q_t'),
                    'device_power': device_data.get('p_out', device_data.get('p_t')),
                    'device_reactive_power': device_data.get('q_t'),
                    'current_value': current_limit,
                    'commanded_value': new_limit,
                    'soc': device_data.get('soc'),
                    'control_enabled': bool(
                        devices_config.get(device_id, {}).get('control_enabled', False)
                    ),
                }
                if device_role == 'battery':
                    control_log_context['battery_power'] = device_data.get('p_t')
                    control_log_context['current_p_max'] = current_limit
                if dry_run:
                    print(
                        f'[SIMULATION] WOULD WRITE {device_id} {parameter}={new_limit:.1f}; '
                        f'current={current_limit}; reason={reason}'
                    )
                    return True
                instrument = None
                try:
                    instrument = self.main_m1_ini.tcpconfig(
                        device['ipaddress'], int(device['deviceID']), int(device['port']), 1
                    )
                    success = False
                    for attempt in range(1, 4):
                        gateway_lock = self._get_gateway_lock(device)
                        if gateway_lock is not None:
                            with gateway_lock:
                                write_ok = self.main_m1_ini.writemodbustcp(instrument, int(reg), raw_value, 1)
                        else:
                            write_ok = self.main_m1_ini.writemodbustcp(instrument, int(reg), raw_value, 1)
                        if write_ok:
                            print(f'Automation [{device_id}] success attempt={attempt}, {parameter}={new_limit}, reason={reason}')
                            _automation_last_commands[command_key] = new_limit
                            _save_automation_commands()
                            record_battery_command(
                                'automation', device_id, parameter, new_limit,
                                new_limit, True, reason,
                                f'A{int(time.time() * 1000)}',
                                control_log_context
                            )
                            success = True
                            break
                    if not success:
                        record_battery_command(
                            'automation', device_id, parameter, new_limit,
                            current_limit, False, reason,
                            f'A{int(time.time() * 1000)}',
                            control_log_context
                        )
                    return success
                finally:
                    if instrument is not None:
                        instrument.close()

            solar_devices = [item for item in controlled if item[2].get('role') == 'solar_inverter']
            ev_devices = [item for item in controlled if item[2].get('role') == 'ev']
            battery_devices = [item for item in controlled if item[2].get('role') == 'battery']

            q_control = config.get('q_control', {})
            if not isinstance(q_control, dict):
                q_control = {}
            q_enabled = bool(q_control.get('enabled', True))
            q_use_comp = bool(q_control.get('use_q_comp', True))
            q_limit = float(q_control.get('qt_main_min', 270))
            q_min_soc = float(q_control.get('min_soc', 20))
            q_max_kvar = max(0.0, float(q_control.get('battery_max_kvar', 100)))

            def reset_battery_q(reason):
                """Mirror PHP cmd_qt_bess=0 outside the active Q-limit state."""
                for priority, device_id, control, p_writable in battery_devices:
                    data = p_arr[device_id]
                    q_actual = float(data.get('q_t', 0) or 0)
                    # With Q control enabled, PHP resets only while measured Q
                    # remains non-zero.  When disabled it explicitly sends zero.
                    if q_enabled and q_actual == 0.0:
                        continue
                    model = dvl_arr[device_id].get('model', '')
                    holding = self.main_m1_ini.get_register_map(
                        rgl_arr.get(model, {}), 'read_holding_tcp'
                    )
                    q_writable = next(
                        ((reg, info) for reg, info in holding.items()
                         if info.get('name') == 'q_max'),
                        None
                    )
                    if q_writable is None:
                        print(f'Automation skip {device_id}: writable q_max not supported')
                        continue
                    # q_t is measured reactive power, not the q_max command register.
                    # If q_max is not included in the current snapshot, use the last
                    # successful command to avoid writing the same zero every cycle.
                    command_key = f'{device_id}:q_max'
                    current_q_limit_raw = data.get('q_max')
                    if current_q_limit_raw is None:
                        previous_q_limit = _automation_last_commands.get(command_key)
                        if previous_q_limit is not None and abs(float(previous_q_limit)) < 0.1:
                            print(
                                f'Automation DEBUG [{device_id}] no q_max write: '
                                'last successful command is already 0.0'
                            )
                            continue
                        current_q_limit = previous_q_limit
                    else:
                        current_q_limit = float(current_q_limit_raw or 0)
                    write_limit(
                        device_id, q_writable, 0.0, current_q_limit,
                        reason, parameter='q_max'
                    )

            def battery_command_base(device_id, data):
                """Use the last successful p_max command as the next adjustment base."""
                if device_id in _automation_last_commands:
                    return float(_automation_last_commands[device_id])
                return float(data['p_max'])

            def safe_inverter_ramp_recovery(active_state):
                """Gradually release Solar caps; never run during STATE 1."""
                if active_state == 'P1_ANTI_BACKFLOW' or not solar_devices:
                    return []

                inverter_rows = []
                for priority, device_id, control, writable in solar_devices:
                    data = p_arr[device_id]
                    rated_kw = max(
                        0.0,
                        control_kw(control, data, 'max', data.get('p_max', 0))
                    )
                    current_cap = max(
                        0.0,
                        min(rated_kw, float(data.get('p_max', 0) or 0))
                    )
                    inverter_rows.append({
                        'priority': priority,
                        'device_id': device_id,
                        'writable': writable,
                        'rated_kw': rated_kw,
                        'current_cap': current_cap,
                        'p_out': max(0.0, float(data.get('p_out', 0) or 0))
                    })

                installed_total = sum(row['rated_kw'] for row in inverter_rows)
                current_total = sum(row['current_cap'] for row in inverter_rows)
                output_total = sum(row['p_out'] for row in inverter_rows)
                target_total, recovery_reason = _safe_inverter_recovery_target(
                    p_main,
                    grid_max_kw,
                    current_total,
                    installed_total,
                    output_total,
                    now.hour
                )
                if target_total is None or target_total - current_total < 10.0:
                    print(
                        f'Safe Inverter Ramp [{active_state}]: hold; '
                        f'cap={current_total:.1f}/{installed_total:.1f} kW, '
                        f'output={output_total:.1f} kW; {recovery_reason}'
                    )
                    return []

                recovery_remaining = max(0.0, target_total - current_total)
                recovery_total = recovery_remaining
                commands = []

                # Add capacity according to actual production first. Devices
                # with equal/no output fall back to configured priority.
                candidates = sorted(
                    inverter_rows,
                    key=lambda row: (-row['p_out'], row['priority'])
                )
                while recovery_remaining >= 0.1:
                    available = [
                        row for row in candidates
                        if row['rated_kw'] - row['current_cap'] >= 0.1
                    ]
                    if not available:
                        break
                    weight_total = sum(max(1.0, row['p_out']) for row in available)
                    distributed = 0.0
                    for row in available:
                        room = row['rated_kw'] - row['current_cap']
                        share = recovery_remaining * max(1.0, row['p_out']) / weight_total
                        addition = min(room, share)
                        row['current_cap'] += addition
                        distributed += addition
                    recovery_remaining = max(0.0, recovery_remaining - distributed)
                    if distributed < 0.1:
                        break

                for row in inverter_rows:
                    data = p_arr[row['device_id']]
                    old_cap = float(data.get('p_max', 0) or 0)
                    new_cap = min(row['rated_kw'], row['current_cap'])
                    if new_cap - old_cap < 2.0:
                        continue
                    reason = (
                        f'Safe Inverter Ramp Recovery [{active_state}]: '
                        f'{recovery_reason}; aggregate Solar cap '
                        f'{current_total:.1f} -> {target_total:.1f} kW '
                        f'(maximum recovery {recovery_total:.1f} kW this cycle)'
                    )
                    if write_limit(
                        row['device_id'], row['writable'], new_cap, old_cap, reason
                    ):
                        commands.append(
                            f'{row["device_id"]} {old_cap:.1f}->{new_cap:.1f} kW'
                        )

                print(
                    f'Safe Inverter Ramp [{active_state}]: '
                    f'cap={current_total:.1f}->{target_total:.1f}/'
                    f'{installed_total:.1f} kW, commands={commands}; {recovery_reason}'
                )
                return commands

            # ============================================================
            # STATE 1: EMERGENCY ANTI-BACKFLOW (Priority 1)
            # PHP-compatible order:
            #   1.1 reduce Battery discharge
            #   1.2 charge Battery with the remaining surplus
            #   1.3 curtail Solar only when Battery cannot absorb the rest
            # No lower-priority System Limit, Peak or TOU rule may write in
            # this cycle after STATE 1 has been selected.
            # ============================================================
            grid_min_kw = max(0.0, float(battery_strategy.get('min_l_kw', 10)))
            grid_max_kw = max(
                grid_min_kw,
                float(battery_strategy.get('min_h_kw', 30))
            )
            grid_avg_kw = (grid_min_kw + grid_max_kw) / 2.0
            if p_main < grid_min_kw:
                anti_backflow_deficit = max(0.0, grid_avg_kw - p_main)
                state1_start_deficit = anti_backflow_deficit
                state1_actions = []
                print(
                    f'STATE 1 [P1_ANTI_BACKFLOW]: Main {p_main:.1f} kW < '
                    f'Grid Minimum {grid_min_kw:.1f} kW; target {grid_avg_kw:.1f} kW, '
                    f'deficit {anti_backflow_deficit:.1f} kW'
                )

                for priority, device_id, control, writable in battery_devices:
                    data = p_arr[device_id]
                    soc = _stable_soc(device_id, data['soc'])
                    battery_actual_power = float(data.get('p_t', 0) or 0)
                    min_kw = -abs(control_kw(control, data, 'min', 0))
                    max_kw = max(0.0, control_kw(control, data, 'max', 0))
                    current_limit = battery_command_base(device_id, data)
                    target, anti_backflow_deficit, actions = _anti_backflow_battery_step(
                        anti_backflow_deficit,
                        battery_actual_power,
                        soc,
                        min_kw,
                        max_kw,
                        soc_max_limit
                    )
                    if target is None:
                        state1_actions.append(f'{device_id}: Battery unavailable')
                        continue
                    action_text = '; '.join(actions) if actions else 'hold Battery power'
                    reason = (
                        f'STATE 1 Anti-Backflow: Main {p_main:.1f} kW, '
                        f'Grid target {grid_avg_kw:.1f} kW; {action_text}; '
                        f'target p_max {target:.1f} kW'
                    )
                    state1_actions.append(f'{device_id}: {action_text} -> {target:.1f} kW')

                    command, target, execution = _battery_change_command(
                        target,
                        battery_actual_power,
                        soc,
                        min_kw,
                        max_kw,
                        soc_min_limit,
                        soc_max_limit,
                        fast_bypass=True
                    )
                    print(f'Section 5 [{device_id}] {execution}; target={target:.1f} kW')
                    if command is not None:
                        write_limit(
                            device_id, writable, command, current_limit,
                            f'{reason}; Section 5: {execution}',
                            force=(execution == 'Max SoC immediate Charge stop')
                        )

                # STATE 1.3: apply the PHP +2 kW protection margin, then
                # distribute the remaining Solar cap in proportion to the
                # actual output of every online controllable inverter.
                solar_rows = []
                for priority, device_id, control, writable in solar_devices:
                    data = p_arr[device_id]
                    solar_rows.append({
                        'device_id': device_id,
                        'p_out': max(0.0, float(data.get('p_out', 0) or 0)),
                        'rated_kw': control_kw(
                            control, data, 'max', data.get('p_max', 0)
                        )
                    })
                solar_output_total = sum(row['p_out'] for row in solar_rows)
                if anti_backflow_deficit > 0.0 and solar_output_total > 0.0:
                    reduction_kw = anti_backflow_deficit + 2.0
                    target_solar_total = max(10.0, solar_output_total - reduction_kw)
                    solar_targets, solar_output_total, curtail_ratio = (
                        _proportional_inverter_curtailment(
                            solar_rows, target_solar_total
                        )
                    )
                    target_by_device = dict(solar_targets)
                    for priority, device_id, control, writable in solar_devices:
                        if device_id not in target_by_device:
                            continue
                        data = p_arr[device_id]
                        current_limit = float(data['p_max'])
                        new_limit = target_by_device[device_id]
                        if abs(current_limit - new_limit) < 2.0:
                            continue
                        write_limit(
                            device_id,
                            writable,
                            new_limit,
                            current_limit,
                            f'STATE 1 Anti-Backflow: Battery cannot absorb '
                            f'{anti_backflow_deficit:.1f} kW; Solar target total '
                            f'{target_solar_total:.1f} kW (+2 kW margin); '
                            f'p_out-based ratio {curtail_ratio:.4f}'
                        )
                        state1_actions.append(
                            f'{device_id}: Solar p_max {current_limit:.1f} -> {new_limit:.1f} kW'
                        )

                print(
                    f'STATE 1 complete: initial deficit={state1_start_deficit:.1f} kW, '
                    f'Battery-unabsorbed={anti_backflow_deficit:.1f} kW, '
                    f'actions={state1_actions}'
                )
                reset_battery_q(
                    'STATE 1 Anti-Backflow: PHP compatibility reset q_max to 0'
                )
                print('==================================================================')
                return

            # ============================================================
            # STATE 1.5: Q-LIMIT OVERFLOW CONTROL (Priority 1.5)
            # Mirrors PHP: when Main reactive power exceeds 270 kvar,
            # command capacitive Q from the Battery, limited to 100 kvar.
            # Active power is left unchanged in this state.
            # ============================================================
            main_q_value = p_arr.get(main_meter, {}).get('q_t')
            if q_enabled and main_q_value is not None and float(main_q_value) > q_limit:
                q_main = float(main_q_value)
                print(
                    f'STATE 1.5 [P1_5_Q_LIMIT_CONTROL]: Main Q {q_main:.1f} kvar > '
                    f'{q_limit:.1f} kvar'
                )
                for priority, device_id, control, p_writable in battery_devices:
                    data = p_arr[device_id]
                    soc = _stable_soc(device_id, data['soc'])
                    q_actual = float(data.get('q_t', 0) or 0)
                    model = dvl_arr[device_id].get('model', '')
                    holding = self.main_m1_ini.get_register_map(
                        rgl_arr.get(model, {}), 'read_holding_tcp'
                    )
                    q_writable = next(
                        ((reg, info) for reg, info in holding.items()
                         if info.get('name') == 'q_max'),
                        None
                    )
                    if q_writable is None:
                        print(f'Automation skip {device_id}: writable q_max not supported')
                        continue
                    total_q_load = q_main + abs(q_actual)
                    q_excess = max(0.0, total_q_load - q_limit)
                    target_q = (
                        -min(q_max_kvar, round(q_excess, 1))
                        if q_use_comp and soc >= q_min_soc else 0.0
                    )
                    current_q_limit = float(data.get('q_max', q_actual) or 0)
                    if abs(abs(target_q) - abs(q_actual)) >= 5.0 or (
                        target_q == 0.0 and q_actual != 0.0
                    ):
                        write_limit(
                            device_id, q_writable, target_q, current_q_limit,
                            f'STATE 1.5 Q Limit: Main Q {q_main:.1f} kvar, total Q '
                            f'{total_q_load:.1f} kvar, limit {q_limit:.1f} kvar, '
                            f'SoC {soc:.1f}%',
                            parameter='q_max'
                        )
                safe_inverter_ramp_recovery('P1_5_Q_LIMIT_CONTROL')
                print('STATE 1.5 complete: lower-priority STATE 2/3 skipped this cycle')
                print('==================================================================')
                return

            reset_battery_q(
                'Q Limit inactive: PHP compatibility reset q_max to 0'
            )

            # ============================================================
            # STATE 2: PEAK SHAVING (Priority 2)
            # Uses the PHP Peak window (the configurable daytime window).
            # A genuine peak is handled first. The Peak deadband may hold
            # discharge only while SoC is at or above Reserve SoC.
            # ============================================================
            strategy_holidays = battery_strategy.get('holiday_dates', [])
            if not isinstance(strategy_holidays, list):
                strategy_holidays = []
            strategy_holiday = now.strftime('%Y-%m-%d') in {
                str(item) for item in strategy_holidays
            }
            tou_state = get_tou_state(now)
            if tou_state is not None:
                is_holiday = bool(tou_state.get('holiday')) or strategy_holiday
                is_working_day = bool(tou_state.get('weekday_enabled')) and not is_holiday
            else:
                is_working_day = now.weekday() < 5 and not strategy_holiday

            peak_window_start = str(battery_strategy.get('day_control_start', '09:00'))
            # PHP Peak[0] is independent from the shorter TOU on[1]
            # daytime-control window and always ends at 18:00.
            peak_window_end = str(battery_strategy.get('peak_window_end', '18:00'))
            in_peak_window = is_working_day and _time_in_range(
                now.time(), peak_window_start, peak_window_end
            )
            peak_min_kw = max(0.0, float(battery_strategy.get('max_l_kw', 470)))
            peak_max_kw = max(
                peak_min_kw,
                float(battery_strategy.get('max_h_kw', 500))
            )
            peak_avg_kw = (peak_min_kw + peak_max_kw) / 2.0
            reserve_soc = max(
                soc_min_limit,
                min(100.0, float(battery_strategy.get('peak_reserve_soc', 50)))
            )
            state2_selected = False
            if in_peak_window:
                for priority, device_id, control, writable in battery_devices:
                    data = p_arr[device_id]
                    soc = _stable_soc(device_id, data['soc'])
                    if soc <= 0.0 or soc > 100.0:
                        continue
                    battery_actual_power = float(data.get('p_t', 0) or 0)
                    min_kw = -abs(control_kw(control, data, 'min', 0))
                    max_kw = max(0.0, control_kw(control, data, 'max', 0))
                    target, state2_mode = _peak_shaving_state2_target(
                        p_main,
                        battery_actual_power,
                        soc,
                        min_kw,
                        max_kw,
                        soc_min_limit,
                        peak_min_kw,
                        peak_max_kw,
                        reserve_soc
                    )
                    if state2_mode is None:
                        continue
                    state2_selected = True
                    current_limit = battery_command_base(device_id, data)
                    if state2_mode == 'peak_shaving':
                        reason = (
                            f'STATE 2 Peak Shaving: Main {p_main:.1f} kW > '
                            f'Peak Maximum {peak_max_kw:.1f} kW; target Main '
                            f'{peak_avg_kw:.1f} kW, Battery p_max {target:.1f} kW; '
                            f'SoC {soc:.1f}%'
                        )
                        command, target, execution = _battery_change_command(
                            target,
                            battery_actual_power,
                            soc,
                            min_kw,
                            max_kw,
                            soc_min_limit,
                            soc_max_limit,
                            fast_bypass=True
                        )
                        print(f'Section 5 [{device_id}] {execution}; target={target:.1f} kW')
                        if command is not None:
                            write_limit(
                                device_id, writable, command, current_limit,
                                f'{reason}; Section 5: {execution}',
                                force=(execution == 'Max SoC immediate Charge stop')
                            )
                    else:
                        reason = (
                            f'STATE 2 Peak Deadband: Main {p_main:.1f} kW inside '
                            f'{peak_min_kw:.1f}-{peak_max_kw:.1f} kW; hold Battery '
                            f'Discharge {battery_actual_power:.1f} kW because SoC '
                            f'{soc:.1f}% >= Reserve {reserve_soc:.1f}%'
                        )
                    print(f'STATE 2 [P2_PEAK_SHAVING] [{device_id}]: {reason}')

            if state2_selected:
                safe_inverter_ramp_recovery('P2_PEAK_SHAVING')
                print('STATE 2 complete: lower-priority STATE 3 skipped this cycle')
                print('==================================================================')
                return

            # ============================================================
            # STATE 3: BASE TOU & GRID DEADBAND (Priority 3)
            # All existing System Limit, EV, Solar balancing and Battery TOU
            # rules below execute only when STATE 1 and STATE 2 are inactive.
            # ============================================================
            print(
                f'STATE 3 [P3_BASE_TOU]: Main {p_main:.1f} kW; '
                f'working_day={is_working_day}, peak_window={in_peak_window}'
            )
            safe_inverter_ramp_recovery('P3_BASE_TOU')

            raw_system_over_limit = (
                system_limit_enabled and system_limit_kw > 0
                and total_consumption > system_limit_kw
            )
            if raw_system_over_limit:
                if _automation_peak_exceeded_since is None:
                    _automation_peak_exceeded_since = time.time()
                peak_elapsed = time.time() - _automation_peak_exceeded_since
                system_over_limit = peak_elapsed >= peak_delay_sec
                if not system_over_limit:
                    print(
                        f'Automation Peak delay: over Limit for '
                        f'{round(peak_elapsed, 1)}/{round(peak_delay_sec, 1)}s; no reduction yet'
                    )
            else:
                _automation_peak_exceeded_since = None
                system_over_limit = False

            # Consumption is the actual facility load (Grid + Solar + Battery).
            # Generation cannot lower that load, so shed controllable EV load first.
            if system_limit_enabled and system_limit_kw > 0:
                consumption_excess = (
                    max(0.0, total_consumption - system_limit_kw)
                    if system_over_limit else 0.0
                )
                consumption_headroom = max(0.0, system_limit_kw - total_consumption)
                for priority, device_id, control, writable in ev_devices:
                    data = p_arr[device_id]
                    current_limit = float(data['p_max'])
                    min_kw = max(0.0, control_kw(control, data, 'min', 0))
                    max_kw = max(min_kw, control_kw(control, data, 'max', current_limit))
                    if consumption_excess > 0:
                        adjustment = min(current_limit - min_kw, consumption_excess)
                        new_limit = current_limit - max(0.0, adjustment)
                        consumption_excess -= max(0.0, adjustment)
                        write_limit(device_id, writable, new_limit, current_limit, 'Total Consumption Limit')
                    elif consumption_headroom > 0:
                        adjustment = min(max_kw - current_limit, consumption_headroom)
                        new_limit = current_limit + max(0.0, adjustment)
                        consumption_headroom -= max(0.0, adjustment)
                        write_limit(device_id, writable, new_limit, current_limit, 'Consumption headroom')
                if consumption_excess > 0:
                    print(
                        f'Automation WARNING: consumption exceeds limit by '
                        f'{round(consumption_excess, 2)} kW; no more controllable EV load'
                    )

            # When Main power is negative (export), use the available surplus to charge
            # Battery first. Solar curtailment receives only the surplus that Battery
            # cannot accept, or all surplus after Battery reaches Max SoC.
            remaining_import = max(0.0, p_main - import_threshold)
            remaining_export = max(0.0, export_threshold - p_main)
            battery_export_targets = {}
            solar_covers_output = bool(solar_devices)
            for priority, device_id, control, writable in solar_devices:
                data = p_arr[device_id]
                inverter_p_out = float(data.get('p_out', 0) or 0)
                inverter_p_max = float(data.get('p_max', 0) or 0)
                if inverter_p_max + 0.1 < inverter_p_out:
                    solar_covers_output = False
                    break

            export_available_for_charge = max(0.0, -p_main)
            if export_available_for_charge > 0 and solar_covers_output:
                for priority, device_id, control, writable in battery_devices:
                    data = p_arr[device_id]
                    soc = _stable_soc(device_id, data['soc'])
                    if soc < 0 or soc > 100 or soc >= soc_max_limit:
                        continue
                    min_kw = min(0.0, control_kw(control, data, 'min', 0))
                    current_limit = battery_command_base(device_id, data)
                    additional_charge_capacity = max(0.0, current_limit - min_kw)
                    additional_charge = min(additional_charge_capacity, export_available_for_charge)
                    if additional_charge <= 0 and current_limit >= 0:
                        continue
                    battery_limit = max(min_kw, current_limit - additional_charge)
                    battery_export_targets[device_id] = (
                        battery_limit,
                        f'Main export {p_main} kW: charge Battery before Solar curtailment'
                    )
                    export_available_for_charge -= additional_charge
                    # Prevent simultaneous Solar curtailment for power already assigned
                    # to Battery. Feedback on the next cycle corrects any remaining export.
                    remaining_export = max(0.0, remaining_export - additional_charge)
                    print(
                        f'Automation DEBUG [{device_id}] Battery-first export charge, '
                        f'soc={soc}, last_p_max={current_limit}, target={round(battery_limit, 1)}, '
                        f'export_unassigned={round(export_available_for_charge, 2)}'
                    )
                    if export_available_for_charge <= 0:
                        break

            # Fixed charging from available Peak headroom:
            # Charge power = Peak Limit - Total Consumption.
            # Set Battery p_max directly to the negative charge power and
            # clamp it to each Battery's configured Min kW.
            peak_charge_remaining = 0.0
            if (
                not battery_strategy_enabled
                and system_limit_enabled and system_limit_kw > 0
                and total_consumption <= system_limit_kw
            ):
                peak_charge_remaining = max(0.0, system_limit_kw - total_consumption)
                for priority, device_id, control, writable in battery_devices:
                    data = p_arr[device_id]
                    soc = _stable_soc(device_id, data['soc'])
                    if soc < 0 or soc > 100 or soc >= soc_max_limit:
                        continue
                    min_kw = min(0.0, control_kw(control, data, 'min', 0))
                    # Respect the latest p_max command (including MQTT) as the
                    # requested charge magnitude. p_max_cha remains only the
                    # hard safety boundary; it must not force charging back to
                    # the model's maximum after an MQTT derating command.
                    latest_pmax = battery_command_base(device_id, data)
                    charge_capacity = min(abs(min_kw), abs(latest_pmax))
                    charge_amount = min(charge_capacity, peak_charge_remaining)
                    battery_limit = max(min_kw, -charge_amount)
                    battery_export_targets[device_id] = (
                        battery_limit,
                        f'Total Consumption {total_consumption} kW below Peak '
                        f'{system_limit_kw} kW: headroom={round(peak_charge_remaining, 1)} kW, '
                        f'latest p_max={round(latest_pmax, 1)} kW, '
                        f'set Battery p_max={round(battery_limit, 1)} kW'
                    )
                    peak_charge_remaining -= charge_amount
                    print(
                        f'Automation DEBUG [{device_id}] Peak headroom charge, '
                        f'consumption={total_consumption}, peak={system_limit_kw}, '
                        f'latest_p_max={round(latest_pmax, 1)}, '
                        f'charge={round(charge_amount, 1)}, '
                        f'target_p_max={round(battery_limit, 1)}, '
                        f'headroom_remaining={round(peak_charge_remaining, 2)}'
                    )

            for priority, device_id, control, writable in solar_devices:
                data = p_arr[device_id]
                current_limit = float(data['p_max'])
                min_kw = max(0.0, control_kw(control, data, 'min', 0))
                max_kw = max(min_kw, control_kw(control, data, 'max', current_limit))
                if remaining_export > 0:
                    adjustment = min(current_limit - min_kw, remaining_export)
                    new_limit = current_limit - max(0.0, adjustment)
                    remaining_export -= max(0.0, adjustment)
                else:
                    continue
                print(
                    f'Automation DEBUG [{device_id}] role={control.get("role")}, '
                    f'p_out={data.get("p_out")}, current_p_max={current_limit}, '
                    f'range={min_kw}..{max_kw}, target={round(new_limit, 1)}, '
                    f'remaining_import={round(remaining_import, 2)}, '
                    f'remaining_export={round(remaining_export, 2)}'
                )
                write_limit(device_id, writable, new_limit, current_limit, 'main-meter balance')

            solar_at_max = bool(solar_devices)
            for priority, device_id, control, writable in solar_devices:
                current_limit = float(p_arr[device_id]['p_max'])
                max_kw = max(0.0, control_kw(control, p_arr[device_id], 'max', current_limit))
                if current_limit < max_kw - 0.1:
                    solar_at_max = False
                    break
            print(
                f'Automation DEBUG Solar: count={len(solar_devices)}, '
                f'all_at_max={solar_at_max}, main={p_main}, '
                f'import_threshold={import_threshold}, export_threshold={export_threshold}'
            )

            for priority, device_id, control, writable in battery_devices:
                data = p_arr[device_id]
                raw_soc = data['soc']
                soc = _stable_soc(device_id, raw_soc)
                # Charge capability registers are commonly reported as a
                # positive magnitude (for example p_max_cha=50). Convert that
                # capability to the signed p_max convention used here.
                min_kw = -abs(control_kw(control, data, 'min', 0))
                max_kw = max(0.0, control_kw(control, data, 'max', 0))
                current_battery_limit = battery_command_base(device_id, data)
                print(
                    f'Automation DEBUG [{device_id}] role=battery, raw_soc={raw_soc}, stable_soc={soc}, '
                    f'p_t={data.get("p_t")}, last_p_max={current_battery_limit}, '
                    f'range={min_kw}..{max_kw}, '
                    f'solar_at_max={solar_at_max}, main={p_main}, '
                    f'load_strategy={"ON" if battery_strategy_enabled else "OFF"}'
                )

                if soc < 0 or soc > 100:
                    print(f'Automation skip {device_id}: invalid Battery SoC={soc}')
                    continue
                # System Peak Limit has priority over the normal TOU/load
                # strategy. Move p_max by the measured excess in one cycle so
                # charging is reduced first, then discharge is increased. The
                # configured device limit and Min SoC remain hard boundaries.
                if system_over_limit and soc > soc_min_limit:
                    peak_excess = max(0.0, total_consumption - system_limit_kw)
                    battery_limit = max(min_kw, min(
                        max_kw, current_battery_limit + peak_excess
                    ))
                    reason = (
                        f'Fast Peak response: Total Consumption {total_consumption} kW '
                        f'exceeds Limit {system_limit_kw} kW by {peak_excess:.1f} kW'
                    )
                elif device_id in battery_export_targets:
                    battery_limit, reason = battery_export_targets[device_id]
                elif battery_strategy_enabled:
                    battery_limit, reason = _battery_strategy_target(
                        now, p_main, soc, current_battery_limit,
                        min_kw, max_kw, soc_min_limit, soc_max_limit,
                        battery_strategy, data.get('p_t', 0)
                    )
                else:
                    battery_limit = current_battery_limit
                    reason = 'Battery Load Strategy disabled: hold latest p_max'
                if not str(reason).startswith('STATE '):
                    reason = f'STATE 3 Base TOU: {reason}'
                dynamic_charge = 'Dynamic charge' in str(reason)
                command, guarded_target, execution = _battery_change_command(
                    battery_limit,
                    float(data.get('p_t', 0) or 0),
                    soc,
                    min_kw,
                    max_kw,
                    soc_min_limit,
                    soc_max_limit,
                    fast_bypass=dynamic_charge,
                    max_step_kw=float(battery_strategy.get('normal_max_step_kw', 50))
                )
                print(
                    f'Section 5 [{device_id}] {execution}; '
                    f'target={guarded_target:.1f} kW, command={command}'
                )
                if command is not None:
                    write_limit(
                        device_id, writable, command, current_battery_limit,
                        f'{reason}; Section 5: {execution}',
                        force=(execution == 'Max SoC immediate Charge stop')
                    )

            if export_threshold <= p_main <= import_threshold and not battery_devices:
                print('Automation: Main Meter is inside deadband')
            print('==================================================================')
            print(f'All workers finished is [{str(int(time.time())-self.start_time)}s]')
            print("==================================================================")
        except Exception as e:
            print(f'Except custom1 as {e}')
            
            
    def _ac_bacnet(self):
        import threading 
        import warnings 
        from bacpypes.core import run, stop 
        from bacpypes.pdu import Address 
        from bacpypes.app import BIPSimpleApplication 
        from bacpypes.local.device import LocalDeviceObject 
        from bacpypes.apdu import ReadPropertyRequest, ReadPropertyACK
        from bacpypes.iocb import IOCB 
        from bacpypes.primitivedata import Real, Unsigned, Boolean, Enumerated 


        warnings.filterwarnings("ignore", message="no signal handlers for child threads") 


        this_device = LocalDeviceObject(
            objectName="PythonClient",
            objectIdentifier=204,
            vendorIdentifier=999
        )
        # this_app = BIPSimpleApplication(this_device, "172.16.21.204/24") 
        this_app = BIPSimpleApplication(this_device, "172.16.110.197/24") 


        thread = threading.Thread(target=run, daemon=True)
        thread.start()




        def read_chiller_value(target_ip, obj_type, obj_inst):
            try:
                request = ReadPropertyRequest(
                    destination=Address(target_ip),
                    objectIdentifier=(obj_type, obj_inst),
                    propertyIdentifier="presentValue",
                )
                iocb = IOCB(request)
                this_app.request_io(iocb)
                iocb.wait(timeout=5)

                if iocb.ioResponse:
                    apdu = iocb.ioResponse
                    if isinstance(apdu, ReadPropertyACK):
                        tag_list = apdu.propertyValue.tagList
                        if 'analog' in obj_type:
                            return apdu.propertyValue.cast_out(Real)
                        elif 'binary' in obj_type:
                            try:
                                return apdu.propertyValue.cast_out(Enumerated)
                            except:
                                return apdu.propertyValue.cast_out(Unsigned)
                        elif 'multiState' in obj_type:
                            return apdu.propertyValue.cast_out(Unsigned)
                        else:
                            return tag_list[0].app_to_object().value 
                    else:
                        return f"Error: Received {apdu.__class__.__name__}"
                else:
                    return f"Error: {iocb.ioError or 'Timeout'}"

            except Exception as e:
                return f"System Error: {e}"


        try:
            chiller_ip = "172.16.21.150:47808"
            print(f"FCU-4: RT {read_chiller_value(chiller_ip, 'analogValue', 104)}")
            print(f"FCU-4: SP {read_chiller_value(chiller_ip, 'analogValue', 100)}")
            print(f"FCU-4: Valve status {read_chiller_value(chiller_ip, 'binaryValue', 109)}")
            print(f"FCU-4: Mode {read_chiller_value(chiller_ip, 'analogValue', 112)}")
        finally:
            import time
            time.sleep(1)
            stop()
