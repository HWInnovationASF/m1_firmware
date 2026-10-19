from multiprocessing import Process, Event
import time
import os

class py_vpn:
    def __init__(self):
        self.stop_event = Event()
        self.process = None

    def task(self):
        while not self.stop_event.is_set():
            print("#######################Running...")
            os.system('sudo openvpn --config /home/mdbcare/python/VPN/VPN.ovpn')
            time.sleep(1)

    def start(self):
        if self.process is None or not self.process.is_alive():
            self.stop_event.clear()
            self.process = Process(target=self.task)
            self.process.start()

    def stop(self):
        if self.process and self.process.is_alive():
            self.stop_event.set()
            self.process.join()
