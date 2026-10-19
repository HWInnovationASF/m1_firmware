import ast
import json
import serial
import minimalmodbus
from pyModbusTCP.client import ModbusClient
import time
from datetime import datetime
import ftplib
import os
import sys
import numpy as np #convert Hex float
import subprocess
import shutil
import tempfile
import threading
import requests
from gpio_compat import GPIO
import paho.mqtt.client as mqtt
from py_vpn import py_vpn
from tou_schedule import save_tou_schedule
from dlog_maintenance import maintain_dlogs
from log_action_maintenance import maintain_log_action
from log_error_maintenance import maintain_error_logs
requests.packages.urllib3.disable_warnings(requests.packages.urllib3.exceptions.InsecureRequestWarning)

FIRMWARE_REPOSITORY = 'https://github.com/HWInnovationASF/m1_firmware.git'
_firmware_update_lock = threading.Lock()

class main_m1:
    def __init__(self, json_input={}):
        if json_input:
            # Automatically set each key as an instance attribute
            for key, value in self._validate_json(json_input).items():
                # print(f"{key}: {value}")
                setattr(self, key, value)
        self.serial_port_rs485 = '/dev/ttyACM0'
        self.py_vpn = py_vpn()

    def set_vpn_state(self, value):
        """Control the single systemd-managed VPN process and verify its state."""
        try:
            requested = int(value)
        except (TypeError, ValueError):
            return {"requested": value, "active": 0, "success": False, "error": "VPN accepts only 1 or 0"}
        if requested not in (0, 1):
            return {"requested": requested, "active": 0, "success": False, "error": "VPN accepts only 1 or 0"}

        action = "start" if requested else "stop"
        try:
            command = subprocess.run(
                ["sudo", "-n", "systemctl", action, "mdb-vpn.service"],
                capture_output=True, text=True, timeout=20
            )
            if command.returncode != 0:
                return {
                    "requested": requested, "active": 0, "success": False,
                    "error": (command.stderr or command.stdout or "systemctl command failed").strip()
                }

            deadline = time.time() + (15 if requested else 8)
            active = False
            ipvpn = None
            while time.time() < deadline:
                status = subprocess.run(
                    ["systemctl", "is-active", "mdb-vpn.service"],
                    capture_output=True, text=True, timeout=3
                )
                active = status.stdout.strip() == "active"
                ipvpn = self.get_ipv4_from_nic("tun0")
                if (requested and active and ipvpn) or (not requested and not active):
                    break
                time.sleep(0.5)

            success = bool(active and ipvpn) if requested else not active
            result = {
                "requested": requested,
                "active": 1 if active else 0,
                "ipvpn": ipvpn,
                "status": "connect" if active and ipvpn else "disconnect",
                "success": success
            }
            if not success:
                result["error"] = "VPN did not reach the requested state"
            return result
        except (subprocess.SubprocessError, OSError) as exc:
            return {"requested": requested, "active": 0, "success": False, "error": str(exc)}

    def update_firmware(self, data=None, progress=None):
        """Download, validate and install firmware without blocking meter processes.

        Only the official repository is accepted.  The installer preserves the
        existing device configuration and runs with SKIP_SERVICE=1 so the MQTT
        caller can publish its final result before the service is restarted.
        """
        data = data if isinstance(data, dict) else {}
        work_dir = None

        def emit(stage, percent, message):
            state = {
                'stage': str(stage),
                'percent': max(0, min(100, int(percent))),
                'message': str(message)
            }
            if callable(progress):
                try:
                    progress(state)
                except Exception as progress_error:
                    print(f'Firmware progress callback failed: {progress_error}')
            return state

        if not _firmware_update_lock.acquire(blocking=False):
            return {
                'success': False, 'stage': 'busy', 'percent': 0,
                'error': 'A firmware update is already running'
            }

        try:
            emit('validate', 2, 'Validating firmware request')
            repository = str(data.get('repo', data.get('repository', FIRMWARE_REPOSITORY))).strip().rstrip('/')
            official_repository = FIRMWARE_REPOSITORY.rstrip('/')
            if repository != official_repository:
                raise ValueError('Firmware repository is not permitted')

            reference = str(data.get('ref', data.get('version', '')) or '').strip()
            if reference and (
                len(reference) > 100 or reference.startswith('-') or '..' in reference
                or not all(char.isalnum() or char in '._/-' for char in reference)
            ):
                raise ValueError('Invalid firmware ref/version')

            try:
                timeout_sec = int(data.get('timeout_sec', 1800) or 1800)
            except (TypeError, ValueError):
                timeout_sec = 1800
            timeout_sec = max(300, min(3600, timeout_sec))

            work_dir = tempfile.mkdtemp(prefix='mdbiot-fw-')
            source_dir = os.path.join(work_dir, 'repository')
            emit('download', 10, 'Downloading firmware package')
            clone_command = ['git', 'clone', '--depth', '1']
            if reference:
                clone_command.extend(['--branch', reference])
            clone_command.extend([repository, source_dir])
            clone = subprocess.run(
                clone_command, capture_output=True, text=True, timeout=min(timeout_sec, 300)
            )
            if clone.returncode != 0:
                raise RuntimeError((clone.stderr or clone.stdout or 'git clone failed').strip()[-1000:])

            emit('verify', 35, 'Checking firmware files and archives')
            required_files = ('install.sh', 'html.7z', 'python.7z')
            missing = [name for name in required_files if not os.path.isfile(os.path.join(source_dir, name))]
            if missing:
                raise RuntimeError('Firmware package is missing: ' + ', '.join(missing))
            for archive_name in ('html.7z', 'python.7z'):
                archive_test = subprocess.run(
                    ['7z', 't', os.path.join(source_dir, archive_name)],
                    capture_output=True, text=True, timeout=180
                )
                if archive_test.returncode != 0:
                    raise RuntimeError(f'{archive_name} integrity check failed')

            emit('install', 55, 'Installing firmware; current configuration will be preserved')
            install_command = [
                'sudo', '-n', 'env', 'YES=1', 'SKIP_SERVICE=1',
                f'SOURCE_DIR={source_dir}', '/bin/bash', os.path.join(source_dir, 'install.sh')
            ]
            install_result = subprocess.run(
                install_command, capture_output=True, text=True, timeout=timeout_sec
            )
            if install_result.returncode != 0:
                detail = (install_result.stderr or install_result.stdout or 'installer failed').strip()
                raise RuntimeError(detail[-2000:])

            emit('verify-installed', 92, 'Verifying installed Python files')
            compile_result = subprocess.run(
                [
                    'python3', '-m', 'py_compile',
                    '/home/mdbcare/python/py_multi.py',
                    '/home/mdbcare/python/py_function_inc1.py'
                ],
                capture_output=True, text=True, timeout=60
            )
            if compile_result.returncode != 0:
                raise RuntimeError((compile_result.stderr or 'installed Python validation failed').strip()[-1000:])

            final_state = emit('complete', 100, 'Firmware installed; service restart scheduled')
            return {
                'success': True,
                **final_state,
                'repository': official_repository,
                'ref': reference or 'default',
                'restart_required': True
            }
        except subprocess.TimeoutExpired as error:
            state = emit('failed', 0, 'Firmware update timed out')
            return {'success': False, **state, 'error': str(error)}
        except Exception as error:
            state = emit('failed', 0, str(error))
            return {'success': False, **state, 'error': str(error)}
        finally:
            if work_dir:
                shutil.rmtree(work_dir, ignore_errors=True)
            _firmware_update_lock.release()

    @staticmethod
    def _restart_py_multi_after_reply(delay=3):
        """Give MQTT time to send the final reply, then activate new firmware."""
        time.sleep(max(1, int(delay)))
        try:
            subprocess.Popen(
                ['sudo', '-n', 'systemctl', 'restart', 'py_multi.service'],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                start_new_session=True
            )
        except Exception as error:
            print(f'Firmware service restart failed: {error}', file=sys.stderr)
        
    def show(self):
        for key, value in self.__dict__.items():
            print(f"{key}: {value}")
     
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

    def is_json(self, wsJson):

        if not wsJson:
            return False

        if not isinstance(wsJson, (str, bytes, bytearray)):
            return False

        try:
            json.loads(wsJson)
            return True
        except (ValueError, TypeError):
            return False
    def safe_json_load(self, data, default=None):

        if not data:
            return default if default is not None else {}

        try:

            # แปลง bytes → string
            if isinstance(data, (bytes, bytearray)):
                data = data.decode("utf-8", errors="ignore")

            # ป้องกัน JSON ใหญ่เกิน
            if isinstance(data, str) and len(data) > 1000000:
                print("JSON too large:", len(data))
                return default if default is not None else {}

            return json.loads(data)

        except Exception as e:
            print("JSON decode error:", str(e))
            return default if default is not None else {}
        
    def read_file(self,pathFile):
        try:
            with open(pathFile, "r") as f:
                return f.read()
        except Exception as e:
            return ""
 
    def write_file(self,filename,data,mode='w'):
        try:
            with open(filename, mode) as f:
                res_txt = data
                f.write(res_txt)
            return True   # success
        except Exception as e:
            print(f"Error writing file: {e}")
            return False  # failure
    
    def log_action(self,msg,typelog,lmode):
        # import glob
        try:
            log_dir = os.path.dirname(__file__)
            logdirNames = os.path.join(log_dir, "log_action/"+str(self.deviceSN))
            if self.ensure_dir_with_perms(logdirNames, mode=0o777 ,debug=0):
                pass
            # if not os.path.isdir(log_dir+"/log_action"):
            #     os.mkdir(log_dir+"/log_action")
            status_log_action = False
            time_l = time.time()
            mlog = str(int(time_l))+"|"+msg+"\n"
            # print(mlog)
            if not os.path.exists(logdirNames):
                os.makedirs(logdirNames)

            if typelog == "log5":
                time_test = datetime.fromtimestamp(int(int(time_l)/300) * 300)
                date_y = time_test.strftime("%Y%m%d%H%M")
                path_log = logdirNames+"/"+str(self.deviceSN)+"_log5_"+str(date_y)+".txt"

                # date_filter = time_test.strftime("%Y%m%d")
                # if int(datetime.now().hour) == 1 and int(datetime.now().minute) >= 0 and int(datetime.now().second) <= 10:
                #     file = os.listdir(logdirNames)
                #     for ifile in file:
                #         if "log5_" in ifile:
                #             if not date_filter in ifile:
                #                 if ".txt" in ifile:
                #                     fname = ifile[:ifile.find('.')]
                #                     if os.path.exists(logdirNames+'/'+fname+'.zip'):
                #                         os.remove(logdirNames+'/'+ifile)
                #                     else:
                #                         if zip_file(logdirNames+'/'+ifile):
                #                             os.remove(logdirNames+'/'+ifile)

                if not os.path.isfile(path_log):
                    # print("========== WRITE 555555")
                    self.write_file(str(path_log),str(mlog),"w")
                    # subprocess.call(['chmod', '0777', str(path_log)])
                    os.system('sudo chmod 777 {}'.format(str(path_log)))
                    status_log_action = True
                    
                else:
                    # print("========== WRITE 6666")
                    self.write_file(str(path_log),str(mlog),str(lmode))
                    # os.system('sudo chmod 777 {}'.format(str(path_log)))
                    # subprocess.call(['chmod', '0777', str(path_log)])
                    status_log_action = False

            elif typelog == "log1":
                time_test = datetime.fromtimestamp(int(int(time_l)/86400) * 86400)
                date_y = time_test.strftime("%Y%m%d")
                path_log = logdirNames+"/"+str(self.deviceSN)+"_log1_"+str(date_y)+".txt"
                # if not os.path.exists(logdirNames):
                if not os.path.isfile(path_log):
                    self.write_file(str(path_log),str(mlog),"w")
                    os.system('sudo chmod 777 {}'.format(str(path_log)))
                else:
                    self.write_file(str(path_log),str(mlog),str(lmode))
                    # os.system('sudo chmod 777 {}'.format(str(path_log)))
                status_log_action = True

            elif typelog == "logserver1":
                time_test = datetime.fromtimestamp(int(int(time_l)/86400) * 86400)
                date_y = time_test.strftime("%Y%m%d")
                path_log = logdirNames+"/"+str(self.deviceSN)+"_logserver1_"+str(date_y)+".txt"
                if not os.path.exists(logdirNames):
                    self.write_file(str(path_log),str(mlog),"w")
                    os.system('sudo chmod 777 {}'.format(str(path_log)))

                else:
                    self.write_file(str(path_log),str(mlog),str(lmode))
                    # os.system('sudo chmod 777 {}'.format(str(path_log)))
                status_log_action = True

            return status_log_action
        except Exception as e:
            mlog_err = "act_log_action|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
    
    def log_error(self,msg,typelog,lmode=""):
        try:
            log_dir = os.getcwd()
            logdirNames = os.path.join(log_dir, "log_err/"+str(self.deviceSN))
            if not os.path.isdir(log_dir+"/log_err"):
                os.mkdir(log_dir+"/log_err")
            status_log_action = False
            time_l = time.time()
            date_l = datetime.fromtimestamp(time_l).strftime("%Y-%m-%d %H:%M:%S")
            mlog = str(date_l)+"|"+msg+"\n"
            if not os.path.exists(logdirNames):
                os.makedirs(logdirNames)
            if typelog == "event":
                time_test = datetime.fromtimestamp(time_l)
                date_y = time_test.strftime("%Y%m%d")
                path_log = logdirNames+"/"+self.deviceSN+"_event_"+str(date_y)+".txt"

                if not os.path.isfile(path_log):
                    self.write_file(str(path_log),str(mlog),"w")
                    os.system('sudo chmod 777 {}'.format(str(path_log)))
                    status_log_action = True
                else:
                    self.write_file(str(path_log),str(mlog),str(lmode))
                    status_log_action = False
            return status_log_action
        except Exception as e:
            return False
        
    def log_device(self,msg,typelog,lmode="w"):
        try:
            log_dir = os.getcwd()
            logdirNames = os.path.join(log_dir, "log_device/"+str(self.deviceSN))
            if not os.path.isdir(log_dir+"/log_device"):
                os.mkdir(log_dir+"/log_device")
            status_log_action = False
            time_l = time.time()
            # mlog = str(date_l)+"|"+msg+"\n"
            mlog = msg+"\n"
            # print(mlog)
            if not os.path.exists(logdirNames):
                os.makedirs(logdirNames)
            if typelog == "device":
                time_test = datetime.fromtimestamp(time_l)
                date_y = time_test.strftime("%Y%m%d")
                path_log = logdirNames+"/"+self.deviceSN+"_"+typelog+"_"+str(date_y)+".txt"

                if not os.path.isfile(path_log):
                    self.write_file(str(path_log),str(mlog),"w")
                    os.system('sudo chmod 777 -R {}'.format(log_dir+"/log_device"))
                    status_log_action = True
                else:
                    self.write_file(str(path_log),str(mlog),str(lmode))
                    status_log_action = False
            return status_log_action
        except Exception as e:
            mlog_err = "act_log_device|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
    
    def log_server(self,msg,typelog,lmode="w"):
        try:
            # log_dir = os.path.dirname(__file__)
            log_dir = os.getcwd()
            logdirNames = os.path.join(log_dir, "dlog/"+str(self.deviceSN))
            # if ensure_dir_with_perms(log_dir+"/dlog", mode=0o777 ,debug=0):
            #     pass
            # if not os.path.isdir(log_dir+"/dlog"):
            #     os.mkdir(log_dir+"/dlog")
            status_log_action = False
            time_l = int(time.time()/300)*300+300
            date_l = datetime.fromtimestamp(time_l).strftime("%Y-%m-%d %H:%M:%S")
            mlog = str(int(time.time()))+"|"+msg+"\n"
            if self.ensure_dir_with_perms(logdirNames, mode=0o777 ,debug=0):
                pass
            # if not os.path.exists(logdirNames):
            #     os.makedirs(logdirNames)
            if typelog:
                # time_test = datetime.fromtimestamp(int(int(time_l)/86400) * 86400)
                time_test = datetime.fromtimestamp(time_l)
                date_y = time_test.strftime("%Y%m%d%H%M")
                path_log = logdirNames+"/"+self.deviceSN+"_"+typelog+"_"+str(date_y)+".txt"

                if not os.path.isfile(path_log):
                    self.write_file(str(path_log),str(mlog),"w")
                    os.system('sudo chmod 777 {}'.format(str(path_log)))
                    status_log_action = True
                else:
                    self.write_file(str(path_log),str(mlog),str(lmode))
                    status_log_action = False
            maintain_dlogs(os.path.dirname(logdirNames))
            return status_log_action
        except Exception as e:
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
    
    def ensure_dir_with_perms(self,path, mode=0o777 ,debug=0):
        import stat
        try:
            # 1. Check if directory exists, create if not
            if not os.path.exists(path):
                os.makedirs(path, exist_ok=True)
                if debug:
                    print(f"[{self.get_current()}][directory] >> Created directory: {path}")
            else:
                if debug:
                    print(f"[{self.get_current()}][directory] >> Directory exists: {path}")

            # 2. Get current permissions
            current_mode = stat.S_IMODE(os.stat(path).st_mode)
                
            if debug:
                print(f"[{self.get_current()}][permission] >> Current permissions: {oct(current_mode)}")

            # 3. Set permissions if different
            if current_mode != mode:
                os.chmod(path, mode)
                if debug:
                    print(f"[{self.get_current()}][permission] >> Permissions updated to: {oct(mode)}")
            else:
                if debug:
                    print(f"[{self.get_current()}][permission] >> Permissions already correct.")
            return True
        except Exception as e:
                    
            if debug:
                print(f'[{self.get_current()}][error] >> {e}')
            return False
    
    def get_current(self):
        time_l = time.time()
        time_test = datetime.fromtimestamp(int(time_l))
        date_time = time_test.strftime("%Y-%m-%d %H:%M:%S")
        return date_time
    
    def rtuconfig(self,ID=1,BR=9600,PT=serial.PARITY_NONE,timeouts=1):
        instrumentrtu = minimalmodbus.Instrument(self.serial_port_rs485, ID)  # port name, slave address (in decimal)
        instrumentrtu.serial.port                  # this is the serial port name
        instrumentrtu.serial.baudrate = BR       # Baud
        instrumentrtu.serial.bytesize = 8
        instrumentrtu.serial.stopbits = 1
        instrumentrtu.serial.parity   = PT
        instrumentrtu.serial.timeout  = timeouts         # seconds
        # this is the slave address number
        instrumentrtu.mode = minimalmodbus.MODE_RTU   # rtu or ascii mode
        instrumentrtu.clear_buffers_before_each_transaction = True
        return instrumentrtu
    
    def readmodbusrtu(self,instrumentrtu,x,y,refn): #Addtess,Length,function 3/4
        try:
            # reg = instrumentrtu.read_register(registeraddress: int, number_of_decimals: int = 0, functioncode: int = 3, signed: bool = False) 
            # https://minimalmodbus.readthedocs.io/en/stable/internalminimalmodbus.html#minimalmodbus.Instrument.read_registers 
            reg = instrumentrtu.read_registers(x, y, int(refn))
            instrumentrtu.serial.close()
            if (len(reg)) == y:
                # print("====================================================",reg)
                return {"data":repr(reg),"error":"","result":1}
            else:
                return {"error":"readmodbus RTU fail","result":0}
                # print("readmodbus RTU fail")
        except Exception as error:
            # print("[!] Exception occurred: ", error)
            mlog_err = "act|{}".format(error)
            self.log_error(mlog_err,"event","a")
            return {"error":"{}".format(error),"result":0}
        
    def tcpconfig(self,Host,ID=1,Port=502,timeouts=1.5):
        # global instrument
        instrument = ModbusClient(host=Host, port=Port, unit_id=ID, auto_open=True,auto_close=True, timeout=timeouts) #timeout=2
        # instrument = ModbusClient(host=Host, port=502, unit_id=ID, auto_open=True, auto_close=True, timeout=1000)
        return instrument
    
    def readmodbustcp(self, instrument, x, y, refn):

        retry = 2

        for i in range(retry):

            try:

                if refn == 3:
                    reg = instrument.read_holding_registers(x, y)

                elif refn == 4:
                    reg = instrument.read_input_registers(x, y)

                elif refn == 1:
                    reg = instrument.read_coils(x, y)

                else:
                    return {"error": "invalid function", "result": 0}

                if reg is None:
                    time.sleep(0.1)
                    continue

                if len(reg) != y:
                    return {"error": "length mismatch", "result": 0}

                return {"data": repr(reg), "error": "", "result": 1}

            except Exception as e:

                if i == retry - 1:
                    return {"error": str(e), "result": 0}

                time.sleep(0.2)

        return {"error": "modbus fail", "result": 0}
    # def readmodbustcp(self,instrument,x,y,refn): #Addtess,Length,function 3/4
    #     try:
    #         if refn == 3:
    #             reg = instrument.read_holding_registers(x, y)
    #         elif refn == 4:
    #             reg = instrument.read_input_registers(x, y)
    #         elif refn == 1:
    #             reg = instrument.read_coils(x, y)
    #         # ii = 0
    #         # time_start_r = int(time.time())
    #         # while ii < 2 and int(int(time.time()) -time_start_r) < 1:
    #         #     ii += 1
    #         #     # print("===act_readmodbustcp=={}".format(reg))
                
    #         #     if reg!=None:
    #         #         print(f"===[{ii}]readmodbustcp Break!!==///// len:{len(reg)}")
    #         #         ii=3
    #         #         break
    #         #     if reg is None:
    #         #         # print("===act_readmodbustcp=={}".format(reg))

    #         #         if refn == 3:
    #         #             reg = instrument.read_holding_registers(x, y)
    #         #         elif refn == 4:
    #         #             reg = instrument.read_input_registers(x, y)
    #         #         elif refn == 1:
    #         #             reg = instrument.read_coils(x, y)
    #         #     time.sleep(0.1)

    #         # # print("===act_readmodbustcp=={}".format(reg))
    #         if (len(reg)) == y:
    #             instrument.close()
    #             return {"data":repr(reg),"error":"","result":1}
    #         else:
    #             instrument.close()
    #             return {"error":"readmodbus TCP fail","result":0}
    #             # print("readmodbus1 fail")
    #     except Exception as error:
    #         # print("[!] Exception readmodbus TCP : ", error)
    #         instrument.close()
    #         mlog_err = "act_readmodbustcp|{}".format(error)
    #         # print("==[{},{}]=={}".format(x,y,mlog_err))
    #         return {"error":"{}".format(error),"result":0}
    
    def writemodbustcp(self,instrument,register,value,timeouts=0.1):
        try:
            # if instrument.write_single_coil(register,bool(value)):
            #     time.sleep(timeouts)
            #     return True
            # if instrument.write_single_register(register,value):
            #     time.sleep(timeouts)
            #     # instrument.close()
            # รองรับ Signed Int16
            if isinstance(value, int):
                if value < -32768 or value > 32767:
                    raise ValueError("Value out of Int16 range")

                # แปลงเลขติดลบเป็น Unsigned 16-bit
                if value < 0:
                    value &= 0xFFFF

                ok = instrument.write_single_register(register, value)
                time.sleep(timeouts)
                return bool(ok)
            else:
                # instrument.close()
                return False

        except Exception as e:
            # instrument.close()
            print("[!] Write modbus TCP Exception error {}".format(e))
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
        
    def writemodbusrtu(self,instrument,register,value,timeouts=0.1):
        try:
            # if instrument.write_single_coil(register,bool(value)):
            #     time.sleep(timeouts)
            #     return True
            # print("XXXXXXX ",instrument,register,value)
            # minimalmodbus.Instrument.write_register() returns None on success.
            # A lack of exception is therefore the success signal; the caller
            # performs an explicit read-back before accepting the command.
            instrument.write_register(register, value)
            time.sleep(timeouts)
            return True

        except Exception as e:
            # instrument.close()
            print("[!] Write modbus TCP Exception error {}".format(e))
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
        
    def ftp_download(self,src,dst):
        try:
            ftp = ftplib.FTP()
            # host = "172.16.0.65"
            # port = 2002
            host = str(self.ftp_host)
            port = int(self.ftp_port)
            # print("Host===",host, port)

            timeouts = 3
            src_fname = os.path.basename(src)
            src_path = os.path.dirname(src)
            dst_fname = os.path.basename(dst)
            # print(src_path,src_fname,dst_fname)

            ftp.connect(host, port, timeouts)
            # print(ftp.getwelcome())
            # ftp.login("meowftp", "Asefa@2o23")
            # print(str(ftp_u), str(ftp_p))
            ftp.login(str(self.ftp_u), str(self.ftp_p))

            # ftp.cwd('/dconfig/'+serial_main+'/')
            ftp.cwd(src_path)
            # ftp.sendcmd('SITE CHMOD 777 '+file_name)
            # print(ftp.dir())
            # with open('test_001.py', 'wb') as f:
            # path_filename_dl = os.path.dirname(__file__)+dst

            script_dir = os.path.dirname(os.path.abspath(__file__))
            path_filename_dl = os.path.join(script_dir, dst.lstrip('/'))

            # print(path_filename_dl)

            'RETR '+src_fname
            if ftp.retrbinary(f'RETR {src_fname}', open(path_filename_dl, 'wb').write):
                ftp.quit()
                os.system('sudo chmod 777 '+path_filename_dl)
                    
                return True
            else:
                return False
        except Exception as e:
            mlog_err = "act_ftp_download|{}".format(e)
            self.log_error(mlog_err,"event","a")
            print("error {}".format(e))
            return False

    def ftp_upload(self,src,dst):
        try:
            ftp = ftplib.FTP()
            # host = "172.16.0.65"
            # port = 2002
            host = str(self.ftp_host)
            port = int(self.ftp_port)
            # print("Host====",host, port)
            timeouts = 3
            src_fname = os.path.basename(src)
            src_path = os.path.dirname(src)
            dst_fname = os.path.basename(dst)
            dst_path = os.path.dirname(dst)
            # print(src_path,src_fname)
            # print(dst_path+'==',str(dst_fname))
            # print("SRC ===="+str(src))
            ftp.connect(host, port, timeouts)
            # ftp.login("meowftp", "Asefa@2o23")
            ftp.login(str(self.ftp_u), str(self.ftp_p))

            ftp.cwd(dst_path)
            current_path = ftp.pwd()
            # print("Current remote path:", current_path)
            # print("Connected successfully:", ftp.getwelcome())
            # print(f'src:{src}')
            # print(f'dst:{dst}')
            if os.path.exists(src.lstrip('/')):
                file = open(src.lstrip('/'),'rb')
                if ftp.storbinary(f'STOR {dst_fname}', file):
                    ftp.sendcmd('SITE CHMOD 777 '+str(dst_fname))
                    ftp.quit()
                    return True
                else:
                    ftp.quit()
                    return False
            else:
                ftp.quit()
                return False
        except Exception as e:
            mlog_err = "act_ftp_upload|{}".format(e)
            self.log_error(mlog_err,"event","a")
            print("error {}".format(e))
            return False

    def hex_to_signed(self,s):
        if not isinstance(s, str):
            raise ValueError("string required")
        if 0 == len(s):
            raise ValueError("string empty")
        sign_bit_mask = 1 << (len(s)*4-1)
        other_bits_mask = sign_bit_mask - 1
        val = int(s, 16)
        return -(val & sign_bit_mask) | (val & other_bits_mask)

    def convertfloat32(self,x,y):
        data_bytes = np.array([  int(hex(y),16) ,  int(hex(x),16) ], dtype=np.uint16)
        data_as_float = data_bytes.view(dtype=np.float32)
        return data_as_float

    def convertint_signed(self,bx):
        Int = bx - 0x10000 if bx & 0x8000 else bx
        return Int

    def convertint_unsigned(self,bx):
        Int = int(str(bx))
        return Int

    def convertlong16_signed(self,ax):
        long16 = ""
        long16 = self.hex_to_signed(str(hex(ax))[2:6].rjust(4, '0'))
        # print(str(hex(ax))[2:6].rjust(4, '0'))
        return long16
    
    def convertlong32(self,cx,cy):
        long32 = ""
        long32 = int((str(hex(cx))[2:6].rjust(4, '0')  +  str(hex(cy))[2:6].rjust(4, '0')),16)
        # long32 = int((str(hex(cx))[2:6]  +  str(hex(cy))[2:6]),16)
        return long32

    def convertlong32_signed(self,ax,ay):
        long32 = ""
        long32 = self.hex_to_signed(str(hex(ax))[2:6].rjust(4, '0')  +  str(hex(ay))[2:6].rjust(4, '0'))
        # long323 = np.array([  int((str(hex(cx))[2:6]  +  str(hex(cy))[2:6]),16) ], dtype=np.int32)/1000
        # print(str(hex(ax))[2:6].rjust(4, '0'), str(hex(ay))[2:6].rjust(4, '0'))
        return long32

    def convertlong64(self,ax,ax2,ay,ay2):
        long64 = ""
        long64 = int((str(hex(ax))[2:6].rjust(4, '0') + str(hex(ax2))[2:6].rjust(4, '0') + str(hex(ay))[2:6].rjust(4, '0') + str(hex(ay2))[2:6].rjust(4, '0')),16)
        return long64

    def convertlong64_signed(self,ax,ax2,ay,ay2):
        long64 = ""
        long64 = self.hex_to_signed( str(hex(ax))[2:6].rjust(4, '0')  + str(hex(ax2))[2:6].rjust(4, '0')  +  str(hex(ay))[2:6].rjust(4, '0') + str(hex(ay2))[2:6].rjust(4, '0') )
        # print(str(hex(ax))[2:6].rjust(4, '0'), str(hex(ax2))[2:6].rjust(4, '0'), str(hex(ay))[2:6].rjust(4, '0'), str(hex(ay2))[2:6].rjust(4, '0'))
        return long64

    def convertfloat(self,dx,dy):
        # data_bytes = np.array([  int(hex(reg[dy]),16) ,  int(hex(reg[dx]),16) ], dtype=np.uint16)
        data_bytes = np.array([  int(hex(dx),16) ,  int(hex(dy),16) ], dtype=np.uint16)
        data_as_float = data_bytes.view(dtype=np.float32)
        return data_as_float

    def convertToInt(self,mantissa_str):
        power_count = -1
        mantissa_int = 0
        for i in mantissa_str:
            mantissa_int += (int(i) * pow(2, power_count))
            power_count -= 1
            
        return (mantissa_int + 1)

    def hex2dec(self,strHex):
        try:
            ieee_32 = str(bin(int(str(strHex),16)).ljust(8, '0'))
            # if print_debug:
            #     # print(ieee_32)
            #     print(ieee_32[0],"|",ieee_32[2 : 10],"|",(int(ieee_32[2 : 10],2)-127))
            sign_bit = int(ieee_32[0])
            exponent_bias = int(ieee_32[2 : 10],2)
            exponent_unbias = exponent_bias - 127
            mantissa_str = ieee_32[10 : ]
            mantissa_int = self.convertToInt(mantissa_str)
            real_no = pow(-1, sign_bit) * mantissa_int * pow(2, exponent_unbias)
        except IOError:
            print("Failed to read from rtu")
            real_no=0
        return real_no

    def get_register_map(self, reg_by_model, function_name=None):
        """Return the register bank for a Modbus function.

        New RGL schema:
            {"holding": {"100": {...}}, "input": {"100": {...}}}
        Legacy flat models are returned unchanged for backward compatibility.
        """
        if not isinstance(reg_by_model, dict):
            return {}
        is_banked = 'holding' in reg_by_model or 'input' in reg_by_model
        if not is_banked:
            return reg_by_model
        bank = 'input' if 'input' in str(function_name or '').lower() else 'holding'
        selected = reg_by_model.get(bank, {})
        return selected if isinstance(selected, dict) else {}

    def find_register(self, reg_by_model, parameter_name, function_name=None):
        """Find a parameter in its selected bank and return (register, info)."""
        selected = self.get_register_map(reg_by_model, function_name)
        for reg, info in selected.items():
            if isinstance(info, dict) and info.get('name') == parameter_name:
                return reg, info
        return None, None

    def convert_data(self,data_read,reg_by_model,start_reg,function_name=None):
        data_command_cv = {}
        try:
            reg_by_model = self.get_register_map(reg_by_model, function_name)
            i_regis = 0
            # print("======...",len(data_read))
            # print(data_read,start_reg)
            for i_para in range(len(data_read)):
                if str(start_reg) in reg_by_model:
                    units = reg_by_model[str(start_reg)]['unit'] if "unit" in reg_by_model[str(start_reg)] else ""
                    decimal = int(reg_by_model[str(start_reg)]['decimal'])
                    multiply = float(reg_by_model[str(start_reg)]['multiply'])

                    if reg_by_model[str(start_reg)]['type'].lower() == "float32":
                        val_cv = float(self.convertfloat32(data_read[i_para],data_read[i_para+1])[0])
                        # data_command_cv[reg_by_model[str(start_reg)]['name']] = {"val":str(round(val_cv*multiply,decimal)),"unit":units}
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "float32 inverse":
                        val_cv = float(self.convertfloat32(data_read[i_para+1],data_read[i_para])[0])
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "4q fp pf":
                        val_cv = float(self.convertfloat32(data_read[i_para],data_read[i_para+1])[0])
                        
                        # print(f'4q fp pf === Pre PF: {val_cv}')
                        if 0 < val_cv < 1:
                            val_cv = val_cv
                        elif -2 < val_cv < -1:
                            val_cv = -2 - val_cv
                        elif -1 < val_cv < 0:
                            val_cv = val_cv
                        elif 1 < val_cv < 2:
                            val_cv = 2 - val_cv
                        # print(f'4q fp pf === After PF: {round(val_cv,3)}')
                        
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "int sign16":
                        val_cv = int(self.convertint_signed(data_read[i_para]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "int unsign16":
                        val_cv = int(self.convertint_unsigned(data_read[i_para]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "int16 binary":
                        val_cv = data_read[i_para]
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(val_cv)
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "long unsign32":
                        val_cv = float(self.convertlong32(data_read[i_para],data_read[i_para+1]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "long unsign32 inverse":
                        val_cv = float(self.convertlong32(data_read[i_para+1],data_read[i_para]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "long sign32":
                        val_cv = float(self.convertlong32_signed(data_read[i_para],data_read[i_para+1]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 1
                    elif reg_by_model[str(start_reg)]['type'].lower() == "long unsign64":
                        val_cv = float(self.convertlong64(data_read[i_para],data_read[i_para+1],data_read[i_para+2],data_read[i_para+3]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 3
                    elif reg_by_model[str(start_reg)]['type'].lower() == "long sign64":
                        val_cv = float(self.convertlong64_signed(data_read[i_para],data_read[i_para+1],data_read[i_para+2],data_read[i_para+3]))
                        data_command_cv[reg_by_model[str(start_reg)]['name']] = str(round(val_cv*multiply,decimal))
                        i_para += 3

                    # print(data_command_cv)
                    # # print(reg_by_model[str(start_reg)])
                    # if reg_by_model[str(start_reg)]['name'] == "sm" or reg_by_model[str(start_reg)]['name'] == "md":
                    #     # print("====data_command_cv==",data_command_cv[reg_by_model[str(start_reg)]['name']])
                    #     k_val = float(data_command_cv[reg_by_model[str(start_reg)]['name']]['val'])
                    #     print("===Status Alarm ",i_para,start_reg,reg_by_model[str(start_reg)]['name'],int(k_val))
                    #     if int(k_val) == 0:
                start_reg+=1
                i_regis += 1

            return data_command_cv
        except Exception as e:
            mlog_err = "convert_data|{}".format(e)
            # log_error(mlog_err,"event","a")
            return data_command_cv

    def get_ipv4_from_nic(self, interface):
        import psutil
        import socket
        interface_addrs = psutil.net_if_addrs().get(interface) or []
        for snicaddr in interface_addrs:
            if snicaddr.family == socket.AF_INET:
                return snicaddr.address
        return None
            
    def check_internet(self):
        try:
            a = subprocess.Popen("ping -c 1 -W 0.1 -s 0 8.8.8.8", stdout=subprocess.PIPE, shell=True)
            a.wait(timeout=1)
            if a.poll() == 0:
                return True
            else:
                return False
        except OSError as error:
            return False
        except Exception as e:
            print("[!] Exception is {}".format(e))
            mlog_err = "act_check_internet|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
    
    def zip_file(self,pathFile):
        from zipfile import ZipFile
        import zipfile
        try:
            # print(pathFile)
            # print("ZipFile....."+str(pathFile))
            if os.path.exists(pathFile):
                f_dir = os.path.dirname(pathFile)
                f_name = os.path.basename(pathFile)
                f_name = f_name[:f_name.find('.')]
                with ZipFile(f_dir+'/'+f_name+'.zip', 'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as zip:
                    zip.write(f_dir+'/'+f_name+'.txt', f_name+'.txt')
                    os.system('sudo chmod 777 {}'.format(str(f_dir+'/'+f_name+'.zip')))
                    st = os.stat(f_dir+'/'+f_name+'.zip')
                    # print(st.st_size)
                    if os.path.exists(f_dir+'/'+f_name+'.zip') and st.st_size > 76:
                        # last_var = f_name[len(f_name)-2:]

                        # if last_var == "00" or last_var == "15" or last_var == "30" or last_var == "45" :
                        #     interest_val = {"meter_SN":"D00010d","measure_val":"e_p"}
                        #     retr = read_e_p(f_dir,f_name+'.txt',interest_val,positions="first")
                        #     if retr != "":
                        #         self.write_file(f_dir+"/log15_e_p.txt",retr,"w")
                        os.remove(f_dir+'/'+f_name+'.txt')
                        return True
                    else:
                        return False
            else:
                return False
        except Exception as e:
            print("Error {}".format(e))
            mlog_err = "act_zip_file|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False
        
    def clear_log(self,path,data,last_clear_log):
        # global last_clear_log

        try:
            time_l = time.time()
            time_test = datetime.fromtimestamp(time_l)
            date_y = time_test.strftime("%Y%m%d")

            time_check = int(int(time.time()) / 86400) * 86400 - (3 * 86400) # -1 day
            time_check_01 = datetime.fromtimestamp(time_check)
            date_check_01= time_check_01.strftime("%Y%m%d")
            # print(date_check_01)

            time_interval = data['time_interval'] if 'time_interval' in data else 1
            time_offset = data['time_offset'] if 'time_offset' in data else 0
            if last_clear_log == 0:
                last_clear_log = int(int(time.time()/time_interval)*time_interval) + time_interval + time_offset
            elif int(time.time()) > last_clear_log:
                if os.path.exists(path):
                    for filename in os.listdir(path):
                        opt = ""
                        if "_device_" in filename:
                            opt = filename.split(self.deviceSN+"_device_")[1].split(".")[0]
                        elif "_event_" in filename:
                            opt = filename.split(self.deviceSN+"_event_")[1].split(".")[0]
                        elif "_log5_" in filename:
                            opt = filename.split(self.deviceSN+"_log5_")[1].split(".")[0]
                            opt = opt[:len(opt)-4]
                        elif "_log1_" in filename:
                            opt = filename.split(self.deviceSN+"_log1_")[1].split(".")[0]
                            opt = opt[:len(opt)]
                        # print("===OPT==",opt)
                        # fname_save = filename[:filename.find('.')]
                        

                        if int(opt) < int(date_y):
                            fname_save = filename[:filename.find('.')]

                            pathFile = os.path.dirname(path)+"/"+self.deviceSN+"/"+filename
                            pathFile2 = os.path.dirname(path)+"/"+self.deviceSN+"/"+fname_save
                            if ".zip" in filename and ".txt" in filename:
                                print("==Remove file:",pathFile2+".txt")
                                os.remove(pathFile2+".txt")
                                # os.remove("/home/pi/Desktop/python/log_device/M117289601136254/M117289601136254_device_20250120.txt")

                            if ".txt" in filename and not ".zip" in filename:
                                # print(os.path.dirname(path))
                                self.zip_file(pathFile)
                                # print("Zip file {}".format(opt),pathFile)
                            if int(opt) < int(date_check_01):
                                os.remove(pathFile)
                                # print("Delete file :",pathFile)
                        # else:
                        #     print("today {}".format(opt))
                    last_clear_log = int(int(time.time()/time_interval)*time_interval) + time_interval + time_offset
                    return True
                else:
                    return False

            else:
                return False
            
        except Exception as e:
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False    
        
    def clear_log_event(self,path,data,last_clear_log_event):
        try:
            time_interval = data['time_interval'] if 'time_interval' in data else 1
            time_offset = data['time_offset'] if 'time_offset' in data else 0
            now = int(time.time())
            if last_clear_log_event == 0 or now > last_clear_log_event:
                log_root = os.path.dirname(os.path.abspath(path))
                status = maintain_error_logs(log_root)
                next_run = int(now/time_interval)*time_interval + time_interval + time_offset
                return status,next_run
            return False,last_clear_log_event
        except Exception as e:
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False,last_clear_log_event

    def clear_log_file(self,path,data,lasttime_clear_log):
        try:
            time_interval = data['time_interval'] if 'time_interval' in data else 1
            time_offset = data['time_offset'] if 'time_offset' in data else 0
            now = int(time.time())
            if lasttime_clear_log == 0 or now > lasttime_clear_log:
                log_root = os.path.dirname(os.path.abspath(path))
                status = maintain_log_action(log_root)
                next_run = int(now/time_interval)*time_interval + time_interval + time_offset
                return status,next_run
            return False,lasttime_clear_log
        except Exception as e:
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False,lasttime_clear_log

    def clear_log5_data(self,path,data,last_clear_log5_data):
        try:
            time_interval = data['time_interval'] if 'time_interval' in data else 1
            time_offset = data['time_offset'] if 'time_offset' in data else 0
            now = int(time.time())
            if last_clear_log5_data == 0 or now > last_clear_log5_data:
                dlog_root = os.path.dirname(os.path.abspath(path))
                status = maintain_dlogs(dlog_root)
                next_run = int(now/time_interval)*time_interval + time_interval + time_offset
                return status,next_run
            return False,last_clear_log5_data
        except Exception as e:
            mlog_err = "act|{}".format(e)
            self.log_error(mlog_err,"event","a")
            return False,last_clear_log5_data
    
    def http_send(self, mode,  msg_input={}, cmd_id = "", timeout = 3,g_cne="cVpWUUl6ZGR1QUUxRy9XL1lWYWpiN1lPSUIzZE9YUmRHdTJtT1ZqK2c5bz0="):
        
        # global g_cne
        # time_hb = time.time()
        try:
            # print("==msg_input==",msg_input)
            deviceSN = self.deviceSN
            http_server = self.httpServer if self.httpServer else "http://api.mdbiot.com/vx_mdbiot_api.php"
            mqtt_server = self.mqttBroker if self.mqttBroker else "mqtt.mdbiot.com"
            mqtt_port = self.mqttBroker if self.mqttBroker else 1883
            m1_class = self
            
            a1 = {}
            a1["mode"] = mode
            a1["device_SN"] = deviceSN
            if cmd_id != "":
                # a1["cmdid"] = cmd_id
                a1["cmnid"] = cmd_id
                
            if mode == "check_ws":
                msg_input['httpServer'] = http_server
                msg_input['mqttBroker'] = mqtt_server
                msg_input['mqttPort'] = mqtt_port
            a1["data"] = msg_input
            a1["counter"] = str(int(time.time()) - self.time_counter)
            a1["ts"] = int(time.time())

            # print("http_send()==to=={}===".format(http_server), json.dumps(a1))
            # if mode == 'reply':
            #     mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
            #     mqttc3.connect(mqtt_server, mqtt_port, 60)
            #     mqttc3.publish("res/"+deviceSN, json.dumps(a1))

            # httpSend = requests.post(http_server,data={"mode":"WSDevice","WSData": json.dumps(a1),"cne":g_cne},headers={"User-Agent": "INNO/2025"},verify=False,timeout=timeout)
            httpSend = requests.post(http_server+"?device_sn="+deviceSN,data={"mode":"WSDevice","WSData": json.dumps(a1)},headers={"User-Agent": "INNO/2025","cne":g_cne},verify=False,timeout=timeout)
            httpSend.raise_for_status()
            # print(f"=== Status Http {httpSend.text}")
            # if httpSend.status_code == 200:
            print("[{}][{}][http] >> server | {}".format(m1_class.get_current(),deviceSN,json.dumps(a1)))
            if httpSend.status_code == 200:
                # print("===httpSend=="+str(httpSend.text))
                # print("[{}][http] >> server | {}".format(deviceSN,json.dumps(a1)))
                g_cne_arr = json.loads(httpSend.text) if m1_class.is_json(httpSend.text) else {}
                if isinstance(g_cne_arr, dict):                
                    g_cne = g_cne_arr['cne'] if 'cne' in g_cne_arr else ''
                    if mode.lower() == 'status':
                        ms_minus = int(g_cne_arr['ms_minus']) if 'ms_minus' in g_cne_arr else 0
                        if 'device_config' in g_cne_arr:
                            if g_cne_arr['device_config'] is not None:
                                # data_alarm = g_cne_arr['device_config'] if m1_class.is_json(g_cne_arr['device_config']) else {}
                                data_alarm = g_cne_arr['device_config']
                                # print("===data_alarm==",data_alarm)
                                m1_class.write_file('alarm_config.json',json.dumps(data_alarm),'w')
                                os.system('sudo chmod 777 alarm_config.json')
                        # print("===httpSend=="+str(httpSend.text))
                print("[{}][{}][http] << server | {}".format(m1_class.get_current(),deviceSN,httpSend.text))

                return True,g_cne_arr
            else:
                return False,{}
        except Exception as e:
            print(f"==Error as ={e}")
            mlog_err = "http_send()|{}|{}|{}".format(e,mode,msg_input)
            m1_class.log_error(mlog_err,"event","a")
            return False,{}

    def alert_config(self,meterSN,data_command_2,lasttime_send_status,global_arr):
        # print(f'alert_config==== {meterSN} == {data_command_2}')
        try:
            if os.path.isfile('alarm_config.json'):
                alarm_txt = self.read_file('alarm_config.json')
                alarm_arr = json.loads(alarm_txt) if self.is_json(alarm_txt) else {}
                
                # print("===alarm_txt===",alarm_arr)
                if alarm_arr and self.deviceSN in alarm_arr:
                    # print("===alarm_txt===",meterSN,alarm_arr[self.deviceSN])
                    # print("===data_device_cmd==",dev_list,data_device_cmd)

                    if meterSN in alarm_arr[self.deviceSN]:
                        print("===alarm_txt===",meterSN,alarm_arr[self.deviceSN][meterSN])
                        
                        if 'AlertConfig' in alarm_arr[self.deviceSN][meterSN]:
                            alarm_list = alarm_arr[self.deviceSN][meterSN]['AlertConfig']
                            print("===alarm_txt===",alarm_arr[self.deviceSN][meterSN]['AlertConfig'])
                            print("===2==",data_command_2[meterSN])
                            for k_alarm in alarm_arr[self.deviceSN][meterSN]['AlertConfig']:
                                # print(k_alarm,data_command_2[meterSN])
                                if k_alarm in data_command_2[meterSN]:
                                    if alarm_list[k_alarm]['Active'] == 1:
                                        # print("==Alarm==",alarm_list[k_alarm]['Alarm'])
                                        # print("==Warning==",alarm_list[k_alarm]['Warning'])
                                        if alarm_list[k_alarm]['Warning'] is None:
                                            alarm_list[k_alarm]['Warning']['Max'] = 0
                                            alarm_list[k_alarm]['Warning']['Min'] = 0
                                        if alarm_list[k_alarm]['Alarm'] is None:
                                            alarm_list[k_alarm]['Alarm']['Max'] = 0
                                            alarm_list[k_alarm]['Alarm']['Min'] = 0
                                        # if alarm_list[k_alarm]['Warning'] is not None:
                                        # print("==Alarm==",alarm_list[k_alarm]['Alarm'])
                                        
                                        # if float(data_command_2[dev_list][k_alarm]) >= float(alarm_list[k_alarm]['Warning']['Max']) or float(data_command_2[dev_list][k_alarm]) <= float(alarm_list[k_alarm]['Warning']['Min']):
                                        if data_command_2[meterSN][k_alarm]:
                                            # print(f'===={type(data_command_2[dev_list][k_alarm])}')
                                            # print(f'===={float(data_command_2[dev_list][k_alarm])}')
                                            v_local = float(data_command_2[meterSN][k_alarm])
                                        
                                            # if alarm_list[k_alarm]['Alarm']['Min'] or alarm_list[k_alarm]['Alarm']['Max'] :
                                                # print(f'==Min: {alarm_list[k_alarm]["Alarm"]["Min"]}')
                                                # print(f'==Max: {alarm_list[k_alarm]["Alarm"]["Max"]}')
                                            print(f'==Max: {alarm_list[k_alarm]}')
                                                
                                            alarm_min = float(alarm_list[k_alarm]['Alarm']['Min']) if alarm_list[k_alarm]['Alarm']['Min'] != '' else 0.0
                                            alarm_max = float(alarm_list[k_alarm]['Alarm']['Max']) if alarm_list[k_alarm]['Alarm']['Max'] != '' else alarm_min
                                            warn_min = float(alarm_list[k_alarm]['Warning']['Min']) if alarm_list[k_alarm]['Warning']['Min'] != '' else 0.0
                                            warn_max = float(alarm_list[k_alarm]['Warning']['Max']) if alarm_list[k_alarm]['Warning']['Max'] != '' else warn_min
                                            
                                            # if alarm_max < alarm_min:
                                            #     alarm_max = alarm_min
                                            print(f'Alarm=={v_local}== min: {alarm_min} , max: {alarm_max}')
                                            print(f'Warnn=={v_local}== min: {warn_min} , max: {warn_max}')
                                            
                                            if v_local >= warn_max or v_local >= alarm_max or v_local <= warn_min or v_local <= alarm_min:
                                                print(f'=={meterSN} : {data_command_2}')
                                                if not 'alarm_arr' in global_arr:
                                                    global_arr['alarm_arr'] = {}
                                                tmp = global_arr['alarm_arr']
                                                if not self.deviceSN in tmp:
                                                    tmp[self.deviceSN] = {}
                                                if not meterSN in tmp[self.deviceSN]:
                                                    tmp[self.deviceSN][meterSN] = {}
                                                if not k_alarm in tmp[self.deviceSN][meterSN]:
                                                    tmp[self.deviceSN][meterSN][k_alarm] = int(time.time())
                                                print(f'==={int(time.time())}===={tmp[self.deviceSN][meterSN]}====last:[{int(lasttime_send_status)}]')
                                                if not 'status_alarm' in tmp:
                                                    tmp['status_alarm'] = 0
                                                if tmp['status_alarm'] == 0 and int(time.time()) >= int(lasttime_send_status):
                                                    tmp['status_alarm'] = 1
                                                    tmp[self.deviceSN][meterSN][k_alarm] = int(time.time())
                                                # print(f'==={int(time.time())}===={tmp[m1_class.deviceSN][meterSN]}====last:[{int(lasttime_send_status.value)}]')
                                                global_arr['alarm_arr'] = tmp
                                                
                                                
                                            # print(f'===={alarm_list[k_alarm]['Alarm']}')
                                                # print(f'==={v_local} == {alarm_min} | {alarm_max}') 
                                            # if alarm_min >= v_local >= alarm_max:
                                                
                                                
                                            #     print(f'=={v_local}== min: {alarm_min} , max: {alarm_max}')
                                            #     # print(k_alarm,data_command_2[dev_list])
                                            #     a1_alarm = dict()
                                            #     a1_alarm["mode"] = "alarm"
                                            #     a1_alarm["device_SN"] = m1_class.deviceSN
                                            #     a1_alarm["data"] = data_command_2
                                            #     a1_alarm["ts"] = int(time.time())
                                            #     a1_alarm["dt"] = datetime.fromtimestamp(int(time.time())).strftime("%Y-%m-%d %H:%M:%S")
                                            #     # print(f"== Alarm == {a1_alarm}")
                                            #     m1_class.log_server(json.dumps(a1_alarm),"log5","a")
                                                # m1_class.http_send('alarm',  data_command_2, g_cne=g_cne.value)
            # # /////////////////////////////////////////////////////////////////////
            return global_arr
        except Exception as e:
            return global_arr
        
    def on_connect(self, client, userdata, flags, reason_code, properties):
        # print(f"Connected with result code {reason_code}")
        m1_class = self
        print("[{}][{}][mqtt] >> server | {}".format(m1_class.get_current(),self.deviceSN,f"Connected with result code {reason_code}"))

        # Subscribing in on_connect() means that if we lose the connection and
        # reconnect then subscriptions will be renewed.
        client.subscribe("meow/{}".format(self.deviceSN))
    
    def on_message(self, client, userdata, msg):
        # global ts_cmd
        # global g_cne
        m1_class = self
        deviceSN = self.deviceSN
        mqtt_server = self.mqttBroker
        mqtt_port = self.mqttPort
        
        try:
            msg_arr = dict()
            message = (msg.payload).decode("utf-8")
            time_conn = m1_class.get_current()
            print("[{}][{}][mqtt] << server | {}".format(m1_class.get_current(),deviceSN,message))

            # print(str(time_conn)+'|'+(message))
            # print(msg.topic+" "+str(msg.payload))
            msg = message
            if m1_class.is_json(msg):
                msg_arr = json.loads(msg)
                time_data = m1_class.get_current()
                
                # writefile(msg,'cma.json','w')
                ts_e = int(time.time())
                if msg_arr:
                    if 'ts_cmd' in msg_arr:
                        # print("=====",msg_arr['ts_cmd'])
                        ts_cmd = msg_arr['ts_cmd']
                        diff_cmd = int(int(time.time())-int(ts_cmd))

                        if diff_cmd > 120 and diff_cmd != int(time.time()):
                            ts_cmd = int(time.time())
                            os.system("sudo pm2 restart all")
                    if 'ts_cmn' in msg_arr:
                        # print("=====",msg_arr['ts_cmd'])
                        ts_cmd = msg_arr['ts_cmn']
                        diff_cmd = int(int(time.time())-int(ts_cmd))

                        if diff_cmd > 120 and diff_cmd != int(time.time()):
                            ts_cmd = int(time.time())
                            os.system("sudo pm2 restart all")

                    if 'mode' in msg_arr: #special function()
                        # print(msg_arr['mode'],"==",msg_arr['data'])
                        msg_mode = msg_arr['mode']
                        cmd_id = (
                            msg_arr.get('cmd_id')
                            or msg_arr.get('cmn_id')
                            or "C" + str(int(time.time() * 1000))
                        )
                        ts_cmd = int(time.time())

                        if msg_mode == "check_ws":
                            pass
                        elif msg_mode == "set_tou":
                            cmd_id = msg_arr.get('cmd_id', "C" + str(int(time.time()*1000)))
                            try:
                                tou_result = save_tou_schedule(msg_arr.get('data', {}))
                                msg_txt = {"mode": msg_mode, "result": 1, "text": tou_result}
                            except Exception as error:
                                msg_txt = {
                                    "mode": msg_mode,
                                    "result": 0,
                                    "text": {"success": False, "error": str(error)}
                                }
                            m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                            mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                            mqttc3.connect(mqtt_server, int(mqtt_port), 15)
                            mqttc3.publish("res/" + deviceSN, json.dumps(msg_txt))
                            mqttc3.disconnect()
                            print(f"=== TOU schedule result cmd_id={cmd_id}: {msg_txt}")
                            return
                        elif msg_mode == "updatefw":
                            cmd_id = msg_arr.get('cmd_id', "C" + str(int(time.time() * 1000)))
                            msg_data = msg_arr.get('data', {})
                            if not isinstance(msg_data, dict):
                                msg_data = {}

                            # Firmware installation can take several minutes.  Keep it
                            # outside Paho's callback thread so MQTT keepalive and all
                            # meter-reading processes continue normally.
                            def run_firmware_update():
                                def fw_progress(state):
                                    progress_msg = {
                                        "mode": msg_mode,
                                        "cmd_id": cmd_id,
                                        "progress": state
                                    }
                                    client.publish(
                                        "res/" + deviceSN,
                                        json.dumps(progress_msg),
                                        qos=1
                                    )
                                    print(
                                        f"=== updatefw [{state.get('percent')}%] "
                                        f"{state.get('stage')}: {state.get('message')}",
                                        flush=True
                                    )

                                fw_result = m1_class.update_firmware(
                                    msg_data,
                                    progress=fw_progress
                                )
                                print(
                                    f"=== updatefw [{fw_result.get('stage')}] "
                                    f"success={fw_result.get('success')} | "
                                    f"{fw_result.get('error', '')}",
                                    flush=True
                                )
                                fw_reply = {
                                    "mode": msg_mode,
                                    "cmd_id": cmd_id,
                                    "result": 1 if fw_result.get("success") else 0,
                                    "text": fw_result
                                }
                                publish_info = client.publish(
                                    "res/" + deviceSN,
                                    json.dumps(fw_reply),
                                    qos=1
                                )
                                try:
                                    publish_info.wait_for_publish(timeout=5)
                                except Exception:
                                    pass
                                if fw_result.get('success') and fw_result.get('restart_required'):
                                    threading.Thread(
                                        target=m1_class._restart_py_multi_after_reply,
                                        name='firmware-service-restart',
                                        daemon=True
                                    ).start()

                            queued = {
                                "mode": msg_mode,
                                "cmd_id": cmd_id,
                                "progress": {
                                    "stage": "queued",
                                    "percent": 0,
                                    "message": "Firmware update queued"
                                }
                            }
                            client.publish("res/" + deviceSN, json.dumps(queued), qos=1)
                            threading.Thread(
                                target=run_firmware_update,
                                name=f'firmware-update-{cmd_id}',
                                daemon=True
                            ).start()
                            return
                        elif msg_mode == "cmd":
                            msg_cmdid = msg_arr['cmd_id'] if "cmd_id" in msg_arr else "C" + str(int(time.time()*1000))
                            cmd_id = msg_cmdid
                            msg_data = msg_arr['data']
                            data_output = {}
                            # print("===Output_CMD:",msg_data)
                            if "getfile" in msg_data:
                                def download(url,target):
                                    get_response = requests.get(url,stream=True)
                                    file_name  = url.split("/")[-1]
                                    # print(os.getcwd())
                                    with open(os.getcwd()+"/config/"+target, 'wb') as f:
                                        for chunk in get_response.iter_content(chunk_size=1024):
                                            if chunk: # filter out keep-alive new chunks
                                                f.write(chunk)
                                        os.system('sudo chmod 777 '+os.getcwd()+"/config/"+target)

                                path_dl = list(msg_data['getfile'].keys())[0]
                                path_tg = list(list(msg_data['getfile'].values())[0].keys())[0]
                                print("==path :",path_dl)
                                print("==Target :",path_tg)
                                download(path_dl,path_tg)
                            elif "EV" in msg_data:
                                data_output = {}
                                if "config" in msg_data['EV']:
                                    if "write" in msg_data['EV']['config']:
                                        print("==path_EV",msg_data['EV']['config']['write'])
                                        cev_txt = m1_class.read_file('cev.json') if os.path.exists('cev.json') else ""
                                        cev_arr = json.loads(cev_txt) if m1_class.is_json(cev_txt) else {}
                                        data_output[cmd_id] = {"writeconfig":msg_data['EV']['config']}
                                        print("===Output:",data_output)
                                        # writefile(json.dumps(data_output),'cev.json','w')
                                        # writefile(json.dumps(data_output),'cma2.json','w')
                                        os.system('sudo chmod 777 cev.json')
                                    if "read" in msg_data['EV']['config']:
                                        print("==path_EV",msg_data['EV']['config']['read'])
                                        cev_txt = m1_class.read_file('cev.json') if os.path.exists('cev.json') else ""
                                        cev_arr = json.loads(cev_txt) if m1_class.is_json(cev_txt) else {}
                                        data_output[cmd_id] = {"readconfig":msg_data['EV']['config']}
                                        print("===Output:",data_output)
                                        # writefile(json.dumps(data_output),'cev.json','w')
                                        # writefile(json.dumps(data_output),'cma2.json','w')
                                        os.system('sudo chmod 777 cev.json')
                            elif "uploadfile" in msg_data:
                                msg_data_arr = msg_data['uploadfile']
                                print(msg_data_arr['src'],msg_data_arr['dst'])
                                if m1_class.ftp_upload(msg_data_arr['src'],msg_data_arr['dst']):
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "upload success. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'uploadfile':'success','data':msg_data_arr['src']}, cmd_id=cmd_id)
                                else:
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "upload not found. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'uploadfile':'505','data':msg_data_arr['src']}, cmd_id=cmd_id)
                            elif "downloadfile" in msg_data:
                                msg_data_arr = msg_data['downloadfile']
                                print(msg_data_arr['src'],msg_data_arr['dst'])
                                if m1_class.ftp_download(msg_data_arr['src'],msg_data_arr['dst']):
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "download success. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'download':'success','data':msg_data_arr['src']}, cmd_id=cmd_id)
                                else:
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "download not found. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'download':'505','data':msg_data_arr['src']}, cmd_id=cmd_id)
                            elif "VPN" in msg_data:
                                vpn_result = m1_class.set_vpn_state(msg_data["VPN"])
                                msg_txt = {
                                    "mode": msg_mode,
                                    "result": 1 if vpn_result["success"] else 0,
                                    "text": vpn_result
                                }
                                m1_class.http_send("reply", msg_txt, cmd_id=cmd_id)
                                mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                mqttc3.connect(mqtt_server, int(mqtt_port), 15)
                                mqttc3.publish("res/" + deviceSN, json.dumps(msg_txt))
                                mqttc3.disconnect()
                                print("===VPN managed result", vpn_result)
                                return
                                print("===VPN",msg_data['VPN'])
                                # global status_vpn
                                
                                status_vpn = int(msg_data['VPN'])
                                if int(msg_data['VPN']) == 0:
                                    a1 = {}
                                    msg_txt = {}
                                    a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                    a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                    msg_txt['mode'] = msg_mode
                                    msg_txt['result'] = 0
                                    msg_txt['text'] = a1
                                    
                                    m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                    mqttc3.publish("meow/"+deviceSN, json.dumps(a1))
                                    mqttc3.disconnect()
                                    # os.system("sudo systemctl restart openvpn.service")
                                    os.system("sudo pm2 restart all")
                                    print("sudo pm2 restart all")
                                    # self.py_vpn.stop()
                                    

                                if int(msg_data['VPN']) == 1:
                                    # status_vpn = 1 if status_vpn == 0 else 0
                                    print("connect VPN")
                                    if m1_class.get_ipv4_from_nic("tun0") != None:
                                        # os.system("sudo killall openvpn")
                                        os.system("sudo systemctl restart openvpn.service")

                                    # /home/pi/Desktop/python/VPN/VPN
                                    # subprocess.Popen("python3 /home/mdbcare/python/zrunvpn.py", shell=True)
                                    self.py_vpn.start()
                                    a1 = {}
                                    msg_txt = {}
                                    a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                    a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                    
                                    
                                    
                                    time.sleep(10)
                                    t_start = int(time.time())
                                    if a1["ipvpn"] is None or int(time.time()) - t_start >= 30:
                                        a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                        a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                        msg_txt['mode'] = msg_mode
                                        msg_txt['result'] = 1
                                        msg_txt['text'] = a1
                                        
                                        m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                                        t_start = int(time.time())
                                        mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                        mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                        mqttc3.publish("meow/"+deviceSN, json.dumps(a1))
                                        mqttc3.disconnect()
                                else:
                                    a1 = {}
                                    msg_txt = {}
                                    a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                    a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                    msg_txt['mode'] = msg_mode
                                    msg_txt['result'] = 0
                                    msg_txt['text'] = a1
                                    
                                    print(json.dumps(a1))
                                    m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                    mqttc3.publish("meow/"+deviceSN, json.dumps(a1))
                            else: 
                                msg_cmdid = msg_arr['cmd_id'] if "cmd_id" in msg_arr else "C" + str(int(time.time()*1000))
                                cmd_id = msg_cmdid
                                msg_data = msg_arr['data']
                                data_output = {}
                                # print("===Read==",m1_class.read_file('cma.json'))

                                if True:
                                    cma_txt = m1_class.read_file('cma.json')
                                    data_output = json.loads(cma_txt) if m1_class.is_json(cma_txt) else {}
                                    print("===========================================================")
                                    print("===ssssPre read===",data_output)
                                    print("============================")
                                    data_output[cmd_id] = {msg_mode:msg_data}
                                    # data_output = {cmd_id:{msg_mode:msg_data}}
                                    print("===ssssssOutput:",data_output)
                                    print("===========================================================")
                                    m1_class.write_file('cma.json',json.dumps(data_output),'w')
                                    # writefile(json.dumps(data_output),'cma2.json','w')
                                    os.system('sudo chmod 777 cma.json')
                                    # os.system('sudo chmod 777 cma2.json')
                                
                        
                        elif msg_mode == "cmn":
                            msg_cmdid = msg_arr['cmn_id'] if "cmn_id" in msg_arr else "C" + str(int(time.time()*1000))
                            cmd_id = msg_cmdid
                            msg_data = msg_arr['data']
                            data_output = {}
                            # print("===Output_CMD:",msg_data)
                            if "getfile" in msg_data:
                                def download(url,target):
                                    get_response = requests.get(url,stream=True)
                                    file_name  = url.split("/")[-1]
                                    # print(os.getcwd())
                                    with open(os.getcwd()+"/config/"+target, 'wb') as f:
                                        for chunk in get_response.iter_content(chunk_size=1024):
                                            if chunk: # filter out keep-alive new chunks
                                                f.write(chunk)
                                        os.system('sudo chmod 777 '+os.getcwd()+"/config/"+target)

                                path_dl = list(msg_data['getfile'].keys())[0]
                                path_tg = list(list(msg_data['getfile'].values())[0].keys())[0]
                                print("==path :",path_dl)
                                print("==Target :",path_tg)
                                download(path_dl,path_tg)
                            elif "EV" in msg_data:
                                data_output = {}
                                if "config" in msg_data['EV']:
                                    if "write" in msg_data['EV']['config']:
                                        print("==path_EV",msg_data['EV']['config']['write'])
                                        cev_txt = m1_class.read_file('cev.json') if os.path.exists('cev.json') else ""
                                        cev_arr = json.loads(cev_txt) if m1_class.is_json(cev_txt) else {}
                                        data_output[cmd_id] = {"writeconfig":msg_data['EV']['config']}
                                        print("===Output:",data_output)
                                        # writefile(json.dumps(data_output),'cev.json','w')
                                        # writefile(json.dumps(data_output),'cma2.json','w')
                                        os.system('sudo chmod 777 cev.json')
                                    if "read" in msg_data['EV']['config']:
                                        print("==path_EV",msg_data['EV']['config']['read'])
                                        cev_txt = m1_class.read_file('cev.json') if os.path.exists('cev.json') else ""
                                        cev_arr = json.loads(cev_txt) if m1_class.is_json(cev_txt) else {}
                                        data_output[cmd_id] = {"readconfig":msg_data['EV']['config']}
                                        print("===Output:",data_output)
                                        # writefile(json.dumps(data_output),'cev.json','w')
                                        # writefile(json.dumps(data_output),'cma2.json','w')
                                        os.system('sudo chmod 777 cev.json')
                            elif "uploadfile" in msg_data:
                                msg_data_arr = msg_data['uploadfile']
                                print(msg_data_arr['src'],msg_data_arr['dst'])
                                if m1_class.ftp_upload(msg_data_arr['src'],msg_data_arr['dst']):
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "upload success. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'uploadfile':'success','data':msg_data_arr['src']}, cmd_id=cmd_id)
                                else:
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "upload not found. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'uploadfile':'505','data':msg_data_arr['src']}, cmd_id=cmd_id)
                            elif "downloadfile" in msg_data:
                                msg_data_arr = msg_data['downloadfile']
                                print(msg_data_arr['src'],msg_data_arr['dst'])
                                if m1_class.ftp_download(msg_data_arr['src'],msg_data_arr['dst']):
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "download success. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'download':'success','data':msg_data_arr['src']}, cmd_id=cmd_id)
                                else:
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect(mqtt_server, mqtt_port, 60)
                                    mqttc3.publish("res/"+deviceSN, "download not found. " + json.dumps(msg_data_arr['src']))
                                    m1_class.http_send('reply', {'download':'505','data':msg_data_arr['src']}, cmd_id=cmd_id)
                            elif "VPN" in msg_data:
                                vpn_result = m1_class.set_vpn_state(msg_data["VPN"])
                                msg_txt = {
                                    "mode": msg_mode,
                                    "result": 1 if vpn_result["success"] else 0,
                                    "text": vpn_result
                                }
                                m1_class.http_send("reply", msg_txt, cmd_id=cmd_id)
                                mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                mqttc3.connect(mqtt_server, int(mqtt_port), 15)
                                mqttc3.publish("res/" + deviceSN, json.dumps(msg_txt))
                                mqttc3.disconnect()
                                print("===VPN managed result", vpn_result)
                                return
                                print("===VPN",msg_data['VPN'])
                                # global status_vpn
                                
                                status_vpn = int(msg_data['VPN'])
                                if int(msg_data['VPN']) == 0:
                                    a1 = {}
                                    msg_txt = {}
                                    a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                    a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                    msg_txt['mode'] = msg_mode
                                    msg_txt['result'] = 0
                                    msg_txt['text'] = a1
                                    
                                    m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                    mqttc3.publish("meow/"+deviceSN, json.dumps(a1))
                                    mqttc3.disconnect()
                                    # os.system("sudo systemctl restart openvpn.service")
                                    os.system("sudo pm2 restart all")
                                    print("pm2 restart all")
                                    # self.py_vpn.stop()
                                    

                                if int(msg_data['VPN']) == 1:
                                    # status_vpn = 1 if status_vpn == 0 else 0
                                    print("connect VPN")
                                    if m1_class.get_ipv4_from_nic("tun0") != None:
                                        # os.system("sudo killall openvpn")
                                        os.system("sudo systemctl restart openvpn.service")

                                    # /home/pi/Desktop/python/VPN/VPN
                                    # subprocess.Popen("python3 /home/mdbcare/python/zrunvpn.py", shell=True)
                                    self.py_vpn.start()
                                    a1 = {}
                                    msg_txt = {}
                                    a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                    a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                    
                                    
                                    
                                    time.sleep(10)
                                    t_start = int(time.time())
                                    if a1["ipvpn"] is None or int(time.time()) - t_start >= 30:
                                        a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                        a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                        msg_txt['mode'] = msg_mode
                                        msg_txt['result'] = 1
                                        msg_txt['text'] = a1
                                        
                                        m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                                        t_start = int(time.time())
                                        mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                        mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                        mqttc3.publish("meow/"+deviceSN, json.dumps(a1))
                                        mqttc3.disconnect()
                                else:
                                    a1 = {}
                                    msg_txt = {}
                                    a1["ipvpn"] = m1_class.get_ipv4_from_nic("tun0")
                                    a1["status"] = "connect" if m1_class.get_ipv4_from_nic("tun0") != None else "disconnect"
                                    msg_txt['mode'] = msg_mode
                                    msg_txt['result'] = 0
                                    msg_txt['text'] = a1
                                    
                                    print(json.dumps(a1))
                                    m1_class.http_send('reply', msg_txt, cmd_id=cmd_id)
                                    mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                    mqttc3.connect("mqtt.mdbiot.com", 1883, 15)
                                    mqttc3.publish("meow/"+deviceSN, json.dumps(a1))
                            else: 
                                msg_cmdid = msg_arr['cmn_id'] if "cmn_id" in msg_arr else "C" + str(int(time.time()*1000))
                                cmd_id = msg_cmdid
                                msg_data = msg_arr['data']
                                data_output = {}
                                # print("===Read==",m1_class.read_file('cma.json'))

                                if True:
                                    cma_txt = m1_class.read_file('cma.json')
                                    data_output = json.loads(cma_txt) if m1_class.is_json(cma_txt) else {}
                                    print("===========================================================")
                                    print("===ssssPre read===",data_output)
                                    print("============================")
                                    data_output[cmd_id] = {msg_mode:msg_data}
                                    # data_output = {cmd_id:{msg_mode:msg_data}}
                                    print("===ssssssOutput:",data_output)
                                    print("===========================================================")
                                    m1_class.write_file('cma.json',json.dumps(data_output),'w')
                                    # writefile(json.dumps(data_output),'cma2.json','w')
                                    os.system('sudo chmod 777 cma.json')
                                    # os.system('sudo chmod 777 cma2.json')
                                        
                        else:
                            if "cmd_id" in msg_arr:
                                msg_cmdid = msg_arr['cmd_id'] if "cmd_id" in msg_arr else "C" + str(int(time.time()*1000))
                                cmd_id = msg_cmdid
                                msg_data = msg_arr['data']
                                data_output = {}
                                # print("===Read==",m1_class.read_file('cma.json'))

                                cma_txt = m1_class.read_file('cma.json')
                                data_output = json.loads(cma_txt) if m1_class.is_json(cma_txt) else {}
                                # print("===Pre read===",data_output)


                                data_output[cmd_id] = {msg_mode:msg_data}
                                # data_output = {cmd_id:{msg_mode:msg_data}}
                                # print("===Output:",data_output)
                                m1_class.write_file('cma.json',json.dumps(data_output),'w')
                                # writefile(json.dumps(data_output),'cma2.json','w')
                                os.system('sudo chmod 777 cma.json')
                                # os.system('sudo chmod 777 cma2.json')
                                
                            if "cmn_id" in msg_arr:
                                msg_cmdid = msg_arr['cmn_id'] if "cmn_id" in msg_arr else "C" + str(int(time.time()*1000))
                                cmd_id = msg_cmdid
                                msg_data = msg_arr['data']
                                data_output = {}
                                # print("===Read==",m1_class.read_file('cma.json'))

                                cma_txt = m1_class.read_file('cma.json')
                                data_output = json.loads(cma_txt) if m1_class.is_json(cma_txt) else {}
                                # print("===Pre read===",data_output)


                                data_output[cmd_id] = {msg_mode:msg_data}
                                # data_output = {cmd_id:{msg_mode:msg_data}}
                                # print("===Output:",data_output)
                                m1_class.write_file('cma.json',json.dumps(data_output),'w')
                                # writefile(json.dumps(data_output),'cma2.json','w')
                                os.system('sudo chmod 777 cma.json')
                                # os.system('sudo chmod 777 cma2.json')
                        
                            
                        # if "restart" in msg_arr:
                        #     os.system('sudo reboot')
                        if msg_mode == "restart":
                            os.system('sudo reboot')
                        if msg_mode == "pm2restart":
                            os.system("sudo pm2 restart all")
                        if msg_mode == "pip":
                            msg_data = msg_arr['data']
                            # print(msg_data)
                            # os.system("lxterminal -e sudo pip install paho-mqtt")
                            # if "sudo" in msg_data:
                            #     os.system(msg_data)
                            os.system(msg_data)
                        if msg_mode == "vpn":
                            msg_data = msg_arr['data']
                        if msg_mode == "uploadfile":
                            msg_data = msg_arr['data']
                            print("=======",msg_data['src'])
                            msg_txt = {}
                            if m1_class.ftp_upload(msg_data['src'],msg_data['dst']):
                                msg_txt['mode'] = msg_mode
                                msg_txt['result'] = 1
                                msg_txt['text'] = msg_data
                                # pass
                                # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                # mqttc3.connect(mqtt_server, mqtt_port, 60)
                                # mqttc3.publish("res/"+deviceSN, "upload success")
                            else:
                                msg_txt['mode'] = msg_mode
                                msg_txt['result'] = 0
                                msg_txt['text'] = msg_data
                                print(f'fail ')
                                # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                # mqttc3.connect(mqtt_server, mqtt_port, 60)
                                # mqttc3.publish("res/"+deviceSN, "upload not found")
                            print(f'===msg_txt: {msg_txt}')
                            # http_send('reply', msg_txt, cmd_id=cmd_id)
                            m1_class.http_send('reply', msg_input=msg_txt, cmd_id=cmd_id)
                            # http_send('reply', msg_data, cne_input=g_cne, cmd_id=cmd_id)
                        if msg_mode == "downloadfile":
                            msg_data = msg_arr['data']
                            msg_txt = {}
                            
                            if m1_class.ftp_download(msg_data['src'],msg_data['dst']):
                                # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                # mqttc3.connect(mqtt_server, mqtt_port, 60)
                                # mqttc3.publish("res/"+deviceSN, "download success")
                                msg_txt['mode'] = msg_mode
                                msg_txt['result'] = 1
                                msg_txt['text'] = msg_data['src']
                            else:
                                # mqttc3 = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
                                # mqttc3.connect(mqtt_server, mqtt_port, 60)
                                # mqttc3.publish("res/"+deviceSN, "download not found")
                                msg_txt['mode'] = msg_mode
                                msg_txt['result'] = 0
                                msg_txt['text'] = msg_data['src']
                            # http_send('reply', msg_data, cne_input=g_cne, cmd_id=cmd_id)
                            m1_class.http_send('reply', msg_input=msg_txt, cmd_id=cmd_id)
                        if msg_mode == "setconfig":
                            msg_data = msg_arr['data']
                            
                            print(f'=== setconfig: ==={msg_data}')
                            # print(f'=== path config: ==={path_fileconfig}')
                            main_txt = m1_class.read_file(self.path_def)
                            main_txt_arr    = json.loads(main_txt) if m1_class.is_json(main_txt) else {}
                            print(f'====main_txt_arr: ===={main_txt_arr.keys()}')

            else:
                time_data = m1_class.get_current()
                print(str(time_data)+' | '+str(msg))
            
        except Exception as e:
            print("[!] Exception is {}".format(e))

