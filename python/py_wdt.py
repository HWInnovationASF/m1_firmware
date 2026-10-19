import serial
import time

def Watchdog():
    serWD = serial.Serial('/dev/ttyAMA2', 9600, writeTimeout=1 ,timeout=1,)
    serWD.write(("WDFromRasPi\r\n").encode())
    
    
start_wtd = int(time.time())
try:
    while True:
        loop_wtd = int(time.time())
        diff_wtd = loop_wtd - start_wtd
        # print("====Time diff=====",diff_wtd)
        # publish_data("11111111")
        if diff_wtd >= 15:
            start_wtd = int(time.time())
            print("===Check Wtd====")
            Watchdog()

        
except:
    print("====Core3 Error======")

# from serial.tools import list_ports

# for p in list_ports.comports():
#     print(p.device, p.description)