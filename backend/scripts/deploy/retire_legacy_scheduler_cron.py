#!/usr/bin/env python3
"""Retire only the proven duplicate cron entry after managed cron installation."""

import argparse
import os
from pathlib import Path
import re
import stat
import tempfile


def retire(cron_file: Path, deploy_root: str, check: bool) -> None:
    if not re.fullmatch(r"/[A-Za-z0-9._/-]+", deploy_root) or ".." in deploy_root:
        raise ValueError("invalid_deploy_root")
    if cron_file.is_symlink():
        raise ValueError("legacy_cron_symlink")
    if not cron_file.exists():
        return
    metadata = cron_file.stat()
    if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise ValueError("legacy_cron_not_regular")
    expected = ("* * * * * www-data cd " + deploy_root
                + "/current/backend && /usr/bin/php artisan schedule:run"
                + " >> /var/log/fap-schedule.log 2>&1")
    original = cron_file.read_bytes()
    lines = original.decode("utf-8").splitlines(keepends=True)
    matches = 0
    replacement = []
    for line in lines:
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or re.match(r"^(SHELL|PATH)=", stripped):
            replacement.append(line)
        elif stripped == expected:
            matches += 1
            replacement.append("# Retired duplicate; managed user crontab owns this tick.\n# " + line)
        else:
            raise ValueError("unknown_legacy_cron_entry")
    if matches > 1:
        raise ValueError("ambiguous_legacy_cron_entries")
    if check or matches == 0:
        return
    descriptor, temporary = tempfile.mkstemp(prefix=".fap-scheduler-", dir=cron_file.parent)
    try:
        with os.fdopen(descriptor, "wb") as candidate:
            os.fchown(candidate.fileno(), metadata.st_uid, metadata.st_gid)
            os.fchmod(candidate.fileno(), stat.S_IMODE(metadata.st_mode))
            candidate.write("".join(replacement).encode("utf-8"))
            candidate.flush()
            os.fsync(candidate.fileno())
        current = cron_file.lstat()
        if (current.st_dev, current.st_ino) != (metadata.st_dev, metadata.st_ino) or cron_file.read_bytes() != original:
            raise ValueError("legacy_cron_changed")
        os.replace(temporary, cron_file)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--deploy-root", required=True)
    parser.add_argument("--cron-file", default="/etc/cron.d/fap-api-scheduler", type=Path)
    parser.add_argument("--check", action="store_true")
    arguments = parser.parse_args()
    try:
        retire(arguments.cron_file, arguments.deploy_root, arguments.check)
    except (OSError, UnicodeError, ValueError) as error:
        reason = str(error) if type(error) is ValueError else type(error).__name__
        print("scheduler_legacy_cron_failed reason=" + reason)
        raise SystemExit(1)
    print("scheduler_legacy_cron_pass mode=" + ("check" if arguments.check else "retire"))
