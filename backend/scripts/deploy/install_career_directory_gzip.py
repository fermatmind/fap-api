#!/usr/bin/env python3
"""Install compression for the existing public Career index through deploy.yml."""
import argparse
import gzip
import json
import os
import re
import subprocess
import tempfile
import time
from pathlib import Path

ROUTE = '/api/v0.5/career/jobs'
BEGIN = '    # BEGIN managed Career directory gzip\n'
END = '    # END managed Career directory gzip\n'


def blocks(source, pattern):
    result = []
    for match in re.finditer(pattern, source, re.M):
        depth, quote, comment, escaped = 0, None, False, False
        opening = source.index('{', match.start(), match.end())
        for index in range(opening, len(source)):
            char = source[index]
            if comment:
                comment = char != '\n'
                continue
            if escaped:
                escaped = False
                continue
            if char == '\\':
                escaped = True
                continue
            if quote:
                if char == quote:
                    quote = None
                continue
            if char in ('"', "'"):
                quote = char
            elif char == '#':
                comment = True
            elif char == '{':
                depth += 1
            elif char == '}':
                depth -= 1
                if depth == 0:
                    result.append((match.start(), index + 1, source[opening + 1:index]))
                    break
        else:
            raise ValueError('unclosed Nginx block')
    return result


def expand_runtime_includes(source, loader, depth=0):
    if depth > 3:
        raise ValueError('unexpected runtime include depth')
    # Expand server-level runtime includes only; leave FastCGI includes intact.
    pattern = r'^\s*include\s+((?:/etc/nginx/)?snippets/fap-api-laravel-runtime\.conf);\s*$'
    return re.sub(pattern, lambda m: expand_runtime_includes(loader('/etc/nginx/' + m[1] if not m[1].startswith('/') else m[1]), loader, depth + 1), source, flags=re.M)


def transform(source, host, loader):
    if source.count(BEGIN) != source.count(END) or source.count(BEGIN) > 1:
        raise ValueError('ambiguous managed compression block')
    source = re.sub(re.escape(BEGIN) + r'.*?' + re.escape(END), '', source, flags=re.S)
    candidates = []
    for start, end, body in blocks(source, r'^\s*server\s*\{'):
        names = re.findall(r'^\s*server_name\s+([^;]+);', body, re.M)
        if len(names) == 1 and host in names[0].split() and re.search(r'^\s*listen\s+[^;]*\b443\b', body, re.M):
            candidates.append((start, end, body))
    if len(candidates) != 1:
        raise ValueError('expected one matching TLS API server')
    _, end, body = candidates[0]
    expanded = expand_runtime_includes(body, loader)
    if ROUTE in expanded:
        raise ValueError('directory location already configured outside managed block')
    php = blocks(expanded, r'^\s*location\s+~\s+\\\.php\$\s*\{')
    if len(php) != 1:
        raise ValueError('expected one existing Laravel PHP handler')
    handler = php[0][2].strip()
    if len(re.findall(r'^\s*fastcgi_pass\s+[^;]+;', handler, re.M)) != 1 or 'SCRIPT_FILENAME' not in handler:
        raise ValueError('existing PHP handler is incomplete')
    if re.search(r'\bgzip\w*\s|\brewrite\s|\breturn\s', handler):
        raise ValueError('unexpected PHP response/routing override')
    # break keeps this exact location while FastCGI sees the same index.php
    # script and unchanged REQUEST_URI/query string as Laravel's existing route.
    managed = (BEGIN + '    location = ' + ROUTE + ' {\n'
               '        gzip on;\n        gzip_types application/json;\n'
               '        gzip_comp_level 6;\n        gzip_min_length 1024;\n'
               '        gzip_vary on;\n        gzip_proxied any;\n'
               '        rewrite ^ /index.php break;\n'
               + '\n'.join('        ' + line.strip() for line in handler.splitlines())
               + '\n    }\n' + END)
    return source[:end - 1] + managed + source[end - 1:]


def run(*args, retry_http2=False):
    deadline = time.monotonic() + 30 if retry_http2 else None
    for attempt in range(2 if retry_http2 else 1):
        command = list(args)
        timeout = 40
        if deadline is not None:
            timeout = deadline - time.monotonic()
            if timeout <= 0:
                raise subprocess.TimeoutExpired(command, 30)
            command[command.index('--max-time') + 1] = str(timeout)
        try:
            return subprocess.run(command, check=True, capture_output=True, timeout=timeout).stdout
        except subprocess.CalledProcessError as error:
            if retry_http2 and attempt == 0 and error.returncode == 16 and deadline - time.monotonic() > 0.2:
                # A fresh, identical HTTP/2 GET may recover while reload drains an
                # old worker. Keep the original total deadline and all checks.
                print('career_directory_gzip_transport_retry=curl_http2_framing', flush=True)
                time.sleep(0.2)
                continue
            # Never disclose command output, response bodies, or private topology.
            operation = Path(args[0]).name
            if operation in ('nginx', 'systemctl'):
                operation += ' ' + args[1]
            raise ValueError(operation + ' failed with exit ' + str(error.returncode)) from None


def read_response(host, locale, encoding):
    with tempfile.TemporaryDirectory() as temporary:
        headers = Path(temporary) / 'headers'
        body = Path(temporary) / 'body'
        status = run('curl', '--silent', '--show-error', '--noproxy', '*',
                     '--connect-timeout', '5', '--max-time', '30',
                     '--resolve', host + ':443:127.0.0.1',
                     '-H', 'Accept-Encoding: ' + encoding,
                     '-H', 'X-FAP-Locale: ' + locale,
                     '-D', str(headers), '-o', str(body), '-w', '%{http_code}',
                     'https://' + host + ROUTE + '?locale=' + locale,
                     retry_http2=True).decode()
        if status != '200':
            raise ValueError('directory compression probe did not return 200')
        pairs = [line.split(':', 1) for line in headers.read_text().splitlines() if ':' in line]
        fields = {}
        for key, value in pairs:
            key = key.lower()
            fields[key] = fields[key] + ', ' + value.strip() if key in fields else value.strip()
        return fields, body.read_bytes()


def probe(host, compressed):
    counts = []
    for locale in ('en', 'zh-CN'):
        _, raw = read_response(host, locale, 'identity')
        payload = json.loads(raw)
        if payload.get('bundle_kind') != 'career_job_index' or not payload.get('items'):
            raise ValueError('directory authority unavailable during compression probe')
        counts.append(len(payload['items']))
        if compressed:
            headers, encoded = read_response(host, locale, 'gzip')
            if headers.get('content-encoding') != 'gzip' or gzip.decompress(encoded) != raw:
                raise ValueError('directory gzip round-trip mismatch')
            if 'accept-encoding' not in headers.get('vary', '').lower() or len(encoded) > len(raw) * 0.1:
                raise ValueError('directory compression budget or Vary mismatch')
            disabled_headers, disabled_body = read_response(host, locale, 'gzip;q=0')
            if disabled_headers.get('content-encoding') or disabled_body != raw:
                raise ValueError('directory gzip refusal was not respected')
    return counts


def atomic_write(path, content, mode):
    fd, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def install(site, host):
    if os.geteuid() != 0 or host not in ('api.fermatmind.com', 'staging-api.fermatmind.com'):
        raise ValueError('root and allowlisted API host required')
    target = Path(site).resolve(strict=True)
    if not target.is_relative_to('/etc/nginx'):
        raise ValueError('site outside Nginx configuration')
    previous, mode = target.read_bytes(), target.stat().st_mode & 0o777
    def load_include(name):
        path = Path(name).resolve(strict=True)
        if not path.is_relative_to('/etc/nginx/snippets'):
            raise ValueError('runtime include outside Nginx snippets')
        return path.read_text()
    candidate = transform(previous.decode(), host, load_include).encode()
    run('/usr/sbin/nginx', '-t')
    probe(host, False)
    try:
        if previous != candidate:
            atomic_write(target, candidate, mode)
            run('/usr/sbin/nginx', '-t')
            run('/usr/bin/systemctl', 'reload', 'nginx')
        counts = probe(host, True)
        run('/usr/bin/systemctl', 'is-active', '--quiet', 'nginx')
    except Exception:
        atomic_write(target, previous, mode)
        run('/usr/sbin/nginx', '-t')
        run('/usr/bin/systemctl', 'reload', 'nginx')
        probe(host, False)
        raise
    print(json.dumps({'career_directory_gzip': 'ready', 'locales': ['en', 'zh-CN'], 'counts': counts}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--site', required=True)
    parser.add_argument('--host', required=True)
    options = parser.parse_args()
    try:
        install(options.site, options.host)
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        # Report only our fixed probe/configuration messages, never command output,
        # response bodies, file paths, or deployment credentials.
        reason = str(error) if type(error) is ValueError else type(error).__name__
        raise SystemExit('career_directory_gzip=failed; reason=' + reason
                         + '; previous configuration restored where applicable')
