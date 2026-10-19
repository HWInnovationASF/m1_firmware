# import threading 
# import warnings 
# from bacpypes.core import run, stop 
# from bacpypes.pdu import Address 
# from bacpypes.app import BIPSimpleApplication 
# from bacpypes.local.device import LocalDeviceObject 
# from bacpypes.apdu import ReadPropertyRequest, ReadPropertyACK
# from bacpypes.iocb import IOCB 
# from bacpypes.primitivedata import Real, Unsigned, Boolean, Enumerated 
# import socket
# import json
# import time
# from datetime import datetime
# import requests
# import os
# requests.packages.urllib3.disable_warnings(requests.packages.urllib3.exceptions.InsecureRequestWarning)


# # device list
# device_SN = "M117718143259745"
# start_time = int(time.time())
# http_server = "http://api.mdbiot.com/vx_mdbiot_api.php"
# lasttime_send_status = 0
# g_cne = "cVpWUUl6ZGR1QUUxRy9XL1lWYWpiN1lPSUIzZE9YUmRHdTJtT1ZqK2c5bz0="

# warnings.filterwarnings("ignore", message="no signal handlers for child threads") 

# s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
# s.connect(("8.8.8.8", 80))
# local_ip = s.getsockname()[0]
# s.close()


# this_device = LocalDeviceObject(
#     objectName="PythonClient",
#     objectIdentifier=204,
#     vendorIdentifier=999
# )
# this_app = BIPSimpleApplication(this_device, f"{local_ip}/24")
# # this_app = BIPSimpleApplication(this_device, "172.16.110.197/24") 
# # this_app = BIPSimpleApplication(this_device, "0.0.0.0/24")


# thread = threading.Thread(target=run, daemon=True)
# thread.start()


# def is_json(wsJson):
#     try:
#         json.loads(wsJson)
#         return True
#     except ValueError as e:
#         return False
    
# def read_file(pathFile):
#     try:
#         res_txt = ""
#         with open(pathFile, "r") as f:
#             res_txt = f.read()
#         return res_txt
#     except Exception as e:
#         return ""

# def write_file(filename,data,mode='w'):
#     try:
#         with open(filename, mode) as f:
#             res_txt = data
#             f.write(res_txt)
#         return True   # success
#     except Exception as e:
#         print(f"Error writing file: {e}")
#         return False  # failure

# def read_chiller_value(target_ip, obj_type, obj_inst):
#     try:
#         request = ReadPropertyRequest(
#             destination=Address(target_ip),
#             objectIdentifier=(obj_type, obj_inst),
#             propertyIdentifier="presentValue",
#         )
#         iocb = IOCB(request)
#         this_app.request_io(iocb)
#         iocb.wait(timeout=5)

#         if iocb.ioResponse:
#             apdu = iocb.ioResponse
#             if isinstance(apdu, ReadPropertyACK):
#                 tag_list = apdu.propertyValue.tagList
#                 if 'analog' in obj_type:
#                     return True,apdu.propertyValue.cast_out(Real)
#                 elif 'binary' in obj_type:
#                     try:
#                         return True,apdu.propertyValue.cast_out(Enumerated)
#                     except:
#                         return False,apdu.propertyValue.cast_out(Unsigned)
#                 elif 'multiState' in obj_type:
#                     return True,apdu.propertyValue.cast_out(Unsigned)
#                 else:
#                     return True,tag_list[0].app_to_object().value 
#             else:
#                 return False,f"Error: Received {apdu.__class__.__name__}"
#         else:
#             return False,f"Error: {iocb.ioError or 'Timeout'}"

#     except Exception as e:
#         return False,f"System Error: {e}"

# def get_current():
#     time_l = time.time()
#     time_test = datetime.fromtimestamp(int(time_l))
#     date_time = time_test.strftime("%Y-%m-%d %H:%M:%S")
#     return date_time

# def get_mtime(filename):
#     try:
#         return os.path.getmtime(filename)
#     except:
#         return 0


# def alert_config(meterSN,data_command_2,lasttime_send_status,global_arr):
#     # print(f'alert_config==== {meterSN} == {data_command_2}')
#     try:
#         if os.path.isfile('alarm_config.json'):
#             alarm_txt = read_file('alarm_config.json')
#             alarm_arr = json.loads(alarm_txt) if is_json(alarm_txt) else {}
            
#             # print("===alarm_txt===",alarm_arr)
#             if alarm_arr and device_SN in alarm_arr:
#                 # print("===alarm_txt===",meterSN,alarm_arr[device_SN])
#                 # print("===data_device_cmd==",dev_list,data_device_cmd)

#                 if meterSN in alarm_arr[device_SN]:
#                     print("===alarm_txt===",meterSN,alarm_arr[device_SN][meterSN])
                    
#                     if 'AlertConfig' in alarm_arr[device_SN][meterSN]:
#                         alarm_list = alarm_arr[device_SN][meterSN]['AlertConfig']
#                         print("===alarm_txt===",alarm_arr[device_SN][meterSN]['AlertConfig'])
#                         print("===2==",data_command_2[meterSN])
#                         for k_alarm in alarm_arr[device_SN][meterSN]['AlertConfig']:
#                             # print(k_alarm,data_command_2[meterSN])
#                             if k_alarm in data_command_2[meterSN]:
#                                 if alarm_list[k_alarm]['Active'] == 1:
#                                     # print("==Alarm==",alarm_list[k_alarm]['Alarm'])
#                                     # print("==Warning==",alarm_list[k_alarm]['Warning'])
#                                     if alarm_list[k_alarm]['Warning'] is None:
#                                         alarm_list[k_alarm]['Warning']['Max'] = 0
#                                         alarm_list[k_alarm]['Warning']['Min'] = 0
#                                     if alarm_list[k_alarm]['Alarm'] is None:
#                                         alarm_list[k_alarm]['Alarm']['Max'] = 0
#                                         alarm_list[k_alarm]['Alarm']['Min'] = 0
#                                     # if alarm_list[k_alarm]['Warning'] is not None:
#                                     # print("==Alarm==",alarm_list[k_alarm]['Alarm'])
                                    
#                                     # if float(data_command_2[dev_list][k_alarm]) >= float(alarm_list[k_alarm]['Warning']['Max']) or float(data_command_2[dev_list][k_alarm]) <= float(alarm_list[k_alarm]['Warning']['Min']):
#                                     if data_command_2[meterSN][k_alarm]:
#                                         # print(f'===={type(data_command_2[dev_list][k_alarm])}')
#                                         # print(f'===={float(data_command_2[dev_list][k_alarm])}')
#                                         v_local = float(data_command_2[meterSN][k_alarm])
                                    
#                                         # if alarm_list[k_alarm]['Alarm']['Min'] or alarm_list[k_alarm]['Alarm']['Max'] :
#                                             # print(f'==Min: {alarm_list[k_alarm]["Alarm"]["Min"]}')
#                                             # print(f'==Max: {alarm_list[k_alarm]["Alarm"]["Max"]}')
#                                         print(f'==Max: {alarm_list[k_alarm]}')
                                            
#                                         alarm_min = float(alarm_list[k_alarm]['Alarm']['Min']) if alarm_list[k_alarm]['Alarm']['Min'] != '' else 0.0
#                                         alarm_max = float(alarm_list[k_alarm]['Alarm']['Max']) if alarm_list[k_alarm]['Alarm']['Max'] != '' else alarm_min
#                                         warn_min = float(alarm_list[k_alarm]['Warning']['Min']) if alarm_list[k_alarm]['Warning']['Min'] != '' else 0.0
#                                         warn_max = float(alarm_list[k_alarm]['Warning']['Max']) if alarm_list[k_alarm]['Warning']['Max'] != '' else warn_min
                                        
#                                         # if alarm_max < alarm_min:
#                                         #     alarm_max = alarm_min
#                                         print(f'Alarm=={v_local}== min: {alarm_min} , max: {alarm_max}')
#                                         print(f'Warnn=={v_local}== min: {warn_min} , max: {warn_max}')
                                        
#                                         if v_local >= warn_max or v_local >= alarm_max or v_local <= warn_min or v_local <= alarm_min:
#                                             print(f'=={meterSN} : {data_command_2}')
#                                             if not 'alarm_arr' in global_arr:
#                                                 global_arr['alarm_arr'] = {}
#                                             tmp = global_arr['alarm_arr']
#                                             if not device_SN in tmp:
#                                                 tmp[device_SN] = {}
#                                             if not meterSN in tmp[device_SN]:
#                                                 tmp[device_SN][meterSN] = {}
#                                             if not k_alarm in tmp[device_SN][meterSN]:
#                                                 tmp[device_SN][meterSN][k_alarm] = int(time.time())
#                                             print(f'==={int(time.time())}===={tmp[device_SN][meterSN]}====last:[{int(lasttime_send_status)}]')
#                                             if not 'status_alarm' in tmp:
#                                                 tmp['status_alarm'] = 0
#                                             if tmp['status_alarm'] == 0 and int(time.time()) >= int(lasttime_send_status):
#                                                 tmp['status_alarm'] = 1
#                                                 tmp[device_SN][meterSN][k_alarm] = int(time.time())
#                                             # print(f'==={int(time.time())}===={tmp[m1_class.device_SN][meterSN]}====last:[{int(lasttime_send_status.value)}]')
#                                             global_arr['alarm_arr'] = tmp
                                            
#         # # /////////////////////////////////////////////////////////////////////
#         return global_arr
#     except Exception as e:
#         return global_arr
    
    
# m_arr = {}
# data_arr = {}

# last_dvl = 0
# last_rgl = 0
# last_cml = 0 

# dvl_air = read_file('dvl.json')
# rgl_air = read_file('rgl.json')
# cml_air = read_file('cml.json')

# dvl_air_arr = json.loads(dvl_air) if is_json(dvl_air) else {}
# rgl_air_arr = json.loads(rgl_air) if is_json(rgl_air) else {}
# cml_air_arr = json.loads(cml_air) if is_json(cml_air) else {}

# start_time_loop = int(time.time())
# while True:
#     try:
#         dvl_time = get_mtime('dvl.json')
#         rgl_time = get_mtime('rgl.json')
#         cml_time = get_mtime('cml.json')
        
#         # ACMP2101 - AC = type, MP = location, 2 = floor, 1 coil ร้อน, 01 = ลำดับ
#         if dvl_time != last_dvl:
#             dvl_air = read_file('dvl.json')
#             dvl_air_arr = json.loads(dvl_air) if is_json(dvl_air) else {}
#             last_dvl = dvl_time

#         if rgl_time != last_rgl:
#             rgl_air = read_file('rgl.json')
#             rgl_air_arr = json.loads(rgl_air) if is_json(rgl_air) else {}
#             last_rgl = rgl_time

#         if cml_time != last_cml:
#             cml_air = read_file('cml.json')
#             cml_air_arr = json.loads(cml_air) if is_json(cml_air) else {}
#             last_cml = cml_time
        
        
#         # m_arr = {}
#         # data_arr = {}
#         a1 = {}
#         # chiller_ip = "172.16.21.157:47808"
#         # print("Loading Data...")  
        
#         # FL2_FCU01 = read_chiller_value(chiller_ip, 'analogValue', 104)
#         # print(f"VSD: {FL2_FCU01}")
#         c_i = 0
#         dev_i = 0
#         for dvl_sn in dvl_air_arr:
#             dev_i += 1
            
#             # if dvl_sn not in data_arr:
#             #     data_arr[dvl_sn] = {}
#             # print(dvl_sn)
#             if "mode" in dvl_air_arr[dvl_sn]:
#                 # print(dvl_sn)
#                 mode = dvl_air_arr[dvl_sn]['mode']
#                 ipadd = dvl_air_arr[dvl_sn]['ip']
#                 name = dvl_air_arr[dvl_sn]['name']
#                 model = dvl_air_arr[dvl_sn]['model']
#                 port = dvl_air_arr[dvl_sn]['port']
#                 if mode == "bacnet":
#                     # print(mode)
#                     for cmd_id in cml_air_arr:
                        
#                         # print(cmd_id,cml_air_arr)
#                         for cmd_act in cml_air_arr[cmd_id]:
#                             if dvl_sn in cml_air_arr[cmd_id][cmd_act]:
                                
#                                 if dvl_sn not in data_arr:
#                                     data_arr[dvl_sn] = {}
                                    
#                                 cml_arr = cml_air_arr[cmd_id][cmd_act]
#                                 # print(cmd_act,cml_arr)
#                                 for c_arr in cml_arr:
#                                     # print(rgl_air_arr[model])
#                                     for l_arr in cml_arr[c_arr]:
#                                         c_i += 1
                                        
#                                         key_val = rgl_air_arr[model][cmd_act][str(l_arr)]['name']
#                                         multiply = rgl_air_arr[model][cmd_act][str(l_arr)]['multiply'] if 'multiply' in rgl_air_arr[model][cmd_act][str(l_arr)] else 1
#                                         # print(f'===multiply==={multiply}')
#                                         # if dvl_sn == "ACH15101" or dvl_sn == "ACH25101":
#                                         # if dvl_sn == "ACMP4111" or dvl_sn == "ACMP4113":
#                                         #     print(f"{name} {key_val}: {read_chiller_value(f'{ipadd}:{port}', cmd_act, l_arr)}")
#                                         # print(f"{name} {key_val}: {read_chiller_value(f'{ipadd}:{port}', cmd_act, l_arr)}")
#                                         if read_chiller_value(ipadd, cmd_act, l_arr)[0]:
#                                             val_chl = read_chiller_value(ipadd, cmd_act, l_arr)[1]
                                            
#                                             if key_val.lower() == "mode":
#                                                 if val_chl == 0:
#                                                     val_chl = 1
#                                                 elif val_chl == 1:
#                                                     val_chl = 2
                                                
#                                             res_chl = val_chl * multiply
#                                             print(f"[{c_i}] {name} {key_val}: {res_chl}")
                                            
#                                             # print(f'===val_chl==={val_chl}')
#                                             # data_arr[dvl_sn][key_val] = f'{val_chl:.2f}'
#                                             data_arr[dvl_sn][key_val] = str(int(val_chl)) if key_val == "mode" or key_val == "ac_mode" or key_val == "fan" or key_val == "vs" else f'{res_chl:.2f}'
#                                         else:
#                                             if data_arr:
#                                                 print(f'[{dvl_sn}][{key_val}]==data_arr>>>=={data_arr[dvl_sn]}')
#                                             data_arr[dvl_sn][key_val] = "0"
                                            

                
#                     # print('=================================')
                    
                    
#         # print(data_arr)
#         # m_arr = data_arr
#         diff_time = int(time.time()) - start_time_loop
#         print(f'[dev:{dev_i}][time:{diff_time}s]')
#         # # AC= type, MP = อาคาร, 1 coil ร้อน, 2 = ชั้น, 01 = ตัวที่ 1
#         # m_arr["ACMP1201"] = data_arr
#         if int(time.time()) >= lasttime_send_status:
#             a1["mode"] = "status"
#             a1["device_SN"] = device_SN
#             a1["data"] = data_arr
#             a1["counter"] = int(time.time()) - start_time
#             a1["ts"] = int(time.time())
#             a1["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")
#             # {"mode":"status","device_SN":"M117550521928946","data":{"ACF31204":{"mode":"1","fan":"0","temp":"0","fault":"0"}},"counter":234408,"ts":"1771813808","dt":"2026-02-23 09:30:08"}
#             httpSend = requests.post(http_server+"?device_sn="+device_SN,data={"mode":"WSDevice","WSData": json.dumps(a1)},headers={"User-Agent": "INNO/2025","cne":g_cne},verify=False,timeout=3)
#             httpSend.raise_for_status()
#             print("[{}][{}][http] >> server | {}".format(get_current(),device_SN,len(json.dumps(a1))))
#             if httpSend.status_code == 200:
#                 # print("===httpSend=="+str(httpSend.text))
#                 # print("[{}][http] >> server | {}".format(device_SN,json.dumps(a1)))
#                 g_cne_arr = json.loads(httpSend.text) if is_json(httpSend.text) else {}
#                 if isinstance(g_cne_arr, dict):                
#                     g_cne = g_cne_arr['cne'] if 'cne' in g_cne_arr else ''
#                     sec_count = g_cne_arr['sec_count'] if 'sec_count' in g_cne_arr else 60
#                     lasttime_send_status = int(time.time()) + sec_count
#                     if mode.lower() == 'status':
#                         ms_minus = int(g_cne_arr['ms_minus']) if 'ms_minus' in g_cne_arr else 0
#                         # print("===httpSend=="+str(httpSend.text))
#                 print("[{}][{}][http] << server | {}".format(get_current(),device_SN,httpSend.text))
                
#             data_arr = {}
        
#         print(f'===============================================================')
        
#         # print(a1)
        
#     except Exception as e:
#         print(f'Error as {e}')


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
import math

requests.packages.urllib3.disable_warnings()

# ===== CONFIG =====
MAX_THREADS = 8   # ?? ปรับได้
device_SN = "M117718143259745"
http_server = "http://api.mdbiot.com/vx_mdbiot_api.php"

start_time = int(time.time())
lasttime_send_status = 0
g_cne = ""

lock = threading.Lock()

# ===== NETWORK =====
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.connect(("8.8.8.8", 80))
local_ip = s.getsockname()[0]
s.close()

# ===== BACNET SETUP =====
this_device = LocalDeviceObject(
    objectName="PythonClient",
    objectIdentifier=204,
    vendorIdentifier=999
)

this_app = BIPSimpleApplication(this_device, f"{local_ip}/24")

threading.Thread(target=run, daemon=True).start()

# ===== UTIL =====
def is_json(wsJson):
    try:
        json.loads(wsJson)
        return True
    except:
        return False

def read_file(path):
    try:
        with open(path, "r") as f:
            return f.read()
    except:
        return ""

def get_mtime(file):
    try:
        return os.path.getmtime(file)
    except:
        return 0

def get_current():
    return datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")

# ===== BACNET READ =====
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

        ipadd = d.get("ip")
        name = d.get("name")
        model = d.get("model")

        device_data = {}
        device_data2 = {}

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

                        key_val = rgl['name']
                        multiply = rgl.get('multiply', 1)

                        # ?? CALL BACNET (ครั้งเดียว)
                        ok, val = read_chiller_value(ipadd, cmd_act, l_arr)
                        # zxr = read_file('zx_focusk.json')
                        # zxr_arr = zxr.split("\n")
                        # if dvl_sn in zxr_arr:
                        #     print(f"{name} {key_val}: {val} =")

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
                                        val = 1 if int(val) == 0 else 2
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
                                                    

        # ?? LOCK
        with lock:
            zxr = read_file('zx_focusk.json')
            zxr_arr = zxr.split("\n")
            
            if dvl_sn in zxr_arr:
                print(f'=[{dvl_sn}]=={device_data}')
                
            result_dict[dvl_sn] = device_data

    except Exception as e:
        print(f"[THREAD ERROR] {dvl_sn}:", e)

# ===== MAIN LOOP =====
while True:
    try:
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

        data_arr = results

        print(f"[dev:{len(data_arr)}][time:{round(time.time()-start_loop,2)}s]")

        # ===== SEND =====
        if int(time.time()) >= lasttime_send_status:

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
                    sec = resp.get("sec_count",60)
                    lasttime_send_status = int(time.time()) + sec

                print(f"[HTTP] {r.status_code}")

            except Exception as e:
                print("HTTP ERROR:", e)

        print("=========================================")

    except Exception as e:
        print("MAIN ERROR:", e)

    time.sleep(1)