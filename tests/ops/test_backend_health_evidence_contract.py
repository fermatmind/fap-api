import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
READINESS = ROOT / "backend/scripts/deploy/readiness.sh"
POST_DEPLOY = ROOT / "backend/scripts/deploy/post_deploy_validate.sh"


class BackendHealthEvidenceContractTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp_dir.cleanup)
        self.temp_path = Path(self.temp_dir.name)
        self.curl_log = self.temp_path / "curl.log"
        fake_curl = self.temp_path / "curl"
        fake_curl.write_text(
            textwrap.dedent(
                """\
                #!/usr/bin/env bash
                set -euo pipefail

                printf '%s\\n' "$*" >> "$CURL_LOG"

                output_file=""
                write_format=""
                url=""
                while [ "$#" -gt 0 ]; do
                  case "$1" in
                    -o)
                      output_file="$2"
                      shift 2
                      ;;
                    -w)
                      write_format="$2"
                      shift 2
                      ;;
                    --max-time|-A|--resolve)
                      shift 2
                      ;;
                    -sS)
                      shift
                      ;;
                    *)
                      url="$1"
                      shift
                      ;;
                  esac
                done

                code="200"
                body='{"ok":true}'
                case "$url" in
                  */api/healthz)
                    if [ "${FAKE_HEALTH_OK:-1}" != "1" ]; then
                      body='{"ok":false}'
                    fi
                    ;;
                  */api/v0.3/flags)
                    if [ "${FAKE_FLAGS_FAIL:-0}" = "1" ]; then code="503"; fi
                    body='{"flags":[]}'
                    ;;
                  *personality-content-assets*)
                    if [ "${FAKE_PERSONALITY_FAIL:-0}" = "1" ]; then code="503"; fi
                    body='{"data":{}}'
                    ;;
                  *software-developers*)
                    code="404"
                    body='{}'
                    ;;
                  *)
                    body='{}'
                    ;;
                esac

                if [ -n "$output_file" ]; then
                  printf '%s' "$body" > "$output_file"
                fi
                if [ -n "$write_format" ]; then
                  printf '%s' "$code"
                fi
                """
            ),
            encoding="utf-8",
        )
        fake_curl.chmod(0o755)

    def environment(self, include_web=False):
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{self.temp_path}:{env['PATH']}",
                "CURL_LOG": str(self.curl_log),
                "HEALTHCHECK_HOST": "internal-health.sentinel.test",
                "PUBLIC_API_BASE_URL": "https://api.example.test",
                "BACKEND_SHA": "a" * 40,
                "RELEASE_NAME": "release-a",
                "PROBE_ID": "probe-20260718-a",
                "TIMEOUT": "3",
            }
        )
        if include_web:
            env["PUBLIC_WEB_BASE_URL"] = "https://www.example.test"
        return env

    def run_script(self, script, env):
        return subprocess.run(
            ["bash", str(script)],
            cwd=ROOT,
            env=env,
            capture_output=True,
            text=True,
            check=False,
        )

    def curl_calls(self):
        if not self.curl_log.exists():
            return ""
        return self.curl_log.read_text(encoding="utf-8")

    def test_readiness_fails_closed_when_a_required_value_is_missing(self):
        for variable in (
            "HEALTHCHECK_HOST",
            "PUBLIC_API_BASE_URL",
            "BACKEND_SHA",
            "RELEASE_NAME",
            "PROBE_ID",
        ):
            with self.subTest(variable=variable):
                self.curl_log.unlink(missing_ok=True)
                env = self.environment()
                env.pop(variable)

                result = self.run_script(READINESS, env)

                self.assertEqual(2, result.returncode)
                self.assertIn(f"{variable} is required", result.stdout)
                self.assertEqual("", self.curl_calls())

    def test_post_deploy_requires_the_public_web_origin(self):
        result = self.run_script(POST_DEPLOY, self.environment())

        self.assertEqual(2, result.returncode)
        self.assertIn("PUBLIC_WEB_BASE_URL is required", result.stdout)
        self.assertEqual("", self.curl_calls())

    def test_readiness_uses_internal_health_and_public_business_probes(self):
        result = self.run_script(READINESS, self.environment())
        calls = self.curl_calls()

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("--resolve internal-health.sentinel.test:443:127.0.0.1", calls)
        self.assertIn("https://internal-health.sentinel.test/api/healthz", calls)
        self.assertIn("https://api.example.test/api/v0.3/flags", calls)
        self.assertIn("personality-content-assets/big_five/hub/big-five?locale=zh-CN", calls)
        self.assertIn("FermatMindReleaseProbe/probe-20260718-a", calls)
        self.assertNotIn("internal-health.sentinel.test", result.stdout + result.stderr)
        public_calls = [line for line in calls.splitlines() if "api.example.test" in line]
        self.assertFalse(any("/api/healthz" in line or "/healthz" in line for line in public_calls))

    def test_readiness_rejects_unhealthy_internal_payload(self):
        env = self.environment()
        env["FAKE_HEALTH_OK"] = "0"

        result = self.run_script(READINESS, env)

        self.assertEqual(1, result.returncode)
        self.assertIn("health payload is not healthy", result.stdout)

    def test_readiness_rejects_failed_flags_or_personality_probe(self):
        for failure_var in ("FAKE_FLAGS_FAIL", "FAKE_PERSONALITY_FAIL"):
            with self.subTest(failure_var=failure_var):
                self.curl_log.unlink(missing_ok=True)
                env = self.environment()
                env[failure_var] = "1"

                result = self.run_script(READINESS, env)

                self.assertEqual(1, result.returncode)

    def test_post_deploy_uses_separate_api_and_web_origins_without_public_health(self):
        result = self.run_script(POST_DEPLOY, self.environment(include_web=True))
        calls = self.curl_calls()

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("https://api.example.test/api/v0.3/flags", calls)
        self.assertIn("https://www.example.test/zh/personality/big-five", calls)
        self.assertIn("https://www.example.test/sitemap.xml", calls)
        self.assertIn("FermatMindReleaseProbe/probe-20260718-a", calls)
        self.assertNotIn("internal-health.sentinel.test", result.stdout + result.stderr)
        public_calls = [
            line
            for line in calls.splitlines()
            if "api.example.test" in line or "www.example.test" in line
        ]
        self.assertFalse(any("/api/healthz" in line or "/healthz" in line for line in public_calls))


if __name__ == "__main__":
    unittest.main()
