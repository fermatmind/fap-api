"""Fail before materialization unless candidate and one LKG rebuild both fit."""
import json
import os
from pathlib import Path
import subprocess
import sys


def requirement(source_bytes, source_files, vendor_bytes, vendor_files, mirror_bytes, copies=2):
    if min(source_bytes, source_files) < 1 or copies not in (1, 2):
        raise ValueError('candidate_inventory_missing')
    # Include the full unpruned archive, installed vendor, one restoration,
    # mirror growth up to its current size, and filesystem metadata headroom.
    return (copies * (source_bytes + vendor_bytes + 64 * 1024**2) + (mirror_bytes if copies == 2 else 0),
            copies * (source_files + vendor_files + 4096))


def inventory(path):
    if not path.exists():
        return 0, 0
    size = int(subprocess.check_output(['du', '-sk', str(path)], text=True).split()[0]) * 1024
    count = sum(len(files) + len(dirs) for _, dirs, files in os.walk(path, followlinks=False))
    return size, count


def main():
    root = Path(sys.argv[1]).resolve(strict=True)
    source_bytes, source_files = int(sys.argv[2]), int(sys.argv[3])
    vendor_bytes, vendor_files = inventory(root / 'current/backend/vendor')
    mirror_bytes, _ = inventory(root / '.dep/repo')
    required_bytes, required_inodes = requirement(source_bytes, source_files, vendor_bytes, vendor_files, mirror_bytes, int(sys.argv[4]))
    fs = os.statvfs(root)
    available_bytes = fs.f_bavail * fs.f_frsize
    available_inodes = fs.f_favail
    ok = available_bytes >= required_bytes and available_inodes >= required_inodes
    print(json.dumps(dict(capacity_ok=ok, available_bytes=available_bytes,
                          required_bytes=required_bytes, available_inodes=available_inodes,
                          required_inodes=required_inodes)))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
