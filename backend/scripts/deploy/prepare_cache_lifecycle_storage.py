#!/usr/bin/env python3
"""Prepare only cache-maintenance paths with the existing shared tree's identities."""
import argparse
import ctypes
import ctypes.util
import sys
import os
import re
from pathlib import Path


def access(target: Path, uid: int, directory: bool) -> None:
    # Production is Linux. Default ACL keeps both deploy owner and runtime group
    # writable after either actor atomically replaces a file. No package install.
    if sys.platform != 'linux':
        return
    library = ctypes.util.find_library('acl')
    if not library:
        raise ValueError('POSIX ACL unavailable')
    acl = ctypes.CDLL(library, use_errno=True)
    acl.acl_from_text.argtypes = [ctypes.c_char_p]
    acl.acl_from_text.restype = ctypes.c_void_p
    acl.acl_set_file.argtypes = [ctypes.c_char_p, ctypes.c_int, ctypes.c_void_p]
    acl.acl_free.argtypes = [ctypes.c_void_p]
    mode = 'rwx' if directory else 'rw-'
    value = acl.acl_from_text(f'u::{mode},u:{uid}:{mode},g::{mode},m::{mode},o::---'.encode())
    if not value:
        raise ValueError('invalid maintenance ACL')
    try:
        for kind in ([0x8000, 0x4000] if directory else [0x8000]):
            if acl.acl_set_file(os.fsencode(target), kind, value) != 0:
                raise OSError(ctypes.get_errno(), 'maintenance ACL failed')
    finally:
        acl.acl_free(value)


def prepare(root: Path) -> int:
    root = root.absolute()
    if root != root.resolve() or root.name != 'storage' or root.parent.name != 'backend' or root.parent.parent.name != 'shared':
        raise ValueError('invalid shared storage root')
    shared = root.parent.parent.stat()
    roots = [root / 'app/private/career-cache-retention', root / 'app/ops/cache-lifecycle']
    directories = list(roots)
    files = []
    for directory in roots:
        if directory.is_symlink() or directory.parent.is_symlink():
            raise ValueError('unsafe maintenance directory')
        if not directory.parent.exists():
            if not directory.parent.parent.is_dir() or directory.parent.parent.is_symlink():
                raise ValueError('unsafe maintenance parent')
            directories.insert(0, directory.parent)
        elif not directory.parent.is_dir():
            raise ValueError('unsafe maintenance parent')
        if not directory.exists():
            continue
        for entry in directory.iterdir():
            if entry.is_symlink():
                raise ValueError('unsafe maintenance entry')
            if directory.name == 'career-cache-retention':
                if not entry.is_dir() or not re.fullmatch(r'\d{8}-\d{6}-[a-f0-9]{8}', entry.name):
                    raise ValueError('unknown backup directory')
                directories.append(entry)
                for item in entry.iterdir():
                    if item.is_symlink() or not item.is_file() or item.name not in ['plan.jsonl', 'removed.jsonl', 'complete']:
                        raise ValueError('unknown backup file')
                    files.append(item)
            elif entry.is_file() and re.fullmatch(r'[a-z_]+\.(json|lock|json\.tmp)', entry.name):
                files.append(entry)
            else:
                raise ValueError('unknown observation file')
    for name in ['career-cache-retention.lock', 'career-cache-retention-cursor.json']:
        item = root / 'app/private' / name
        if item.is_symlink():
            raise ValueError('unsafe retention lock')
        if item.exists():
            if not item.is_file():
                raise ValueError('invalid retention lock')
            files.append(item)
    for target in directories:
        target.mkdir(mode=0o2770, exist_ok=True)
        os.chown(target, shared.st_uid, shared.st_gid)
        target.chmod(0o2770)
        access(target, shared.st_uid, True)
    lock = root / 'app/private/career-cache-retention.lock'
    if not lock.exists():
        lock.touch(mode=0o660, exist_ok=False)
        files.append(lock)
    for target in files:
        os.chown(target, shared.st_uid, shared.st_gid)
        target.chmod(0o660)
        access(target, shared.st_uid, False)
    return len(directories) + len(files)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--storage-root', type=Path, required=True)
    options = parser.parse_args()
    try:
        count = prepare(options.storage_root)
        print(f'cache_lifecycle_storage=ready targets={count}')
    except (OSError, ValueError):
        raise SystemExit('cache_lifecycle_storage=failed')
