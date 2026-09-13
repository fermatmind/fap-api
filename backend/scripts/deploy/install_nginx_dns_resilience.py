#!/usr/bin/env python3
"""Keep the existing private PDF proxy usable across transient DNS outages."""
import os
import re
import subprocess
import tempfile
from pathlib import Path

PROXY = Path('/etc/nginx/conf.d/fm-gotenberg-result-print-private.conf')
UNIT = Path('/etc/systemd/system/nginx.service.d/fermatmind-recovery.conf')
DROP_IN = '''[Unit]
StartLimitIntervalSec=60
StartLimitBurst=6

[Service]
Restart=on-failure
RestartSec=5s
'''
RESOLVER = '    resolver 127.0.0.53 valid=30s ipv6=off;\n    resolver_timeout 5s;\n'


def transform(source):
    # Preserve every listener, route, header, timeout and URI. Only fixed,
    # allowlisted origins become variables; request input never selects an origin.
    if re.search(r'^\s*resolver\s', source, re.M):
        if RESOLVER not in source:
            raise ValueError('unexpected resolver configuration')
    else:
        source, count = re.subn(r'(server\s*\{\n)', r'\1' + RESOLVER, source, count=1)
        if count != 1:
            raise ValueError('private proxy server missing')
    for host, variable in [('fermatmind.com', 'fm_print_web'), ('api.fermatmind.com', 'fm_print_api')]:
        source = source.replace('proxy_pass https://' + host + ';',
                                'set $' + variable + ' https://' + host + ';\n        proxy_pass $' + variable + ';')
    if re.search(r'proxy_pass\s+https?://', source):
        raise ValueError('unexpected static upstream')
    if not all('$' + name in source for name in ['fm_print_web', 'fm_print_api']):
        raise ValueError('expected fixed upstreams missing')
    return source


def run(*args):
    subprocess.run(args, check=True, capture_output=True, timeout=30)


def atomic_write(path, content):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def install():
    if os.geteuid() != 0:
        raise ValueError('root required')
    run('systemctl', 'is-active', '--quiet', 'systemd-resolved')
    for path in (PROXY, UNIT):
        if path.is_symlink():
            raise ValueError('unexpected symlink')
    previous = {path: path.read_bytes() if path.exists() else None for path in (PROXY, UNIT)}
    candidates = {UNIT: DROP_IN.encode()}
    # Staging has no private Gotenberg proxy. Do not invent a new listener there.
    if previous[PROXY] is not None:
        candidates[PROXY] = transform(previous[PROXY].decode()).encode()
    if all(previous[path] == content for path, content in candidates.items()):
        run('nginx', '-t')
        run('systemctl', 'is-active', '--quiet', 'nginx')
        return
    run('nginx', '-t')
    try:
        for path, content in candidates.items():
            atomic_write(path, content)
        run('nginx', '-t')
        run('systemctl', 'daemon-reload')
        run('systemctl', 'reload', 'nginx')
        run('systemctl', 'is-active', '--quiet', 'nginx')
    except Exception:
        for path in candidates:
            if previous[path] is None:
                path.unlink(missing_ok=True)
            else:
                atomic_write(path, previous[path])
        run('systemctl', 'daemon-reload')
        run('nginx', '-t')
        run('systemctl', 'reload', 'nginx')
        raise


if __name__ == '__main__':
    try:
        install()
        print('nginx_dns_resilience=ready')
    except (OSError, ValueError, subprocess.SubprocessError):
        raise SystemExit('nginx_dns_resilience=failed; previous configuration restored where applicable')
