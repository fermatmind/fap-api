#!/usr/bin/env python3
"""Install the one repository-owned loopback Redis service; preserve original Redis."""
import argparse
import os
import pwd
import socket
import subprocess
from pathlib import Path

UNIT = '''[Unit]
Description=FermatMind public projection cache
After=network.target

[Service]
Type=simple
User=redis
Group=redis
ExecStart=/usr/bin/redis-server /etc/redis/fermatmind-public-projection.conf
Restart=on-failure
RestartSec=5
TimeoutStopSec=60
LimitNOFILE=65535
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/redis-public-projection

[Install]
WantedBy=multi-user.target
'''


def validate(candidate: Path, staging: bool = False) -> str:
    if candidate.is_symlink() or not candidate.is_file() or candidate.stat().st_size > 8192:
        raise ValueError('invalid public Redis candidate')
    content = candidate.read_text()
    lines = content.splitlines()
    expected = {'bind': '127.0.0.1', 'protected-mode': 'yes', 'port': '6381', 'daemonize': 'no',
                'supervised': 'no', 'dir': '/var/lib/redis-public-projection', 'dbfilename': 'dump.rdb',
                'save': '""', 'appendonly': 'yes', 'appendfsync': 'everysec', 'auto-aof-rewrite-percentage': '100',
                'auto-aof-rewrite-min-size': '64mb', 'maxmemory': '2147483648', 'maxmemory-policy': 'noeviction', 'logfile': '""'}
    fields = dict(line.split(' ', 1) for line in lines)
    if len(fields) != len(lines) or set(fields) != set(expected) | {'requirepass'} or any(fields[k] != v for k, v in expected.items()):
        raise ValueError('public Redis configuration drift')
    import json
    password = json.loads(fields['requirepass'])
    if not isinstance(password, str) or (not password and not staging) or any(c in password for c in '\r\n\0'):
        raise ValueError('invalid Redis credential')
    return content


def redis_binary() -> Path:
    # Reuse the installed host binary. Staging uses /usr/local/bin while
    # production uses the distro package; never install/upgrade the original.
    for candidate in [Path('/usr/bin/redis-server'), Path('/usr/local/bin/redis-server')]:
        if candidate.is_file() and candidate.lstat().st_uid == 0:
            stat = candidate.stat()
            if stat.st_uid == 0 and stat.st_mode & 0o022 == 0 and os.access(candidate, os.X_OK):
                return candidate
    raise ValueError('trusted installed Redis binary unavailable')


def install(candidate: Path, staging: bool = False) -> None:
    if os.geteuid() != 0:
        raise ValueError('root installation required')
    content = validate(candidate, staging)
    unit_text = UNIT.replace('/usr/bin/redis-server', str(redis_binary()))
    destination = Path('/etc/redis/fermatmind-public-projection.conf')
    unit = Path('/etc/systemd/system/fermatmind-public-projection.service')
    directory = Path('/var/lib/redis-public-projection')
    for target in [destination, unit, directory]:
        if target.is_symlink():
            raise ValueError('unsafe public Redis path')
    # Existing live configuration is immutable here. Credential/config changes need
    # a separately verified rolling path, not an implicit restart during a UI release.
    if destination.exists() and destination.read_text() != content:
        raise ValueError('existing public Redis configuration differs')
    if unit.exists() and unit.read_text() != unit_text:
        raise ValueError('existing public Redis unit differs')
    if not unit.exists():
        # Refuse an occupied port before creating any persistent service files.
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 6381))
    redis = pwd.getpwnam('redis')
    directory.mkdir(mode=0o700, exist_ok=True)
    os.chown(directory, redis.pw_uid, redis.pw_gid)
    directory.chmod(0o700)
    destination.parent.mkdir(mode=0o755, exist_ok=True)
    if not destination.exists():
        with os.fdopen(os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640), 'w') as stream:
            stream.write(content)
        os.chown(destination, 0, redis.pw_gid)
        destination.chmod(0o640)
    if not unit.exists():
        unit.write_text(unit_text)
        unit.chmod(0o644)
    subprocess.run(['systemd-analyze', 'verify', str(unit)], check=True, capture_output=True, timeout=20)
    subprocess.run(['systemctl', 'daemon-reload'], check=True, capture_output=True, timeout=20)
    subprocess.run(['systemctl', 'enable', '--now', 'fermatmind-public-projection.service'], check=True, capture_output=True, timeout=40)
    subprocess.run(['systemctl', 'is-active', '--quiet', 'fermatmind-public-projection.service'], check=True, timeout=10)
    candidate.unlink()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--candidate', type=Path, required=True)
    parser.add_argument('--check-only', action='store_true')
    parser.add_argument('--staging', action='store_true')
    args = parser.parse_args()
    try:
        if args.check_only:
            validate(args.candidate, args.staging)
        else:
            install(args.candidate, args.staging)
        print('public_projection_redis=ready')
    except (OSError, ValueError, subprocess.SubprocessError):
        raise SystemExit('public_projection_redis=failed')
