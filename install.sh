#!/usr/bin/env bash
# ============================================================================
# install.sh — ติดตั้ง MDBIoT firmware จาก repo m1_firmware (html.7z + python.7z)
#   web    -> /var/www/html      (จาก html.7z : โฟลเดอร์ html/*)
#   python -> /home/mdbcare      (จาก python.7z : โฟลเดอร์ python/)
#   config/log เริ่มต้นว่าง (มากับ 7z)  ·  ตั้งสิทธิ์ 777
#
# ใช้:  sudo ./install.sh            (ถามยืนยัน — จะเขียนทับ config เดิม)
#      sudo YES=1 ./install.sh       (ไม่ถาม)
#      REPO=... WEB_DEST=... HOME_DEST=... sudo -E ./install.sh
# ============================================================================
set -euo pipefail
REPO="${REPO:-https://github.com/HWInnovationASF/m1_firmware.git}"
WEB_DEST="${WEB_DEST:-/var/www/html}"
HOME_DEST="${HOME_DEST:-/home/mdbcare}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==================================================="
echo " MDBIoT firmware installer"
echo "   repo   : $REPO"
echo "   web    : $WEB_DEST   (จาก html.7z)"
echo "   python : $HOME_DEST/python   (จาก python.7z)"
echo "   * config/log จะถูกตั้งเป็นค่าเริ่มต้น (ว่าง)"
echo "==================================================="
if [ "${YES:-0}" != "1" ]; then
  read -r -p "ดำเนินการติดตั้ง/เขียนทับ? (พิมพ์ yes): " a; [ "$a" = "yes" ] || { echo "ยกเลิก"; exit 1; }
fi

# ---- dependencies ----
command -v git >/dev/null 2>&1 || sudo apt-get install -y git
command -v 7z  >/dev/null 2>&1 || sudo apt-get install -y p7zip-full
command -v rsync >/dev/null 2>&1 || sudo apt-get install -y rsync

# ---- clone ----
echo "==> git clone"
git clone --depth 1 "$REPO" "$WORK/m1_firmware"
cd "$WORK/m1_firmware"

# ---- 2.2.1) html.7z -> /var/www/html (ไม่ทับ config เดิม) ----
echo "==> แตก html.7z -> $WEB_DEST"
7z x -y html.7z -o"$WORK/meow_temp" >/dev/null
FW="$WORK/meow_temp/html"
sudo mkdir -p "$WEB_DEST/meow/config" "$WEB_DEST/meow/data"

# (ก) sync โค้ด/assets — กันไฟล์ config ของเครื่องเดิมไว้ (ไม่แตะ)
echo "==> อัปเดตโค้ด/assets (คง config เดิม)"
sudo rsync -a \
  --exclude='meow/config/' \
  --exclude='meow/data/mcf_default.json' --exclude='meow/data/mcf_network.json' --exclude='meow/data/mcf.json' \
  --exclude='meow/userauth.json' \
  "$FW/" "$WEB_DEST/"

# (ข) seed config เฉพาะไฟล์ที่ยังไม่มี (เครื่องใหม่) — ของเดิมไม่แตะ
seed(){ [ -f "$WEB_DEST/$1" ] || { [ -f "$FW/$1" ] && sudo cp "$FW/$1" "$WEB_DEST/$1"; }; }
for f in meow/config/dvl.json meow/config/cml.json meow/config/detail_rgl.json \
         meow/config/parameter_list.json meow/config/rgl.json \
         meow/data/mcf_default.json meow/data/mcf_network.json meow/data/mcf.json; do
  seed "$f"
done

# userauth: ไม่เก็บไฟล์ creds ใน repo — สร้าง default ที่เครื่องปลายทางเฉพาะเมื่อยังไม่มี
# (เข้ารหัสแบบเดียวกับ check_login: cnee + bcrypt)  superadmin/admin, admin/admin
if [ ! -f "$WEB_DEST/meow/userauth.json" ] && command -v php >/dev/null 2>&1; then
  php -r '
    $d=["superadmin"=>["password"=>password_hash("admin",PASSWORD_BCRYPT),"type"=>"superadmin"],
        "admin"=>["password"=>password_hash("admin",PASSWORD_BCRYPT),"type"=>"admin"]];
    echo base64_encode(openssl_encrypt(base64_encode(json_encode($d,JSON_UNESCAPED_SLASHES)),"AES-128-CBC","inno2024"));
  ' 2>/dev/null | sudo tee "$WEB_DEST/meow/userauth.json" >/dev/null
  echo "   สร้าง userauth default: superadmin/admin, admin/admin — โปรดเปลี่ยนรหัส!"
fi

# (ค) rgl.json — merge: รุ่นใหม่เพิ่มเข้า, รุ่นที่มี register "เท่าหรือมากกว่า" อัปเดตทับ,
#     รุ่นเดิมที่ไม่มีใน firmware เก็บไว้
if [ -f "$FW/meow/config/rgl.json" ] && [ -f "$WEB_DEST/meow/config/rgl.json" ]; then
  echo "==> merge rgl.json (library รุ่นมิเตอร์)"
  php -r '
    list($_,$ex,$fw)=$argv;
    function rc($model,$m){ if(preg_match("/bac/",$model)&&is_array($m)&&$m){$m=array_values($m)[0];} return is_array($m)?count($m):0; }
    $E=json_decode(@file_get_contents($ex),true)?:[]; $F=json_decode(@file_get_contents($fw),true)?:[];
    $add=0;$upd=0;
    foreach($F as $mo=>$rg){ if(!isset($E[$mo])){$E[$mo]=$rg;$add++;} elseif(rc($mo,$rg)>=rc($mo,$E[$mo])){$E[$mo]=$rg;$upd++;} }
    file_put_contents("'"$WORK"'/rgl.merged.json", json_encode($E, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, "   +$add รุ่นใหม่, ~$upd รุ่นอัปเดต, คงเดิม ".(count($E)-$add)."\n");
  ' "$WEB_DEST/meow/config/rgl.json" "$FW/meow/config/rgl.json"
  sudo cp "$WORK/rgl.merged.json" "$WEB_DEST/meow/config/rgl.json"
fi

# ---- 2.2.2) python.7z -> /home/mdbcare ----
echo "==> แตก python.7z -> $HOME_DEST"
7z x -y python.7z -o"$WORK/py_temp" >/dev/null
sudo mkdir -p "$HOME_DEST"
sudo rsync -a "$WORK/py_temp/python/" "$HOME_DEST/python/"

# ---- โฟลเดอร์ log ว่าง + สิทธิ์ ----
echo "==> โฟลเดอร์ log ว่าง + chmod 777"
for d in log_device log_err dlog textfile; do sudo mkdir -p "$WEB_DEST/meow/$d"; done
for d in log_action log_err dlog VPN; do sudo mkdir -p "$HOME_DEST/python/$d"; done
sudo chmod -R 777 "$WEB_DEST"
sudo chmod -R 777 "$HOME_DEST/python"

# ---- systemd: py_multi.service (ข้ามด้วย SKIP_SERVICE=1) ----
RUN_USER="${RUN_USER:-$(basename "$HOME_DEST")}"
if [ "${SKIP_SERVICE:-0}" != "1" ] && command -v systemctl >/dev/null 2>&1; then
  echo "==> ตั้ง systemd: py_multi.service (user=$RUN_USER)"
  sudo tee /etc/systemd/system/py_multi.service >/dev/null <<UNIT
[Unit]
Description=MDBIoT py_multi runner
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$RUN_USER
WorkingDirectory=$HOME_DEST/python
ExecStart=/usr/bin/python3 $HOME_DEST/python/py_multi.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
  sudo systemctl daemon-reload
  sudo systemctl enable py_multi.service
  sudo systemctl restart py_multi.service || true
  echo "   ตรวจ: sudo systemctl status py_multi.service"
fi

# เปิด apache (ถ้ามี)
command -v systemctl >/dev/null 2>&1 && { sudo systemctl enable apache2 2>/dev/null || true; sudo systemctl restart apache2 2>/dev/null || true; }

echo "==================================================="
echo " ติดตั้งเสร็จ ✅"
echo " ต่อไป: เปิด http://<ip>/ ล็อกอิน (superadmin/admin) → ตั้งค่า mcf_default (deviceSN/broker/ftp)"
echo "        py_multi ทำงานผ่าน systemd แล้ว — ตรวจ: sudo systemctl status py_multi.service"
echo " หมายเหตุ: VPN cert ไม่มากับ firmware — ติดตั้งแยก · เปลี่ยนรหัส default ด้วย"
echo "==================================================="
