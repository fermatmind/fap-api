import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('installer', Path(__file__).parents[2] / 'scripts/deploy/install_nginx_dns_resilience.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

SOURCE = '''server {
    listen 127.0.0.1:18080;
    location /result/ { proxy_pass https://fermatmind.com; }
    location /api/ { proxy_pass https://api.fermatmind.com; }
    location / { return 404; }
}
'''


class NginxResilienceTest(unittest.TestCase):
    def test_only_fixed_origins_change_and_transform_is_idempotent(self):
        result = module.transform(SOURCE)
        self.assertEqual(result, module.transform(result))
        self.assertIn('listen 127.0.0.1:18080;', result)
        self.assertIn('location / { return 404; }', result)
        self.assertNotIn('proxy_pass https://', result)
        self.assertIn('resolver_timeout 5s;', result)
        self.assertNotIn('$request_uri', result)

    def test_unexpected_upstream_fails_closed(self):
        with self.assertRaises(ValueError):
            module.transform(SOURCE.replace('https://fermatmind.com;', 'https://unexpected.example;'))

    def test_failed_reload_restores_both_files(self):
        with tempfile.TemporaryDirectory() as directory:
            proxy = Path(directory) / 'proxy.conf'
            unit = Path(directory) / 'unit.conf'
            proxy.write_text(SOURCE)
            calls = []
            def run(*args):
                calls.append(args)
                if args == ('systemctl', 'reload', 'nginx') and calls.count(args) == 1:
                    raise module.subprocess.CalledProcessError(1, args)
            with patch.object(module, 'PROXY', proxy), patch.object(module, 'UNIT', unit), patch.object(module.os, 'geteuid', return_value=0), patch.object(module, 'run', run):
                with self.assertRaises(module.subprocess.CalledProcessError):
                    module.install()
            self.assertEqual(SOURCE, proxy.read_text())
            self.assertFalse(unit.exists())
            self.assertEqual(2, calls.count(('systemctl', 'reload', 'nginx')))


if __name__ == '__main__':
    unittest.main()
