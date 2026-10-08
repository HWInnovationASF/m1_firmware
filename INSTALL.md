# MDBIoT M1 installation

The installer is **deploy-first**. By default it deploys the web application and
Python runtime without installing, stopping, enabling, or reconfiguring an
existing Apache, nginx, AppServ, MySQL, or MariaDB installation.

## Raspberry Pi OS, Debian, or Ubuntu

```bash
git clone https://github.com/HWInnovationASF/m1_firmware.git
cd m1_firmware
sudo ./m1 install
```

The interactive installer asks for the existing web document root, Python
destination, missing packages, Python dependencies, and the optional systemd
service. A typical existing-server deployment is:

```bash
sudo ./m1 install --deploy-only \
  --web-dir /var/www/html \
  --python-dir /opt/mdbiot/python
```

For unattended installation, make each optional choice explicit:

```bash
sudo ./m1 install --deploy-only --yes \
  --web-dir /var/www/html \
  --python-dir /opt/mdbiot/python \
  --install-missing yes --python-deps yes --service yes
```

To install a new complete stack, select the required services explicitly:

```bash
sudo ./m1 install --full --web-server nginx --database mariadb
```

Use `--web-server apache` for Apache. Existing services are never selected or
reconfigured just because they were detected.

## Windows with WSL2

Run PowerShell from the repository directory:

```powershell
.\install-windows.ps1
```

The script asks which existing Windows or WSL web document root to use and then
runs the Linux installer inside Ubuntu. Example for an existing AppServ root:

```powershell
.\install-windows.ps1 -Distro Ubuntu `
  -WebDir C:\AppServ\www `
  -PythonDir /opt/mdbiot/python
```

The web files will be placed in `C:\AppServ\www\meow`; the Python application
runs under WSL and can read the same configuration through `/mnt/c/...`.

If WSL or the selected distribution is missing, the launcher asks before
starting its installation. WSL installation may require Administrator rights,
a reboot, and first-run Linux user creation. For a systemd service in WSL,
systemd must be enabled in that distribution. Otherwise choose `-Service no`
and run the Python process with another supervisor.

## Important options

```text
--deploy-only                 Deploy application only (default)
--full                        Allow installation of a selected server stack
--profile auto|raspi|linux|wsl
--web-dir PATH                Existing document root; installs PATH/meow
--python-dir PATH             Python application destination
--web-server existing|none|apache|nginx
--database existing|none|mariadb
--install-missing ask|yes|no  Minimal deployment tools only
--python-deps ask|yes|no      Virtual environment and requirements
--service ask|yes|no          py_multi.service
```

## Preserved runtime data

Updates preserve machine-specific web configuration, users, network data, VPN
files, logs, and runtime data. These files are excluded from GitHub and must be
created or imported on a fresh device before production use.

## Operations

```bash
m1 status
sudo m1 restart
m1 logs 100
m1 update
sudo m1 install
```

## Build release archives on Windows

Install 7-Zip, then run:

```powershell
.\build-installer.ps1
```

Commit `html.7z`, `python.7z`, and `SHA256SUMS` together.
