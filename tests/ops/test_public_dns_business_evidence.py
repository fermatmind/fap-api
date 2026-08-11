import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = (
    ROOT
    / "backend"
    / "scripts"
    / "deploy"
    / "verify_public_dns_business_evidence.sh"
)


class PublicDnsBusinessEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp_dir.cleanup)
        self.temp_path = Path(self.temp_dir.name)
        self.count_path = self.temp_path / "curl-count"
        self.log_path = self.temp_path / "curl.log"
        self.sleep_path = self.temp_path / "sleep.log"

        self._write_executable(
            "curl",
            """
            #!/usr/bin/env bash
            set -euo pipefail

            count=0
            if [ -f "$FAKE_CURL_COUNT" ]; then
              count="$(cat "$FAKE_CURL_COUNT")"
            fi
            count=$((count + 1))
            printf '%s' "$count" > "$FAKE_CURL_COUNT"
            printf '%s\n' "$*" >> "$FAKE_CURL_LOG"
            url="${!#}"

            case "${FAKE_CURL_MODE:-success}" in
              transient_flags_transport)
                if [ "$count" -eq 2 ]; then
                  exit 28
                fi
                ;;
              persistent_flags_503)
                if [[ "$url" == */api/v0.3/flags ]]; then
                  printf '{"ok":false}\n503'
                  exit 0
                fi
                ;;
              flags_401)
                if [[ "$url" == */api/v0.3/flags ]]; then
                  printf '{"ok":false}\n401'
                  exit 0
                fi
                ;;
              invalid_bigfive_contract)
                if [[ "$url" == */api/v0.5/personality-content-assets/big_five/hub/big-five\\?locale=zh-CN ]]; then
                  printf '{"ok":true,"personality_public_content_asset_v1":{"source_hash":"bad"}}\n200'
                  exit 0
                fi
                ;;
            esac

            case "$url" in
              */api/healthz)
                printf '{}\n404'
                ;;
              */api/v0.3/flags)
                printf '{"ok":true}\n200'
                ;;
              */api/v0.5/personality-content-assets/big_five/hub/big-five\\?locale=zh-CN)
                printf '{"ok":true,"personality_public_content_asset_v1":{"source_hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}}\n200'
                ;;
              *)
                exit 64
                ;;
            esac
            """,
        )
        self._write_executable(
            "sleep",
            """
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "$FAKE_SLEEP_LOG"
            """,
        )

    def _write_executable(self, name, body):
        path = self.temp_path / name
        path.write_text(textwrap.dedent(body).lstrip(), encoding="utf-8")
        path.chmod(0o755)

    def environment(self, **overrides):
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{self.temp_path}:{env['PATH']}",
                "FAKE_CURL_COUNT": str(self.count_path),
                "FAKE_CURL_LOG": str(self.log_path),
                "FAKE_SLEEP_LOG": str(self.sleep_path),
                "PUBLIC_DNS_PROBE_BASE_URL": "https://api.example.test",
                "PUBLIC_DNS_PROBE_ATTEMPTS": "3",
                "PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS": "0 0",
                "PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS": "3",
                "PUBLIC_DNS_PROBE_MAX_TIME_SECONDS": "10",
            }
        )
        env.update(overrides)
        return env

    def run_script(self, **overrides):
        return subprocess.run(
            ["bash", str(SCRIPT)],
            cwd=ROOT,
            env=self.environment(**overrides),
            capture_output=True,
            text=True,
            check=False,
        )

    def curl_count(self):
        if not self.count_path.exists():
            return 0
        return int(self.count_path.read_text(encoding="utf-8"))

    def test_success_runs_one_complete_probe(self):
        result = self.run_script()

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(3, self.curl_count())
        self.assertIn("passed on attempt 1", result.stdout)
        self.assertNotIn("api.example.test", result.stdout + result.stderr)

    def test_single_attempt_accepts_an_empty_retry_schedule(self):
        result = self.run_script(
            PUBLIC_DNS_PROBE_ATTEMPTS="1",
            PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS="",
        )

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(3, self.curl_count())

    def test_transport_failure_retries_the_complete_probe(self):
        result = self.run_script(FAKE_CURL_MODE="transient_flags_transport")

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(5, self.curl_count())
        self.assertIn("retrying after attempt 1: stage=public_flags status=none rc=75", result.stderr)
        self.assertIn("passed on attempt 2", result.stdout)

    def test_retryable_status_exhausts_the_exact_attempt_limit(self):
        result = self.run_script(FAKE_CURL_MODE="persistent_flags_503")

        self.assertEqual(1, result.returncode)
        self.assertEqual(6, self.curl_count())
        self.assertIn("failed after 3 attempts: stage=public_flags status=503 rc=75", result.stderr)

    def test_non_retryable_http_status_fails_immediately(self):
        result = self.run_script(FAKE_CURL_MODE="flags_401")

        self.assertEqual(1, result.returncode)
        self.assertEqual(2, self.curl_count())
        self.assertIn("failed terminally: stage=public_flags status=401 rc=1", result.stderr)
        self.assertFalse(self.sleep_path.exists())

    def test_contract_mismatch_fails_immediately_without_body_output(self):
        result = self.run_script(FAKE_CURL_MODE="invalid_bigfive_contract")

        self.assertEqual(1, result.returncode)
        self.assertEqual(3, self.curl_count())
        self.assertIn("stage=public_bigfive_contract status=200 rc=1", result.stderr)
        self.assertNotIn("source_hash", result.stdout + result.stderr)
        self.assertNotIn("bad", result.stdout + result.stderr)

    def test_invalid_configuration_fails_before_curl(self):
        cases = (
            {"PUBLIC_DNS_PROBE_BASE_URL": "http://api.example.test"},
            {"PUBLIC_DNS_PROBE_BASE_URL": "https://user@example.test"},
            {"PUBLIC_DNS_PROBE_BASE_URL": "https://api.example.test/path"},
            {"PUBLIC_DNS_PROBE_ATTEMPTS": "0"},
            {"PUBLIC_DNS_PROBE_ATTEMPTS": "6"},
            {"PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS": "0"},
            {"PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS": "0"},
            {"PUBLIC_DNS_PROBE_MAX_TIME_SECONDS": "121"},
        )

        for overrides in cases:
            with self.subTest(overrides=overrides):
                self.count_path.unlink(missing_ok=True)
                result = self.run_script(**overrides)
                self.assertEqual(2, result.returncode)
                self.assertEqual(0, self.curl_count())


if __name__ == "__main__":
    unittest.main()
