# -*- coding: utf-8 -*-
import ipaddress
from bacpypes.core import run, deferred
from bacpypes.local.device import LocalDeviceObject
from bacpypes.app import BIPSimpleApplication
from bacpypes.apdu import WhoIsRequest, IAmRequest
from bacpypes.pdu import Address

# ---------------- CONFIG ----------------
SUBNET = "172.16.21.0/24"

found_devices = set()

# ---------------- DEVICE ----------------
this_device = LocalDeviceObject(
    objectName="BACnet-Port-Scanner",
    objectIdentifier=999,
    vendorIdentifier=999
)

app = BIPSimpleApplication(this_device, "0.0.0.0")


# ---------------- I-AM HANDLER ----------------
def indication(apdu):
    if isinstance(apdu, IAmRequest):
        ip = str(apdu.pduSource)

        if ip not in found_devices:
            found_devices.add(ip)
            print(f"[✓] BACnet device (47808 active) at: {ip}")


app.indication = indication


# ---------------- SCAN ----------------
def scan():
    net = ipaddress.ip_network(SUBNET, strict=False)

    print(f"[*] Scanning subnet {SUBNET} for BACnet port 47808 ...")

    for ip in net.hosts():
        req = WhoIsRequest()
        req.pduDestination = Address(str(ip))
        app.request(req)


deferred(scan)
run()