import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "backend" / "scripts" / "deploy" / "verify_sitemap_source_cache_warm.sh"
DEPLOYER = ROOT / "deploy.php"


class SitemapSourceCacheWarmTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp_dir.cleanup)
        self.temp_path = Path(self.temp_dir.name)
        self.invocation_log = self.temp_path / "php-invocations"

        self.php_bin = self.temp_path / "php"
        self.php_bin.write_text(
            textwrap.dedent(
                """\
                #!/usr/bin/env bash
                set -euo pipefail

                printf '%s\n' "$*" >> "$FAKE_WARM_INVOCATION_LOG"

                case "${FAKE_WARM_MODE:-warmed}" in
                  warmed)
                    printf '%s\n' '{"status":"warmed","count":104,"elapsed_seconds":1.2}'
                    ;;
                  fallback)
                    printf '%s\n' '{"status":"fallback_warmed","count":19,"elapsed_seconds":2.3}'
                    ;;
                  locked)
                    printf '%s\n' '{"status":"locked","count":0,"elapsed_seconds":0.1}'
                    ;;
                  empty)
                    printf '%s\n' '{"status":"warmed","count":0,"elapsed_seconds":1.0}'
                    ;;
                  invalid_json)
                    printf '%s\n' 'not-json'
                    ;;
                  failure)
                    printf '%s\n' 'private-error-material' >&2
                    exit 17
                    ;;
                esac
                """
            ),
            encoding="utf-8",
        )
        self.php_bin.chmod(0o755)

        self.artisan = self.temp_path / "artisan"
        self.artisan.write_text("# test fixture\n", encoding="utf-8")

        fake_timeout = self.temp_path / "timeout"
        fake_timeout.write_text(
            textwrap.dedent(
                """\
                #!/usr/bin/env bash
                set -euo pipefail

                if [ "${FAKE_WARM_MODE:-}" = "timeout" ]; then
                  exit 124
                fi

                shift 2
                exec "$@"
                """
            ),
            encoding="utf-8",
        )
        fake_timeout.chmod(0o755)

    def environment(self, **overrides):
        env = os.environ.copy()
        env.update(
            {
                "PATH": f"{self.temp_path}:{env['PATH']}",
                "FAKE_WARM_INVOCATION_LOG": str(self.invocation_log),
                "SITEMAP_SOURCE_WARM_PHP_BIN": str(self.php_bin),
                "SITEMAP_SOURCE_WARM_ARTISAN": str(self.artisan),
                "SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS": "180",
                "SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS": "30",
                "SITEMAP_SOURCE_WARM_STRICT": "false",
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

    def invocation_count(self):
        if not self.invocation_log.exists():
            return 0
        return len(self.invocation_log.read_text(encoding="utf-8").splitlines())

    def test_warmed_payload_passes_once_with_expected_artisan_flags(self):
        result = self.run_script(FAKE_WARM_MODE="warmed")

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(1, self.invocation_count())
        self.assertIn("sitemap_source_cache_warm_status=warmed", result.stdout)
        invocation = self.invocation_log.read_text(encoding="utf-8")
        self.assertIn("seo:warm-sitemap-source-cache", invocation)
        self.assertIn("--json --no-interaction --no-ansi", invocation)

    def test_fallback_warmed_payload_is_an_accepted_safe_result(self):
        result = self.run_script(FAKE_WARM_MODE="fallback")

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn("sitemap_source_cache_warm_status=fallback_warmed", result.stdout)
        self.assertIn("sitemap_source_cache_warm_count=19", result.stdout)

    def test_timeout_is_nonblocking_by_default(self):
        result = self.run_script(FAKE_WARM_MODE="timeout")

        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual(0, self.invocation_count())
        self.assertIn("sitemap_source_cache_warm_status=degraded", result.stdout)
        self.assertIn("sitemap_source_cache_warm_reason=timeout", result.stdout)
        self.assertIn("sitemap_source_cache_warm_strict=false", result.stdout)

    def test_timeout_fails_in_strict_mode(self):
        result = self.run_script(
            FAKE_WARM_MODE="timeout",
            SITEMAP_SOURCE_WARM_STRICT="true",
        )

        self.assertEqual(1, result.returncode)
        self.assertIn("sitemap_source_cache_warm_reason=timeout", result.stdout)

    def test_degraded_results_follow_the_strict_policy(self):
        for mode, reason in (
            ("failure", "command_failed"),
            ("invalid_json", "invalid_json"),
            ("empty", "empty"),
            ("locked", "locked"),
        ):
            with self.subTest(mode=mode, strict=False):
                result = self.run_script(FAKE_WARM_MODE=mode)
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)
                self.assertIn(f"sitemap_source_cache_warm_reason={reason}", result.stdout)

            with self.subTest(mode=mode, strict=True):
                result = self.run_script(
                    FAKE_WARM_MODE=mode,
                    SITEMAP_SOURCE_WARM_STRICT="true",
                )
                self.assertEqual(1, result.returncode)
                self.assertIn(f"sitemap_source_cache_warm_reason={reason}", result.stdout)

    def test_invalid_configuration_fails_before_invocation(self):
        cases = (
            {"SITEMAP_SOURCE_WARM_PHP_BIN": "php"},
            {"SITEMAP_SOURCE_WARM_PHP_BIN": str(self.temp_path / "missing-php")},
            {"SITEMAP_SOURCE_WARM_ARTISAN": "artisan"},
            {"SITEMAP_SOURCE_WARM_ARTISAN": str(self.temp_path / "missing-artisan")},
            {"SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS": "119"},
            {"SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS": "601"},
            {"SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS": "4"},
            {"SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS": "61"},
            {"SITEMAP_SOURCE_WARM_STRICT": "yes"},
        )

        for overrides in cases:
            with self.subTest(overrides=overrides):
                self.invocation_log.unlink(missing_ok=True)
                result = self.run_script(**overrides)
                self.assertEqual(2, result.returncode)
                self.assertEqual(0, self.invocation_count())
                self.assertIn(
                    "sitemap_source_cache_warm_status=configuration_error",
                    result.stderr,
                )

    def test_command_output_and_paths_are_not_exposed(self):
        result = self.run_script(FAKE_WARM_MODE="failure")
        combined = result.stdout + result.stderr

        self.assertEqual(0, result.returncode)
        self.assertNotIn("private-error-material", combined)
        self.assertNotIn(str(self.temp_path), combined)
        self.assertNotIn(str(self.php_bin), combined)
        self.assertNotIn(str(self.artisan), combined)

    def test_deployer_uses_the_helper_as_www_data_and_keeps_the_safe_health_gate(self):
        deployer = DEPLOYER.read_text(encoding="utf-8")
        warm_start = deployer.index("task('seo:warm-sitemap-source-cache'")
        warm_end = deployer.index("task('guard:public-content-release'", warm_start)
        warm_task = deployer[warm_start:warm_end]

        self.assertIn("verify_sitemap_source_cache_warm.sh", warm_task)
        self.assertIn("sudo -n -u www-data -- env", warm_task)
        self.assertIn('php_bin="$(command -v {{bin/php}})"', warm_task)
        self.assertIn('SITEMAP_SOURCE_WARM_PHP_BIN="$php_bin"', warm_task)
        self.assertNotIn("SITEMAP_SOURCE_WARM_PHP_BIN={{bin/php}}", warm_task)
        self.assertIn("SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS", warm_task)
        self.assertIn("SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS", warm_task)
        self.assertIn("SITEMAP_SOURCE_WARM_STRICT", warm_task)
        self.assertNotIn("seo:warm-sitemap-source-cache --json", warm_task)

        health_start = deployer.index("task('healthcheck:sitemap-source'")
        health_end = deployer.index("task('healthcheck:public-dns'", health_start)
        health_task = deployer[health_start:health_end]

        self.assertIn("/api/v0.5/seo/sitemap-source", health_task)
        self.assertIn("backend_sitemap_generator_fallback", health_task)
        self.assertIn(".count >= 1", health_task)
        self.assertIn(
            "after('healthcheck:public', 'healthcheck:sitemap-source')",
            deployer,
        )
        self.assertIn(
            "after('healthcheck:sitemap-source', 'healthcheck:public-dns')",
            deployer,
        )


if __name__ == "__main__":
    unittest.main()
