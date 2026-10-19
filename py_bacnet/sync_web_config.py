"""Merge the legacy BACnet configuration into the web configuration once.

Existing web entries always win.  Afterwards py_bacnet reads the same dvl/rgl/
cml files edited by the web configurator.
"""
import argparse
import json
import os
import shutil
from datetime import datetime
from pathlib import Path


FILES = ("dvl.json", "rgl.json", "cml.json", "alarm_config.json")


def load(path):
    with path.open("r", encoding="utf-8-sig") as handle:
        value = json.load(handle)
    # Some web actions historically initialized an empty configuration as []
    # rather than {}.  Treat only that empty legacy shape as an empty object.
    if value == []:
        return {}
    if not isinstance(value, dict):
        raise ValueError(f"{path} must contain a JSON object")
    return value


def atomic_write(path, value):
    temporary = path.with_suffix(path.suffix + ".tmp")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump(value, handle, ensure_ascii=False, separators=(",", ":"))
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, path)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", default=str(Path(__file__).resolve().parent))
    parser.add_argument("--web", default="/var/www/html/meow/config")
    args = parser.parse_args()
    source_dir = Path(args.source)
    web_dir = Path(args.web)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")

    for name in FILES:
        source_path = source_dir / name
        web_path = web_dir / name
        if not source_path.is_file():
            continue
        legacy = load(source_path)
        current = load(web_path) if web_path.is_file() else {}

        if name == "dvl.json":
            for device in legacy.values():
                if not isinstance(device, dict):
                    continue
                if "ipaddress" not in device and device.get("ip"):
                    device["ipaddress"] = device["ip"]
                device.setdefault("port", "47808")
                device.setdefault("deviceID", "")

        # Preserve anything already created or edited through the website.
        merged = dict(legacy)
        merged.update(current)
        if web_path.is_file():
            shutil.copy2(web_path, web_path.with_name(web_path.name + ".backup_" + stamp))
        atomic_write(web_path, merged)
        print(f"{name}: {len(current)} web + {len(legacy)} legacy -> {len(merged)}")


if __name__ == "__main__":
    main()
