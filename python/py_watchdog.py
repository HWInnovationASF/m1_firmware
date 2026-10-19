import os
import time
import datetime

time.sleep(5)

while True:
    try:
        file_path = "m1_timestamp.txt"

        stats = os.stat(file_path)
        modified = int(stats.st_mtime)   # last modified timestamp (seconds since epoch)
        now = int(time.time())
        #  Check if more than 10 seconds passed
        if now - modified > 10:
            print("File is older than 10 seconds")
            os.system('sudo pm2 restart py_multi')
        else:
            print(f"{now} - {modified} = {int(now - modified)}")
            print(f"File is recent : [{datetime.datetime.fromtimestamp(stats.st_ctime)}]")
        time.sleep(1)
    except Exception as e:
        print(f'{e}')