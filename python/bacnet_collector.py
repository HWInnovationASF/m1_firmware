import threading 
import warnings 
from bacpypes.core import run
from bacpypes.pdu import Address 
from bacpypes.app import BIPSimpleApplication 
from bacpypes.local.device import LocalDeviceObject 
from bacpypes.apdu import (
    ReadPropertyRequest, ReadPropertyACK,
    ReadPropertyMultipleRequest, ReadPropertyMultipleACK,
    ReadAccessSpecification, PropertyReference,
)
from bacpypes.iocb import IOCB 
from bacpypes.primitivedata import Real, Unsigned, Enumerated 
import socket
import json
import copy
import time
from datetime import datetime
import requests
import os
from concurrent.futures import ThreadPoolExecutor
from multiprocessing import Process, Value
from pathlib import Path

requests.packages.urllib3.disable_warnings()

# ===== CONFIG =====
MAX_THREADS = 8   # 🔥 ปรับได้
DEFAULT_DEVICE_SN = "M117718143259745"
DEFAULT_HTTP_SERVER = "http://api.mdbiot.com/vx_mdbiot_api.php"
DEVICE_CONFIG_PATH = Path(os.environ.get("MDBCARE_DEVICE_CONFIG", "/var/www/html/meow/data/mcf_default.json"))


def load_device_config():
    """Read deviceSN/httpServer from the shared device config used by py_multi."""
    try:
        with open(DEVICE_CONFIG_PATH, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError) as error:
        print(f"[BACnet] Cannot read {DEVICE_CONFIG_PATH}: {error}; using default device_SN")
        return DEFAULT_DEVICE_SN, DEFAULT_HTTP_SERVER
    sn = str(cfg.get("deviceSN") or "").strip() or DEFAULT_DEVICE_SN
    server = str(cfg.get("httpServer") or "").strip() or DEFAULT_HTTP_SERVER
    return sn, server


device_SN, http_server = load_device_config()
BASE_DIR = Path(__file__).resolve().parent
WEB_CONFIG_DIR = Path(os.environ.get("MDBCARE_WEB_CONFIG", "/var/www/html/meow/config"))
LIVE_CACHE_PATH = Path(os.environ.get("MDBCARE_BACNET_CACHE", "/var/www/html/meow/data/bacnet_latest.json"))
BACNET_TIMEOUT = 3
HTTP_RETRY_SECONDS = 10
BACNET_STALL_TIMEOUT = max(30, int(os.environ.get("MDBCARE_BACNET_STALL_TIMEOUT", "120")))
BACNET_RESTART_DELAY = max(1, int(os.environ.get("MDBCARE_BACNET_RESTART_DELAY", "5")))
BACNET_NO_SUCCESS_LIMIT = max(1, int(os.environ.get("MDBCARE_BACNET_NO_SUCCESS_LIMIT", "3")))
BACNET_TARGET_FAIL_LIMIT = max(1, int(os.environ.get("MDBCARE_BACNET_TARGET_FAIL_LIMIT", "2")))
BACNET_NETWORK_WAIT = 5
# ReadPropertyMultiple: objects per request, and how long to fall back to
# single ReadProperty after a target rejects RPM.
BACNET_RPM_CHUNK = max(1, int(os.environ.get("MDBCARE_BACNET_RPM_CHUNK", "32")))
BACNET_RPM_RETRY_SECONDS = 600
# Start-to-start period of one BACnet read cycle (was "work + 1 s" ~ 3 s).
BACNET_POLL_INTERVAL = max(1.0, float(os.environ.get("MDBCARE_BACNET_POLL_INTERVAL", "3")))
# Re-publish unchanged data at least this often (Automation wake-up / live cache ts).
BACNET_PUBLISH_REFRESH = 30
BACNET_CACHE_REFRESH = 10
# 1 = print every device on every cycle (old behaviour); 0 = only changed devices.
BACNET_VERBOSE = os.environ.get("MDBCARE_BACNET_VERBOSE", "0") == "1"

start_time = int(time.time())
lasttime_send_status = 0
g_cne = ""

lock = threading.Lock()
last_value_lock = threading.Lock()
last_good_values = {}
last_device_values = {}


def touch_heartbeat(heartbeat):
    if heartbeat is None:
        return
    with heartbeat.get_lock():
        heartbeat.value = time.monotonic()

# ===== NETWORK =====
def get_local_ip():
    """Return the outbound IPv4 address, or None while no usable interface exists.

    Never fall back to a loopback address: binding BACnet to 127.x makes every
    read time out until the process is restarted.
    """
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.connect(("8.8.8.8", 80))
        ip = sock.getsockname()[0]
    except OSError:
        return None
    finally:
        sock.close()
    if not ip or ip.startswith("127.") or ip == "0.0.0.0":
        return None
    return ip


def wait_for_local_ip(heartbeat=None):
    """Block until the network has an IPv4 address, keeping the heartbeat alive."""
    last_log = 0
    while True:
        ip = get_local_ip()
        if ip:
            return ip
        if time.time() - last_log >= 30:
            print("[BACnet Network] no usable IPv4 address yet; waiting", flush=True)
            last_log = time.time()
        touch_heartbeat(heartbeat)
        time.sleep(BACNET_NETWORK_WAIT)


# ===== BACNET SETUP =====
this_app = None
local_ip = None

# Per-cycle reachability of BACnet targets (ip:port).  A target that fails
# BACNET_TARGET_FAIL_LIMIT reads without any success is skipped for the rest
# of the cycle so an offline controller does not cost one timeout per point.
target_lock = threading.Lock()
target_cycle_stats = {}
target_online = {}
rpm_disabled_until = {}
cycle_prefetch = {}       # {target: {(obj_type, obj_inst): (ok, value)}} for the current cycle

def bacnet_runner():
    try:
        print("BACnet thread start")
        run()
    except Exception as e:
        print("BACNET THREAD ERROR:", e)

def start_bacnet():
    """Create the BACnet client in the process that actually owns it.

    Keeping this out of module import is important when this collector is
    started by multiprocessing from py_multi.py.  Otherwise the parent and
    its children inherit competing UDP/47808 state.
    """
    global this_app, local_ip
    if this_app is not None:
        return this_app
    local_ip = get_local_ip()
    if not local_ip:
        raise RuntimeError("BACnet cannot start: no usable IPv4 address")
    print(f"[BACnet Network] binding to {local_ip}/24", flush=True)
    this_device = LocalDeviceObject(
        objectName="PythonClient",
        objectIdentifier=9001,
        vendorIdentifier=999,
    )
    this_app = BIPSimpleApplication(this_device, f"{local_ip}/24")
    threading.Thread(target=bacnet_runner, name="bacnet-core", daemon=True).start()
    time.sleep(0.2)
    return this_app

# with warnings.catch_warnings():
#     warnings.simplefilter(
#         "ignore",
#         UserWarning
#     )

#     threading.Thread(
#         target=run,
#         daemon=True
#     ).start()
# ===== UTIL =====
def is_json(wsJson):
    try:
        json.loads(wsJson)
        return True
    except:
        return False

def read_file(path):
    try:
        web_path = WEB_CONFIG_DIR / path
        source = web_path if web_path.is_file() else BASE_DIR / path
        with open(source, "r", encoding="utf-8") as f:
            return f.read()
    except (OSError, UnicodeError):
        return ""

def get_mtime(file):
    try:
        web_path = WEB_CONFIG_DIR / file
        source = web_path if web_path.is_file() else BASE_DIR / file
        return os.path.getmtime(source)
    except OSError:
        return 0

def get_current():
    return datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")

def replace_negative_infinity(value):
    """Convert -inf values to None so JSON serialization produces null."""
    if isinstance(value, dict):
        return {key: replace_negative_infinity(item) for key, item in value.items()}
    if isinstance(value, list):
        return [replace_negative_infinity(item) for item in value]
    if isinstance(value, float) and value == float("-inf"):
        return None
    if isinstance(value, str) and value.strip().lower() in {"-inf", "-infinity"}:
        return None
    return value

# ===== BACNET READ =====
def read_chiller_value(target_ip, obj_type, obj_inst):
    try:
        app = start_bacnet()
        request = ReadPropertyRequest(
            destination=Address(target_ip),
            objectIdentifier=(obj_type, obj_inst),
            propertyIdentifier="presentValue",
        )

        iocb = IOCB(request)
        app.request_io(iocb)
        # print(f"START {target_ip} {obj_type} {obj_inst}")
        iocb.wait(timeout=BACNET_TIMEOUT)
        # Timeout
        if iocb.ioError or not iocb.ioResponse:
            if not iocb.ioError and not iocb.ioResponse:
                # Abort the still-pending request; bacpypes serialises requests
                # per address, so an orphaned IOCB blocks every later read.
                try:
                    iocb.abort(TimeoutError("BACnet read timeout"))
                except Exception:
                    pass
            return False, 0
        # print(f"END   {target_ip} {obj_type} {obj_inst}")

        if iocb.ioResponse:
            apdu = iocb.ioResponse

            if isinstance(apdu, ReadPropertyACK):
                # A late answer to an aborted request can be matched to a new
                # request; never store one object's value under another.
                if tuple(apdu.objectIdentifier) != (obj_type, obj_inst):
                    return False, 0
                return True, cast_present_value(obj_type, apdu.propertyValue)

        return False, 0

    except Exception as e:
        return False, 0


def cast_present_value(obj_type, property_value):
    if 'analog' in obj_type:
        return property_value.cast_out(Real)
    elif 'binary' in obj_type:
        try:
            return property_value.cast_out(Enumerated)
        except:
            return property_value.cast_out(Unsigned)
    elif 'multiState' in obj_type:
        return property_value.cast_out(Unsigned)
    else:
        return property_value.cast_out(Real)


def rpm_enabled(target):
    with target_lock:
        return time.monotonic() >= rpm_disabled_until.get(target, 0)


def read_multiple_values(target, objects, heartbeat=None):
    """Read presentValue of many objects with ReadPropertyMultiple.

    Returns {(obj_type, obj_inst): (ok, value)}.  Objects missing from the
    result were not answered by RPM and must be read with read_chiller_value.
    """
    results = {}
    if not objects or not rpm_enabled(target):
        return results
    app = start_bacnet()
    for start in range(0, len(objects), BACNET_RPM_CHUNK):
        if target_unreachable(target):
            break
        chunk = objects[start:start + BACNET_RPM_CHUNK]
        request = ReadPropertyMultipleRequest(
            destination=Address(target),
            listOfReadAccessSpecs=[
                ReadAccessSpecification(
                    objectIdentifier=(obj_type, obj_inst),
                    listOfPropertyReferences=[PropertyReference(propertyIdentifier="presentValue")],
                )
                for obj_type, obj_inst in chunk
            ],
        )
        iocb = IOCB(request)
        try:
            app.request_io(iocb)
            iocb.wait(timeout=BACNET_TIMEOUT)
        except Exception:
            pass
        touch_heartbeat(heartbeat)

        if not iocb.ioResponse and not iocb.ioError:
            # Timeout: same handling as a single read.
            try:
                iocb.abort(TimeoutError("BACnet RPM timeout"))
            except Exception:
                pass
            record_target_read(target, False)
            for obj in chunk:
                results[obj] = (False, 0)
            continue

        if not isinstance(iocb.ioResponse, ReadPropertyMultipleACK):
            # Rejected/aborted (unsupported service, APDU too large, ...):
            # fall back to single reads for this target for a while.
            with target_lock:
                rpm_disabled_until[target] = time.monotonic() + BACNET_RPM_RETRY_SECONDS
            print(
                f"[BACnet RPM] {target} rejected ReadPropertyMultiple ({iocb.ioError}); "
                f"using single reads for {BACNET_RPM_RETRY_SECONDS}s",
                flush=True,
            )
            return results

        record_target_read(target, True)
        requested = set(chunk)
        for access_result in iocb.ioResponse.listOfReadAccessResults:
            obj = tuple(access_result.objectIdentifier)
            if obj not in requested:
                # Late answer to a different (aborted) request: ignore it.
                continue
            for element in access_result.listOfResults:
                read_result = element.readResult
                if read_result.propertyAccessError is not None:
                    results[obj] = (False, 0)
                    continue
                try:
                    results[obj] = (True, cast_present_value(obj[0], read_result.propertyValue))
                except Exception:
                    results[obj] = (False, 0)
        for obj in chunk:
            # Object omitted from an otherwise valid ACK: treat as failed read.
            results.setdefault(obj, (False, 0))
    return results


def target_unreachable(target):
    with target_lock:
        stats = target_cycle_stats.get(target)
        return bool(stats and stats["ok"] == 0 and stats["fail"] >= BACNET_TARGET_FAIL_LIMIT)


def record_target_read(target, ok):
    with target_lock:
        stats = target_cycle_stats.setdefault(target, {"ok": 0, "fail": 0})
        stats["ok" if ok else "fail"] += 1


def device_objects(dvl_sn):
    """(obj_type, obj_inst) configured for one device, in CML order."""
    model = dvl_air_arr.get(dvl_sn, {}).get("model")
    objects = []
    for cmd_id in cml_air_arr:
        for cmd_act in cml_air_arr[cmd_id]:
            if dvl_sn not in cml_air_arr[cmd_id][cmd_act]:
                continue
            model_registers = rgl_air_arr.get(model, {}).get(cmd_act, {})
            for l_arr in expand_bacnet_command(
                cml_air_arr[cmd_id][cmd_act].get(dvl_sn, []), model_registers
            ):
                if (cmd_act, l_arr) not in objects:
                    objects.append((cmd_act, l_arr))
    return objects


def prefetch_targets(devices, heartbeat=None):
    """Read all devices' objects per BACnet target with ReadPropertyMultiple.

    Many devices share one controller IP (e.g. 50 FCUs on one gateway), so
    batching per target instead of per device cuts requests ~4x.
    Returns {target: {(obj_type, obj_inst): (ok, value)}}.
    """
    by_target = {}
    for dvl_sn in devices:
        target = get_bacnet_target(dvl_air_arr.get(dvl_sn, {}))
        objects = by_target.setdefault(target, [])
        seen = set(objects)
        for obj in device_objects(dvl_sn):
            if obj not in seen:
                objects.append(obj)
                seen.add(obj)
    prefetched = {}
    with ThreadPoolExecutor(max_workers=MAX_THREADS) as executor:
        futures = {
            target: executor.submit(read_multiple_values, target, objects, heartbeat)
            for target, objects in by_target.items()
        }
        for target, future in futures.items():
            try:
                prefetched[target] = future.result()
            except Exception as error:
                print(f"[BACnet RPM] {target} prefetch error: {error}", flush=True)
                prefetched[target] = {}
    return prefetched


def report_target_status():
    """Log only transitions so a long outage does not flood the journal."""
    with target_lock:
        for target, stats in target_cycle_stats.items():
            online = stats["ok"] > 0
            if target_online.get(target) != online:
                state = "ONLINE" if online else "OFFLINE (cannot connect)"
                print(f"[BACnet Target] {target} {state}", flush=True)
                target_online[target] = online
        target_cycle_stats.clear()

# ===== LOAD CONFIG =====
last_dvl = last_rgl = last_cml = last_alarm = 0

last_focus = 0
focus_lines = []          # zx_focusk.json, re-read once per cycle when it changes

published_blocks = {}     # last block sent to py_multi per BACNET_<sn> key
last_cache_write = 0.0
last_cache_data = None
last_revision_bump = 0.0

dvl_air_arr = {}
rgl_air_arr = {}
cml_air_arr = {}
alarm_air_arr = {}


def expand_bacnet_command(command_values, register_map):
    """Return only the BACnet object instances explicitly saved in CML.

    BACnet has no Modbus range operation. Treating a two-item list as a range
    made a selection such as [1, 4] silently read objects 2 and 3 as well.
    Duplicate values (the legacy [object, object] shape) are collapsed.
    """
    if not isinstance(command_values, (list, tuple)):
        return []
    expanded = []
    for instance in command_values:
        try:
            instance_number = int(instance)
        except (TypeError, ValueError):
            continue
        if str(instance_number) in register_map and instance_number not in expanded:
            expanded.append(instance_number)
    return expanded


def get_bacnet_target(device):
    """Build a BACnet/IP destination from the web device configuration."""
    host = str(device.get("ipaddress") or device.get("ip") or "").strip()
    try:
        port = int(device.get("port", 47808) or 47808)
    except (TypeError, ValueError):
        port = 47808
    if port < 1 or port > 65535:
        port = 47808

    # Preserve an explicitly port-qualified address from legacy configs.
    if not host or ":" in host:
        return host
    return f"{host}:{port}"


# ===== THREAD WORKER =====
def process_device(dvl_sn, result_dict, heartbeat=None):
    try:
        zzxr_arr = focus_lines
                
        d = dvl_air_arr.get(dvl_sn, {})

        if d.get("mode") != "bacnet":
            return

        # Web device configuration uses ipaddress; legacy BACnet configuration
        # used ip.  Accept both while the web value remains authoritative.
        ipadd = get_bacnet_target(d)
        name = d.get("name")
        model = d.get("model")

        with last_value_lock:
            device_data = dict(last_device_values.get(dvl_sn, {}))
        device_data2 = {}
        read_plan = {}
        fresh_reads = 0

        # Values already fetched with ReadPropertyMultiple for this target by
        # prefetch_targets(); the loop below single-reads anything missing.
        prefetched = cycle_prefetch.get(ipadd, {})

        for cmd_id in cml_air_arr:
            for cmd_act in cml_air_arr[cmd_id]:
                if dvl_sn not in cml_air_arr[cmd_id][cmd_act]:
                    continue

                cml_arr = cml_air_arr[cmd_id][cmd_act]
                model_registers = rgl_air_arr.get(model, {}).get(cmd_act, {})
                object_instances = expand_bacnet_command(
                    cml_arr.get(dvl_sn, []), model_registers
                )

                # BACnet has no Modbus-style range read. Issue one
                # ReadProperty command per configured object instance.
                for l_arr in object_instances:

                        rgl = model_registers[str(l_arr)]

                        read_plan.setdefault(cmd_act, [])
                        if l_arr not in read_plan[cmd_act]:
                            read_plan[cmd_act].append(l_arr)

                        key_val = rgl['name']
                        # Values saved from the web form are JSON strings.  A
                        # float BACnet value multiplied by a string raises
                        # "can't multiply sequence by non-int of type float"
                        # and used to abort the whole device read cycle.
                        try:
                            multiply = float(rgl.get('multiply', 1) or 1)
                        except (TypeError, ValueError):
                            multiply = 1.0

                        # 🔥 CALL BACNET (ครั้งเดียว)
                        if (cmd_act, l_arr) in prefetched:
                            ok, val = prefetched[(cmd_act, l_arr)]
                        elif target_unreachable(ipadd):
                            # Target already failed this cycle: fall back to
                            # the cached value without waiting for a timeout.
                            ok, val = False, 0
                        else:
                            ok, val = read_chiller_value(ipadd, cmd_act, l_arr)
                            record_target_read(ipadd, ok)
                        touch_heartbeat(heartbeat)
                        read_succeeded = ok
                        if read_succeeded:
                            fresh_reads += 1
                        cache_key = (dvl_sn, cmd_act, l_arr)
                        with last_value_lock:
                            if ok:
                                last_good_values[cache_key] = val
                            elif cache_key in last_good_values:
                                # Keep the payload complete during a transient timeout.
                                val = last_good_values[cache_key]
                                ok = True
                        if not ok:
                            # A bad BACnet object must not block the remaining
                            # configured objects. Retain a cached value above,
                            # otherwise make the unavailable point explicit.
                            device_data[key_val] = None
                            continue
                        zxr_arr = focus_lines
                        if dvl_sn in zxr_arr:
                            print(f"{name} {key_val}: {val} =")

                        if ok:
                            # print(f"{name} {key_val}: {val}")
                            
                            
                            # if key_val.lower() == "mode":
                            #     val = 1 if val == 0 else 2 if val == 1 else val
                            #     val = val * multiply
                            #     device_data[key_val] = str(int(val))
                            # if dvl_sn == "ACF51105":
                            #     print(f"{name} {key_val}: {val}")
                            # print(f'==={key_val}=={key_val in alarm_air_arr.get(device_SN, {}).get(dvl_sn, {}).get("AlertConfig", {})}')
                            # print(f'==={key_val}=={key_val in list(alarm_air_arr.get(device_SN, {}).get(dvl_sn, {}).get("AlertConfig", {}).keys())}')
                            # print(f'==={key_val}=={list(alarm_air_arr.get(device_SN, {}).get(dvl_sn, {}).get("AlertConfig", {}).keys())}')
                            # print(f'===============================================================================================')

                            # if key_val in ["mode", "ac_mode", "fan", "vs"]:
                            #     if key_val.lower() == "mode":
                            #         val = 1 if val == 0 else 2 if val == 1 else val
                            #     val = val * multiply
                            #     device_data[key_val] = int(val)
                            #     # if isinstance(val, float):
                            #     #     device_data[key_val] = f"{float(val):.2f}"
                            #     # else:
                            #     #     device_data[key_val] = int(val)
                                    
                            
                            
                            # print(f'===={list(alarm_air_arr.get(device_SN, {}).get(dvl_sn, {}).get("AlertConfig", {}).keys())}')
                            
                            if key_val in list(alarm_air_arr.get(device_SN, {}).get(dvl_sn, {}).get("AlertConfig", {}).keys()):
                                # if key_val in alarm_air_arr[device_SN][dvl_sn]["AlertConfig"]:
                                #     continue
                                # if key_val 
                                # print(f'===Array Key [{key_val}]: {alarm_air_arr[device_SN][dvl_sn]["AlertConfig"].keys()}')
                                
                                if device_SN in alarm_air_arr:
                                    if dvl_sn in alarm_air_arr[device_SN]:
                                        if "AlertConfig" in alarm_air_arr[device_SN][dvl_sn]:
                                            if key_val in alarm_air_arr[device_SN][dvl_sn]["AlertConfig"].keys():
                                                if alarm_air_arr[device_SN][dvl_sn]["AlertConfig"][key_val]['Active']:
                                                    if next((k for k in ["Alarm", "Warning"] if k in alarm_air_arr[device_SN][dvl_sn]["AlertConfig"][key_val]), "Normal"):
                                                        if val == 1:
                                                            device_data[key_val] = str(int(val))
                                                            # print(f'==Alarm   list ===[{dvl_sn}][{key_val}]==={alarm_air_arr[device_SN][dvl_sn]["AlertConfig"][key_val]["Alarm"]}')
                                                            # print(f'==Warning list ===[{dvl_sn}][{key_val}]==={alarm_air_arr[device_SN][dvl_sn]["AlertConfig"][key_val]["Warning"]}')
                            
                            else:
                                
                                    
                                # val = val * multiply
                                # device_data[key_val] = f"{val:.2f}"
                                
                                if key_val in ["mode", "ac_mode", "fan", "vs"]:
                                    if key_val.lower() == "mode":
                                        # val = 1 
                                        zxr_arr = focus_lines
                                        
                                        if dvl_sn in zxr_arr:
                                            print(f'=[{dvl_sn}]=={val}')
                                            
                                        if int(val) == 0:
                                            val = 1
                                        elif int(val) == 1:
                                            val = 2
                                        
                                    val = val * multiply
                                    device_data[key_val] = str(int(val))
                                else:
                                    val = val * multiply
                                    device_data[key_val] = f"{val:.2f}"
                                                        
                                # if device_SN not in alarm_air_arr or dvl_sn not in alarm_air_arr[device_SN]:
                                #     device_data[key_val] = f"{val:.2f}"
                        else:
                            if key_val in ["mode", "ac_mode", "fan", "vs"]:
                                if key_val.lower() == "mode":
                                    if device_data:
                                        val = 1 if int(val) == 0 else 2
                                        # print(f'All==[{dvl_sn}]==')
                                        # print(f'{key_val}=={type(device_data)}=={device_data}')
                                    else:
                                        val = 0
                                val = val * multiply
                                device_data[key_val] = str(int(val))
                            else:
                                val = val * multiply
                                device_data[key_val] = f"{val:.2f}"

                        # Failed objects are represented above as a cached value
                        # or null, while later objects continue to be read.
                                                    

        # Match py_multi's Modbus console log shape so mixed-protocol reads can
        # be followed from the same service journal.  Only devices whose values
        # changed are logged (~6000 lines/min otherwise) unless BACNET_VERBOSE.
        with last_value_lock:
            previous_data = last_device_values.get(dvl_sn)
        if BACNET_VERBOSE or previous_data != device_data or dvl_sn in focus_lines:
            print(
                f"[{get_current()}][{device_SN}][BACnet] >> "
                f"{dvl_sn} | {json.dumps(read_plan, ensure_ascii=False, separators=(',', ':'))}",
                flush=True,
            )
            print(
                f"[{get_current()}][{device_SN}][BACnet] << "
                f"{dvl_sn} | {json.dumps(device_data, ensure_ascii=False, separators=(',', ':'))}",
                flush=True,
            )

        # Each worker owns one dvl_sn. Only protect the shared dictionary write.
        with lock:
            result_dict[dvl_sn] = device_data

        with last_value_lock:
            last_device_values[dvl_sn] = dict(device_data)
        return fresh_reads

    except Exception as e:
        print(f"[THREAD ERROR] {dvl_sn}:", e)
        return 0


def update_cross_device_calculations(result_dict):
    """Add derived values to the matching dvl_sn after all reads finish.

    These values depend on BTUMN101, so calculating them inside a worker is
    timing-dependent and can intermittently omit an entire device.
    """
    focus_devices = {
        sn.strip() for sn in focus_lines if sn.strip()
    }
    for dvl_sn in sorted(focus_devices):
        device_data = result_dict.get(dvl_sn)
        if not device_data:
            continue
        try:
            chr_temp = float(device_data["chr_temp"])
            chs_temp = float(device_data["chs_temp"])
            
            if dvl_sn == "BTUF5101" and False:
                device_data_fake = result_dict.get("BTUF6101")
                chs_temp = float(device_data_fake["chs_temp"])
                # device_data_fake_x = {}
                # device_data_fake_x['chs_temp'] = device_data_fake["chs_temp"]
                result_dict[dvl_sn]['chs_temp'] = device_data_fake["chs_temp"]
            
            
            flow_gpm = float(device_data["water_flow"])
        except (KeyError, TypeError, ValueError):
            continue

        if chs_temp == 0 or chs_temp == float("-inf"):
            device_data["current_btu"] = None
            continue

        delta_f = abs((chr_temp - chs_temp) * 1.8)
        device_data["delta_t_f"] = str(round(delta_f, 3))
        device_data["current_btu"] = str(
            round((500 * delta_f * flow_gpm) / 12000, 3)
        )

    cwp_data = result_dict.get("BTUMN101", {})
    try:
        flow_gpm = float(cwp_data["water_flow"])
    except (KeyError, TypeError, ValueError):
        return

    cdn_devices = {
        sn.strip() for sn in read_file("zx_cdn.json").splitlines() if sn.strip()
    }
    active_ach_current_btu = []
    for dvl_sn in sorted(cdn_devices):
        device_data = result_dict.get(dvl_sn)
        if not device_data:
            continue

        try:
            temp_leaving = float(device_data["temp_leaving"])
            temp_entering = float(device_data["temp_entering"])
        except (KeyError, TypeError, ValueError):
            continue

        print(f'==={dvl_sn}===temp_leaving== {(temp_leaving*1.8)+32}')
        print(f'==={dvl_sn}===temp_entering== {(temp_entering*1.8)+32}')
        print(f'==={dvl_sn}===flow_gpm== {flow_gpm}')
        print(f'=====================================================')
        
        delta_f = abs((temp_leaving - temp_entering) * 1.8)
        # print(f'==={dvl_sn}===({temp_leaving} - {temp_entering}  * 1.8) = {delta_f}')
        ton_btu = (500 * delta_f * flow_gpm) / 12000
        eer = 500 * delta_f * flow_gpm

        current_btu = str(round(ton_btu, 3))
        print(f'===={dvl_sn}===current_btu={current_btu}')
        print(f'===={dvl_sn}===eer={eer}')
        eer_value = str(round(eer, 3)) 
        device_data["delta_t_f"] = str(round(delta_f, 3))
        device_data["current_btu"] = current_btu
        device_data["eer_devide_ach"] = eer_value

        # Preserve the old scalar EER field for API compatibility.
        cwp_data.setdefault("eer_devide_ach", eer_value)

        try:
            compressor_running = any(
                int(float(device_data.get(key, 0))) == 1
                for key in ("com_1a_status", "com_2a_status")
            )
            print(f'===compressor_running={compressor_running}')
            
            if compressor_running:
                active_ach_current_btu.append(ton_btu)
                delta_c = temp_entering - temp_leaving
                cop_pre = str(round(flow_gpm * delta_c * 0.26, 3))
                device_data["cop_pre"] = cop_pre
                print(f'===cop_pre={cop_pre}')
                
        except (TypeError, ValueError):
            pass

    # BTUMN101 represents the load of only the running ACH units. If both ACH
    # units are running, their current_btu values are summed.
    if active_ach_current_btu:
        cwp_data["current_btu"] = str(round(sum(active_ach_current_btu), 3))
    else:
        cwp_data.pop("current_btu", None)

def publish_to_py_multi(result_dict, shared_data, automation_data=None, automation_revision=None):
    """Expose BACnet snapshots in the same shape consumed by py_multi.

    py_multi merges every shared-data block by meter serial number.  A stable
    synthetic block per BACnet device avoids collisions with CML command IDs
    while retaining the payload format: {block: {meter: values}}.

    Every Manager access is an IPC round trip, so only blocks that changed (or
    were removed by py_multi's m_arr.clear() after each status log) are sent.
    """
    global last_cache_write, last_cache_data, last_revision_bump
    active_keys = set()
    shared_keys = set(shared_data.keys())
    automation_keys = set(automation_data.keys()) if automation_data is not None else set()
    changed = False
    for meter_sn, values in result_dict.items():
        block_key = f"BACNET_{meter_sn}"
        active_keys.add(block_key)
        block = {meter_sn: values}
        value_changed = published_blocks.get(block_key) != block
        if value_changed or block_key not in shared_keys:
            shared_data[block_key] = block
        if automation_data is not None and (value_changed or block_key not in automation_keys):
            automation_data[block_key] = block
        if value_changed:
            published_blocks[block_key] = copy.deepcopy(block)
            changed = True

    # Do not leave removed BACnet devices in subsequent status payloads.
    for block_key in (shared_keys | automation_keys | set(published_blocks)):
        if str(block_key).startswith("BACNET_") and block_key not in active_keys:
            shared_data.pop(block_key, None)
            if automation_data is not None:
                automation_data.pop(block_key, None)
            published_blocks.pop(block_key, None)
            changed = True

    # Wake Automation when BACnet values changed (and at least periodically).
    now = time.monotonic()
    if automation_revision is not None and (changed or now - last_revision_bump >= BACNET_PUBLISH_REFRESH):
        with automation_revision.get_lock():
            automation_revision.value += 1
        last_revision_bump = now

    # The All Readings API historically reconstructs live values from Modbus
    # action logs. BACnet does not create one log line per property, so publish
    # one atomic snapshot for the API to merge with those readings.  The API
    # reports the file's ts as the data time, so refresh it periodically even
    # when values are unchanged; otherwise skip the SD-card write.
    if result_dict == last_cache_data and now - last_cache_write < BACNET_CACHE_REFRESH:
        return
    last_cache_write = now
    last_cache_data = copy.deepcopy(result_dict)
    cache_payload = {
        "ts": int(time.time()),
        "dt": get_current(),
        "data": result_dict,
    }
    try:
        LIVE_CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
        temporary = LIVE_CACHE_PATH.with_suffix(LIVE_CACHE_PATH.suffix + ".tmp")
        with temporary.open("w", encoding="utf-8") as handle:
            json.dump(cache_payload, handle, ensure_ascii=False, separators=(",", ":"), allow_nan=False)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, LIVE_CACHE_PATH)
    except (OSError, TypeError, ValueError) as error:
        print(f"BACnet live cache error: {error}")


def main(shared_data=None, automation_data=None, automation_revision=None, heartbeat=None):
    """Run standalone, or publish into py_multi when shared_data is supplied."""
    global last_dvl, last_rgl, last_cml, last_alarm, last_focus, focus_lines, cycle_prefetch
    global dvl_air_arr, rgl_air_arr, cml_air_arr, alarm_air_arr, lasttime_send_status, g_cne

    wait_for_local_ip(heartbeat)
    start_bacnet()
    integrated = shared_data is not None
    print("BACnet collector started" + (" (py_multi integration)" if integrated else ""))
    touch_heartbeat(heartbeat)
    no_success_cycles = 0

    while True:
        start_loop = time.time()

        # ===== NETWORK CHECK =====
        # The BACnet socket is bound to one address.  If DHCP/Wi-Fi changes it
        # (or the link drops) the stack can never recover, so restart the reader.
        current_ip = get_local_ip()
        if current_ip != local_ip:
            raise RuntimeError(f"Local IP changed {local_ip} -> {current_ip}; rebinding BACnet")

        # ===== RELOAD FILE =====
        dvl_time = get_mtime('dvl.json')
        rgl_time = get_mtime('rgl.json')
        cml_time = get_mtime('cml.json')
        alarm_time = get_mtime('alarm_config.json')

        if dvl_time != last_dvl:
            dvl_air_arr = json.loads(read_file('dvl.json') or "{}")
            last_dvl = dvl_time
            print("Reload dvl")

        if rgl_time != last_rgl:
            rgl_air_arr = json.loads(read_file('rgl.json') or "{}")
            last_rgl = rgl_time
            print("Reload rgl")

        if cml_time != last_cml:
            cml_air_arr = json.loads(read_file('cml.json') or "{}")
            last_cml = cml_time
            print("Reload cml")
            
        if alarm_time != last_alarm:
            alarm_air_arr = json.loads(read_file('alarm_config.json') or "{}")
            last_alarm = alarm_time
            print("Reload alarm")

        focus_time = get_mtime('zx_focusk.json')
        if focus_time != last_focus:
            focus_lines = read_file('zx_focusk.json').split("\n")
            last_focus = focus_time

        # ===== THREAD EXECUTE =====
        results = {}

        bacnet_devices = [
            sn for sn, device in dvl_air_arr.items()
            if isinstance(device, dict) and str(device.get("mode", "")).lower() == "bacnet"
        ]
        cycle_prefetch = prefetch_targets(bacnet_devices, heartbeat)
        with ThreadPoolExecutor(max_workers=MAX_THREADS) as executor:
            read_counts = list(executor.map(
                lambda sn: process_device(sn, results, heartbeat),
                bacnet_devices,
            ))

        report_target_status()
        fresh_read_count = sum(read_counts)
        if bacnet_devices and fresh_read_count == 0:
            no_success_cycles += 1
            print(
                f"[BACnet Health] no fresh reads "
                f"({no_success_cycles}/{BACNET_NO_SUCCESS_LIMIT})",
                flush=True,
            )
            if no_success_cycles >= BACNET_NO_SUCCESS_LIMIT:
                raise RuntimeError("BACnet stack returned no fresh values")
        else:
            no_success_cycles = 0

        update_cross_device_calculations(results)

        data_arr = replace_negative_infinity(results)

        print(f"[dev:{len(data_arr)}][time:{round(time.time()-start_loop,2)}s]")

        if integrated:
            publish_to_py_multi(data_arr, shared_data, automation_data, automation_revision)
        touch_heartbeat(heartbeat)

        # ===== SEND (standalone mode only) =====
        if not integrated and int(time.time()) >= lasttime_send_status:

            payload = {
                "mode": "status",
                "device_SN": device_SN,
                "data": data_arr,
                "counter": int(time.time()) - start_time,
                "ts": int(time.time()),
                "dt": get_current()
            }

            try:
                r = requests.post(
                    http_server + "?device_sn=" + device_SN,
                    data={"mode":"WSDevice","WSData": json.dumps(payload)},
                    headers={"User-Agent":"INNO/2025","cne":g_cne},
                    verify=False,
                    timeout=3
                )

                if r.status_code == 200:
                    resp = json.loads(r.text) if is_json(r.text) else {}
                    g_cne = resp.get("cne","")
                    try:
                        sec = max(1, int(resp.get("sec_count", 60)))
                    except (TypeError, ValueError):
                        sec = 60
                    lasttime_send_status = int(time.time()) + sec
                else:
                    lasttime_send_status = int(time.time()) + HTTP_RETRY_SECONDS

                print(f"[HTTP] {r.status_code}")

            except Exception as e:
                print("HTTP ERROR:", e)
                lasttime_send_status = int(time.time()) + HTTP_RETRY_SECONDS

        print("=========================================")

        # Fixed start-to-start period instead of "work + 1 s" busy polling.
        time.sleep(max(0.5, BACNET_POLL_INTERVAL - (time.time() - start_loop)))


def run_integrated(shared_data, automation_data=None, automation_revision=None, heartbeat=None):
    """Multiprocessing target used by python/py_multi.py."""
    main(shared_data, automation_data, automation_revision, heartbeat)


def run_supervised(shared_data, automation_data=None, automation_revision=None):
    """Keep BACnet recoverable without restarting py_multi's Modbus workers."""
    while True:
        heartbeat = Value("d", time.monotonic())
        collector = Process(
            target=run_integrated,
            args=(shared_data, automation_data, automation_revision, heartbeat),
            name="bacnet-reader",
        )
        collector.start()
        reason = "collector exited"
        while collector.is_alive():
            age = time.monotonic() - heartbeat.value
            if age > BACNET_STALL_TIMEOUT:
                reason = f"collector heartbeat stale for {age:.1f}s"
                print(f"[BACnet Supervisor] {reason}; restarting reader", flush=True)
                collector.terminate()
                collector.join(timeout=5)
                if collector.is_alive():
                    collector.kill()
                    collector.join(timeout=5)
                break
            collector.join(timeout=2)

        exitcode = collector.exitcode
        try:
            collector.close()
        except ValueError:
            pass
        print(
            f"[BACnet Supervisor] {reason} (exit={exitcode}); "
            f"retrying in {BACNET_RESTART_DELAY}s",
            flush=True,
        )
        time.sleep(BACNET_RESTART_DELAY)


if __name__ == "__main__":
    main()
