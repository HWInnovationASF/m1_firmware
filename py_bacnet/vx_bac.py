import logging
from bacpypes.core import run, stop
from bacpypes.local.device import LocalDeviceObject
from bacpypes.app import BIPSimpleApplication
from bacpypes.apdu import WhoIsRequest, ReadPropertyRequest, IAmRequest
from bacpypes.pdu import Address

# ---------------- CONFIG ----------------
LOCAL_IP = "172.16.21.204/24"   # 🔥 แก้เป็น IP เครื่องคุณ
TARGET_IP = "172.16.21.183"

# ---------------------------------------

logging.basicConfig(level=logging.INFO)

device_id = None
object_list = []

# สร้าง device ฝั่งเรา
this_device = LocalDeviceObject(
    objectName="PythonClient",
    objectIdentifier=204,
    vendorIdentifier=999
)

# สร้าง BACnet app
this_app = BIPSimpleApplication(this_device, LOCAL_IP)

# ---------------- HANDLERS ----------------

def indication(apdu):
    global device_id, object_list

    # รับ I-Am
    if isinstance(apdu, IAmRequest):
        print(f"[+] I-Am received from {apdu.pduSource}")
        device_id = apdu.iAmDeviceIdentifier[1]
        print(f"[+] Device ID = {device_id}")

        # อ่าน objectList
        req = ReadPropertyRequest(
            objectIdentifier=('device', device_id),
            propertyIdentifier='objectList'
        )
        req.pduDestination = Address(TARGET_IP)
        this_app.request(req)

    # รับ ReadProperty response
    elif apdu.__class__.__name__ == "ReadPropertyACK":

        obj = apdu.objectIdentifier
        prop = apdu.propertyIdentifier

        # ได้ objectList
        if prop == 'objectList':
            print("[+] Object list received")
            object_list = apdu.propertyValue.cast_out(list)

            print(f"[+] Total objects: {len(object_list)}")

            # เริ่มไล่อ่าน presentValue
            for obj_id in object_list:
                req = ReadPropertyRequest(
                    objectIdentifier=obj_id,
                    propertyIdentifier='presentValue'
                )
                req.pduDestination = Address(TARGET_IP)
                this_app.request(req)

        # ได้ presentValue
        elif prop == 'presentValue':
            try:
                value = apdu.propertyValue.cast_out(object)
                print(f"{obj} = {value}")
            except:
                pass

# bind handler
this_app.indication = indication

# ---------------- START ----------------

# ยิง Who-Is แบบ unicast
whois = WhoIsRequest()
whois.pduDestination = Address(TARGET_IP)

print("[*] Sending Who-Is...")
this_app.request(whois)

# run loop
run()