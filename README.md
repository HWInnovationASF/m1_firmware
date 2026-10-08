# MDBIoT M1 Firmware

MDBIoT M1 web dashboard, Python runtime, and deploy-first installer for
Raspberry Pi OS, Debian, Ubuntu, and Windows through WSL2.

The default installation uses an existing web server and database. It does not
replace or reconfigure Apache, nginx, AppServ, MySQL, or MariaDB.

## Linux / Raspberry Pi

```bash
git clone https://github.com/HWInnovationASF/m1_firmware.git
cd m1_firmware
sudo ./m1 install
```

## Windows / WSL2

```powershell
git clone https://github.com/HWInnovationASF/m1_firmware.git
cd m1_firmware
.\install-windows.ps1
```

See [INSTALL.md](INSTALL.md) for existing-server paths, non-interactive usage,
optional dependencies, full-stack installation, and update instructions.
