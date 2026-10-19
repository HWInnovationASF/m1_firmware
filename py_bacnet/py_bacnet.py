import threading 
import warnings 
from bacpypes.core import run
from bacpypes.pdu import Address 
from bacpypes.app import BIPSimpleApplication 
from bacpypes.local.device import LocalDeviceObject 
from bacpypes.apdu import ReadPropertyRequest, ReadPropertyACK
from bacpypes.iocb import IOCB 
from bacpypes.primitivedata import Real, Unsigned, Enumerated 
import socket
import json
import time
from datetime import datetime
import requests
import os
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

requests.packages.urllib3.disable_warnings()

# ===== CONFIG =====
MAX_THREADS = 8   # 🔥 ปรับได้
device_SN = "M117718143259745"
http_server = "http://api.mdbiot.com/vx_mdbiot_api.php"
BASE_DIR = Path(__file__).resolve().parent
WEB_CONFIG_DIR = Path(os.environ.get("MDBCARE_WEB_CONFIG", "/var/www/html/meow/config"))
LIVE_CACHE_PATH = Path(os.environ.get("MDBCARE_BACNET_CACHE", "/var/www/html/meow/data/bacnet_latest.json"))
BACNET_TIMEOUT = 3
HTTP_RETRY_SECONDS = 10

start_time = int(time.time())
lasttime_send_status = 0
g_cne = ""

lock = threading.Lock()
last_value_lock = threading.Lock()
last_good_values = {}

# ===== NETWORK =====
def get_local_ip():
    """Return the outbound IPv4 address without sending network traffic."""
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.connect(("8.8.8.8", 80))
        return sock.getsockname()[0]
    except OSError:
        try:
            return socket.gethostbyname(socket.gethostname())
        except OSError:
            return "127.0.0.1"
    finally:
        sock.close()


local_ip = get_local_ip()

# ===== BACNET SETUP =====
this_app = None

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
    global this_app
    if this_app is not None:
        return this_app
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
            return False, 0
        # print(f"END   {target_ip} {obj_type} {obj_inst}")

        if iocb.ioResponse:
            apdu = iocb.ioResponse

            if isinstance(apdu, ReadPropertyACK):
                if 'analog' in obj_type:
                    return True, apdu.propertyValue.cast_out(Real)
                elif 'binary' in obj_type:
                    try:
                        return True, apdu.propertyValue.cast_out(Enumerated)
                    except:
                        return True, apdu.propertyValue.cast_out(Unsigned)
                elif 'multiState' in obj_type:
                    return True, apdu.propertyValue.cast_out(Unsigned)
                else:
                    return True, apdu.propertyValue.cast_out(Real)

        return False, 0

    except Exception as e:
        return False, 0

# ===== LOAD CONFIG =====
last_dvl = last_rgl = last_cml = last_alarm = 0

dvl_air_arr = {}
rgl_air_arr = {}
cml_air_arr = {}
alarm_air_arr = {}

# ===== THREAD WORKER =====
def process_device(dvl_sn, result_dict):
    try:
        zzxr = read_file('zx_focusk.json')
        zzxr_arr = zzxr.split("\n")
                
        d = dvl_air_arr.get(dvl_sn, {})

        if d.get("mode") != "bacnet":
            return

        # Web device configuration uses ipaddress; legacy BACnet configuration
        # used ip.  Accept both while the web value remains authoritative.
        ipadd = d.get("ipaddress") or d.get("ip")
        name = d.get("name")
        model = d.get("model")

        device_data = {}
        device_data2 = {}
        read_plan = {}

        for cmd_id in cml_air_arr:
            for cmd_act in cml_air_arr[cmd_id]:

                if dvl_sn not in cml_air_arr[cmd_id][cmd_act]:
                    continue

                cml_arr = cml_air_arr[cmd_id][cmd_act]

                for c_arr in cml_arr:
                    for l_arr in cml_arr[c_arr]:

                        try:
                            rgl = rgl_air_arr[model][cmd_act][str(l_arr)]
                        except:
                            continue

                        read_plan.setdefault(cmd_act, [])
                        if l_arr not in read_plan[cmd_act]:
                            read_plan[cmd_act].append(l_arr)

                        key_val = rgl['name']
                        multiply = rgl.get('multiply', 1)

                        # 🔥 CALL BACNET (ครั้งเดียว)
                        ok, val = read_chiller_value(ipadd, cmd_act, l_arr)
                        cache_key = (dvl_sn, cmd_act, l_arr)
                        with last_value_lock:
                            if ok:
                                last_good_values[cache_key] = val
                            elif cache_key in last_good_values:
                                # Keep the payload complete during a transient timeout.
                                val = last_good_values[cache_key]
                                ok = True
                        zxr = read_file('zx_focusk.json')
                        zxr_arr = zxr.split("\n")
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
                                        zxr = read_file('zx_focusk.json')
                                        zxr_arr = zxr.split("\n")
                                        
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
                                                    

        # Match py_multi's Modbus console log shape so mixed-protocol reads can
        # be followed from the same service journal.
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

    except Exception as e:
        print(f"[THREAD ERROR] {dvl_sn}:", e)


def update_cross_device_calculations(result_dict):
    """Add derived values to the matching dvl_sn after all reads finish.

    These values depend on BTUMN101, so calculating them inside a worker is
    timing-dependent and can intermittently omit an entire device.
    """
    focus_devices = {
        sn.strip() for sn in read_file("zx_focusk.json").splitlines() if sn.strip()
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
    """
    active_keys = set()
    for meter_sn, values in result_dict.items():
        block_key = f"BACNET_{meter_sn}"
        active_keys.add(block_key)
        block = {meter_sn: values}
        shared_data[block_key] = block
        if automation_data is not None:
            automation_data[block_key] = block

    # Do not leave removed BACnet devices in subsequent status payloads.
    for block_key in list(shared_data.keys()):
        if str(block_key).startswith("BACNET_") and block_key not in active_keys:
            shared_data.pop(block_key, None)
            if automation_data is not None:
                automation_data.pop(block_key, None)

    if automation_revision is not None:
        with automation_revision.get_lock():
            automation_revision.value += 1

    # The All Readings API historically reconstructs live values from Modbus
    # action logs. BACnet does not create one log line per property, so publish
    # one atomic snapshot for the API to merge with those readings.
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


def main(shared_data=None, automation_data=None, automation_revision=None):
    """Run standalone, or publish into py_multi when shared_data is supplied."""
    global last_dvl, last_rgl, last_cml, last_alarm
    global dvl_air_arr, rgl_air_arr, cml_air_arr, alarm_air_arr, lasttime_send_status, g_cne

    start_bacnet()
    integrated = shared_data is not None
    print("BACnet collector started" + (" (py_multi integration)" if integrated else ""))

    while True:
        start_loop = time.time()

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

        # ===== THREAD EXECUTE =====
        results = {}

        with ThreadPoolExecutor(max_workers=MAX_THREADS) as executor:
            executor.map(lambda sn: process_device(sn, results), dvl_air_arr.keys())

        update_cross_device_calculations(results)

        data_arr = replace_negative_infinity(results)

        print(f"[dev:{len(data_arr)}][time:{round(time.time()-start_loop,2)}s]")

        if integrated:
            publish_to_py_multi(data_arr, shared_data, automation_data, automation_revision)

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

        time.sleep(1)


def run_integrated(shared_data, automation_data=None, automation_revision=None):
    """Multiprocessing target used by python/py_multi.py."""
    main(shared_data, automation_data, automation_revision)


if __name__ == "__main__":
    main()
