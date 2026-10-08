#!/usr/bin/env bash
# MDBIoT M1 deploy-first installer for Raspberry Pi OS, Debian, Ubuntu and WSL2.
set -Eeuo pipefail
IFS=$'\n\t'

REPO="${REPO:-https://github.com/HWInnovationASF/m1_firmware.git}"
SOURCE_DIR="${SOURCE_DIR:-}"
WEB_DEST="${WEB_DEST:-/var/www/html}"
PYTHON_DEST="${PYTHON_DEST:-${HOME_DEST:+$HOME_DEST/python}}"
PYTHON_DEST="${PYTHON_DEST:-/opt/mdbiot/python}"
RUN_USER="${RUN_USER:-${SUDO_USER:-mdbcare}}"
WEB_GROUP="${WEB_GROUP:-www-data}"
PROFILE="${PROFILE:-auto}"
INSTALL_MODE="${INSTALL_MODE:-deploy-only}"
INSTALL_MISSING="${INSTALL_MISSING:-ask}"
INSTALL_PYTHON_DEPS="${INSTALL_PYTHON_DEPS:-ask}"
CREATE_SERVICE="${CREATE_SERVICE:-ask}"
WEB_SERVER="${WEB_SERVER:-existing}"
DATABASE="${DATABASE:-existing}"
YES="${YES:-0}"
AUTOMATION_MODE="${AUTOMATION_MODE:-preserve}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d /tmp/mdbiot-install.XXXXXX)"
trap 'rm -rf -- "$WORK"' EXIT

usage() {
  cat <<'EOF'
Usage: sudo ./install.sh [options]

Default mode deploys only the web files and Python application. It does not
install, stop, enable or reconfigure an existing web server or database.

  --deploy-only              Deploy application only (default)
  --full                     Offer/install a complete server stack
  --profile auto|raspi|linux|wsl
  --web-dir PATH             Existing web document root (meow is placed inside)
  --python-dir PATH          Python application destination
  --run-user USER            User for files and optional Python service
  --web-group GROUP          Web server group
  --web-server existing|none|apache|nginx
  --database existing|none|mariadb
  --install-missing ask|yes|no
  --python-deps ask|yes|no
  --service ask|yes|no
  --automation-mode off|simulation|live|preserve
  --source-dir PATH          Directory containing html.7z and python.7z
  -y, --yes                  Non-interactive; accept selected operations
  -h, --help
EOF
}

while (($#)); do
  case "$1" in
    --deploy-only) INSTALL_MODE=deploy-only ;;
    --full) INSTALL_MODE=full; INSTALL_MISSING=yes; CREATE_SERVICE=yes ;;
    --profile) PROFILE="${2:?missing profile}"; shift ;;
    --web-dir) WEB_DEST="${2:?missing web path}"; shift ;;
    --python-dir) PYTHON_DEST="${2:?missing python path}"; shift ;;
    --run-user) RUN_USER="${2:?missing user}"; shift ;;
    --web-group) WEB_GROUP="${2:?missing group}"; shift ;;
    --web-server) WEB_SERVER="${2:?missing web server}"; shift ;;
    --database) DATABASE="${2:?missing database mode}"; shift ;;
    --install-missing) INSTALL_MISSING="${2:?missing value}"; shift ;;
    --python-deps) INSTALL_PYTHON_DEPS="${2:?missing value}"; shift ;;
    --service) CREATE_SERVICE="${2:?missing value}"; shift ;;
    --automation-mode) AUTOMATION_MODE="${2:?missing mode}"; shift ;;
    --source-dir) SOURCE_DIR="${2:?missing source path}"; shift ;;
    -y|--yes) YES=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

[[ $EUID -eq 0 ]] || { echo "Please run with sudo/root." >&2; exit 1; }
case "$PROFILE" in auto|raspi|linux|wsl) ;; *) echo "Invalid profile: $PROFILE" >&2; exit 2;; esac
case "$INSTALL_MODE" in deploy-only|full) ;; *) echo "Invalid install mode" >&2; exit 2;; esac
case "$INSTALL_MISSING" in ask|yes|no) ;; *) echo "--install-missing must be ask, yes or no" >&2; exit 2;; esac
case "$INSTALL_PYTHON_DEPS" in ask|yes|no) ;; *) echo "--python-deps must be ask, yes or no" >&2; exit 2;; esac
case "$CREATE_SERVICE" in ask|yes|no) ;; *) echo "--service must be ask, yes or no" >&2; exit 2;; esac
case "$WEB_SERVER" in existing|none|apache|nginx) ;; *) echo "Invalid web server mode" >&2; exit 2;; esac
case "$DATABASE" in existing|none|mariadb) ;; *) echo "Invalid database mode" >&2; exit 2;; esac
case "$AUTOMATION_MODE" in off|simulation|live|preserve) ;; *) echo "Invalid automation mode" >&2; exit 2;; esac

is_wsl=0
grep -qi microsoft /proc/version 2>/dev/null && is_wsl=1
if [[ "$PROFILE" == auto ]]; then
  if ((is_wsl)); then PROFILE=wsl
  elif [[ -r /proc/device-tree/model ]] && grep -qi 'raspberry pi' /proc/device-tree/model; then PROFILE=raspi
  else PROFILE=linux
  fi
fi

ask_yes_no() {
  local prompt="$1" default="${2:-no}" answer
  if [[ "$YES" == 1 ]]; then [[ "$default" == yes ]]; return; fi
  [[ -t 0 ]] || return 1
  if [[ "$default" == yes ]]; then read -r -p "$prompt [Y/n]: " answer; [[ ! "$answer" =~ ^[Nn]$ ]]
  else read -r -p "$prompt [y/N]: " answer; [[ "$answer" =~ ^[Yy]$ ]]
  fi
}

install_choice() {
  local prompt="$1"
  case "$INSTALL_MISSING" in yes) return 0;; no) return 1;; esac
  ask_yes_no "$prompt" no
}

if [[ "$YES" != 1 && -t 0 ]]; then
  echo "Detected profile: $PROFILE"
  read -r -p "Web document root [$WEB_DEST]: " answer; WEB_DEST="${answer:-$WEB_DEST}"
  read -r -p "Python destination [$PYTHON_DEST]: " answer; PYTHON_DEST="${answer:-$PYTHON_DEST}"
  read -r -p "Runtime user [$RUN_USER]: " answer; RUN_USER="${answer:-$RUN_USER}"
fi

echo "MDBIoT deployment plan"
echo "  Mode       : $INSTALL_MODE"
echo "  Profile    : $PROFILE"
echo "  Web root   : $WEB_DEST"
echo "  Python     : $PYTHON_DEST"
echo "  Run user   : $RUN_USER"
echo "  Web server : $WEB_SERVER (existing services are not modified)"
echo "  Database   : $DATABASE (existing services are not modified)"
if [[ "$YES" != 1 ]]; then ask_yes_no "Continue with this deployment?" no || exit 0; fi

command -v apt-get >/dev/null 2>&1 || { echo "This installer currently requires an apt-based Debian/Ubuntu system." >&2; exit 1; }
required_packages=()
command -v 7z >/dev/null 2>&1 || required_packages+=(p7zip-full)
command -v rsync >/dev/null 2>&1 || required_packages+=(rsync)
command -v python3 >/dev/null 2>&1 || required_packages+=(python3 python3-venv)
if command -v python3 >/dev/null 2>&1 && ! python3 -m venv --help >/dev/null 2>&1; then required_packages+=(python3-venv); fi

if ((${#required_packages[@]})); then
  if install_choice "Missing deployment tools (${required_packages[*]}). Install them?"; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y "${required_packages[@]}"
  else
    echo "Missing required deployment tools: ${required_packages[*]}" >&2
    exit 1
  fi
fi

if [[ "$INSTALL_MODE" == full ]]; then
  optional_packages=(php-cli php-mbstring php-curl php-zip php-mysql)
  [[ "$WEB_SERVER" == apache ]] && optional_packages+=(apache2)
  [[ "$WEB_SERVER" == nginx ]] && optional_packages+=(nginx php-fpm)
  [[ "$DATABASE" == mariadb ]] && optional_packages+=(mariadb-server)
  [[ "$PROFILE" == raspi ]] && optional_packages+=(python3-rpi.gpio)
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y "${optional_packages[@]}"
fi

if ! id "$RUN_USER" >/dev/null 2>&1; then
  if install_choice "User '$RUN_USER' does not exist. Create it?"; then useradd --create-home --shell /bin/bash "$RUN_USER"
  else echo "Runtime user does not exist: $RUN_USER" >&2; exit 1; fi
fi
getent group "$WEB_GROUP" >/dev/null 2>&1 || WEB_GROUP="$(id -gn "$RUN_USER")"

if [[ -n "$SOURCE_DIR" ]]; then SOURCE="$SOURCE_DIR"
elif [[ -f "$SCRIPT_DIR/html.7z" && -f "$SCRIPT_DIR/python.7z" ]]; then SOURCE="$SCRIPT_DIR"
else
  command -v git >/dev/null 2>&1 || { install_choice "Git is required to download the release. Install it?" && apt-get update && apt-get install -y git || exit 1; }
  git clone --depth 1 "$REPO" "$WORK/repository"
  SOURCE="$WORK/repository"
fi

for file in html.7z python.7z; do
  [[ -f "$SOURCE/$file" ]] || { echo "Missing package: $SOURCE/$file" >&2; exit 1; }
  7z t "$SOURCE/$file" >/dev/null
done
[[ ! -f "$SOURCE/SHA256SUMS" ]] || (cd "$SOURCE" && sha256sum -c SHA256SUMS)
7z x -y "$SOURCE/html.7z" -o"$WORK/html" >/dev/null
7z x -y "$SOURCE/python.7z" -o"$WORK/python" >/dev/null
WEB_SOURCE="$WORK/html/html"; PY_SOURCE="$WORK/python/python"
[[ -d "$WEB_SOURCE/meow" && -f "$PY_SOURCE/py_multi.py" ]] || { echo "Invalid package structure" >&2; exit 1; }

mkdir -p "$WEB_DEST" "$PYTHON_DEST"
rsync -rlt --no-owner --no-group --no-perms --exclude='/meow/config/' --exclude='/meow/data/' --exclude='/meow/userauth.json' --exclude='/meow/log_device/' --exclude='/meow/log_err/' --exclude='/meow/dlog/' --exclude='/meow/textfile/' "$WEB_SOURCE/" "$WEB_DEST/"
rsync -rlt --no-owner --no-group --no-perms --exclude='/VPN/' --exclude='/__pycache__/' --exclude='/log_action/' --exclude='/log_err/' --exclude='/dlog/' --exclude='/battery_control_logs/' --exclude='/*.json.lock' "$PY_SOURCE/" "$PYTHON_DEST/"
mkdir -p "$WEB_DEST/meow"/{config,data,log_device,log_err,dlog,textfile} "$PYTHON_DEST"/{VPN,log_action,log_err,dlog,battery_control_logs}
chown -R "$RUN_USER:$WEB_GROUP" "$WEB_DEST/meow" "$PYTHON_DEST"
find "$WEB_DEST/meow" "$PYTHON_DEST" -type d -exec chmod 0755 {} +

install_python_deps=0
case "$INSTALL_PYTHON_DEPS" in yes) install_python_deps=1;; no) ;; ask) ask_yes_no "Create a Python virtual environment and install dependencies?" yes && install_python_deps=1;; esac
if ((install_python_deps)); then
  python3 -m venv --system-site-packages "$PYTHON_DEST/.venv"
  "$PYTHON_DEST/.venv/bin/python" -m pip install --upgrade pip
  [[ ! -f "$PYTHON_DEST/requirements.txt" ]] || "$PYTHON_DEST/.venv/bin/python" -m pip install -r "$PYTHON_DEST/requirements.txt"
  chown -R "$RUN_USER:$WEB_GROUP" "$PYTHON_DEST/.venv"
fi

if [[ "$AUTOMATION_MODE" != preserve && -f "$WEB_DEST/meow/config/automation_control.json" ]] && command -v php >/dev/null 2>&1; then
  AUTOMATION_MODE="$AUTOMATION_MODE" php -r '$p=$argv[1];$c=json_decode(@file_get_contents($p),true);if(!is_array($c))exit(0);$m=getenv("AUTOMATION_MODE");$c["enabled"]=$m!=="off";$c["simulation_log_enabled"]=$m==="simulation";file_put_contents($p,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");' "$WEB_DEST/meow/config/automation_control.json"
fi

create_service=0
case "$CREATE_SERVICE" in yes) create_service=1;; no) ;; ask) ask_yes_no "Create/update py_multi.service?" yes && create_service=1;; esac
if ((create_service)) && ! command -v systemctl >/dev/null 2>&1; then echo "systemd unavailable; service was not created" >&2; create_service=0; fi
if ((create_service)); then
  python_exec=/usr/bin/python3; [[ -x "$PYTHON_DEST/.venv/bin/python" ]] && python_exec="$PYTHON_DEST/.venv/bin/python"
  cat > /etc/systemd/system/py_multi.service <<UNIT
[Unit]
Description=MDBIoT py_multi runner
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
User=$RUN_USER
WorkingDirectory=$PYTHON_DEST
Environment=PYTHONUNBUFFERED=1
Environment=MDBCARE_WEB_CONFIG=$WEB_DEST/meow/config
Environment=MDBCARE_BACNET_CACHE=$WEB_DEST/meow/data/bacnet_latest.json
ExecStart=$python_exec $PYTHON_DEST/py_multi.py
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable --now py_multi.service
fi

command -v php >/dev/null 2>&1 || echo "WARNING: PHP not found; web files deployed but PHP pages cannot run yet." >&2
[[ -f "$WEB_DEST/meow/config/dvl.json" ]] || echo "WARNING: Runtime web config is missing; import/create it before production use." >&2
install -d -m 0755 /opt/mdbiot-installer
for file in install.sh install-windows.ps1 html.7z python.7z SHA256SUMS INSTALL.md m1; do [[ -f "$SOURCE/$file" ]] && install -m 0644 "$SOURCE/$file" "/opt/mdbiot-installer/$file"; done
[[ -f "$SOURCE/m1" ]] && install -m 0755 "$SOURCE/m1" /usr/local/bin/m1
chmod 0755 /opt/mdbiot-installer/install.sh 2>/dev/null || true
echo "Deployment complete"
echo "  Web    : $WEB_DEST/meow"
echo "  Python : $PYTHON_DEST"
echo "  Profile: $PROFILE"
((create_service)) && systemctl is-active py_multi.service || true
