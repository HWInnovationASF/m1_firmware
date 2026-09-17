# MDBIoT M1 Installation

ชุดติดตั้งประกอบด้วย `install.sh`, `html.7z` และ `python.7z`

## ติดตั้งจาก Git

```bash
git clone https://github.com/HWInnovationASF/m1_firmware.git
cd m1_firmware
sudo install -m 0755 ./m1 /usr/local/bin/m1
m1 install
```

## ติดตั้งจากไฟล์ที่คัดลอกเข้าเครื่อง

วางไฟล์ทั้งสามไว้ในโฟลเดอร์เดียวกัน แล้วรัน:

```bash
sudo bash install.sh
```

หรือเรียกผ่านคำสั่งแบบสั้นจากโฟลเดอร์ที่แตกไฟล์:

```bash
sudo ./m1 install
```

เมื่อติดตั้งครั้งแรกสำเร็จ ระบบจะเพิ่มคำสั่ง `m1` ให้เรียกใช้ได้จากทุก path:

```bash
sudo m1 install
m1 status
sudo m1 restart
m1 logs 100
```

ระหว่างติดตั้งสามารถเลือกโหมด Automation ได้:

- `ปิด` — อ่านมิเตอร์อย่างเดียว
- `Simulation` — คำนวณและบันทึก Log แต่ไม่เขียน Modbus
- `Live` — ควบคุมอุปกรณ์และเขียน Modbus จริง

การติดตั้งแบบไม่ถาม สามารถกำหนดโหมดได้โดยตรง:

```bash
sudo env YES=1 AUTOMATION_MODE=simulation bash install.sh
```

ค่า `AUTOMATION_MODE` รองรับ `off`, `simulation`, `live` และ `preserve`
โดย `preserve` ใช้ค่าปัจจุบันต่อ เหมาะสำหรับอัปเดตเครื่องเดิม

สำหรับติดตั้งแบบไม่ถามยืนยัน:

```bash
sudo YES=1 bash install.sh
```

ตัวติดตั้งจะติดตั้ง Apache, PHP, MariaDB, OpenVPN, Python libraries และสร้าง
`py_multi.service` ให้อัตโนมัติ เครื่องใหม่จะเริ่มต้นโดยปิด Automation เพื่อให้
อ่านมิเตอร์ได้อย่างปลอดภัยก่อน ส่วนการอัปเดตเครื่องเดิมจะเก็บ Config, User,
VPN และ Log เดิมไว้

หลังติดตั้ง ตรวจสอบบริการด้วย:

```bash
systemctl status py_multi.service --no-pager
systemctl status apache2.service --no-pager
```
