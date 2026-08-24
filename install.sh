#!/usr/bin/env bash
# MDBIoT M1 firmware installer/updater
# Installs html.7z and python.7z while preserving device configuration and logs.

set -Eeuo pipefail
IFS=$'\n\t'

REPO="${REPO:-https://github.com/HWInnovationASF/m1_firmware.git}"
SOURCE_DIR="${SOURCE_DIR:-}"
WEB_DEST="${WEB_DEST:-/var/www/html}"
HOME_DEST="${HOME_DEST:-/home/mdbcare}"
RUN_USER="${RUN_USER:-$(basename "$HOME_DEST")}"
WEB_GROUP="${WEB_GROUP:-www-data}"
KEEP_BACKUPS="${KEEP_BACKUPS:-3}"
YES="${YES:-0}"
SKIP_SERVICE="${SKIP_SERVICE:-0}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/mdbiot}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-Scada@2024}"
DB_APP_USER="${DB_APP_USER:-meow}"
DB_APP_PASSWORD="${DB_APP_PASSWORD:-Scada@2024}"
ENABLE_MDBCARE_PASSWORDLESS_SUDO="${ENABLE_MDBCARE_PASSWORDLESS_SUDO:-1}"

if [[ $EUID -ne 0 ]]; then
  echo "กรุณารันด้วยสิทธิ์ root: sudo ./install.sh" >&2
  exit 1
fi

if ! [[ "$KEEP_BACKUPS" =~ ^[0-9]+$ ]]; then
  echo "KEEP_BACKUPS ต้องเป็นเลขจำนวนเต็มตั้งแต่ 0 ขึ้นไป" >&2
  exit 1
fi

WORK="$(mktemp -d /tmp/mdbiot-install.XXXXXX)"
cleanup() { rm -rf -- "$WORK"; }
trap cleanup EXIT
trap 'echo "ติดตั้งไม่สำเร็จที่บรรทัด $LINENO" >&2' ERR

echo "==================================================="
echo " MDBIoT M1 firmware installer"
echo " Web destination    : $WEB_DEST"
echo " Python destination : $HOME_DEST/python"
echo " Runtime user       : $RUN_USER"
echo "==================================================="

if [[ "$YES" != "1" ]]; then
  read -r -p "ดำเนินการติดตั้ง/อัปเดตหรือไม่? พิมพ์ yes: " answer
  [[ "$answer" == "yes" ]] || { echo "ยกเลิก"; exit 0; }
fi

install_dependencies() {
  local missing=()
  command -v git >/dev/null 2>&1 || missing+=(git)
  command -v 7z >/dev/null 2>&1 || missing+=(p7zip-full)
  command -v rsync >/dev/null 2>&1 || missing+=(rsync)
  command -v php >/dev/null 2>&1 || missing+=(php-cli)
  command -v openssl >/dev/null 2>&1 || missing+=(openssl)
  if ((${#missing[@]})); then
    command -v apt-get >/dev/null 2>&1 || { echo "ไม่พบ apt-get สำหรับติดตั้ง: ${missing[*]}" >&2; exit 1; }
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
  fi
}

install_dependencies

install_platform() {
  command -v apt-get >/dev/null 2>&1 || { echo "This installer requires Debian/Ubuntu with apt-get" >&2; exit 1; }
  echo "==> Install Apache, PHP, MariaDB, OpenVPN and system dependencies"
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 mariadb-server php php-cli php-mysql php-ftp php-xml php-mbstring php-curl php-zip phpmyadmin \
    openvpn network-manager python3 python3-pip python3-tk git p7zip-full rsync openssl sudo

  phpenmod mysqli
  systemctl enable --now mariadb.service
  systemctl enable --now apache2.service

  echo "==> Install Python libraries"
  local pip_options=(--disable-pip-version-check)
  if python3 -m pip help install 2>/dev/null | grep -q -- '--break-system-packages'; then
    pip_options+=(--break-system-packages)
  fi
  python3 -m pip install "${pip_options[@]}" \
    pyserial minimalmodbus pyModbusTCP numpy pycryptodome paho-mqtt psutil \
    websocket-client rel filelock bacpypes paramiko
}

configure_database() {
  [[ "$DB_ROOT_PASSWORD" =~ ^[A-Za-z0-9@._%+=:-]+$ ]] || { echo "DB_ROOT_PASSWORD contains unsupported characters" >&2; exit 1; }
  [[ "$DB_APP_PASSWORD" =~ ^[A-Za-z0-9@._%+=:-]+$ ]] || { echo "DB_APP_PASSWORD contains unsupported characters" >&2; exit 1; }
  [[ "$DB_APP_USER" =~ ^[A-Za-z0-9_-]+$ ]] || { echo "DB_APP_USER is invalid" >&2; exit 1; }

  local mysql=(mariadb)
  if ! "${mysql[@]}" --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1; then
    mysql=(mariadb -uroot "-p$DB_ROOT_PASSWORD")
    "${mysql[@]}" --batch --skip-column-names -e 'SELECT 1' >/dev/null
  fi

  echo "==> Configure MariaDB database"
  "${mysql[@]}" <<SQL
CREATE DATABASE IF NOT EXISTS \`A-system\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS \`A-system\`.\`send_log5\` (
  \`id\` int(11) NOT NULL,
  \`device_SN\` varchar(32) CHARACTER SET utf8 NOT NULL,
  \`ts\` datetime NOT NULL,
  \`Data\` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(\`Data\`)),
  \`status\` int(11) NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
CREATE USER IF NOT EXISTS '$DB_APP_USER'@'localhost' IDENTIFIED BY '$DB_APP_PASSWORD';
CREATE USER IF NOT EXISTS '$DB_APP_USER'@'%' IDENTIFIED BY '$DB_APP_PASSWORD';
ALTER USER '$DB_APP_USER'@'localhost' IDENTIFIED BY '$DB_APP_PASSWORD';
ALTER USER '$DB_APP_USER'@'%' IDENTIFIED BY '$DB_APP_PASSWORD';
GRANT ALL PRIVILEGES ON \`A-system\`.* TO '$DB_APP_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`A-system\`.* TO '$DB_APP_USER'@'%';
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_ROOT_PASSWORD';
SQL
}

configure_apache() {
  echo "==> Configure Apache web root"
  mkdir -p "$WEB_DEST/meow"
  rm -f -- "$WEB_DEST/index.html"
  if [[ ! -e "$WEB_DEST/meow/phpmyadmin" ]]; then
    ln -s /usr/share/phpmyadmin "$WEB_DEST/meow/phpmyadmin"
  fi
  sed -i -E 's|^[[:space:]]*DocumentRoot[[:space:]]+.*$|\tDocumentRoot /var/www/html/meow|' /etc/apache2/sites-available/000-default.conf
  apache2ctl configtest
}

configure_sudoers() {
  echo "==> Configure required sudo permissions"
  local sudoers_file=/etc/sudoers.d/mdbiot
  {
    if [[ "$ENABLE_MDBCARE_PASSWORDLESS_SUDO" == "1" ]]; then
      printf '%s ALL=(ALL) NOPASSWD: ALL\n' "$RUN_USER"
    fi
    printf '%s\n' \
      'www-data ALL=(root) NOPASSWD: /usr/bin/nmcli' \
      'www-data ALL=(root) NOPASSWD: /sbin/reboot'
  } > "$sudoers_file"
  chmod 0440 "$sudoers_file"
  visudo -cf "$sudoers_file" >/dev/null
}

install_platform
if ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "==> Create runtime user: $RUN_USER"
  useradd --create-home --shell /bin/bash "$RUN_USER"
fi
configure_database
configure_apache
configure_sudoers

if [[ -n "$SOURCE_DIR" ]]; then
  SOURCE_DIR="$(readlink -f "$SOURCE_DIR")"
  [[ -d "$SOURCE_DIR" ]] || { echo "ไม่พบ SOURCE_DIR: $SOURCE_DIR" >&2; exit 1; }
  SOURCE="$SOURCE_DIR"
else
  echo "==> Download firmware"
  git clone --depth 1 "$REPO" "$WORK/repository"
  SOURCE="$WORK/repository"
fi

for archive in html.7z python.7z; do
  [[ -f "$SOURCE/$archive" ]] || { echo "ไม่พบ $archive ใน $SOURCE" >&2; exit 1; }
  7z t "$SOURCE/$archive" >/dev/null
done

echo "==> Extract packages"
7z x -y "$SOURCE/html.7z" -o"$WORK/html" >/dev/null
7z x -y "$SOURCE/python.7z" -o"$WORK/python" >/dev/null
WEB_SOURCE="$WORK/html/html"
PY_SOURCE="$WORK/python/python"
[[ -d "$WEB_SOURCE/meow" ]] || { echo "html.7z ไม่มีโครงสร้าง html/meow" >&2; exit 1; }
[[ -f "$PY_SOURCE/py_multi.py" ]] || { echo "python.7z ไม่มี python/py_multi.py" >&2; exit 1; }

timestamp="$(date +%Y%m%d_%H%M%S)"
backup_dir="$BACKUP_ROOT/$timestamp"
mkdir -p "$backup_dir/web" "$backup_dir/python"

echo "==> Backup current configuration: $backup_dir"
[[ -d "$WEB_DEST/meow/config" ]] && cp -a "$WEB_DEST/meow/config" "$backup_dir/web/"
[[ -d "$WEB_DEST/meow/data" ]] && cp -a "$WEB_DEST/meow/data" "$backup_dir/web/"
[[ -f "$WEB_DEST/meow/userauth.json" ]] && cp -a "$WEB_DEST/meow/userauth.json" "$backup_dir/web/"
for item in VPN dfl.json cma.json tou_schedule.json automation_last_commands.json; do
  [[ -e "$HOME_DEST/python/$item" ]] && cp -a "$HOME_DEST/python/$item" "$backup_dir/python/"
done

mkdir -p "$WEB_DEST/meow/config" "$WEB_DEST/meow/data" "$HOME_DEST/python"

echo "==> Update web application (preserve config/data/logs)"
rsync -a \
  --exclude='/meow/config/' \
  --exclude='/meow/data/' \
  --exclude='/meow/userauth.json' \
  --exclude='/meow/log_device/' \
  --exclude='/meow/log_err/' \
  --exclude='/meow/dlog/' \
  --exclude='/meow/textfile/' \
  "$WEB_SOURCE/" "$WEB_DEST/"

seed_web() {
  local relative="$1"
  if [[ ! -e "$WEB_DEST/$relative" && -e "$WEB_SOURCE/$relative" ]]; then
    mkdir -p "$(dirname "$WEB_DEST/$relative")"
    cp -a "$WEB_SOURCE/$relative" "$WEB_DEST/$relative"
  fi
}

for file in \
  meow/config/dvl.json meow/config/cml.json meow/config/detail_rgl.json \
  meow/config/parameter_list.json meow/config/rgl.json \
  meow/config/automation_control.json meow/config/command_list.json \
  meow/data/mcf_default.json meow/data/mcf_network.json meow/data/mcf.json; do
  seed_web "$file"
done

if [[ -f "$WEB_SOURCE/meow/config/rgl.json" && -f "$WEB_DEST/meow/config/rgl.json" ]]; then
  echo "==> Merge register library"
  php -r '
    [$script,$existing,$firmware,$output]=$argv;
    $current=json_decode(@file_get_contents($existing),true);
    $incoming=json_decode(@file_get_contents($firmware),true);
    if(!is_array($current)||!is_array($incoming)){fwrite(STDERR,"Invalid rgl.json\n");exit(1);}
    foreach($incoming as $model=>$registers){
      if(!isset($current[$model])){$current[$model]=$registers;continue;}
      $count=function($value) use (&$count){
        if(!is_array($value))return 0;
        $total=0;foreach($value as $item)$total+=is_array($item)&&isset($item["name"])?1:$count($item);
        return $total;
      };
      if($count($registers)>=$count($current[$model]))$current[$model]=$registers;
    }
    $json=json_encode($current,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false||file_put_contents($output,$json)===false)exit(1);
  ' "$WEB_DEST/meow/config/rgl.json" "$WEB_SOURCE/meow/config/rgl.json" "$WORK/rgl.json"
  install -m 0664 "$WORK/rgl.json" "$WEB_DEST/meow/config/rgl.json"
fi

if [[ ! -f "$WEB_DEST/meow/userauth.json" && -f "$WEB_SOURCE/meow/userauth.json" ]]; then
  echo "==> Install bundled user authentication"
  install -o www-data -g "$WEB_GROUP" -m 0660 \
    "$WEB_SOURCE/meow/userauth.json" "$WEB_DEST/meow/userauth.json"
fi

if [[ ! -f "$WEB_DEST/meow/userauth.json" ]]; then
  initial_password="${INITIAL_ADMIN_PASSWORD:-$(openssl rand -hex 8)}"
  INITIAL_ADMIN_PASSWORD="$initial_password" php -r '
    $password=getenv("INITIAL_ADMIN_PASSWORD");
    $users=[
      "superadmin"=>["password"=>password_hash($password,PASSWORD_BCRYPT),"type"=>"superadmin"],
      "admin"=>["password"=>password_hash($password,PASSWORD_BCRYPT),"type"=>"admin"]
    ];
    $encrypted=openssl_encrypt(base64_encode(json_encode($users,JSON_UNESCAPED_SLASHES)),"AES-128-CBC","inno2024");
    echo base64_encode($encrypted);
  ' > "$WEB_DEST/meow/userauth.json"
  chmod 0660 "$WEB_DEST/meow/userauth.json"
  echo "สร้างบัญชีเริ่มต้น superadmin/admin ด้วยรหัสผ่าน: $initial_password"
  echo "โปรดเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบครั้งแรก"
fi

echo "==> Update Python application (preserve runtime data/config)"
rsync -a \
  --exclude='/VPN/' \
  --exclude='/dlog/' \
  --exclude='/log_action/' \
  --exclude='/log_err/' \
  --exclude='/battery_control_logs/' \
  --exclude='/dfl.json' \
  --exclude='/cma.json' \
  --exclude='/tou_schedule.json' \
  --exclude='/automation_last_commands.json' \
  --exclude='/mqtt_command_history.json' \
  --exclude='/mqtt_command_history.json.lock' \
  "$PY_SOURCE/" "$HOME_DEST/python/"

for file in dfl.json cma.json tou_schedule.json; do
  [[ -e "$HOME_DEST/python/$file" ]] || { [[ -e "$PY_SOURCE/$file" ]] && cp -a "$PY_SOURCE/$file" "$HOME_DEST/python/$file"; }
done

for directory in log_device log_err dlog textfile; do mkdir -p "$WEB_DEST/meow/$directory"; done
for directory in log_action log_err dlog VPN battery_control_logs; do mkdir -p "$HOME_DEST/python/$directory"; done

echo "==> Set ownership and safe writable permissions"
chown -R "$RUN_USER:$WEB_GROUP" "$WEB_DEST/meow/config" "$WEB_DEST/meow/data" "$WEB_DEST/meow"/log_device "$WEB_DEST/meow"/log_err "$WEB_DEST/meow"/dlog "$WEB_DEST/meow"/textfile
find "$WEB_DEST/meow/config" "$WEB_DEST/meow/data" "$WEB_DEST/meow"/log_device "$WEB_DEST/meow"/log_err "$WEB_DEST/meow"/dlog "$WEB_DEST/meow"/textfile -type d -exec chmod 2775 {} +
find "$WEB_DEST/meow/config" "$WEB_DEST/meow/data" "$WEB_DEST/meow"/log_device "$WEB_DEST/meow"/log_err "$WEB_DEST/meow"/dlog "$WEB_DEST/meow"/textfile -type f -exec chmod 0664 {} +
chown -R "$RUN_USER:$RUN_USER" "$HOME_DEST/python"
find "$HOME_DEST/python" -type d -exec chmod 0755 {} +
find "$HOME_DEST/python" -type f -exec chmod 0644 {} +
find "$HOME_DEST/python" -type f -name '*.sh' -exec chmod 0755 {} +
if [[ -d "$HOME_DEST/python/VPN" ]]; then
  find "$HOME_DEST/python/VPN" -type d -exec chmod 0700 {} +
  find "$HOME_DEST/python/VPN" -type f -exec chmod 0600 {} +
fi

php -l "$WEB_DEST/meow/index.php" >/dev/null
python3 -m py_compile "$HOME_DEST/python/py_multi.py"

if [[ "$SKIP_SERVICE" != "1" ]] && command -v systemctl >/dev/null 2>&1; then
  echo "==> Configure py_multi.service"
  cat > /etc/systemd/system/py_multi.service <<UNIT
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
  systemctl daemon-reload
  systemctl enable py_multi.service
  systemctl restart py_multi.service
  systemctl is-active --quiet py_multi.service || { systemctl status py_multi.service --no-pager; exit 1; }
fi

if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files apache2.service >/dev/null 2>&1; then
  systemctl enable apache2.service >/dev/null 2>&1 || true
  systemctl restart apache2.service
fi

if ((KEEP_BACKUPS == 0)); then
  rm -rf -- "$backup_dir"
else
  mapfile -t old_backups < <(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n +$((KEEP_BACKUPS + 1)))
  for old in "${old_backups[@]}"; do
    [[ "$old" =~ ^[0-9]{8}_[0-9]{6}$ ]] && rm -rf -- "$BACKUP_ROOT/$old"
  done
fi

echo "==================================================="
echo "ติดตั้ง MDBIoT firmware สำเร็จ"
echo "Web       : $WEB_DEST/meow"
echo "Python    : $HOME_DEST/python"
echo "Backup    : $backup_dir"
echo "ตรวจสอบ   : systemctl status py_multi.service"
echo "==================================================="
