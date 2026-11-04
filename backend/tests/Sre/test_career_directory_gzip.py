import gzip
import hashlib
import importlib.util
import json
import os
import shutil
import socket
import socketserver
import struct
import threading
import urllib.parse
import subprocess
import tempfile
import time
import unittest
import urllib.request
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).parents[2] / 'scripts/deploy/install_career_directory_gzip.py'
spec = importlib.util.spec_from_file_location('career_gzip', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
HANDLER = '''location ~ \\.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param SCRIPT_NAME $fastcgi_script_name;
    fastcgi_pass unix:/run/php/existing-fpm.sock;
    fastcgi_cache_bypass $skip_api_cache;
    fastcgi_no_cache $skip_api_cache;
}
'''
SOURCE = '''server {
    listen 443 ssl;
    server_name api.fermatmind.com;
    root /existing/public;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    include /etc/nginx/snippets/fap-api-laravel-runtime.conf;
}
server { listen 80; server_name api.fermatmind.com; return 301 https://$host$request_uri; }
'''


class CareerGzipTransformTest(unittest.TestCase):
    def test_exact_location_reuses_complete_php_handler_and_is_idempotent(self):
        candidate = module.transform(SOURCE, 'api.fermatmind.com', lambda _: HANDLER)
        self.assertEqual(candidate, module.transform(candidate, 'api.fermatmind.com', lambda _: HANDLER))
        self.assertEqual(1, candidate.count('location = /api/v0.5/career/jobs {'))
        for line in HANDLER.splitlines()[1:-1]:
            self.assertIn(line.strip(), candidate)
        self.assertIn('rewrite ^ /index.php break;', candidate)
        self.assertIn('gzip_types application/json;', candidate)
        self.assertIn('gzip_vary on;', candidate)
        self.assertIn(SOURCE[SOURCE.index('server { listen 80'):], candidate)

    def test_production_relative_runtime_include_is_supported(self):
        source = SOURCE.replace('/etc/nginx/snippets/', 'snippets/')
        def loader(path):
            self.assertEqual('/etc/nginx/snippets/fap-api-laravel-runtime.conf', path)
            return HANDLER
        candidate = module.transform(source, 'api.fermatmind.com', loader)
        self.assertIn('location = /api/v0.5/career/jobs {', candidate)
        self.assertIn('include snippets/fap-api-laravel-runtime.conf;', candidate)

    def test_staging_inline_handler_and_query_routing_are_preserved(self):
        source = SOURCE.replace('api.fermatmind.com', 'staging-api.fermatmind.com').replace('include /etc/nginx/snippets/fap-api-laravel-runtime.conf;', HANDLER)
        candidate = module.transform(source, 'staging-api.fermatmind.com', lambda _: self.fail())
        self.assertIn('try_files $uri $uri/ /index.php?$query_string;', candidate)
        self.assertIn('fastcgi_cache_bypass $skip_api_cache;', candidate)
        self.assertNotIn('proxy_pass', candidate)

    def test_ambiguous_or_incomplete_existing_configuration_fails_closed(self):
        for source, handler in [(SOURCE + SOURCE, HANDLER), (SOURCE, ''), (SOURCE, HANDLER.replace('SCRIPT_FILENAME', 'OTHER')), (SOURCE.replace('location / {', 'location = /api/v0.5/career/jobs {'), HANDLER)]:
            with self.subTest(source=hashlib.sha256(source.encode()).hexdigest()):
                with self.assertRaises(ValueError):
                    module.transform(source, 'api.fermatmind.com', lambda _: handler)

    def test_failed_probe_restores_exact_original_file(self):
        with tempfile.TemporaryDirectory() as directory:
            site = Path(directory) / 'site.conf'
            source = SOURCE.replace('include /etc/nginx/snippets/fap-api-laravel-runtime.conf;', HANDLER)
            site.write_text(source)
            calls = []
            def probe(host, compressed):
                calls.append(compressed)
                if compressed:
                    raise ValueError('injected post-activation probe failure')
                return [30, 30]
            original = Path.is_relative_to
            with patch.object(module.os, 'geteuid', return_value=0), patch.object(module, 'run'), patch.object(module, 'probe', side_effect=probe), patch.object(Path, 'is_relative_to', lambda self, other: True if other == '/etc/nginx' else original(self, other)):
                with self.assertRaises(ValueError):
                    module.install(str(site), 'api.fermatmind.com')
            self.assertEqual(source, site.read_text())
            self.assertEqual([False, True, False], calls)

    def test_public_probe_rejects_gzip_when_client_refuses_it(self):
        payload = json.dumps({'bundle_kind': 'career_job_index', 'items': [{'slug': 'public-role'}], 'padding': 'x' * 5000}).encode()
        def response(host, locale, encoding):
            if encoding == 'identity':
                return {}, payload
            return {'content-encoding': 'gzip', 'vary': 'Origin, Accept-Encoding'}, gzip.compress(payload)
        with patch.object(module, 'read_response', side_effect=response):
            with self.assertRaisesRegex(ValueError, 'refusal'):
                module.probe('api.fermatmind.com', True)

    def test_failed_syntax_or_reload_restores_and_revalidates_previous_config(self):
        for failing_command in [('/usr/sbin/nginx', '-t'), ('/usr/bin/systemctl', 'reload', 'nginx')]:
            with self.subTest(command=failing_command), tempfile.TemporaryDirectory() as directory:
                site = Path(directory) / 'site.conf'
                source = SOURCE.replace('include /etc/nginx/snippets/fap-api-laravel-runtime.conf;', HANDLER)
                site.write_text(source)
                calls = []
                def run(*args):
                    calls.append(args)
                    if args == failing_command and site.read_text() != source:
                        raise subprocess.CalledProcessError(1, args)
                with patch.object(module.os, 'geteuid', return_value=0), patch.object(module, 'run', side_effect=run), patch.object(module, 'probe', return_value=[30, 30]), patch.object(Path, 'is_relative_to', return_value=True):
                    with self.assertRaises(subprocess.CalledProcessError):
                        module.install(str(site), 'api.fermatmind.com')
                self.assertEqual(source, site.read_text())
                self.assertEqual([('/usr/sbin/nginx', '-t'), ('/usr/bin/systemctl', 'reload', 'nginx')], calls[-2:])


@unittest.skipUnless(shutil.which('nginx'), 'Nginx binary required for wire integration tests')
class CareerGzipWireTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory()
        cls.root = Path(cls.directory.name)
        cls.payload = json.dumps({'bundle_kind': 'career_job_index', 'bundle_version': 'career.protocol.job_index.v1', 'items': [{'identity': {'canonical_slug': 'role-' + str(i)}, 'titles': {'canonical_en': 'Public role ' + str(i), 'canonical_zh': '公开职业' + str(i)}} for i in range(1046)]}, ensure_ascii=False).encode()
        # Transport fixtures, not production business acceptance.
        (cls.root / 'body.json').write_bytes(cls.payload)
        (cls.root / 'body.json.gz').write_bytes(gzip.compress(cls.payload))
        sock = socket.socket();sock.bind(('127.0.0.1', 0));cls.port = sock.getsockname()[1];sock.close()
        prefix = str(cls.root) + '/'
        handler = 'location ~ \\.php$ {\n fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;\n fastcgi_pass unix:/unused;\n}'
        source = 'server {\nlisten 443 ssl;\nserver_name api.fermatmind.com;\n' + handler + '\n}\n'
        candidate = module.transform(source, 'api.fermatmind.com', lambda _: '')
        start = candidate.index(module.BEGIN);end = candidate.index(module.END) + len(module.END)
        exact = candidate[start:end]
        exact = exact[:exact.index('        rewrite ^')] + '''        proxy_pass http://127.0.0.1:UPSTREAM/index.php$is_args$args;
    }
'''
        def record(kind, content):
            return struct.pack('!BBHHBB', 1, kind, 1, len(content), 0, 0) + content
        def read_exact(stream, count):
            data = b''
            while len(data) < count:
                part = stream.recv(count - len(data))
                if not part:
                    raise EOFError()
                data += part
            return data
        def parse_params(data):
            params = {}
            offset = 0
            def length():
                nonlocal offset
                value = data[offset];offset += 1
                if value & 128:
                    value = struct.unpack('!I', bytes([value]) + data[offset:offset + 3])[0] & 0x7fffffff;offset += 3
                return value
            while offset < len(data):
                keylen, valuelen = length(), length()
                key = data[offset:offset + keylen];offset += keylen
                params[key.decode()] = data[offset:offset + valuelen].decode();offset += valuelen
            return params
        class Handler(socketserver.BaseRequestHandler):
            def handle(self):
                parameters = b''
                while True:
                    _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', read_exact(self.request, 8))
                    data = read_exact(self.request, size)
                    if padding:read_exact(self.request, padding)
                    if kind == 4:parameters += data
                    if kind == 5 and not size:break
                params = parse_params(parameters)
                query = params.get('QUERY_STRING', '')
                flags = urllib.parse.parse_qs(query)
                status = '503 Service Unavailable' if 'failure' in flags else '200 OK'
                body = b'{"error":"unavailable"}' if 'failure' in flags else cls.payload
                encoding = ''
                if 'preencoded' in flags:
                    body = gzip.compress(body);encoding = 'Content-Encoding: gzip\r\n'
                headers = ('Status: ' + status + '\r\nContent-Type: application/json\r\nCache-Control: no-store\r\nVary: Origin, X-FAP-Locale\r\nX-Test-Query: ' + query + '\r\nX-Test-Uri: ' + params.get('REQUEST_URI', '') + '\r\nX-Test-Script: ' + params.get('SCRIPT_NAME', '') + '\r\n' + encoding + '\r\n').encode()
                response = headers + body
                for offset in range(0, len(response), 60000):self.request.sendall(record(6, response[offset:offset + 60000]))
                self.request.sendall(record(6, b'') + record(3, b'\0' * 8))
        cls.upstream = socketserver.TCPServer(('127.0.0.1', 0), Handler)
        cls.thread = threading.Thread(target=cls.upstream.serve_forever, daemon=True);cls.thread.start()
        handler = HANDLER.replace('    include fastcgi_params;', '    fastcgi_param QUERY_STRING $query_string;\n    fastcgi_param REQUEST_URI $request_uri;').replace('unix:/run/php/existing-fpm.sock', '127.0.0.1:' + str(cls.upstream.server_address[1]))
        source = 'server {\nlisten 443 ssl;\nserver_name api.fermatmind.com;\n' + handler + '\n}\n'
        candidate = module.transform(source, 'api.fermatmind.com', lambda _: '')
        exact = candidate[candidate.index(module.BEGIN):candidate.index(module.END)]
        config = f'''pid {prefix}nginx.pid;
error_log {prefix}error.log;
events {{}}
http {{
 access_log off;
 default_type application/json;
 gzip on;
 server {{
  listen 127.0.0.1:{cls.port};
  root {cls.root};
  set $skip_api_cache 1;
  {exact}
  location / {{ try_files $uri $uri/ /index.php?$query_string; }}
  {handler}
 }}
}}
'''
        (cls.root / 'index.php').write_text('transport fixture')
        cls.config = cls.root / 'nginx.conf';cls.config.write_text(config)
        subprocess.run(['nginx', '-t', '-p', prefix, '-c', str(cls.config)], check=True, capture_output=True)
        cls.process = subprocess.Popen(['nginx', '-p', prefix, '-c', str(cls.config), '-g', 'daemon off;'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1', cls.port), timeout=.1):break
            except OSError:time.sleep(.05)

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate();cls.process.wait(timeout=5);cls.upstream.shutdown();cls.upstream.server_close();cls.thread.join();cls.directory.cleanup()

    def read(self, encoding=None, suffix='?locale=en', route=module.ROUTE):
        headers = {} if encoding is None else {'Accept-Encoding': encoding}
        try:
            response = urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{self.port}' + route + suffix, headers=headers))
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.headers, response.read()

    def test_bilingual_gzip_is_identical_and_query_is_preserved(self):
        for locale in ['en', 'zh-CN']:
            status, headers, body = self.read('gzip', '?locale=' + locale)
            self.assertEqual(200, status);self.assertEqual('gzip', headers.get('Content-Encoding'))
            self.assertEqual(self.payload, gzip.decompress(body));self.assertLess(len(body), len(self.payload) * .1)
            self.assertIn('Accept-Encoding', ','.join(headers.get_all('Vary')))
            self.assertEqual('locale=' + locale, headers.get('X-Test-Query'))
            self.assertEqual(module.ROUTE + '?locale=' + locale, headers.get('X-Test-Uri'))
            self.assertEqual('/index.php', headers.get('X-Test-Script'))

    def test_identity_and_gzip_q_zero_are_uncompressed(self):
        for encoding in [None, 'identity', 'gzip;q=0', 'br']:
            status, headers, body = self.read(encoding)
            self.assertEqual(200, status);self.assertIsNone(headers.get('Content-Encoding'));self.assertEqual(self.payload, body)

    def test_failure_and_other_paths_keep_existing_behavior(self):
        status, headers, body = self.read('gzip', '?failure=1')
        self.assertEqual(503, status);self.assertIsNone(headers.get('Content-Encoding'));self.assertEqual({'error': 'unavailable'}, json.loads(body))
        for path in ['/api/v0.5/career/jobs/role-1', '/api/v0.5/other']:
            status, headers, body = self.read('gzip', route=path)
            self.assertEqual(200, status);self.assertIsNone(headers.get('Content-Encoding'));self.assertEqual(self.payload, body)

    def test_preencoded_response_is_not_compressed_twice(self):
        status, headers, body = self.read('gzip', '?preencoded=1')
        self.assertEqual(200, status);self.assertEqual('gzip', headers.get('Content-Encoding'));self.assertEqual(self.payload, gzip.decompress(body))


if __name__ == '__main__':
    unittest.main()
