import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "backend" / "scripts" / "deploy" / "verify_scale_lookup.sh"
DEPLOYER = ROOT / "deploy.php"
SLUG = "mbti-personality-test-16-personality-types"


class ScaleLookupHealthcheckRetryTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp_dir.cleanup)
        self.temp_path = Path(self.temp_dir.name)
        self.count_path = self.temp_path / "curl-count"
        self.log_path = self.temp_path / "curl.log"
        fake_curl = self.temp_path / "curl"
        fake_curl.write_text(
            textwrap.dedent(
                """\
                #!/usr/bin/env bash
                set -euo pipefail

                count=0
                if [ -f "$FAKE_CURL_COUNT" ]; then
                  count="$(cat "$FAKE_CURL_COUNT")"
                fi
                count=$((count + 1))
                printf '%s' "$count" > "$FAKE_CURL_COUNT"
                printf '%s\n' "$*" >> "$FAKE_CURL_LOG"

                case "${FAKE_CURL_MODE:-success}" in
                  transient_504)
                    if [ "$count" -eq 1 ]; then
                      exit 22
                    fi
                    ;;
                  persistent_504)
                    exit 22
                    ;;
                  unhealthy)
                    printf '%s\n' '{"ok":false,"primary_slug":"mbti-personality-test-16-personality-types"}'
                    exit 0
                    ;;
                  wrong_slug)
                    printf '%s\n' '{"ok":true,"primary_slug":"wrong-slug"}'
                    exit 0
                    ;;
                esac

                printf '%s\n' '{"ok":true,"primary_slug":"mbti-personality-test-16-personality-types"}'
                """
            ),
            encoding="utf-8",
        )
        fake_curl.chmod(0o755)

    def environment(self, **overrides):
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{self.temp_path}:{env['PATH']}",
                "FAKE_CURL_COUNT": str(self.count_path),
                "FAKE_CURL_LOG": str(self.log_path),
                "SCALE_LOOKUP_BASE_URL": "https://api.example.test",
                "SCALE_LOOKUP_SLUG": SLUG,
                "SCALE_LOOKUP_USE_RESOLVE": "false",
                "SCALE_LOOKUP_ATTEMPTS": "3",
                "SCALE_LOOKUP_RETRY_DELAY_SECONDS": "0",
                "SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS": "5",
                "SCALE_LOOKUP_MAX_TIME_SECONDS": "40",
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

    def curl_log(self):
        if not self.log_path.exists():
            return ""
        return self.log_path.read_text(encoding="utf-8")

    def test_transient_504_retries_the_full_semantic_probe(self):
        result = self.run_script(FAKE_CURL_MODE="transient_504")

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(2, self.curl_count())
        self.assertIn("passed on attempt 2", result.stdout)

    def test_persistent_504_fails_after_the_exact_attempt_limit(self):
        result = self.run_script(FAKE_CURL_MODE="persistent_504")

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(3, self.curl_count())
        self.assertIn("all bounded attempts failed", result.stderr)

    def test_unhealthy_payload_retries_then_fails(self):
        result = self.run_script(FAKE_CURL_MODE="unhealthy")

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(3, self.curl_count())

    def test_wrong_slug_retries_then_fails(self):
        result = self.run_script(FAKE_CURL_MODE="wrong_slug")

        self.assertNotEqual(0, result.returncode)
        self.assertEqual(3, self.curl_count())

    def test_invalid_inputs_fail_before_curl(self):
        cases = (
            {"SCALE_LOOKUP_BASE_URL": "http://api.example.test"},
            {"SCALE_LOOKUP_BASE_URL": "https://user@example.test"},
            {"SCALE_LOOKUP_BASE_URL": "https://api.example.test/path"},
            {"SCALE_LOOKUP_SLUG": "MBTI_bad"},
            {"SCALE_LOOKUP_ATTEMPTS": "0"},
            {"SCALE_LOOKUP_ATTEMPTS": "6"},
            {"SCALE_LOOKUP_RETRY_DELAY_SECONDS": "31"},
            {"SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS": "0"},
            {"SCALE_LOOKUP_MAX_TIME_SECONDS": "121"},
        )

        for overrides in cases:
            with self.subTest(overrides=overrides):
                self.count_path.unlink(missing_ok=True)
                self.log_path.unlink(missing_ok=True)
                result = self.run_script(**overrides)
                self.assertEqual(2, result.returncode)
                self.assertEqual(0, self.curl_count())

    def test_resolve_mode_targets_loopback_without_printing_the_host(self):
        result = self.run_script(SCALE_LOOKUP_USE_RESOLVE="true")

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("--resolve api.example.test:443:127.0.0.1", self.curl_log())
        self.assertNotIn("api.example.test", result.stdout + result.stderr)

    def test_public_mode_does_not_add_resolve(self):
        result = self.run_script()

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertNotIn("--resolve", self.curl_log())
        self.assertIn(
            f"/api/v0.3/scales/lookup?slug={SLUG}&locale=zh-CN",
            self.curl_log(),
        )

    def test_deployer_runs_the_bounded_helper_from_the_current_release(self):
        deployer = DEPLOYER.read_text(encoding="utf-8")
        task_start = deployer.index("task('healthcheck:scale-lookup'")
        task_end = deployer.index("task('healthcheck:ops-entry-contract'", task_start)
        task = deployer[task_start:task_end]

        self.assertIn("within('{{current_path}}/backend'", task)
        self.assertIn("bash scripts/deploy/verify_scale_lookup.sh", task)
        self.assertIn("'SCALE_LOOKUP_ATTEMPTS' => '3'", task)
        self.assertIn("'SCALE_LOOKUP_RETRY_DELAY_SECONDS' => '2'", task)
        self.assertIn("'SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS' => '5'", task)
        self.assertIn("'SCALE_LOOKUP_MAX_TIME_SECONDS' => '40'", task)
        self.assertNotIn("curl -fsS", task)


if __name__ == "__main__":
    unittest.main()
