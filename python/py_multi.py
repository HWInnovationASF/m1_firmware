# from concurrent.futures import ThreadPoolExecutor, wait, FIRST_COMPLETED
# import threading

import multiprocessing
from multiprocessing import Process, Queue, Lock, Value, Manager
from ctypes import c_char_p
from filelock import FileLock

import sys
import traceback
from contextlib import redirect_stdout
from io import StringIO

# import py_function_inc1
from py_function_inc1 import main_m1
from py_function_custom1 import m1_custom1
from automation_simulation_log import (
    record_automation_simulation,
    record_simulated_battery_commands,
)
from log_action_maintenance import maintain_log_action
from system_health_watchdog import (
    HEALTH_MIN_MAIN_STALL,
    adaptive_stall_limit,
    start_health_watchdog,
)
import ast
import json
from statistics import median
import time
from datetime import datetime, timedelta
import subprocess
import serial
import os
import re
import struct
import minimalmodbus
import socket
import threading

# The BACnet collector now lives beside py_multi.  Keeping the process entry
# point local removes the runtime dependency on the legacy /py_bacnet tree.
from bacnet_collector import run_supervised as run_bacnet_collector

from bacpypes.app import BIPSimpleApplication
from bacpypes.core import run as bacnet_run
from bacpypes.iocb import IOCB
from bacpypes.local.device import LocalDeviceObject
from bacpypes.pdu import Address
from bacpypes.apdu import ReadPropertyRequest, ReadPropertyACK, WritePropertyRequest
from bacpypes.constructeddata import Any as BacnetAny
from bacpypes.primitivedata import Real, Enumerated, Unsigned

from base64 import b64encode, b64decode 
from Crypto.Cipher import AES #pip install pycryptodome
from Crypto.Util.Padding import pad,unpad

import paho.mqtt.client as mqtt
from gpio_compat import GPIO

def normalise_command_list(command_data):
    """Return the CML command map safely, including legacy list-shaped data."""
    if isinstance(command_data, dict):
        return command_data
    if not isinstance(command_data, list):
        return {}
    normalised = {}
    for index, item in enumerate(command_data):
        if not isinstance(item, dict):
            continue
        # A list item may already contain one or more command-id keys.
        if item and all(isinstance(value, dict) for value in item.values()) and any(
            str(key).startswith('C') for key in item
        ):
            normalised.update(item)
        else:
            normalised[f'list_{index}'] = item
    return normalised


MANUAL_COMMAND_QUEUE = '/var/www/html/meow/config/command_queue'
MANUAL_COMMAND_RESULTS = '/var/www/html/meow/config/command_results'
_bacnet_app = None
_bacnet_lock = threading.Lock()

def _local_ipv4():
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.connect(('8.8.8.8', 80))
        return sock.getsockname()[0]
    except OSError:
        return '127.0.0.1'
    finally:
        sock.close()

def _get_bacnet_app():
    global _bacnet_app
    with _bacnet_lock:
        if _bacnet_app is None:
            device = LocalDeviceObject(
                objectName='MDBCareManualClient',
                objectIdentifier=204,
                maxApduLengthAccepted=1024,
                segmentationSupported='noSegmentation',
                vendorIdentifier=999
            )
            _bacnet_app = BIPSimpleApplication(device, f'{_local_ipv4()}/24')
            threading.Thread(target=bacnet_run, name='bacnet-manual', daemon=True).start()
            time.sleep(0.2)
    return _bacnet_app

def _bacnet_read(target, object_type, instance):
    request = ReadPropertyRequest(objectIdentifier=(object_type, instance), propertyIdentifier='presentValue')
    request.pduDestination = Address(target)
    iocb = IOCB(request)
    _get_bacnet_app().request_io(iocb)
    iocb.wait(timeout=5)
    if not iocb.ioResponse:
        raise IOError(str(iocb.ioError or 'BACnet read timeout'))
    response = iocb.ioResponse
    if not isinstance(response, ReadPropertyACK):
        raise IOError(f'Unexpected BACnet response: {response.__class__.__name__}')
    if 'analog' in object_type:
        return response.propertyValue.cast_out(Real)
    if 'binary' in object_type:
        try:
            return response.propertyValue.cast_out(Enumerated)
        except Exception:
            return response.propertyValue.cast_out(Unsigned)
    if 'multiState' in object_type:
        return response.propertyValue.cast_out(Unsigned)
    tag_list = response.propertyValue.tagList
    return tag_list[0].app_to_object().value

def _bacnet_write(target, object_type, instance, value, priority=8):
    if 'analog' in object_type:
        datatype = Real(float(value))
    elif 'binary' in object_type:
        datatype = Enumerated(int(float(value)))
    elif 'multiState' in object_type:
        datatype = Unsigned(int(float(value)))
    else:
        datatype = Real(float(value))
    request = WritePropertyRequest(objectIdentifier=(object_type, instance), propertyIdentifier='presentValue')
    request.pduDestination = Address(target)
    request.propertyValue = BacnetAny()
    request.propertyValue.cast_in(datatype)
    if priority:
        request.priority = int(priority)
    iocb = IOCB(request)
    _get_bacnet_app().request_io(iocb)
    iocb.wait(timeout=5)
    if not iocb.ioResponse:
        raise IOError(str(iocb.ioError or 'BACnet write timeout'))

def _manual_registers(value, data_type):
    """Encode a local manual-test value without using MQTT command handling."""
    data_type = str(data_type or 'Int Unsign16')
    if data_type in ('Float32', 'Float32 inverse'):
        words = list(struct.unpack('>HH', struct.pack('>f', float(value))))
        return list(reversed(words)) if data_type.endswith('inverse') else words
    number = int(float(value))
    if data_type in ('Long Sign32', 'Long Unsign32', 'Long Unsign32 inverse'):
        words = [(number >> 16) & 0xffff, number & 0xffff]
        return list(reversed(words)) if data_type.endswith('inverse') else words
    return [number & 0xffff]

def _run_manual_command(command, verify_write=True):
    """Execute one local test command. This function never publishes or replies externally."""
    protocol = str(command.get('Protocol', '')).lower()
    function_raw = str(command.get('Function', ''))
    function = function_raw.lower()
    address = int(command.get('Target', 1))
    value = command.get('Value', '')
    registers = _manual_registers(value, command.get('DataType')) if value != '' else []

    if protocol == 'modbus_tcp':
        instrument = m1_class.tcpconfig(
            str(command.get('IpAddress', '')),
            int(command.get('UnitId', 1)),
            int(command.get('Port', 502)),
            1.5
        )
        if function == 'write_single_tcp':
            if not registers:
                raise ValueError('Value is required')
            ok = instrument.write_single_register(address, registers[0]) if len(registers) == 1 else instrument.write_multiple_registers(address, registers)
            write_error = instrument.last_error_as_txt if not ok else ''
            write_exception = instrument.last_except_as_txt if not ok else ''
            if not verify_write:
                if not ok:
                    raise IOError(f'Modbus TCP write failed: {write_error or write_exception or "no ACK"}')
                feedback = {'operation': 'write only', 'address': address, 'command_value': value, 'ack_received': True}
                instrument.close()
                return feedback
            time.sleep(0.25)
            readback = instrument.read_holding_registers(address, len(registers))
            if readback is None or [int(word) & 0xffff for word in readback] != registers:
                raise IOError(f'Modbus TCP feedback mismatch: ACK={bool(ok)}, write_error={write_error or write_exception or "none"}, expected={registers}, feedback={readback}')
            feedback = {'operation': 'write + read-back', 'address': address, 'command_value': value, 'raw_feedback': readback, 'ack_received': bool(ok), 'verified': True}
        elif function == 'write_coil_tcp':
            if not registers:
                raise ValueError('Value is required')
            ok = instrument.write_single_coil(address, bool(registers[0]))
            write_error = instrument.last_error_as_txt if not ok else ''
            write_exception = instrument.last_except_as_txt if not ok else ''
            if not verify_write:
                if not ok:
                    raise IOError(f'Modbus TCP coil write failed: {write_error or write_exception or "no ACK"}')
                feedback = {'operation': 'write coil only', 'address': address, 'command_value': bool(registers[0]), 'ack_received': True}
                instrument.close()
                return feedback
            time.sleep(0.25)
            readback = instrument.read_coils(address, 1)
            expected = bool(registers[0])
            if not readback or bool(readback[0]) != expected:
                raise IOError(f'Modbus TCP coil feedback mismatch: ACK={bool(ok)}, write_error={write_error or write_exception or "none"}, expected={expected}, feedback={readback}')
            feedback = {'operation': 'write coil + read-back', 'address': address, 'command_value': expected, 'feedback_value': bool(readback[0]), 'ack_received': bool(ok), 'verified': True}
        elif function in ('read_holding_tcp', 'read_input_tcp'):
            count = max(1, len(registers))
            result = instrument.read_holding_registers(address, count) if function == 'read_holding_tcp' else instrument.read_input_registers(address, count)
            if result is None:
                raise IOError('Modbus TCP read failed')
            print(f'[Manual Command] read result: {result}')
            feedback = {'operation': 'read', 'address': address, 'value': result}
        else:
            raise ValueError(f'Unsupported TCP function: {function}')
        instrument.close()
        return feedback

    if protocol == 'modbus_rtu':
        port = str(command.get('SerialPort', ''))
        if not port:
            raise ValueError('SerialPort is required')
        instrument = minimalmodbus.Instrument(port, int(command.get('SlaveId', 1)))
        instrument.mode = minimalmodbus.MODE_RTU
        instrument.serial.baudrate = int(command.get('BaudRate', 9600))
        instrument.serial.bytesize = 8
        instrument.serial.stopbits = int(command.get('StopBits', 1))
        instrument.serial.parity = {'none': serial.PARITY_NONE, 'even': serial.PARITY_EVEN, 'odd': serial.PARITY_ODD}.get(str(command.get('Parity', 'none')).lower(), serial.PARITY_NONE)
        instrument.serial.timeout = 1.5
        instrument.clear_buffers_before_each_transaction = True
        try:
            if function == 'write_single_rtu':
                if not registers:
                    raise ValueError('Value is required')
                if len(registers) == 1:
                    instrument.write_register(address, registers[0], 0, 6, False)
                else:
                    instrument.write_registers(address, registers)
                if not verify_write:
                    feedback = {'operation': 'write only', 'address': address, 'command_value': value, 'ack_received': True}
                    return feedback
                time.sleep(0.25)
                readback = instrument.read_registers(address, len(registers), 3)
                if [int(word) & 0xffff for word in readback] != registers:
                    raise IOError(f'Modbus RTU feedback mismatch: expected {registers}, got {readback}')
                feedback = {'operation': 'write + read-back', 'address': address, 'command_value': value, 'raw_feedback': readback, 'verified': True}
            elif function == 'write_coil_rtu':
                if not registers:
                    raise ValueError('Value is required')
                instrument.write_bit(address, 1 if registers[0] else 0, 5)
                if not verify_write:
                    feedback = {'operation': 'write coil only', 'address': address, 'command_value': bool(registers[0]), 'ack_received': True}
                    return feedback
                time.sleep(0.25)
                readback = bool(instrument.read_bit(address, 1))
                expected = bool(registers[0])
                if readback != expected:
                    raise IOError(f'Modbus RTU coil feedback mismatch: expected {expected}, got {readback}')
                feedback = {'operation': 'write coil + read-back', 'address': address, 'command_value': expected, 'feedback_value': readback, 'verified': True}
            elif function in ('read_holding_rtu', 'recv_holding_rtu', 'read_input_rtu'):
                fn = 4 if function == 'read_input_rtu' else 3
                result = instrument.read_registers(address, max(1, len(registers)), fn)
                print(f'[Manual Command] read result: {result}')
                feedback = {'operation': 'read', 'address': address, 'value': result}
            else:
                raise ValueError(f'Unsupported RTU function: {function}')
        finally:
            instrument.serial.close()
        return feedback

    if protocol == 'bacnet':
        host = str(command.get('BacnetIp', '')).strip()
        port = int(command.get('BacnetPort', 47808))
        if not host:
            raise ValueError('BACnet/IP Address is required')
        target = f'{host}:{port}'
        object_type = function_raw
        instance = address
        if value == '':
            result = _bacnet_read(target, object_type, instance)
            print(f'[Manual Command] BACnet read result: {result}')
            feedback = {'operation': 'read', 'object': f'{object_type}:{instance}', 'value': result}
        else:
            _bacnet_write(target, object_type, instance, value, command.get('Priority', 8))
            print(f'[Manual Command] BACnet write success: {value}')
            if not verify_write:
                return {'operation': 'write only', 'object': f'{object_type}:{instance}', 'command_value': value, 'priority': int(command.get('Priority', 8)), 'ack_received': True}
            time.sleep(0.25)
            readback = _bacnet_read(target, object_type, instance)
            expected = float(value) if 'analog' in object_type else int(float(value))
            actual = float(readback) if 'analog' in object_type else int(readback)
            tolerance = max(0.0001, abs(expected) * 0.00001)
            if abs(actual - expected) > tolerance:
                raise IOError(f'BACnet feedback mismatch: expected {expected}, got {actual}')
            feedback = {'operation': 'write + read-back', 'object': f'{object_type}:{instance}', 'command_value': expected, 'feedback_value': actual, 'priority': int(command.get('Priority', 8)), 'verified': True}
        return feedback

    raise ValueError(f'Protocol is not supported for local manual test: {protocol}')

def _run_bacnet_manual_process(command, result_queue):
    """BACnet must own UDP/47808 in one short-lived process; workers must not inherit it."""
    try:
        result_queue.put({'success': True, 'feedback': _run_manual_command(command, verify_write=False)})
    except Exception as error:
        print(f"[Manual Command] BACnet failed {command.get('cmdid', '')}: {error}", file=sys.stderr, flush=True)
        result_queue.put({'success': False, 'error': str(error)})

def _write_manual_result(run_id, payload):
    if not re.match(r'^[A-Za-z0-9_-]{8,100}$', str(run_id or '')):
        return
    os.makedirs(MANUAL_COMMAND_RESULTS, exist_ok=True)
    payload['run_id'] = run_id
    payload['finished_at'] = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    temporary = os.path.join(MANUAL_COMMAND_RESULTS, run_id + '.tmp')
    destination = os.path.join(MANUAL_COMMAND_RESULTS, run_id + '.json')
    with open(temporary, 'w', encoding='utf-8') as handle:
        json.dump(payload, handle, ensure_ascii=False, separators=(',', ':'))
    os.chmod(temporary, 0o660)
    os.replace(temporary, destination)

def process_manual_command_queue():
    """Consume local one-shot files; deliberately has no server status/reply path."""
    try:
        os.makedirs(MANUAL_COMMAND_QUEUE, exist_ok=True)
        names = sorted(name for name in os.listdir(MANUAL_COMMAND_QUEUE) if name.endswith('.json'))
    except Exception as error:
        print(f'[Manual Command] queue unavailable: {error}', file=sys.stderr)
        return
    for name in names[:5]:
        source = os.path.join(MANUAL_COMMAND_QUEUE, name)
        processing = source + '.processing'
        run_id = ''
        command = {}
        try:
            os.replace(source, processing)
            with open(processing, 'r', encoding='utf-8') as handle:
                command = json.load(handle)
            run_id = str(command.get('_run_id', ''))
            if not isinstance(command, dict) or command.get('Enabled') is False:
                raise ValueError('Command is disabled or invalid')
            print(f"[Manual Command] run {command.get('cmdid', name)} {command.get('Name', '')}")
            if str(command.get('Protocol', '')).lower() == 'bacnet':
                result_queue = Queue()
                process = Process(target=_run_bacnet_manual_process, args=(command, result_queue))
                process.start()
                process.join(8)
                if process.is_alive():
                    process.terminate()
                    process.join(1)
                    raise TimeoutError('BACnet manual process timeout')
                try:
                    outcome = result_queue.get(timeout=1)
                except Exception:
                    outcome = {'success': False, 'error': 'BACnet process returned no feedback'}
                process.close()
                result_queue.close()
                if not outcome.get('success'):
                    raise RuntimeError(outcome.get('error', 'BACnet command failed'))
                feedback = outcome.get('feedback')
            else:
                feedback = _run_manual_command(command, verify_write=False)
            _write_manual_result(run_id, {'success': True, 'cmdid': command.get('cmdid', ''), 'name': command.get('Name', ''), 'protocol': command.get('Protocol', ''), 'function': command.get('Function', ''), 'feedback': feedback})
            print(f"[Manual Command] completed {command.get('cmdid', name)}")
        except Exception as error:
            print(f'[Manual Command] failed {name}: {error}', file=sys.stderr)
            try:
                _write_manual_result(run_id, {'success': False, 'cmdid': command.get('cmdid', '') if isinstance(command, dict) else '', 'error': str(error)})
            except Exception as result_error:
                print(f'[Manual Command] result write failed: {result_error}', file=sys.stderr)
        finally:
            try:
                if os.path.exists(processing):
                    os.unlink(processing)
            except OSError:
                pass

COMMAND_SCHEDULE_STATE = '/home/mdbcare/python/command_schedule_state.json'

def _schedule_seconds(value, unit):
    factors = {'seconds': 1, 'minutes': 60, 'hours': 3600, 'days': 86400}
    return max(1, int(value or 1) * factors.get(str(unit).lower(), 1))

def _schedule_start(command, now):
    mode = str(command.get('ScheduleMode', 'once')).lower()
    time_text = str(command.get('ScheduleTime', '00:00:00'))
    try:
        parsed_time = datetime.strptime(time_text, '%H:%M:%S').time()
    except ValueError:
        parsed_time = datetime.strptime(time_text, '%H:%M').time()
    if mode == 'once':
        date_text = str(command.get('ScheduleDate', ''))
        return datetime.combine(datetime.strptime(date_text, '%Y-%m-%d').date(), parsed_time)
    today_start = datetime.combine(now.date(), parsed_time)
    candidates = [today_start - timedelta(days=days) for days in range(0, 8)]
    for start in candidates:
        if start > now:
            continue
        if mode == 'daily':
            return start
        if mode == 'weekly' and ((start.weekday() + 1) % 7) in [int(day) for day in command.get('WeekDays', [])]:
            return start
    return None

def command_schedule_loop(command_path='/var/www/html/meow/config/command_list.json'):
    """Run enabled local commands at second precision without MQTT/HTTP status or replies."""
    state = {}
    missing_file_notice_shown = False
    try:
        with open(COMMAND_SCHEDULE_STATE, 'r', encoding='utf-8') as handle:
            state = json.load(handle)
        if not isinstance(state, dict):
            state = {}
    except Exception:
        state = {}
    while True:
        cycle_started = time.monotonic()
        try:
            with open(command_path, 'r', encoding='utf-8') as handle:
                commands = json.load(handle)
            missing_file_notice_shown = False
            if not isinstance(commands, list):
                commands = []
            now = datetime.now()
            for command in commands:
                if not isinstance(command, dict) or command.get('Enabled') is False or command.get('ScheduleEnabled') is not True:
                    continue
                cmdid = str(command.get('cmdid', ''))
                start = _schedule_start(command, now)
                if not cmdid or start is None:
                    continue
                duration = _schedule_seconds(command.get('DurationValue', 1), command.get('DurationUnit', 'seconds'))
                repeat = _schedule_seconds(command.get('RepeatValue', 1), command.get('RepeatUnit', 'seconds'))
                signature = '|'.join((str(command.get('ScheduleMode', '')), str(command.get('ScheduleDate', '')), str(command.get('ScheduleTime', '')), str(duration), str(repeat), ','.join(map(str, command.get('WeekDays', [])))))
                state_key = cmdid + '|' + signature
                elapsed = (now - start).total_seconds()
                if elapsed < 0 or elapsed > duration:
                    continue
                slot = int(start.timestamp()) + int(elapsed // repeat) * repeat
                if int(state.get(state_key, 0)) >= slot:
                    continue
                state[state_key] = slot
                temporary = COMMAND_SCHEDULE_STATE + '.tmp'
                with open(temporary, 'w', encoding='utf-8') as handle:
                    json.dump(state, handle, separators=(',', ':'))
                os.replace(temporary, COMMAND_SCHEDULE_STATE)
                print(f"[Command Schedule] run {cmdid} {command.get('Name', '')} slot={slot}", flush=True)
                try:
                    _run_manual_command(command)
                    print(f'[Command Schedule] completed {cmdid}', flush=True)
                except Exception as error:
                    print(f'[Command Schedule] failed {cmdid}: {error}', file=sys.stderr, flush=True)
        except FileNotFoundError:
            # Defensive first-boot fallback.  The installer normally seeds this
            # file, but an older package or a partial install must not flood the
            # service log or stop the scheduler process.
            try:
                os.makedirs(os.path.dirname(command_path), exist_ok=True)
                with open(command_path, 'x', encoding='utf-8') as handle:
                    json.dump([], handle)
                print(f'[Command Schedule] created missing file: {command_path}', flush=True)
            except FileExistsError:
                pass
            except Exception as create_error:
                if not missing_file_notice_shown:
                    print(
                        f'[Command Schedule] command list unavailable: {create_error}',
                        file=sys.stderr,
                        flush=True
                    )
                    missing_file_notice_shown = True
                time.sleep(1)
        except Exception as error:
            print(f'[Command Schedule] loop error: {error}', file=sys.stderr, flush=True)
        time.sleep(max(0.05, 0.25 - (time.monotonic() - cycle_started)))


# ////////////////////// Part main function //////////////////////
def worker(lock,k,p,c,meterSN,rgl,lasttime_send_status,m_arr,g_cne,para_arr,global_arr,manager,
           gateway_locks=None, automation_arr=None, automation_revision=None):
    # for show debug read all
    
    debug_ = True
    data_device_cmd = dict()
    return_status   = dict()
    raw_read_arr    = dict()
    # print(f'data : {p}')
    # print(f'===PRE t_i_m_e==={datetime.fromtimestamp(lasttime_send_status).strftime("%Y-%m-%d %H:%M:%S")}')       
    
    # with lock:
    try:
        # GPIO.setmode(GPIO.BCM)
        # GPIO.setup(17, GPIO.IN)  # Set +11,-09 ,DI0
        # GPIO.setup(27, GPIO.IN)  # Set +15,-13 ,DI1

        # GPIO.setup(23, GPIO.OUT) 
        # GPIO.setup(24, GPIO.OUT)
        
        if isinstance(p,dict):
            # print(f"Working on {n} - {p}")
            # print(rgl)
            if 'mode' in p:
                # d0_arr = dict()
                
                if p['mode'].lower() == 'tcp':
                    if 'ipaddress' in p:
                        port_485 = int(p['port']) if 'port' in p else 502
                        deviceID_485 = int(p['deviceID']) if 'deviceID' in p else 1
                        gateway_key = f"{p['ipaddress']}:{port_485}"
                        gateway_lock = gateway_locks.get(gateway_key) if gateway_locks is not None else None
                        instrument = m1_class.tcpconfig(p['ipaddress'],deviceID_485,port_485,2)
                        # start_t = int(time.time())
                        start_reg = 0
                        for cmd_id in c:
                            for cmd_act in c[cmd_id]:
                                return_status[cmd_act] = False
                                
                                cml_arr = c[cmd_id][cmd_act]
                                if meterSN in cml_arr:
                                    valid_values = [v for v in cml_arr[meterSN] if v is not None]
                                    min_reg = min(valid_values)
                                    max_reg = max(valid_values)
                                    if cmd_act == "read_holding_tcp" or cmd_act == "read_input_tcp":
                                        # print(f'===MIN:  {cml_arr}')
                                        # Filter out None
                                        
                                        if min_reg == max_reg:
                                            max_reg=max_reg+2
                                        length_485 = int(max_reg - min_reg) if int(max_reg - min_reg) < 125 else 125
                                        
                                        fn_read = 3
                                        if cmd_act == "read_holding_tcp":
                                            fn_read = 3
                                        if cmd_act == "read_input_tcp":
                                            fn_read = 4
                                            
                                
                                        if gateway_lock is not None:
                                            with gateway_lock:
                                                data_mb_read = m1_class.readmodbustcp(instrument,int(min_reg),int(length_485),fn_read)
                                        else:
                                            data_mb_read = m1_class.readmodbustcp(instrument,int(min_reg),int(length_485),fn_read)
                                        # print(f"Working on {n} [{meterSN}] - {min_reg},{max_reg} = {len(data_mb_read)}")
                                        data_read = {}
                                        if "data" in data_mb_read:
                                            data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                            raw_read_arr[cmd_id] = data_read
                                            
                                        # if meterSN == "PM113009":
                                        #     print(f'=== {meterSN} ==== {data_mb_read}')
                                            

                                    msg_req2 = {}
                                    msg_req2[cmd_id] = {"fn": cmd_act, "sn": meterSN, "params": cml_arr[meterSN]}
                                    msg_req = {"method": "req", "data": msg_req2}
                                    m1_class.log_action(json.dumps(msg_req),"log5","a")
                                    m1_class.log_action(json.dumps(msg_req),"log1","a")
                                    # print(msg_req)
                                    start_reg = start_reg = min_reg if valid_values else 0
                                    data_command = {}
                                    if cmd_id in raw_read_arr:
                                        # print((rgl[p['model']]))
                                        data_command = m1_class.convert_data(raw_read_arr[cmd_id],rgl[p['model']],start_reg,cmd_act)
                                        data_command = _sanitize_battery_snapshot(meterSN, data_command)
                                        data_command = _sanitize_soc(meterSN, data_command)
                                        if len(data_command) > 0:
                                            data_command['error'] = '0'
                                        # if debug_ == True:
                                        #     print("[{}][{}][modbusTCP] >> {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,cml_arr[meterSN]))
                                        #     print("[{}][{}][modbusTCP] << {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,data_command))
                                    else:
                                        # {'p_max': '100.0'}
                                        data_command['error'] = '1'
                                        
                                    if debug_ == True:
                                        # if data_command == {}:
                                        #     print(f'cmdid: {cmd_id}')
                                        #     print(f'raw: {raw_read_arr}')
                                        print("[{}][{}][modbusTCP] >> {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,cml_arr[meterSN]))
                                        print("[{}][{}][modbusTCP] << {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,data_command))
                                        
                                    if data_command != {}:
                                        data_command_2 = dict()
                                        data_command_2[meterSN] = data_command
                                        # if 'error' in data_command_2[meterSN]:
                                        #     print('==========================================================')
                                        #     print(f'=Meter fail222== {cmd_id} == {meterSN} ==== {data_command_2}')
                                        #     print('==========================================================')
                                        # data_device_cmd[cmd_id] = data_command_2[meterSN]
                                        if not cmd_id in data_device_cmd:
                                            data_device_cmd[cmd_id] = {}
                                        data_device_cmd[cmd_id] = data_command
                                        global_arr = m1_class.alert_config(meterSN,data_command_2,lasttime_send_status.value,global_arr)
                                        
                                        # # # /////////////////////////////////////////////////////////////////////
                                        
                                        data_res = dict()
                                        data_res_2 = dict()
                                        data_res_4 = dict()

                                        data_res_2['cmdid'] = cmd_id
                                        data_res_2['meter_SN'] = meterSN
                                        data_res_2['data']  = data_device_cmd[cmd_id]
                                        
                                        # data_res_4[dev_list] = convert_data(data_read,rgl_arr[deviceModel],start_reg)
                                        data_res_4[meterSN] =  data_command
                                        if cmd_id not in m_arr:
                                            m_arr[cmd_id] = {}
                                        m_arr[cmd_id] = data_res_4 #Global data meter
                                        if automation_arr is not None:
                                            automation_arr[cmd_id] = data_res_4
                                            if automation_revision is not None:
                                                with automation_revision.get_lock():
                                                    automation_revision.value += 1

                                        if data_device_cmd[cmd_id]:
                                            data_res_2['result'] = 1
                                            data_res_2['error'] = ""
                                        else:
                                            data_res_2['result'] = 0
                                            data_res_2['error'] = ""


                                        data_res['method'] = "res"
                                        data_res['data'] = data_res_2
                                        msg_2_log = json.dumps(data_res)
                                        # if hostname == "172.16.21.114":
                                        # print(msg_2_log)
                                        status_res_log5 = m1_class.log_action(msg_2_log,"log5","a")
                                        status_res_log1 = m1_class.log_action(msg_2_log,"log1","a")
                                        return_status[cmd_act] = True
                elif p['mode'].lower() == 'rtu':
                    if 'deviceID' in p:
                        # print(f"RTU on {p}")
                        dvl_id = int(p['deviceID']) if 'deviceID' in p else 1
                        dvl_baurdrate = int(p['baurdrate']) if 'baurdrate' in p else 9600
                        dvl_parity = p['parity'] if 'parity' in p else 'none'
                        parity_list = {'none':serial.PARITY_NONE,'even':serial.PARITY_EVEN}
                        instrument = m1_class.rtuconfig(dvl_id,dvl_baurdrate,parity_list[dvl_parity],timeouts=1)
                        # start_t = int(time.time())
                        start_reg = 0
                        for cmd_id in c:
                            for cmd_act in c[cmd_id]:
                                return_status[cmd_act] = False
                                
                                cml_arr = c[cmd_id][cmd_act]
                                if meterSN in cml_arr:
                                    valid_values = [v for v in cml_arr[meterSN] if v is not None]
                                    min_reg = min(valid_values)
                                    max_reg = max(valid_values)
                                    if cmd_act == "read_holding_rtu" or cmd_act == "read_input_rtu":
                                        # valid_values = [v for v in cml_arr[meterSN] if v is not None]
                                        # min_reg = min(valid_values)
                                        # max_reg = max(valid_values)
                                        if min_reg == max_reg:
                                            max_reg=max_reg+2
                                        length_485 = int(max_reg - min_reg) if int(max_reg - min_reg) < 125 else 125
                                        
                                        fn_read = 3
                                        if cmd_act == "read_holding_rtu":
                                            fn_read = 3
                                        if cmd_act == "read_input_rtu":
                                            fn_read = 4
                                
                                        with lock:
                                            data_mb_read = m1_class.readmodbusrtu(instrument,int(min_reg),int(length_485),int(fn_read))
                                        # print(f"Working on [{meterSN}] - {min_reg},{max_reg} = {data_mb_read}")
                                        data_read = {}
                                        if "data" in data_mb_read:
                                            data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                            raw_read_arr[cmd_id] = data_read
                                            # print(raw_read_arr)
                                    msg_req2 = {}
                                    msg_req2[cmd_id] = {"fn": cmd_act, "sn": meterSN, "params": cml_arr[meterSN]}
                                    msg_req = {"method": "req", "data": msg_req2}
                                    m1_class.log_action(json.dumps(msg_req),"log5","a")
                                    m1_class.log_action(json.dumps(msg_req),"log1","a")
                                    # print(msg_req)
                                    start_reg = min_reg if valid_values else 0
                                    data_command = {}
                                    if cmd_id in raw_read_arr:
                                        # print((rgl[p['model']]))
                                        # print(f'====raw_read_arr[cmd_id]: {raw_read_arr[cmd_id]}')
                                        data_command = m1_class.convert_data(raw_read_arr[cmd_id],rgl[p['model']],start_reg,cmd_act)
                                        data_command = _sanitize_battery_snapshot(meterSN, data_command)
                                        data_command = _sanitize_soc(meterSN, data_command)
                                        # print(f'====data_command: {data_command}')

                                        # if debug_ == True:
                                        print("[{}][{}][modbusRTU] >> {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,cml_arr[meterSN]))
                                        print("[{}][{}][modbusRTU] << {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,data_command))
                                        
                                    if data_command != {}:
                                        # data_command_2[meterSN] = data_command
                                        # data_device_cmd[cmd_id] = data_command_2[meterSN]
                                        data_command_2 = dict()
                                        data_command_2[meterSN] = data_command
                                        if not cmd_id in data_device_cmd:
                                            data_device_cmd[cmd_id] = {}
                                        data_device_cmd[cmd_id] = data_command
                                        global_arr = m1_class.alert_config(meterSN,data_command_2,lasttime_send_status.value,global_arr)
                                        
                                        data_res = dict()
                                        data_res_2 = dict()
                                        data_res_4 = dict()

                                        data_res_2['cmdid'] = cmd_id
                                        data_res_2['meter_SN'] = meterSN
                                        data_res_2['data']  = data_device_cmd[cmd_id]
                                        
                                        # data_res_4[dev_list] = convert_data(data_read,rgl_arr[deviceModel],start_reg)
                                        data_res_4[meterSN] =  data_command
                                        if cmd_id not in m_arr:
                                            m_arr[cmd_id] = {}
                                        m_arr[cmd_id] = data_res_4 #Global data meter
                                        if automation_arr is not None:
                                            automation_arr[cmd_id] = data_res_4
                                            if automation_revision is not None:
                                                with automation_revision.get_lock():
                                                    automation_revision.value += 1

                                        if data_device_cmd[cmd_id]:
                                            data_res_2['result'] = 1
                                            data_res_2['error'] = ""
                                        else:
                                            data_res_2['result'] = 0
                                            data_res_2['error'] = ""


                                        data_res['method'] = "res"
                                        data_res['data'] = data_res_2
                                        msg_2_log = json.dumps(data_res)
                                        # if hostname == "172.16.21.114":
                                        # print(msg_2_log)
                                        status_res_log5 = m1_class.log_action(msg_2_log,"log5","a")
                                        status_res_log1 = m1_class.log_action(msg_2_log,"log1","a")
                                        return_status[cmd_act] = True
                        
                        # tcpconfig(p['ipaddress'],int(p['deviceID']),int(p['port']),3)
                        # start_t = int(time.time())
                        start_reg = 0
                elif p['mode'].lower() == 'recv':
                    # print(f"RECV on {n} - {p}")
                    start_reg = 0
                    for cmd_id in c:
                        for cmd_act in c[cmd_id]:
                            cml_arr = c[cmd_id][cmd_act]
                            if meterSN in cml_arr:
                                # print(f'=={cmd_id}=={cml_arr[meterSN]}')
                                if cmd_act == "recv_holding_rtu":
                                    min_reg = min(cml_arr[meterSN])
                                    max_reg = max(cml_arr[meterSN])
                                    length_485 = int(max_reg - min_reg)+4 if int(max_reg - min_reg)+4 < 125 else 125
                                    path_local = f"{path_fileconfig}/textfile"
                                    local_data_arr = {}
                                    d0_arr_local = {}
                                    # print(f'Model : {rgl[p["model"]]}')
                                    
                                    # print(f"Working on {n} [{meterSN}] - {min_reg},{max_reg} = {len(path_local)}")
                                    if m1_class.ensure_dir_with_perms(path_local, 0o777 ,debug=0):
                                        for filename in os.listdir(path_local):
                                            if filename.endswith('.txt'):
                                                local_data = m1_class.read_file(path_local + "/" + filename)
                                                local_data_arr = json.loads(local_data) if m1_class.is_json(local_data) else local_data
                                                # print("==path Local==",local_data_arr)
                                                if "mode" in local_data_arr:
                                                    if local_data_arr['mode'] == "status":
                                                        # print("==path Local==",local_data_arr['device_SN'],local_data_arr)
                                                        if local_data_arr['data'] != None:
                                                            # print(local_data_arr['data'])
                                                            if meterSN in local_data_arr['data']:
                                                                local_battery_data = _sanitize_battery_snapshot(meterSN, local_data_arr['data'][meterSN])
                                                                d0_arr_local[meterSN] = _sanitize_soc(meterSN, local_battery_data)
                                                                # if cmd_id not in m_arr:
                                                                #     m_arr[cmd_id] = {}
                                                                # if not meterSN in m_arr[cmd_id]:
                                                                #     m_arr[cmd_id][meterSN] = {}
                                                                # m_arr[cmd_id][meterSN] = local_data_arr['data'][meterSN]
                                                                m_arr[cmd_id] = d0_arr_local
                                                                if automation_arr is not None:
                                                                    automation_arr[cmd_id] = d0_arr_local
                                                                    if automation_revision is not None:
                                                                        with automation_revision.get_lock():
                                                                            automation_revision.value += 1
                                                                print("[{}][{}][RECV] >> {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,cml_arr[meterSN]))
                                                                print("[{}][{}][RECV] << {} | {}".format(m1_class.get_current(),m1_class.deviceSN,meterSN,d0_arr_local[meterSN]))
                                                                
                                                                data_res = dict()
                                                                data_res['method'] = "rec" #rec = receive data
                                                                data_res['data'] = d0_arr_local
                                                                msg_2_log = json.dumps(data_res)
                                                                print("====================== LOG ==================================")
                                                                print(msg_2_log)
                                                                status_res_log5 = m1_class.log_action(msg_2_log,"log5","a")
                                                                print("========================================================")
                                                                if meterSN in m_arr[cmd_id]:
                                                                    local_data_arr["data"].pop(meterSN)
                                                                print(f'Local data: {local_data_arr["data"]}')
                                                                m1_class.write_file(path_local + "/" + filename,json.dumps(local_data_arr),"w")
                                                                
                                                                # for local_deviceSN in local_data_arr['data']:

                                                                # if meterSN in d0_arr:
                                                                #     d0_arr[meterSN].update(local_data_arr['data'][meterSN])
                                                                # else:
                                                                #     d0_arr[meterSN] = local_data_arr['data'][meterSN]
                    
                                # data_res = dict()
                                # data_res_2 = dict()
                                
                                # data_res['method'] = "rec" #rec = receive data
                                # data_res['data'] = d0_arr
                                # msg_2_log = json.dumps(data_res)
                                # print(msg_2_log)
                                # # status_res_log5 = log_action(msg_2_log,"log5","a")
                elif p['mode'].lower() == 'cmd':
                    print("=============================================")
                    print(f'====={c}')
                    print("=============================================")
                
                
                if not lasttime_send_status.value:
                    lasttime_send_status.value = 0
                # print("DIFF====={}".format(int(time.time()) - int(lasttime_send_status.value)))
                # print("===========Next {} ==================".format(datetime.fromtimestamp(lasttime_send_status.value).strftime("%Y-%m-%d %H:%M:%S")))
                # print(f'=Type111=={type(dict(m_arr))} == {m_arr}')
                
                # //////////////////////////////////////////////////////////////
                # print("===================================================")
                # WSData_arr = {}
                # for outer in m_arr.values():
                #     for key, val in outer.items():
                #         WSData_arr.setdefault(key, {}).update(val)
                
                # print(f'=====WSData_arr: {WSData_arr}')
                flag_alarm = 0
                if "alarm_arr" in global_arr:
                    if 'status_alarm' in global_arr["alarm_arr"]:
                        flag_alarm = global_arr["alarm_arr"]["status_alarm"]
                        print(f'= Status alarm : == {global_arr["alarm_arr"]["status_alarm"]}')
                        # tmp2 = global_arr['alarm_arr']
                        
                        # if int(time.time()) >= int(lasttime_send_status.value) or global_arr["alarm_arr"]["status_alarm"] == 1:
                        #     tmp2['status_alarm'] = 0
                        #     global_arr['alarm_arr'] = tmp2
                        #     print(f'=== Send http : {global_arr["alarm_arr"]}')
                    

                #     print("===================================================")
                # //////////////////////////////////////////////////////////////
                print(f'===[{k}] Before Time send[{int(m1_class.cml_send)}] : {int(time.time())} > {int(lasttime_send_status.value)}')
                if int(time.time()) >= int(lasttime_send_status.value) or flag_alarm:
                    
                # if int(time.time()) >= int(lasttime_send_status.value) or True:
                    t_i_m_e = int(m1_class.cml_send)
                    # t_i_m_e = int(10)
                    # lasttime_send_status = int(int(time.time())/t_i_m_e)*t_i_m_e+t_i_m_e
                    # print(f'===t_i_m_e==={lasttime_send_status.value}')
                    # print(f'===t_i_m_e==={t_i_m_e}')
                    lasttime_send_status.value = int(time.time()) +t_i_m_e
                    # print(f'=== After Teime send : {int(lasttime_send_status.value)}')
                    
                    # print(f'===t_i_m_e==={lasttime_send_status.value}')       
                    # print(f'===t_i_m_e==={datetime.fromtimestamp(lasttime_send_status.value).strftime("%Y-%m-%d %H:%M:%S")}')   
                    a1 = dict()
                    d0_arr = dict()
                    status_SI1 = GPIO.input(17)
                    status_SI2 = GPIO.input(27)
                    
                    status_SO1 = GPIO.input(23) #DO0
                    status_SO2 = GPIO.input(24) #DO1
                    status_SO1 = 1 if status_SO1 == 0 else 2
                    status_SO2 = 1 if status_SO2 == 0 else 2
                    d0_arr['SI'] = {'SI1':str(status_SI1),'SI2':str(status_SI2),'RL1':str(status_SO1),'RL2':str(status_SO2)}
                    # GPIO.cleanup()
                    if "para" in para_arr:
                        # d0_arr['para'] = para_arr["para"]
                        v_para = {}
                        v_para = para_arr["para"]
                        d0_arr['para'] = dict(v_para)
                        # print(f'===para_arr: {d0_arr}')
                    # d0_arr = d0_arr | para_arr
                    # print(f'=Type=={type(dict(m_arr))} == {m_arr}')
                    if m_arr:
                        if isinstance(dict(m_arr),dict):
                            # print(f'==={m_arr}')
                            for m_cmdid in dict(m_arr):
                                for sn_meter in dict(m_arr)[m_cmdid]:
                                    # print("====sn_meter==={}".format(sn_meter))
                                    if sn_meter in dict(m_arr)[m_cmdid]:
                                        # print("===>> {} === {} == LEN:{}".format(m_cmdid,sn_meter,len(m_arr[m_cmdid][sn_meter])))
                                        if sn_meter in d0_arr:
                                            if isinstance(dict(m_arr)[m_cmdid][sn_meter], dict):
                                                d0_arr[sn_meter].update(dict(m_arr)[m_cmdid][sn_meter])
                                            else:
                                                print("Warning: Cannot update with non-dict:{}".format(type(dict(m_arr)[m_cmdid][sn_meter])))
                                                print("==Data is: {}".format(dict(m_arr)[m_cmdid][sn_meter]))
                                        else:
                                            d0_arr[sn_meter] = dict(m_arr)[m_cmdid][sn_meter]
                    
                    
                                    
                    a1["mode"] = "alarm" if flag_alarm == 1 else "status"
                    a1["device_SN"] = m1_class.deviceSN
                    a1["data"] = d0_arr
                    # if err_arr:
                    #     a1["error"] = err_arr
                    a1["counter"] = str(int(time.time()) - m1_class.time_counter)
                    a1["ts"] = int(time.time())
                    a1["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")


                    flag_alarm = 0
                    if not 'alarm_arr' in global_arr:
                        global_arr['alarm_arr'] = {}
                    tmp2 = global_arr['alarm_arr']
                    tmp2['status_alarm'] = 0
                    global_arr['alarm_arr'] = tmp2
                    # clear data
                    # m_arr = dict()  
                    # m_arr.clear()  
                    
                    
                    if m1_class.log_server(json.dumps(a1),"log5","a"):
                        # time_counter = int(time.time())
                        m_arr.clear() 
                    # print(f"=== {a1}")
                    res_http = m1_class.http_send(a1["mode"],  d0_arr, cmd_id="",timeout=3,g_cne=g_cne.value)
                    if res_http[0]:
                        print(f'=== Send Http success {res_http}')
                        if not res_http[1] is None:
                            sec_count = res_http[1]['sec_count'] if 'sec_count' in res_http[1] else 60
                            lasttime_send_status.value = int(time.time()) + sec_count
                            # time_l = lasttime_send_status.value
                            # time_test_cutoff_s = datetime.fromtimestamp(int(time_l))
                            # time_test_cutoff_s = time_test_cutoff_s.strftime("%H:%M:%S")
                            # print(f'Last time == {time_test_cutoff_s}')
                            # print("==================================================")
                            # if 'ms_minus' in res_http[1] and False:
                            #     time_l = lasttime_send_status.value
                            #     time_test_cutoff_s = datetime.fromtimestamp(int(time_l))
                            #     time_test_cutoff_s = time_test_cutoff_s.strftime("%H:%M:%S")
                            #     print(f'Pre time == {time_test_cutoff_s}')
                            #     ms_minus = res_http[1]['ms_minus']
                            #     lasttime_send_status.value = lasttime_send_status.value - ms_minus
                            #     time_l = lasttime_send_status.value
                            #     time_test_cutoff_s = datetime.fromtimestamp(int(time_l))
                            #     time_test_cutoff_s = time_test_cutoff_s.strftime("%H:%M:%S")
                            #     print(f'Last time == {time_test_cutoff_s}')
                            #     print("==================================================")
                            if 'cne' in res_http[1]:
                                g_cne.value = res_http[1]['cne'].encode('utf-8')
                                
                    else:
                        print(f"=== Send Http Error ")
                # print(m_arr)            
            # else:
            #     print(f"Working on {n} - {p}")
                
    except Exception as e:
        print(f'= Error as {e}')

def _write_modbus_verified(mode, instrument, register, raw_value, retries=3):
    """Write one holding register and confirm the exact raw value by reading it back."""
    expected_raw = int(raw_value) & 0xFFFF
    last_error = "write was not attempted"

    for attempt in range(1, retries + 1):
        try:
            if mode == "tcp":
                write_ok = m1_class.writemodbustcp(instrument, int(register), int(raw_value), 0.2)
            elif mode == "rtu":
                write_ok = m1_class.writemodbusrtu(instrument, int(register), int(raw_value), 0.2)
            else:
                return {"success": False, "attempts": attempt, "error": f"unsupported mode: {mode}"}

            if not write_ok:
                last_error = "write function returned failure"
                time.sleep(0.3)
                continue

            time.sleep(0.3)
            if mode == "tcp":
                readback = m1_class.readmodbustcp(instrument, int(register), 1, 3)
            else:
                readback = m1_class.readmodbusrtu(instrument, int(register), 1, 3)

            if not isinstance(readback, dict) or "data" not in readback:
                last_error = readback.get("error", "read-back failed") if isinstance(readback, dict) else "invalid read-back"
                time.sleep(0.3)
                continue

            raw_data = json.loads(readback["data"]) if m1_class.is_json(readback["data"]) else ast.literal_eval(readback["data"])
            if not isinstance(raw_data, (list, tuple)) or not raw_data:
                last_error = "read-back returned no register"
                time.sleep(0.3)
                continue

            actual_raw = int(raw_data[0]) & 0xFFFF
            if actual_raw == expected_raw:
                return {
                    "success": True,
                    "attempts": attempt,
                    "expected_raw": expected_raw,
                    "actual_raw": actual_raw
                }

            last_error = f"verify mismatch: expected {expected_raw}, got {actual_raw}"
            time.sleep(0.3)
        except Exception as error:
            last_error = str(error)
            time.sleep(0.3)

    return {
        "success": False,
        "attempts": retries,
        "expected_raw": expected_raw,
        "error": last_error
    }

_SOC_HISTORY_LEN = 3
_SOC_LAST_GOOD_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "soc_last_good.json")
_BATTERY_LAST_GOOD_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "battery_last_good.json")
_BATTERY_SNAPSHOT_FIELDS = ('p_t', 'q_t', 'v_avg', 'i_avg', 'soc', 'batt_temp_avg', 'batt_temp_max', 'cell_v_max', 'cell_v_min', 'status')

def _sanitize_battery_snapshot(meter_sn, data_command):
    """Reject a shifted Battery block without hiding a legitimate zero p_t."""
    if not isinstance(data_command, dict):
        return data_command
    required = {'p_t', 'v_avg', 'i_avg', 'soc', 'cell_v_max', 'cell_v_min'}
    if not required.issubset(data_command):
        return data_command

    def number(name):
        try:
            value = float(data_command[name])
            return value if value == value and abs(value) != float('inf') else None
        except (KeyError, TypeError, ValueError):
            return None

    values = {name: number(name) for name in required}
    issues = []
    if values['v_avg'] is None or not 10.0 <= values['v_avg'] <= 2000.0:
        issues.append(f"v_avg={data_command.get('v_avg')}")
    if values['i_avg'] is None or abs(values['i_avg']) > 10000.0:
        issues.append(f"i_avg={data_command.get('i_avg')}")
    if values['soc'] is None or not 0.0 <= values['soc'] <= 100.0:
        issues.append(f"soc={data_command.get('soc')}")
    cell_min, cell_max = values['cell_v_min'], values['cell_v_max']
    if cell_min is None or cell_max is None or not (1.5 <= cell_min <= cell_max <= 5.0):
        issues.append(f"cell_v_min/max={data_command.get('cell_v_min')}/{data_command.get('cell_v_max')}")
    for name in ('batt_temp_avg', 'batt_temp_max'):
        if name in data_command:
            value = number(name)
            if value is None or not -40.0 <= value <= 120.0:
                issues.append(f"{name}={data_command.get(name)}")

    try:
        with FileLock(_BATTERY_LAST_GOOD_PATH + '.lock', timeout=2):
            saved = {}
            if os.path.exists(_BATTERY_LAST_GOOD_PATH):
                try:
                    with open(_BATTERY_LAST_GOOD_PATH, 'r', encoding='utf-8') as state_file:
                        loaded = json.load(state_file)
                        if isinstance(loaded, dict):
                            saved = loaded
                except (OSError, ValueError):
                    pass
            device_key = str(meter_sn)
            if issues:
                previous = saved.get(device_key)
                print(f"== Battery snapshot rejected [{meter_sn}]: {', '.join(issues)}; using last-good block")
                if not isinstance(previous, dict) or not previous:
                    print(f"== Battery snapshot dropped [{meter_sn}]: no last-good block available")
                    return {}
                repaired = dict(data_command)
                for name in _BATTERY_SNAPSHOT_FIELDS:
                    if name in previous:
                        repaired[name] = previous[name]
                return repaired

            saved[device_key] = {name: data_command[name] for name in _BATTERY_SNAPSHOT_FIELDS if name in data_command}
            temp_path = _BATTERY_LAST_GOOD_PATH + '.tmp'
            with open(temp_path, 'w', encoding='utf-8') as state_file:
                json.dump(saved, state_file, ensure_ascii=False, indent=2)
                state_file.flush()
                os.fsync(state_file.fileno())
            os.replace(temp_path, _BATTERY_LAST_GOOD_PATH)
    except Exception as error:
        print(f"== Battery snapshot filter failed [{meter_sn}]: {error}")
    return data_command

def _load_soc_last_good():
    try:
        if os.path.exists(_SOC_LAST_GOOD_PATH):
            with open(_SOC_LAST_GOOD_PATH, "r", encoding="utf-8") as state_file:
                loaded = json.load(state_file)
                if isinstance(loaded, dict):
                    return loaded
    except Exception as error:
        print(f"== Load last-good SoC failed: {error}")
    return {}

def _save_soc_last_good(saved):
    temp_path = _SOC_LAST_GOOD_PATH + ".tmp"
    try:
        with open(temp_path, "w", encoding="utf-8") as state_file:
            json.dump(saved, state_file, ensure_ascii=False, indent=2)
            state_file.flush()
            os.fsync(state_file.fileno())
        os.replace(temp_path, _SOC_LAST_GOOD_PATH)
    except Exception as error:
        print(f"== Persist last-good SoC failed: {error}")
        try:
            if os.path.exists(temp_path):
                os.remove(temp_path)
        except OSError:
            pass

def _sanitize_soc(meter_sn, data_command):
    """Smooth a single-cycle SoC glitch (comm error / garbage register) using the
    median of the last few reads, persisted to disk so it survives process restarts.

    A plain "reject changes bigger than X%" rule was tried first and rejected: if the
    very first reading after a restart happens to be the glitch, every legitimate
    reading after it also differs from that bad seed by more than X%, so the bad
    value gets stuck forever. A rolling median recovers within 1-2 cycles instead.
    """
    if not isinstance(data_command, dict) or 'soc' not in data_command or data_command['soc'] is None:
        return data_command
    try:
        raw_soc = float(data_command['soc'])
    except (TypeError, ValueError):
        return data_command

    saved = _load_soc_last_good()
    history = saved.get(str(meter_sn), [])
    if not isinstance(history, list):
        history = []

    clamped = raw_soc if 0 <= raw_soc <= 100 else (history[-1] if history else raw_soc)
    if clamped != raw_soc:
        print(f"== SoC {meter_sn} out of range ({raw_soc}%), using last known value")

    history.append(clamped)
    history = history[-_SOC_HISTORY_LEN:]
    sanitized = median(history)

    if abs(raw_soc - sanitized) > 0.01:
        print(f"== SoC {meter_sn} raw={raw_soc}% -> sanitized={sanitized}% (recent history={history})")

    saved[str(meter_sn)] = history
    _save_soc_last_good(saved)
    data_command['soc'] = str(sanitized)
    return data_command

def _persist_last_pmax(device_id, value):
    """Share a successful manual/MQTT p_max with the Automation process."""
    state_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "automation_last_commands.json")
    temp_path = state_path + ".cma.tmp"
    try:
        saved = {}
        if os.path.exists(state_path):
            with open(state_path, "r", encoding="utf-8") as state_file:
                loaded = json.load(state_file)
                if isinstance(loaded, dict):
                    saved = loaded
        saved[str(device_id)] = float(value)
        with open(temp_path, "w", encoding="utf-8") as state_file:
            json.dump(saved, state_file, ensure_ascii=False, indent=2)
            state_file.flush()
            os.fsync(state_file.fileno())
        os.replace(temp_path, state_path)
        print(f"== Persist latest p_max {device_id}={float(value)} kW")
    except Exception as error:
        print(f"== Persist latest p_max failed {device_id}: {error}")
        try:
            if os.path.exists(temp_path):
                os.remove(temp_path)
        except OSError:
            pass


def _get_ipv4_address(interface=None):
    """Return an interface/default-route IPv4 address, or None when unavailable."""
    try:
        if interface:
            command = ["ip", "-4", "-o", "addr", "show", "dev", interface]
        else:
            command = ["ip", "-4", "route", "get", "1.1.1.1"]
        result = subprocess.run(command, capture_output=True, text=True, timeout=3)
        if result.returncode != 0:
            return None
        if interface:
            match = re.search(r"\binet\s+(\d+(?:\.\d+){3})/", result.stdout)
        else:
            match = re.search(r"\bsrc\s+(\d+(?:\.\d+){3})\b", result.stdout)
        return match.group(1) if match else None
    except (subprocess.SubprocessError, OSError):
        return None


def _set_vpn_state(value):
    """Start, stop, or query the managed OpenVPN tunnel."""
    normalized = str(value).strip().lower()
    if normalized in ("2", "status", "query"):
        try:
            status = subprocess.run(
                ["systemctl", "is-active", "mdb-vpn.service"],
                capture_output=True,
                text=True,
                timeout=3
            )
            active = status.stdout.strip() == "active"
            ipvpn = _get_ipv4_address("tun0")
            return {
                "requested": 2,
                "active": 1 if active else 0,
                "tunnel": 1 if ipvpn else 0,
                "success": True,
                "ip": _get_ipv4_address(),
                "ipvpn": ipvpn
            }
        except (subprocess.SubprocessError, OSError) as exc:
            return {
                "requested": 2,
                "active": 0,
                "tunnel": 0,
                "success": False,
                "ip": _get_ipv4_address(),
                "ipvpn": None,
                "error": str(exc)
            }
    if normalized in ("1", "true", "on", "start"):
        requested = 1
        action = "start"
    elif normalized in ("0", "false", "off", "stop"):
        requested = 0
        action = "stop"
    else:
        return {
            "requested": value,
            "active": 0,
            "success": False,
            "error": "VPN accepts 1 (start), 0 (stop), or 2 (status)"
        }

    try:
        command = subprocess.run(
            ["sudo", "-n", "systemctl", action, "mdb-vpn.service"],
            capture_output=True,
            text=True,
            timeout=20
        )
        if command.returncode != 0:
            error = (command.stderr or command.stdout or "systemctl command failed").strip()
            return {
                "requested": requested,
                "active": 0,
                "success": False,
                "error": error
            }

        # Starting the process is not enough: wait until OpenVPN creates tun0.
        deadline = time.time() + (15 if requested else 8)
        active = False
        tunnel_ready = False
        while time.time() < deadline:
            status = subprocess.run(
                ["systemctl", "is-active", "mdb-vpn.service"],
                capture_output=True,
                text=True,
                timeout=3
            )
            active = status.stdout.strip() == "active"
            tunnel_ready = os.path.exists("/sys/class/net/tun0")
            if (requested and active and tunnel_ready) or (not requested and not active):
                break
            time.sleep(0.5)

        success = (active and tunnel_ready) if requested else not active
        result = {
            "requested": requested,
            "active": 1 if active else 0,
            "tunnel": 1 if tunnel_ready else 0,
            "success": success
        }
        if not success:
            result["error"] = "VPN tunnel did not reach the requested state"
        return result
    except (subprocess.SubprocessError, OSError) as exc:
        return {
            "requested": requested,
            "active": 0,
            "success": False,
            "error": str(exc)
        }


from battery_control_log import record_battery_action, record_battery_command


def _record_mqtt_command(cmd_id, command, result, response, live_data=None):
    """Keep a compact local audit trail of commands received through MQTT."""
    history_path = "mqtt_command_history.json"
    lock_path = history_path + ".lock"
    try:
        with FileLock(lock_path, timeout=3):
            history = []
            if os.path.exists(history_path):
                with open(history_path, "r", encoding="utf-8") as history_file:
                    loaded = json.load(history_file)
                    if isinstance(loaded, list):
                        history = loaded
            history.append({
                "cmd_id": str(cmd_id),
                "source": "mqtt",
                "timestamp": int(time.time()),
                "datetime": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "command": command,
                "result": result,
                "response": response
            })
            history = history[-100:]
            temp_path = history_path + ".tmp"
            with open(temp_path, "w", encoding="utf-8") as history_file:
                json.dump(history, history_file, ensure_ascii=False, separators=(",", ":"))
                history_file.flush()
                os.fsync(history_file.fileno())
            os.chmod(temp_path, 0o644)
            os.replace(temp_path, history_path)
        source = "energy_web" if str(cmd_id).startswith("E") else "mqtt"
        if isinstance(command, dict):
            live_snapshots = {}
            if hasattr(live_data, 'values'):
                for block in live_data.values():
                    if not isinstance(block, dict):
                        continue
                    for live_device_id, live_values in block.items():
                        if isinstance(live_values, dict):
                            live_snapshots.setdefault(live_device_id, {}).update(live_values)
            solar_power = 0.0
            automation_config = {}
            battery_device_ids = set()
            try:
                with open('/var/www/html/meow/config/automation_control.json', 'r', encoding='utf-8') as config_file:
                    automation_config = json.load(config_file)
                configured_devices = automation_config.get('devices', {})
                if not isinstance(configured_devices, dict):
                    configured_devices = {}
                battery_device_ids = {
                    str(config_device_id)
                    for config_device_id, control in configured_devices.items()
                    if isinstance(control, dict) and control.get('role') == 'battery'
                }
                for solar_device_id, solar_control in configured_devices.items():
                    if isinstance(solar_control, dict) and solar_control.get('role') == 'solar_inverter':
                        solar_data = live_snapshots.get(solar_device_id, {})
                        solar_power += float(solar_data.get('p_out', solar_data.get('p_t', 0)) or 0)
            except (OSError, ValueError, TypeError, AttributeError):
                solar_power = 0.0
            for device_id, parameters in command.items():
                if not isinstance(parameters, dict):
                    continue
                snapshot = live_snapshots.get(device_id, {})
                is_battery = (
                    str(device_id) in battery_device_ids
                    or 'e_p_cha' in snapshot
                    or 'e_p_dis' in snapshot
                )
                if not is_battery:
                    continue
                for parameter, operation in parameters.items():
                    if parameter not in ("p_max", "p_max_cha", "p_max_dis", "p_min_cha"):
                        continue
                    requested = operation.get("write") if isinstance(operation, dict) else operation
                    actual = (
                        response.get(device_id, {}).get(parameter)
                        if isinstance(response, dict) and isinstance(response.get(device_id), dict)
                        else None
                    )
                    device_result = result.get(device_id) if isinstance(result, dict) else None
                    main_meter_id = str(automation_config.get('main_meter', '') or '')
                    main_snapshot = live_snapshots.get(main_meter_id, {})
                    context = {
                        "mode": "executed",
                        "state": "MQTT_COMMAND" if source == "mqtt" else "ENERGY_WEB_COMMAND",
                        "main_meter": main_meter_id,
                        "main_power": main_snapshot.get('p_t'),
                        "battery_power": snapshot.get('p_t'),
                        "current_p_max": snapshot.get('p_max'),
                        "soc": snapshot.get('soc'),
                        "control_enabled": bool(
                            automation_config.get('devices', {})
                            .get(str(device_id), {}).get('control_enabled', False)
                        ) if isinstance(automation_config.get('devices', {}), dict) else False,
                    }
                    record_battery_command(
                        source, device_id, parameter, requested, actual,
                        None if device_result is None else bool(device_result),
                        "Command received through CMA", cmd_id, context
                    )
                    if parameter == "p_max" and bool(device_result):
                        record_battery_action(
                            requested,
                            snapshot.get('e_p_cha'),
                            snapshot.get('e_p_dis'),
                            "Command received through CMA",
                            solar_power
                        )
    except Exception as exc:
        print(f"MQTT command history error: {exc}")


def Process_CMA(resCMA,devicelist,registerlist,lasttime_send_status,m_arr,g_cne,para_arr):
    print("===Core 1 =====")
    #  {"mode":"cmd","data":{"uploadfile":{"src":"dlog/M117585323744150/M117585323744150_log5_202509241115.txt","dst":"dlog/M117585323744150/M117585323744150_log5_202509241115.txt"}}}
    # print(f'===={registerlist}')
    while True:
        try:
            
            cma_text = m1_class.read_file('cma.json')
            resCMA = json.loads(cma_text) if m1_class.is_json(cma_text) else {}
            if not resCMA:
                time.sleep(0.2)
                continue
        
            for cma_id in list(resCMA):
                print(f'>> Pre: {resCMA}')
                
                # print(f'core 04 [{cma_id}]: {resCMA}')
                
                for cma_act in resCMA[cma_id]:
                    
                    # print(f'core 04 [{dfl_id}][{dfl_act}]: {resDFL[dfl_id][dfl_act]}')
                    if cma_act == "cmd":
                        cma_status_sendRT = 0
                        cma_data_arr = {}
                        cma_status_arr = {}
                        start_t = int(time.time())
                        for cma_devicesn in resCMA[cma_id][cma_act]:
                            # print(f'=={cma_id}=={dev_sn}')
                            # print(f'==={devicelist[dev_sn]}')
                            if cma_devicesn == "VPN":
                                vpn_value = resCMA[cma_id][cma_act][cma_devicesn]
                                vpn_result = _set_vpn_state(vpn_value)
                                cma_status_arr["VPN"] = 1 if vpn_result["success"] else 0
                                cma_data_arr["VPN"] = vpn_result
                                cma_status_sendRT = 1
                                print(f"== VPN command requested={vpn_value} result={vpn_result}")
                            elif cma_devicesn in devicelist:
                                cma_model = devicelist[cma_devicesn]['model']
                                cma_mode = devicelist[cma_devicesn]['mode']
                                cma_deviceID = int(devicelist[cma_devicesn]['deviceID'])
                                if cma_mode.lower() == 'tcp':
                                    cma_ip = devicelist[cma_devicesn]['ipaddress']
                                    cma_port = int(devicelist[cma_devicesn]['port'])
                                    rgl_model_all = registerlist.get(cma_model, {})
                                    # print(f'====={rgl_model}')
                                    # print(f'==connect by {cma_mode} : == {cma_ip}:{cma_port}')
                                    instrument = m1_class.tcpconfig(cma_ip,cma_deviceID,cma_port,1)
                                    if instrument.open():
                                        for  cma_para in resCMA[cma_id][cma_act][cma_devicesn]:
                                            operation = resCMA[cma_id][cma_act][cma_devicesn][cma_para]
                                            if not isinstance(operation, dict) or not operation:
                                                print(f"== MQTT command SKIP {cma_devicesn}:{cma_para}: no operation/write command")
                                                continue
                                            act_keys, act_values = next(iter(operation.items()))
                                            act_name = str(act_keys).lower()
                                            if act_name not in ('write', 'read', 'realtime'):
                                                print(f"== MQTT command SKIP {cma_devicesn}:{cma_para}: unsupported operation={act_keys}")
                                                continue
                                            fn_name = 'read_input_tcp' if act_name in ('read', 'realtime') and int(act_values) == 4 else 'read_holding_tcp'
                                            rgl_model = m1_class.get_register_map(rgl_model_all, fn_name)
                                            matched_registers = [
                                                (reg, info) for reg, info in rgl_model.items()
                                                if isinstance(info, dict) and info.get("name") == cma_para
                                            ]
                                            if not matched_registers:
                                                print(
                                                    f"== MQTT command SKIP {cma_devicesn}:{cma_para}: "
                                                    f"no {fn_name} register mapping"
                                                )
                                                continue
                                            for reg, info in matched_registers:
                                                    # print(f'Para is : {act_keys}')
                                                    # print(f'Para is : {reg}')
                                                    # resCMA[cma_id][cma_act][cma_devicesn]
                                                    
                                                    if not cma_devicesn in cma_data_arr:
                                                        cma_data_arr[cma_devicesn] = {}
                                                    if not cma_para in cma_data_arr[cma_devicesn]:
                                                        cma_data_arr[cma_devicesn][cma_para] = {}
                                                        
                                                        
                                                    # For test realtime
                                                    if act_keys.lower() == 'realtime':
                                                        fn_read = 3
                                                        if act_values == 1:
                                                            fn_read = 3
                                                        if act_values == 4:
                                                            fn_read = 4
                                                        data_mb_read = m1_class.readmodbustcp(instrument,int(reg),int(2),fn_read)
                                                        data_read = {}
                                                        if "data" in data_mb_read:
                                                            cma_status_sendRT = 1
                                                            data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                            data_command = m1_class.convert_data(data_read,rgl_model,int(reg))
                                                            cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                            # print(f'=====EX== {cma_data_arr}')
                                                            # a1 = {}
                                                            # a1["mode"] = "status"
                                                            # a1["device_SN"] = m1_class.deviceSN
                                                            # a1["data"] = cma_data_arr
                                                            # a1["ts"] = int(time.time())
                                                            # a1["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")
                                                            # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                                            # mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                                            # mqttc3.publish("res/"+m1_class.deviceSN, json.dumps(a1))
                                                            
                                                            
                                                    if act_keys.lower() == 'read':
                                                        fn_read = 3
                                                        if act_values == 1:
                                                            fn_read = 3
                                                        if act_values == 4:
                                                            fn_read = 4

                                                        data_mb_read = m1_class.readmodbustcp(instrument,int(reg),int(2),fn_read)
                                                        data_read = {}
                                                        if "data" in data_mb_read:
                                                            data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                            data_command = m1_class.convert_data(data_read,rgl_model,int(reg))
                                                            cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                    if act_keys.lower() == 'write':
                                                        # print(f'==={act_values}')
                                                        # print(f'==={info.get("multiply")}')
                                                        # print(f'==={int(float(act_values)/float(info.get("multiply")))}')
                                                        cma_data_dst = round(float(act_values)/float(info.get("multiply")))
                                                        verify = _write_modbus_verified("tcp", instrument, reg, cma_data_dst, retries=3)
                                                        if verify["success"]:
                                                            print(f"== Verified TCP write {cma_devicesn}:{cma_para} raw={verify['actual_raw']} attempts={verify['attempts']}")
                                                            cma_status_arr[cma_devicesn] = 1
                                                            if cma_para == "p_max":
                                                                _persist_last_pmax(cma_devicesn, act_values)
                                                            data_mb_read = m1_class.readmodbustcp(instrument,int(reg),int(2),3)
                                                            data_read = {}
                                                            if "data" in data_mb_read:
                                                                data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                                data_command = m1_class.convert_data(data_read,rgl_model,int(reg))
                                                                if cma_para in data_command:
                                                                    cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                        else:
                                                            print(f"== TCP write verification failed {cma_devicesn}:{cma_para}: {verify['error']}")
                                                            cma_status_arr[cma_devicesn] = 0
                                                        # data_mb_read = readmodbustcp(instrument,int(reg),int(2),3)
                                                        # data_read = {}
                                                        # if "data" in data_mb_read:
                                                        #     data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                        #     data_command = convert_data(data_read,rgl_model,int(reg))
                                                        #     cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                        
                                                    
                                        # print(f'==connect by {cma_mode} : == {cma_ip}:{cma_port}//[{cma_deviceID}]====Data: [{cma_data_arr}]')
                                        print(f'=============================================================================')
                                    time.sleep(1)
                            
                                if cma_mode.lower() == 'rtu':
                                    cma_devid = int(devicelist[cma_devicesn]['deviceID'])
                                    cma_baurdrate = int(devicelist[cma_devicesn]['baurdrate'])
                                    rgl_model_all = registerlist.get(cma_model, {})
                                    # print(f'====={rgl_model}')
                                    print(f'==connect by {cma_mode} : == {cma_id}:{cma_baurdrate}')
                                    instrumentrtu = m1_class.rtuconfig(cma_devid,cma_baurdrate,PT=serial.PARITY_NONE,timeouts=1)
                                    if 1:
                                        # print(f'==== TEST222222222222222222222222 {cma_id} {resCMA}')
                                        
                                        for  cma_para in resCMA[cma_id][cma_act][cma_devicesn]:
                                            print(f'==== TEST222222222222222222222222')
                                            operation = resCMA[cma_id][cma_act][cma_devicesn][cma_para]
                                            if not isinstance(operation, dict) or not operation:
                                                print(f"== MQTT command SKIP {cma_devicesn}:{cma_para}: no operation/write command")
                                                continue
                                            act_keys, act_values = next(iter(operation.items()))
                                            act_name = str(act_keys).lower()
                                            if act_name not in ('write', 'read', 'realtime'):
                                                print(f"== MQTT command SKIP {cma_devicesn}:{cma_para}: unsupported operation={act_keys}")
                                                continue
                                            fn_name = 'read_input_rtu' if act_name in ('read', 'realtime') and int(act_values) == 4 else 'read_holding_rtu'
                                            rgl_model = m1_class.get_register_map(rgl_model_all, fn_name)
                                            matched_registers = [
                                                (reg, info) for reg, info in rgl_model.items()
                                                if isinstance(info, dict) and info.get("name") == cma_para
                                            ]
                                            if not matched_registers:
                                                print(
                                                    f"== MQTT command SKIP {cma_devicesn}:{cma_para}: "
                                                    f"no {fn_name} register mapping"
                                                )
                                                continue
                                            for reg, info in matched_registers:
                                                    # print(f'Para is : {act_keys}')
                                                    # print(f'Para is : {reg}')
                                                    # resCMA[cma_id][cma_act][cma_devicesn]
                                                    
                                                    if not cma_devicesn in cma_data_arr:
                                                        cma_data_arr[cma_devicesn] = {}
                                                    if not cma_para in cma_data_arr[cma_devicesn]:
                                                        cma_data_arr[cma_devicesn][cma_para] = {}
                                                        
                                                        
                                                    # For test realtime
                                                    if act_keys.lower() == 'realtime':
                                                        data_mb_read = m1_class.readmodbusrtu(instrumentrtu,int(reg),int(2),3)
                                                        data_read = {}
                                                        if "data" in data_mb_read:
                                                            cma_status_sendRT = 1
                                                            data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                            data_command = m1_class.convert_data(data_read,rgl_model,int(reg))
                                                            cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                            # print(f'=====EX== {cma_data_arr}')
                                                            # a1 = {}
                                                            # a1["mode"] = "status"
                                                            # a1["device_SN"] = m1_class.deviceSN
                                                            # a1["data"] = cma_data_arr
                                                            # a1["ts"] = int(time.time())
                                                            # a1["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")
                                                            # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                                            # mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                                            # mqttc3.publish("res/"+m1_class.deviceSN, json.dumps(a1))
                                                            
                                                            
                                                    if act_keys.lower() == 'read':
                                                        data_mb_read = m1_class.readmodbusrtu(instrumentrtu,int(reg),int(2),3)
                                                        data_read = {}
                                                        if "data" in data_mb_read:
                                                            data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                            data_command = m1_class.convert_data(data_read,rgl_model,int(reg))
                                                            cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                    if act_keys.lower() == 'write':
                                                        # print(f'==={act_values}')
                                                        # print(f'==={info.get("multiply")}')
                                                        # print(f'==={int(float(act_values)/float(info.get("multiply")))}')
                                                        cma_data_dst = round(float(act_values)/float(info.get("multiply")))
                                                        verify = _write_modbus_verified("rtu", instrumentrtu, reg, cma_data_dst, retries=3)
                                                        if verify["success"]:
                                                            print(f"== Verified RTU write {cma_devicesn}:{cma_para} raw={verify['actual_raw']} attempts={verify['attempts']}")
                                                            cma_status_arr[cma_devicesn] = 1
                                                            if cma_para == "p_max":
                                                                _persist_last_pmax(cma_devicesn, act_values)
                                                            data_mb_read = m1_class.readmodbusrtu(instrumentrtu,int(reg),int(2),3)
                                                            data_read = {}
                                                            if "data" in data_mb_read:
                                                                data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                                data_command = m1_class.convert_data(data_read,rgl_model,int(reg))
                                                                if cma_para in data_command:
                                                                    cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                        else:
                                                            print(f"== RTU write verification failed {cma_devicesn}:{cma_para}: {verify['error']}")
                                                            cma_status_arr[cma_devicesn] = 0
                                                        # data_mb_read = readmodbustcp(instrument,int(reg),int(2),3)
                                                        # data_read = {}
                                                        # if "data" in data_mb_read:
                                                        #     data_read = json.loads(data_mb_read['data']) if m1_class.is_json(data_mb_read['data']) else ast.literal_eval(data_mb_read['data'])
                                                        #     data_command = convert_data(data_read,rgl_model,int(reg))
                                                        #     cma_data_arr[cma_devicesn][cma_para] = data_command[cma_para]
                                                        
                                                    
                                        # print(f'==connect by {cma_mode} : == {cma_ip}:{cma_port}//[{cma_deviceID}]====Data: [{cma_data_arr}]')
                                        print(f'=============================================================================')
                                    time.sleep(1)
                                    
                            elif cma_devicesn == "para":
                                # print(f'====para: {resCMA[cma_id][cma_act][cma_devicesn]}')
                                for cma_para in resCMA[cma_id][cma_act][cma_devicesn]:
                                    if "write" in resCMA[cma_id][cma_act][cma_devicesn][cma_para]:
                                        val_para = resCMA[cma_id][cma_act][cma_devicesn][cma_para]["write"]
                                        # print(f'====para2: {type(para_arr)}')
                                        temp = para_arr['para']
                                        temp['para1'] = val_para
                                        para_arr['para'] = temp
                        data_device_cmd = {}
                        if not cma_id in data_device_cmd:
                            data_device_cmd[cma_id] = {}
                            
                        # if cma_data_arr != {}:
                        #     data_device_cmd[cma_id] = cma_data_arr
                        
                        msg_txt = {}
                        # print(f'Send ====HTTP ==== {json.dumps(a1)}')
                        print(f'Send ====HTTP ==== {cma_data_arr}')
                        if cma_id in data_device_cmd:
                            msg_txt['mode'] = cma_act
                            msg_txt['result'] = cma_status_arr
                            msg_txt['text'] = resCMA[cma_id][cma_act] if cma_act != 'cmd' else cma_data_arr
                            if cma_status_sendRT == 1:
                                cma_status_sendRT = 0
                                a1 = {}
                                a1["mode"] = "status"
                                a1["device_SN"] = m1_class.deviceSN
                                a1["data"] = cma_data_arr
                                a1["ts"] = int(time.time())
                                a1["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")
                                mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                mqttc3.publish("res/"+m1_class.deviceSN, json.dumps(a1))
                            
                            # res_http = http_send('reply',  data_device_cmd[cma_id], cmd_id=cma_id,timeout=3,g_cne=g_cne.value)
                            res_http = m1_class.http_send('reply',  msg_txt, cmd_id=cma_id,timeout=3,g_cne=g_cne.value)
                            if res_http[0]:
                                print(f'=== Send Http success {res_http[1]}')
                            _record_mqtt_command(
                                cma_id,
                                resCMA[cma_id][cma_act],
                                cma_status_arr,
                                cma_data_arr,
                                m_arr
                            )
                        # print(f'end check : {resCMA}')
                        # if cma_id in resCMA:
                        #     del resCMA[cma_id]
                        # m1_class.write_file('cma.json',json.dumps(resCMA),'w')
                        print(f'===========================cccc485=================================================')          
                    if cma_act == "restart":
                        print(cma_act)
                        
                    print(f'end check : {resCMA}')
                    if cma_id in resCMA:
                        del resCMA[cma_id]
                    m1_class.write_file('cma.json',json.dumps(resCMA),'w')
        except Exception as e:
            print(f'===={e}')   
            pass
        time.sleep(1)
    
def Process_mqtt(ts_cmd,time_hb,g_cne):
    while True:

        try:

            mqttc = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)

            mqttc.on_connect = m1_class.on_connect
            mqttc.on_message = m1_class.on_message

            print(f"[MQTT] Connecting to {m1_class.mqttBroker}:{m1_class.mqttPort}")

            mqttc.connect(
                m1_class.mqttBroker,
                int(m1_class.mqttPort),
                30
            )

            # network loop (blocking)
            mqttc.loop_forever()

        except Exception as e:

            print(f"[MQTT ERROR] {e}")

            # wait before reconnect
            time.sleep(5)

def Process_checkws(ts_cmd,time_hb,g_cne):
    # global ts_cmd
    # global g_cne
    # global mqttc
    # global last_send2api
    # print(f'===HB Time: {time_hb.value}')
    print(f'===g_cne: {g_cne.value}')
    # time_count_1s = 0
    ts_cmd.value = int(time.time())
    # last_action = time.time()
    # time_hb = 0
    last_send2api = 0
    debug_ = False
    while True:
        try:
                
            # print("==time_hb",time_hb)
            # now = time.time()
            # if now - last_action >= 1:
            #     print("==diff time ==="+str(int(time.time()))+" - "+str(ts_cmd.value)+" = "+str(int(int(time.time())-int(ts_cmd.value))))
            #     last_action = now
            time_now = int(time.time())
            if time_now - time_hb.value >= 60:
                print("==time_hb",time_hb.value)
                
                time_hb.value = int(time.time())
                msg_txt = {}
                msg_txt['ip'] = m1_class.get_ipv4_from_nic("eth0")
                msg_txt['ipvpn'] = m1_class.get_ipv4_from_nic("tun0")
                
                m1_class.http_send('check_ws', msg_input=msg_txt, cmd_id="")
                # a1 = {}
                # a1["mode"] = "check_ws"
                # a1["device_SN"] = deviceSN
                # a1["data"] = ""
                

                
                # a1["ip"] = m1_class.get_ipv4_from_nic("eth0")
                # a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")

                # ts_e = int(time.time())
                # a1["ts"] = ts_e

                # # print(a1)
                # diff_cmd = int(int(time.time())-int(ts_cmd.value))
                # print("==diff time ==="+str(int(time.time()))+" - "+str(ts_cmd.value)+" = "+str(diff_cmd))
                # print(a1)
                
                # httpSend = requests.post("http://api.mdbiot.com/vx_mdbiot_api.php"+"?device_sn="+deviceSN,data={"mode":"WSDevice","WSData": json.dumps(a1)},headers={"User-Agent": "INNO/2025","cne":g_cne.value},verify=False,timeout=3)
                # # if httpSend.status_code == 200:
                # if debug_ == True:
                #     print("[{}][{}][http][check_ws] >> server | {}".format(m1_class.get_current(),deviceSN,json.dumps(a1)))
                # if httpSend.status_code == 200:
                #     # print("===httpSend=="+str(httpSend.text))
                #     g_cne_arr = json.loads(httpSend.text) if m1_class.is_json(httpSend.text) else {}
                #     # if g_cne_arr != {}:
                #         # ms_minus = int(g_cne_arr['ms_minus']) if 'ms_minus' in g_cne_arr else 0
                #         # g_cne = g_cne_arr['cne'] if 'cne' in g_cne_arr else ''
                #         # sec_send = g_cne_arr['sec_send'] if 'sec_send' in g_cne_arr else ''
                #         # print("====================================================================")
                #         # print("===g_cne=="+str(g_cne))
                #         # print("===ms_minus=="+str(ms_minus))
                #         # print("===sec_send=="+str(sec_send))
                #         # print("====================================================================")
                #     if debug_ == True:
                        
                #         print("[{}][{}][http][check_ws] << server | {}".format(m1_class.get_current(),deviceSN,httpSend.text))

                    
                # if diff_cmd > 120 and diff_cmd != int(time.time()):
                #     ts_cmd.value = int(time.time())
                #     # os.system("pm2 restart all")

            # if time.time() - ts_cmd > 120:
            #     # os.system("pm2 restart all")
            #     print("pm2 restart all")
            # hh()
            # time.sleep(2)
        except Exception as e:
            print(e)
        time.sleep(1)

def Process_defaultlist(resDFL,lasttime_log5,lasttime_clear_log,last_clear_log5_data,last_clear_log_event):
    # global devicelist,registerlist
    # print(f'core 04 : {resDFL}')
    while True:
        try:

            for dfl_id in resDFL:
                # print(f'core 04 [{dfl_id}]: {resDFL[dfl_id]}')
                for dfl_act in resDFL[dfl_id]:
                    
                    # print(f'core 04 [{dfl_id}][{dfl_act}]: {resDFL[dfl_id][dfl_act]}')
                    if dfl_act == "updatelog":
                        start_t = int(time.time())
                        
                        cml_arr = resDFL[dfl_id][dfl_act]
                        log_dir = os.path.dirname(__file__)
                        logdirNames = os.path.join(log_dir, "log_action/"+str(m1_class.deviceSN)+"/")
                        time_interval = cml_arr['time_interval'] if "time_interval" in cml_arr else 1
                        time_offset = cml_arr['time_offset'] if "time_offset" in cml_arr else 0
                        # print(f'==={time_interval} ==== {time_offset} ==== {lasttime_log5.value}')
                        if lasttime_log5.value == 0:
                            lasttime_log5.value = int(int(time.time()/time_interval)*time_interval) + time_interval + time_offset
                            print("updatelog===Next Time {}".format(str(datetime.fromtimestamp(lasttime_log5.value))))
                            # print("time log5",lasttime_log5)
                        elif int(start_t) > int(lasttime_log5.value):
                            # print(lasttime_log5)
                            cmd_id_dev = "M"+str(int(time.time()*1000000))
                            # log_device(mlog_device+"|OK","device","a")

                            lasttime_log5_str = datetime.fromtimestamp(lasttime_log5.value)
                            time_log5_date = lasttime_log5_str.strftime("%Y%m%d%H%M")
                            time_log5_date_22 = lasttime_log5_str.strftime("%Y%m%d")

                            ts_ep_log5 = int(int(time.time())/300)*300
                            time_ep_log5 = datetime.fromtimestamp(ts_ep_log5)
                            time_ep_log5_date = time_ep_log5.strftime("%Y%m%d%H%M")
                            time_ep_log5_date_tt = time_ep_log5.strftime("%Y%m%d")
                            # print("2=Next Time ")
                            lasttime_log5.value = int(int(time.time()/time_interval)*time_interval) + time_interval + time_offset
                            # Maintenance is daily and non-blocking.  It replaces the
                            # legacy five-minute ZIP loop that fragmented each log.
                            maintain_log_action(os.path.join(os.path.dirname(__file__), "log_action"))
                
                    if dfl_act == "clearlog5":
                        start_t = int(time.time())
                        cml_arr = resDFL[dfl_id][dfl_act]
                        path_err = os.path.dirname(__file__)+'/dlog/'+m1_class.deviceSN
                        res_cl5 = m1_class.clear_log5_data(path_err,cml_arr,last_clear_log5_data.value)
                        if res_cl5[0]:
                            last_clear_log5_data.value = res_cl5[1]
                            # print(f'clear_log5_data==[{last_clear_log5_data.value}] === {cml_arr}')
                        # else:
                        #     print(f'clear_log5_data==[{last_clear_log5_data.value}] === XXXX')
                            
                            # log_device("dlog|OK","device","a")
                        
                    if dfl_act == "clearlogevent":
                        start_t = int(time.time())
                        cml_arr = resDFL[dfl_id][dfl_act]
                        path_err = os.path.dirname(__file__)+'/log_err/'+m1_class.deviceSN
                        res_clerr = m1_class.clear_log_event(path_err,cml_arr,last_clear_log_event.value)
                        if res_clerr[0]:
                            last_clear_log_event.value = res_clerr[1]
                            print(f'clear_log_event==[{last_clear_log_event.value}] === {cml_arr}')
                        # else:
                        #     print(f'clear_log5_data==[{last_clear_log5_data.value}] === XXXX')
                            
                            # log_device("dlog|OK","device","a")
                    
                    if dfl_act == "checkinternet":
                        cml_arr = resDFL[dfl_id][dfl_act]
                        # m1_class.check_internet()   
                    if dfl_act == "clearlogfile":
                        start_t = int(time.time())
                        cml_arr = resDFL[dfl_id][dfl_act]
                        path_logs = os.path.dirname(__file__)+'/log_action/'+m1_class.deviceSN
                        # print(f'====Clear log file // {path_logs}')
                        # print("========================================xxxxxx=========")
                        # res_clf = clear_log_file(cml_arr,lasttime_clear_log.value)
                        res_clf = m1_class.clear_log_file(path_logs,cml_arr,lasttime_clear_log.value)
                        if res_clf[0]:
                            lasttime_clear_log.value = res_clf[1]
                            print("clearlogfile=======",res_clf[1])
                
        except Exception as e:
            print(f'Except process4 is {e}')
        time.sleep(1)
  
def Process_automation(automation_arr, automation_revision, para_arr, gateway_locks):
    """Run Automation independently from the meter polling lifecycle.

    Meter workers publish each completed Modbus block immediately.  Automation
    consumes the latest persistent snapshot when its revision changes, so a
    slow/offline gateway cannot delay control decisions for healthy devices.
    """
    automation_m1 = main_m1()
    last_revision = -1
    disabled_notice_shown = False
    while True:
        try:
            revision = automation_revision.value
            if revision == last_revision:
                time.sleep(0.1)
                continue

            # Briefly debounce adjacent register blocks from the same polling pass.
            time.sleep(0.15)
            revision = automation_revision.value
            snapshot = list(dict(automation_arr).values())
            if not snapshot:
                last_revision = revision
                time.sleep(0.1)
                continue

            web_root = '/var/www/html/meow'
            automation_config_text = automation_m1.read_file(
                f'{web_root}/config/automation_control.json'
            )
            automation_config = (
                json.loads(automation_config_text)
                if automation_m1.is_json(automation_config_text)
                else {}
            )
            automation_enabled = bool(automation_config.get('enabled', False))
            simulation_enabled = bool(
                automation_config.get('simulation_log_enabled', False)
            )
            if not automation_enabled and not simulation_enabled:
                if not disabled_notice_shown:
                    print('Automation controller is OFF; meter polling only')
                    disabled_notice_shown = True
                last_revision = revision
                time.sleep(0.25)
                continue
            disabled_notice_shown = False
            automation_input = {
                'devicelist': automation_m1.read_file(f'{web_root}/config/dvl.json'),
                'registerlist': automation_m1.read_file(f'{web_root}/config/rgl.json'),
                'resCML': automation_m1.read_file(f'{web_root}/config/cml.json'),
                'automationconfig': automation_config,
                'start_time': int(time.time()),
                'para_arr': para_arr,
                'gateway_locks': gateway_locks,
            }
            if simulation_enabled:
                # Simulation always takes precedence over the Automation master
                # switch. Automation may be ON so every rule is evaluated, but
                # the Modbus write boundary in py_function_custom1 stays blocked.
                automation_input['automationconfig'] = dict(automation_config)
                automation_input['automationconfig']['_dry_run'] = True
                output = StringIO()
                with redirect_stdout(output):
                    m1_custom1(automation_input)._automation(snapshot)
                calculation_text = output.getvalue()
                if calculation_text:
                    print(calculation_text, end='')
                # Intermediate register revisions are rejected by the control
                # interval. Save only complete State evaluations.
                if re.search(r'STATE\s+\d+\s+\[', calculation_text):
                    path = record_automation_simulation(
                        snapshot, automation_config, calculation_text
                    )
                    if path:
                        print(f'Automation simulation snapshot saved: {path}')
                    comparison_count = record_simulated_battery_commands(
                        snapshot, automation_config, calculation_text
                    )
                    if comparison_count:
                        print(
                            f'Automation Battery comparison log: '
                            f'{comparison_count} command(s) saved'
                        )
            else:
                m1_custom1(automation_input)._automation(snapshot)
            last_revision = revision
        except Exception as error:
            print(f'Automation process error: {error}', file=sys.stderr)
            traceback.print_exc(file=sys.stderr)
            time.sleep(1)
if __name__ == "__main__":
    # Workers rely on globals created below (m1_class, ...), which only works
    # with fork.  Python 3.14 changed the Linux default to forkserver.
    multiprocessing.set_start_method("fork")

    # ////////////////////// Setup //////////////////////
    GPIO.setwarnings(False)
    GPIO.setmode(GPIO.BCM)  # Use BCM pin numbering
    GPIO.setup(17, GPIO.IN)  # Set +11,-09 ,DI0
    GPIO.setup(27, GPIO.IN)  # Set +15,-13 ,DI1

    GPIO.setup(23, GPIO.OUT) 
    GPIO.setup(24, GPIO.OUT) 
    
    # ////////////////////// init class //////////////////////
    m1_class            = main_m1()
    m1_class_custom1    = m1_custom1()
    # m1_class_2 = main_m1()
            
    # ////////////////////// key encrypt aes-128 //////////////////////
    key             = "inno2024"
    iv              = ""
    # ////////////// Path config & webserver //////////////////
    path_fileconfig = '/var/www/html/meow'
    # ////////////////////// Variable start //////////////////
    path_def = f'{path_fileconfig}/data/mcf_default.json'
    main_txt = m1_class.read_file(path_def)
    main_txt_arr    = json.loads(main_txt) if m1_class.is_json(main_txt) else {}
    # ////////////////////// custom set variable //////////////////////
    main_txt_arr['status_vpn']  = 0
    main_txt_arr['path_def']    = path_def
    main_txt_arr['time_counter'] = int(time.time())
    main_txt_arr['g_cne'] = 'cVpWUUl6ZGR1QUUxRy9XL1lWYWpiN1lPSUIzZE9YUmRHdTJtT1ZqK2c5bz0='
    # ////////////////////// use class //////////////////////
    m1_class = main_m1(main_txt_arr)
    m1_class.show()
    
    # q = Queue()
    lock = Lock()
    manager = Manager()
    
    # //////////////////////////////////////////////////////////////////////////////////////////////

    devicelist = m1_class.read_file(f'{path_fileconfig}/config/dvl.json')
    registerlist = m1_class.read_file(f'{path_fileconfig}/config/rgl.json')
    resCML = m1_class.read_file(f'{path_fileconfig}/config/cml.json')
    resDFL = m1_class.read_file('dfl.json')
    resCMA = m1_class.read_file('cma.json')
    
    # lasttime_send_status = int(time.time())
    lasttime_send_status    = Value('d', 0)  # 'd' = double (float)
    lasttime_log5           = Value('i', 0)
    lasttime_clear_log      = Value('i', 0)
    last_clear_log5_data    = Value('i', 0)
    last_clear_log_event    = Value('i', 0)
    
    status_vpn = Value('i', 0)
    
    m_arr       = manager.dict()
    automation_arr = manager.dict()
    automation_revision = Value('i', 0)
    gateway_locks = manager.dict()
    global_arr  = manager.dict()
    para_arr    = manager.dict({"para":{"para1": "0"}})
    
    ts_cmd  = Value('i', 0)
    time_hb = Value('i', 0)
    g_cne   = Value(c_char_p, b"cVpWUUl6ZGR1QUUxRy9XL1lWYWpiN1lPSUIzZE9YUmRHdTJtT1ZqK2c5bz0=")
    main_heartbeat = Value('d', time.monotonic())
    main_stall_limit = Value('d', HEALTH_MIN_MAIN_STALL)
    
    #  ////////////////////// array list //////////////////////
    actionlist_arr  = json.loads(resCMA) if m1_class.is_json(resCMA) else {}
    defaultlist_arr = json.loads(resDFL) if m1_class.is_json(resDFL) else {}
    devicelist_arr  = json.loads(devicelist) if m1_class.is_json(devicelist) else {}
    registerlist_arr = json.loads(registerlist) if m1_class.is_json(registerlist) else {}
    # ////////////////////// Process all //////////////////////
    p1 = Process(target=Process_CMA, args=(actionlist_arr,devicelist_arr,registerlist_arr,lasttime_send_status,m_arr,g_cne,para_arr,), name='cma-command')
    p1.start()
   
    p2 = Process(target=Process_mqtt, args=(ts_cmd,time_hb,g_cne,), name='mqtt-client')
    p2.start()
    
    p3 = Process(target=Process_checkws, args=(ts_cmd,time_hb,g_cne,), name='server-heartbeat')
    p3.start()

    # Dedicated local scheduler: independent from the slower meter polling cycle.
    # It never uses MQTT/http reply paths and checks due slots four times per second.
    command_scheduler = Process(target=command_schedule_loop, name='command-scheduler')
    command_scheduler.start()
   
    # Default list command 
    p4 = Process(target=Process_defaultlist, args=(defaultlist_arr,lasttime_log5,lasttime_clear_log,last_clear_log5_data,last_clear_log_event,), name='default-maintenance')
    p4.start()

    automation_process = Process(
        target=Process_automation,
        args=(automation_arr, automation_revision, para_arr, gateway_locks),
        name='automation-controller'
    )
    automation_process.start()

    # One dedicated process owns the BACnet/IP socket and publishes readings
    # into the same shared snapshots used by Modbus workers.
    bacnet_process = Process(
        target=run_bacnet_collector,
        args=(m_arr, automation_arr, automation_revision),
        name='bacnet-collector'
    )
    bacnet_process.start()

    critical_processes = [
        ('cma', p1),
        ('mqtt', p2),
        ('server_heartbeat', p3),
        ('command_scheduler', command_scheduler),
        ('default_maintenance', p4),
        ('automation', automation_process),
        ('bacnet', bacnet_process),
    ]
    start_health_watchdog(
        critical_processes,
        main_heartbeat,
        main_stall_limit,
        error_logger=lambda reason: m1_class.log_error(
            f"system_health_watchdog|restart|{reason}", "event", "a"
        ),
    )
    
    while True:
        try:
            main_heartbeat.value = time.monotonic()
            process_manual_command_queue()
            devicelist = m1_class.read_file(f'{path_fileconfig}/config/dvl.json')
            # print(f'len : {len(json.loads(devicelist))}')
            registerlist = m1_class.read_file(f'{path_fileconfig}/config/rgl.json')
            resCML = m1_class.read_file(f'{path_fileconfig}/config/cml.json')
            # /////////////////////// array list ///////////////////////
            devicelist_arr = json.loads(devicelist) if m1_class.is_json(devicelist) else {}
            commandlist_raw = json.loads(resCML) if m1_class.is_json(resCML) else {}
            commandlist_arr = normalise_command_list(commandlist_raw)
            registerlist_arr = json.loads(registerlist) if m1_class.is_json(registerlist) else {}
            if not isinstance(devicelist_arr, dict):
                print(f'Invalid dvl.json root type: {type(devicelist_arr).__name__}; cycle skipped', file=sys.stderr)
                time.sleep(2)
                continue
            if not isinstance(registerlist_arr, dict):
                print(f'Invalid rgl.json root type: {type(registerlist_arr).__name__}; cycle skipped', file=sys.stderr)
                time.sleep(2)
                continue

            # One lock per TCP gateway: downstream meters queue on the same
            # serial bus, while different gateways can still run in parallel.
            for device in devicelist_arr.values():
                if not isinstance(device, dict):
                    continue
                if str(device.get('mode', '')).lower() not in ('tcp', 'modbus_tcp'):
                    continue
                ip_address = str(device.get('ipaddress', '')).strip()
                if not ip_address:
                    continue
                try:
                    tcp_port = int(device.get('port', 502) or 502)
                except (TypeError, ValueError):
                    tcp_port = 502
                gateway_key = f'{ip_address}:{tcp_port}'
                if gateway_key not in gateway_locks:
                    gateway_locks[gateway_key] = manager.Lock()
            if not commandlist_arr:
                print(
                    f'Invalid or empty cml.json command list '
                    f'(root type: {type(commandlist_raw).__name__}); cycle skipped',
                    file=sys.stderr
                )
                time.sleep(2)
                continue
            # ////////////////////////////////////////////////////////////////////////////////////////////////////////
            start_time = int(time.time())
            # print(devicelist_arr)
            # print(commandlist_arr)
            
            # /////////////////// �����ǹ�ٻ����ӹǹ device �ͧ�ش����觷���ͧ��� ///////////////////
            unique_meter_ids = list({
                meter
                for session in commandlist_arr.values() if isinstance(session, dict)
                for cmd in session.values() if isinstance(cmd, dict)
                for meter in cmd
                if meter in devicelist_arr
                and str(devicelist_arr[meter].get('mode', '')).lower() != 'bacnet'
            })
            # Allow slow/shared gateways to complete naturally.  This adaptive
            # health ceiling only catches a truly stuck main loop; it is not a
            # Modbus transaction or shared round timeout.
            main_stall_limit.value = adaptive_stall_limit(len(unique_meter_ids))
            main_heartbeat.value = time.monotonic()
            print(f'==== meter_ids: {unique_meter_ids}')
            processes = []

            for k,dvl in enumerate(unique_meter_ids):

                if dvl not in devicelist_arr:
                    continue

                p = Process(
                    target=worker,
                     args=(lock,k,devicelist_arr[dvl],commandlist_arr,dvl,
                         registerlist_arr,lasttime_send_status,
                         m_arr,g_cne,para_arr,global_arr,manager,
                         gateway_locks,automation_arr,automation_revision,)
                )

                p.start()
                processes.append((dvl, p))


            # Each Modbus transaction owns its protocol timeout.  Do not apply a
            # shared round deadline: waiting for a gateway/RTU queue is normal.
            for dvl, p in processes:
                p.join()
                p.close()
            main_heartbeat.value = time.monotonic()
            
            
            # ////////////////////////////////////////////////////////////////////////////////////////////////////////
            # Automation is handled by Process_automation from its independent
            # snapshot; meter polling never waits for an Automation cycle.
            
            # a1 = {}
            # a1["mode"] = "status"
            # a1["device_SN"] = m1_class.deviceSN
            # a1["data"] = m_arr.values()
            # a1["ts"] = int(time.time())
            # a1["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")
            # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
            # mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
            # mqttc3.publish("res/"+m1_class.deviceSN, json.dumps(a1))
            # ////////////////////////////////////////////////////////////////////////////////////////////////////////
            print('==================================================================')
            print(f'All workers finished is [{str(int(time.time())-start_time)}s]')
            print(f'=== watchdog timmer : {time.time()}')
            
            
            time.sleep(1)
        except Exception as e:
            print(f'Error as {e}', file=sys.stderr)
            traceback.print_exc(file=sys.stderr)
            if getattr(e, 'errno', None) == 24:
                # os.system("sudo ulimit -n 4096")
                # os.system("")
                print(e)
            # else:
            #     raise  # re-raise other OS errors
            
            # Log the error (PM2 reads stderr)
            # print("[ERROR] Unhandled exception:", file=sys.stderr)
            # print(traceback.format_exc(), file=sys.stderr)
            
            # Optional: slow down restart loop to avoid 100% CPU
            time.sleep(2)
            # Exit with non-zero  PM2 restarts the app
            # sys.exit(1)
            
        # finally:
        #     # Cleanup GPIO pins
        #     GPIO.cleanup()
            
    
    
# ///////// NOTE //////////////////
# pm2 start main.py \
#   --name meter_app \
#   --interpreter python3 \
#   --restart-delay 2000 \
#   --max-memory-restart 200M \
#   --max-restarts 20
