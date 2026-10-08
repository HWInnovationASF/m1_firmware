# คู่มือติดตั้ง MDBIoT M1 บนเครื่องใหม่

คู่มือนี้ใช้สำหรับ Raspberry Pi OS, Debian หรือ Ubuntu ที่ยังไม่ได้ติดตั้ง
Web server, PHP, Database และ Python runtime มาก่อน

> ตัวติดตั้งจะไม่สร้าง Device, Register, Dashboard, ผู้ใช้ และรหัสผ่านแทนผู้ดูแล
> เนื่องจากข้อมูลเหล่านี้เป็นข้อมูลเฉพาะของแต่ละเครื่อง

## 1. เตรียมเครื่อง

เชื่อมต่ออินเทอร์เน็ต ตั้งวันที่และ Time zone ให้ถูกต้อง แล้วติดตั้ง Git:

```bash
sudo apt update
sudo apt install -y git
```

สำหรับ Raspberry Pi แนะนำให้ใช้ Raspberry Pi OS 64-bit หรือ Debian 64-bit

## 2. ดาวน์โหลด Firmware

```bash
git clone https://github.com/HWInnovationASF/m1_firmware.git
cd m1_firmware
```

## 3. เลือกรูปแบบการติดตั้ง

### เครื่องใหม่ทั้งหมด: Apache + PHP + MariaDB

ตัวเลือกนี้เหมาะกับการติดตั้ง Raspberry Pi เครื่องใหม่และเป็นวิธีที่แนะนำ:

```bash
sudo ./m1 install \
  --full \
  --profile raspi \
  --web-dir /var/www/html \
  --python-dir /opt/mdbiot/python \
  --web-server apache \
  --database mariadb
```

ตัวติดตั้งจะแสดงแผนการติดตั้งและถามก่อนดำเนินการ โดยจะติดตั้ง:

- Apache และ PHP
- MariaDB
- Python และ virtual environment
- Python libraries ที่โปรแกรมต้องใช้
- หน้าเว็บ MDBIoT M1
- `py_multi.service`

หลังติดตั้ง หน้าเว็บจะอยู่ที่:

```text
http://IP-ADDRESS/meow/
```

### เครื่องใหม่ที่ต้องการใช้ nginx

```bash
sudo ./m1 install \
  --full \
  --profile raspi \
  --web-dir /var/www/html \
  --python-dir /opt/mdbiot/python \
  --web-server nginx \
  --database mariadb
```

ตัวติดตั้งจะติดตั้ง nginx และ PHP-FPM แต่ผู้ดูแลยังต้องสร้าง nginx server block
ให้ document root หรือ alias ชี้ไปที่ `/var/www/html/meow` และส่งไฟล์ `.php`
ให้ PHP-FPM ก่อนเปิดใช้งานจริง

### เครื่องที่มี Web server หรือ Database อยู่แล้ว

ใช้โหมด deploy-only เพื่อไม่ให้ตัวติดตั้งแก้ไขบริการเดิม:

```bash
sudo ./m1 install \
  --deploy-only \
  --web-dir /path/to/existing/document-root \
  --python-dir /opt/mdbiot/python
```

ระบบจะสร้างโฟลเดอร์ `meow` ภายใน document root ที่ระบุ

## 4. การติดตั้งอัตโนมัติ

หากใช้สำหรับเตรียมเครื่องจำนวนมาก สามารถระบุคำตอบทั้งหมดล่วงหน้าได้:

```bash
sudo ./m1 install \
  --full \
  --profile raspi \
  --web-dir /var/www/html \
  --python-dir /opt/mdbiot/python \
  --run-user mdbcare \
  --web-server apache \
  --database mariadb \
  --install-missing yes \
  --python-deps yes \
  --service yes \
  --yes
```

หากกำหนด `--run-user mdbcare` แต่ยังไม่มีผู้ใช้นี้ ตัวติดตั้งจะสร้างให้เมื่อใช้
`--install-missing yes`

## 5. ตั้งค่าครั้งแรก

เปิดหน้าเว็บด้วย IP address ของเครื่อง จากนั้นตั้งค่าข้อมูลเฉพาะเครื่อง:

1. สร้างหรือนำเข้าผู้ใช้ Super Admin
2. ตั้งค่า Database connection และสร้างฐานข้อมูลที่ระบบต้องใช้
3. เพิ่ม Device และเลือก Device model
4. ตรวจสอบ Modbus/BACnet registers
5. ตั้งค่า Network, MQTT และ VPN ตามการใช้งาน
6. เลือก Dashboard template ของอุปกรณ์
7. ตรวจสอบค่าที่อ่านได้ก่อนเปิด Automation
8. เริ่มจาก Automation `off` หรือ `simulation` ก่อนเลือก `live`

Config, User, Device data, VPN และ Log จะไม่ถูกบรรจุใน GitHub เพื่อป้องกันข้อมูล
และรหัสผ่านของแต่ละไซต์รั่วไหล

## 6. ตรวจสอบหลังติดตั้ง

```bash
systemctl status apache2 --no-pager
systemctl status mariadb --no-pager
m1 status
m1 logs 100
```

ตรวจหน้าเว็บ:

```bash
curl -I http://127.0.0.1/meow/
```

สถานะที่ถูกต้องควรเป็น `active` และหน้าเว็บควรตอบ HTTP `200` หรือ `302`

## 7. คำสั่งดูแลระบบ

```bash
m1 status             # ตรวจ Python service
sudo m1 restart       # Restart Python service
m1 logs 100           # ดู Log ล่าสุด
m1 update             # ดาวน์โหลดไฟล์ล่าสุดใน Git repository
sudo m1 install       # Deploy package ที่ดาวน์โหลดแล้ว
```

## 8. Windows และ WSL2

เปิด PowerShell ในโฟลเดอร์ repository:

```powershell
.\install-windows.ps1
```

ตัวอย่างใช้หน้าเว็บร่วมกับ AppServ ที่ติดตั้งบน Windows:

```powershell
.\install-windows.ps1 `
  -Distro Ubuntu `
  -WebDir C:\AppServ\www `
  -PythonDir /opt/mdbiot/python
```

หน้าเว็บจะอยู่ที่ `C:\AppServ\www\meow` ส่วน Python runtime จะทำงานใน WSL
ตัวติดตั้งจะไม่แก้ไขการตั้งค่า AppServ หรือ MySQL เดิม

## 9. การแก้ปัญหาเบื้องต้น

### GitHub เปิดไม่ได้หรือ DNS ใช้งานไม่ได้

```bash
getent hosts github.com
ping -c 2 1.1.1.1
```

หาก ping IP ได้แต่ค้นหา `github.com` ไม่ได้ ให้ตรวจ DNS ของเครื่องหรือ Router

### เว็บเปิดไม่ได้

```bash
systemctl status apache2 --no-pager
sudo journalctl -u apache2 -n 100 --no-pager
php -l /var/www/html/meow/index.php
```

### Python service ไม่ทำงาน

```bash
m1 status
m1 logs 200
```

ตรวจสอบ Device/Serial port, Network interface และไฟล์ config ของเครื่องก่อน
restart service

## ข้อควรระวัง

- อย่าใช้โหมด `live` ก่อนตรวจสอบ Register และค่าควบคุมทั้งหมด
- สำรอง Config และ Database ก่อนอัปเดตเครื่องที่ใช้งานจริง
- อย่านำรหัสผ่าน, VPN key หรือไฟล์ config ของลูกค้าขึ้น GitHub
- หากเครื่องมี Apache, nginx, AppServ หรือ Database อยู่แล้ว ให้ใช้ `--deploy-only`
