# -*- coding: utf-8 -*-
import logging
import socket
from collections import deque

from bacpypes.core import run, deferred
from bacpypes.local.device import LocalDeviceObject
from bacpypes.app import BIPSimpleApplication
from bacpypes.apdu import WhoIsRequest, ReadPropertyRequest, IAmRequest
from bacpypes.pdu import Address
from bacpypes.iocb import IOCB

from bacpypes.primitivedata import (
    Real, Integer, Boolean, Enumerated, Unsigned, CharacterString
)
from bacpypes.constructeddata import ArrayOf
from bacpypes.primitivedata import ObjectIdentifier

# ---------------- AUTO IP ----------------
s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
try:
    s.connect(("8.8.8.8", 80))
    local_ip = s.getsockname()[0]
except:
    local_ip = "0.0.0.0"
s.close()

LOCAL_IP = f"{local_ip}/24"
TARGET_IP = "172.16.21.175"

print(f"[+] Local IP: {LOCAL_IP}")

# ---------------- CONFIG ----------------
TIMEOUT = 5
logging.basicConfig(level=logging.WARNING)

device_id = None
object_queue = deque()
seen_devices = set()

# ---------------- BACnet INIT ----------------

this_device = LocalDeviceObject(
    objectName="PythonClient",
    objectIdentifier=204,
    vendorIdentifier=999
)

this_app = BIPSimpleApplication(this_device, LOCAL_IP)

# ---------------- DECODE ANY (�����ѡ) ----------------

def decode_any(apdu):
    val = apdu.propertyValue

    # 1. �ͧ type ����ͺ���
    for t in (Real, Integer, Unsigned, Boolean, Enumerated, CharacterString):
        try:
            return val.cast_out(t)
        except:
            pass

    # 2. �ͧ array (�� objectList)
    try:
        return val.cast_out(ArrayOf(ObjectIdentifier))
    except:
        pass

    # 3. debug raw (�ó� decode �����)
    try:
        print("[DEBUG UNKNOWN TYPE]")
        val.debug_contents()
    except:
        pass

    return "<Unknown>"

# ---------------- READ FUNCTION ----------------

def read_property(obj_id, prop, callback):
    req = ReadPropertyRequest(
        objectIdentifier=obj_id,
        propertyIdentifier=prop
    )
    req.pduDestination = Address(TARGET_IP)

    iocb = IOCB(req)
    iocb.set_timeout(TIMEOUT)

    def _callback(iocb):
        if iocb.ioResponse:
            callback(iocb.ioResponse)
        else:
            schedule_next()

    iocb.add_callback(_callback)
    this_app.request_io(iocb)

# ---------------- SCHEDULER ----------------

def schedule_next():
    deferred(process_queue)

# ---------------- SCAN ENGINE ----------------

def process_queue():
    if not object_queue:
        print("\n[?] Scan complete")
        return

    obj_id = object_queue.popleft()
    obj_type = obj_id[0]

    obj_list = [
        'analogInput', 'analogOutput', 'analogValue',
        'binaryInput', 'binaryOutput', 'binaryValue',
        'multiStateInput', 'multiStateOutput', 'multiStateValue'
    ]
    # filter object ����դ��
    if obj_type not in [
        'analogInput', 'analogOutput', 'analogValue',
        'binaryInput', 'binaryOutput', 'binaryValue',
        'multiStateInput', 'multiStateOutput', 'multiStateValue'
    ]:
    # if obj_type not in obj_list:
        schedule_next()
        return

    # -------- read name --------
    def on_name(apdu):
        name = decode_any(apdu)

        # -------- read value --------
        def on_value(apdu2):
            value = decode_any(apdu2)

            # �ŧ ON/OFF
            if value == 0:
                value = "OFF"
            elif value == 1:
                value = "ON"

            print(f"{obj_id} | {name} = {value}")

            schedule_next()

        # -------- fallback --------
        def on_fallback(apdu3):
            value = decode_any(apdu3)

            print(f"{obj_id} | {name} (fallback) = {value}")

            schedule_next()

        read_property(obj_id, 'presentValue',
            lambda apdu2:
                on_value(apdu2) if apdu2 else
                read_property(obj_id, 'relinquishDefault', on_fallback)
        )

    read_property(obj_id, 'objectName', on_name)

# ---------------- HANDLER ----------------

def indication(apdu):
    global device_id

    if isinstance(apdu, IAmRequest):

        if apdu.pduSource in seen_devices:
            return
        seen_devices.add(apdu.pduSource)

        print(f"[+] I-Am from {apdu.pduSource}")

        device_id = apdu.iAmDeviceIdentifier[1]

        def on_object_list(apdu):
            try:
                objs = apdu.propertyValue.cast_out(ArrayOf(ObjectIdentifier))

                print(f"[+] Found {len(objs)} objects")

                for obj in objs:
                    object_queue.append(obj)

                deferred(process_queue)

            except Exception as e:
                print("Error reading objectList:", e)

        read_property(('device', device_id), 'objectList', on_object_list)

this_app.indication = indication

# ---------------- START ----------------

whois = WhoIsRequest()
whois.pduDestination = Address(TARGET_IP)

print("[*] Sending Who-Is...")
this_app.request(whois)

run()