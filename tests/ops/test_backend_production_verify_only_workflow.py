import json
import os
import re
import subprocess
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-verify-only.yml"


class BackendProductionVerifyOnlyWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.raw = WORKFLOW.read_text(encoding="utf-8")
        cls.run_text = cls.raw
        remote_match = re.search(
            r'''<<'REMOTE' > "\$RUNNER_TEMP/backend-production-verification\.remote\.json"\n(?P<body>.*?)\n\s+REMOTE''',
            cls.run_text,
            re.DOTALL,
        )
        if remote_match is None:
            raise AssertionError("read-only SSH body is missing")
        cls.remote_body = remote_match.group("body")

    def test_manual_main_only_contract_and_exact_inputs(self):
        trigger_block = re.search(r"(?ms)^on:\n(?P<body>.*?)(?=^permissions:)", self.raw)
        self.assertIsNotNone(trigger_block)
        trigger_text = trigger_block.group("body")
        self.assertIn("workflow_dispatch:", trigger_text)
        self.assertNotRegex(trigger_text, r"(?m)^\s{2}(?:push|pull_request|workflow_run|schedule):")
        self.assertEqual(1, trigger_text.count("expected_release_sha:"))
        self.assertEqual(1, trigger_text.count("release_name:"))
        self.assertEqual(1, trigger_text.count("operator_approval_phrase:"))
        self.assertIn('test "$GITHUB_REF" = "refs/heads/main"', self.run_text)
        self.assertIn("ref: main", self.raw)
        self.assertIn('git merge-base --is-ancestor "$EXPECTED_RELEASE_SHA" origin/main', self.run_text)
        self.assertIn("needs: eligibility", self.raw)
        eligibility = re.search(r"(?ms)^  eligibility:\n(?P<body>.*?)(?=^  verify:)", self.raw)
        self.assertIsNotNone(eligibility)
        self.assertNotIn("environment:", eligibility.group("body"))

    def test_approval_permissions_environment_and_concurrency_are_fail_closed(self):
        self.assertRegex(self.raw, r"(?ms)^permissions:\n\s+contents: read\n\nconcurrency:")
        self.assertIn("environment: production", self.raw)
        self.assertIn("cancel-in-progress: false", self.raw)
        self.assertIn('[[ "$EXPECTED_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]', self.run_text)
        self.assertIn('[[ "$RELEASE_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]', self.run_text)
        self.assertIn(
            "I explicitly approve read-only backend production verification for SHA "
            "${EXPECTED_RELEASE_SHA} release ${RELEASE_NAME}.",
            self.run_text,
        )

    def test_production_connection_identity_uses_protected_secrets(self):
        for name in (
            "PRODUCTION_DEPLOY_USER",
            "PRODUCTION_DEPLOY_PORT",
            "PRODUCTION_DEPLOY_HOST",
            "PRODUCTION_RETIRED_DEPLOY_HOST",
            "PRODUCTION_DEPLOY_PATH",
        ):
            with self.subTest(name=name):
                self.assertIn(f"${{{{ secrets.{name} }}}}", self.raw)
                self.assertNotIn(f"${{{{ vars.{name} }}}}", self.raw)

        self.assertIn("${{ vars.PRODUCTION_HEALTHCHECK_URL }}", self.raw)

    def test_release_name_contract_accepts_existing_utc_release_identifiers(self):
        release_name_pattern = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")

        self.assertRegex(
            "big5-zh-v3-gate-20260718T122115Z-ddee2ec42-29644251396-1",
            release_name_pattern,
        )
        for invalid_release_name in (
            "-starts-with-separator",
            "contains/path",
            "contains space",
            "contains:$variable",
            "a" * 129,
        ):
            with self.subTest(release_name=invalid_release_name):
                self.assertNotRegex(invalid_release_name, release_name_pattern)

    def test_exact_release_health_and_public_authority_checks_are_present(self):
        required = (
            'test "$(basename "$current_release")" = "$RELEASE_NAME"',
            'test "$deployed_revision" = "$EXPECTED_RELEASE_SHA"',
            '--resolve "${healthcheck_host}:443:127.0.0.1"',
            'test "$public_health_status" = "404"',
            "/api/v0.3/flags",
            "personality-content-assets/big_five/hub/big-five?locale=zh-CN",
            'test "$internal_source_hash" = "$public_source_hash"',
            '(.questions.items | length) == 60',
            '(.questions.items | length) == 140',
            "php artisan fap:schema:verify --no-interaction --no-ansi",
            "pgrep -f '[p]hp-fpm'",
            "pgrep -x nginx",
            "sudo -n /usr/bin/supervisorctl status",
            'test "$queue_running" -eq "$queue_total"',
        )

        for value in required:
            with self.subTest(value=value):
                self.assertIn(value, self.remote_body)

    def test_all_five_scale_lookup_slugs_use_the_bounded_helper(self):
        slugs = (
            "mbti-personality-test-16-personality-types",
            "big-five-personality-test-ocean-model",
            "enneagram-personality-test-nine-types",
            "iq-test-intelligence-quotient-assessment",
            "clinical-depression-anxiety-assessment-professional-edition",
        )
        for slug in slugs:
            self.assertIn(slug, self.raw)
        self.assertIn("backend/scripts/deploy/verify_scale_lookup.sh", self.raw)
        self.assertIn("SCALE_LOOKUP_ATTEMPTS=3", self.raw)
        self.assertIn("SCALE_LOOKUP_MAX_TIME_SECONDS=40", self.raw)
        self.assertIn('test "$scale_lookup_count" -eq 5', self.raw)
        self.assertNotIn("$current_release/backend/scripts/deploy/verify_scale_lookup.sh", self.raw)

    def test_artifact_contract_is_safe_and_retained_for_thirty_days(self):
        self.assertIn(
            "uses: actions/checkout@df4cb1c069e1874edd31b4311f1884172cec0e10",
            self.raw,
        )
        self.assertIn(
            "uses: webfactory/ssh-agent@e83874834305fe9a4a2997156cb26c5de65a8555",
            self.raw,
        )
        self.assertIn(
            "uses: actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a",
            self.raw,
        )
        self.assertIn("retention-days: 30", self.raw)
        self.assertIn('schema_version: "backend-production-verify-only.v2"', self.remote_body)
        self.assertIn(
            'emit_receipt "PASS_BACKEND_PRODUCTION_VERIFY_ONLY" "" null',
            self.remote_body,
        )
        self.assertIn(
            'emit_receipt "FAIL_BACKEND_PRODUCTION_VERIFY_ONLY" "$1" 1',
            self.remote_body,
        )
        self.assertIn("writes_committed: false", self.remote_body)
        for guarantee in (
            "deploy",
            "migration",
            "database_write",
            "cms_write",
            "publisher",
            "queue_restart",
            "process_restart",
            "deploy_lock_mutation",
            "remote_file_write",
            "raw_log_read",
            "search_submit",
        ):
            self.assertIn(f"{guarantee}: false", self.remote_body)

    def test_failure_receipt_is_initialized_before_checkout_and_always_uploaded(self):
        initialize_at = self.raw.index("- name: Initialize sanitized verification receipt")
        checkout_at = self.raw.index("- name: Checkout main authority", initialize_at)
        self.assertLess(initialize_at, checkout_at)
        self.assertIn(
            'receipt="$RUNNER_TEMP/backend-production-verification.json"',
            self.raw,
        )
        self.assertIn(
            'remote_receipt="$RUNNER_TEMP/backend-production-verification.remote.json"',
            self.raw,
        )
        self.assertIn(
            "path: ${{ runner.temp }}/backend-production-verification.json",
            self.raw,
        )
        self.assertNotIn(
            'receipt="artifacts/backend-production-verification.json"',
            self.raw,
        )
        self.assertIn('failed_check: "checkout_main"', self.raw)
        self.assertIn('status: "FAIL_BACKEND_PRODUCTION_VERIFY_ONLY"', self.raw)

        for step_name in (
            "Validate sanitized verification receipt",
            "Publish safe verification summary",
            "Upload safe verification artifact",
        ):
            step_match = re.search(
                rf"(?ms)^\s+- name: {re.escape(step_name)}\n(?P<body>.*?)(?=^\s+- name:|\Z)",
                self.raw,
            )
            self.assertIsNotNone(step_match)
            self.assertIn("if: ${{ always() }}", step_match.group("body"))

    def test_remote_checks_emit_only_allowlisted_sanitized_checkpoints(self):
        checkpoint_ids = (
            "healthcheck_url_contract",
            "active_symlink",
            "release_name",
            "revision_file",
            "revision_match",
            "internal_health",
            "public_health_policy_404",
            "flags_api",
            "big_five_public_authority",
            "big_five_internal_authority",
            "personality_source_hash_match",
            "riasec_60",
            "riasec_140",
            "schema_verify",
            "php_fpm",
            "nginx",
            "queue_status",
            "queue_runtime",
        )
        self.assertIn("fail_check()", self.remote_body)
        self.assertIn("last_completed_check", self.remote_body)
        for checkpoint_id in checkpoint_ids:
            with self.subTest(checkpoint_id=checkpoint_id):
                self.assertIn(f'fail_check "{checkpoint_id}"', self.remote_body)
                self.assertIn(f'"{checkpoint_id}"', self.raw)

        self.assertNotIn("$current_release", self.raw.split("Publish safe verification summary", 1)[1])
        self.assertNotIn("$public_hub", self.raw.split("Publish safe verification summary", 1)[1])

    def test_runner_preserves_failure_receipt_for_transport_or_remote_validation_failure(self):
        self.assertIn("ssh_status=$?", self.raw)
        self.assertIn('.failed_check = "ssh_session"', self.raw)
        self.assertIn('.failed_check = "remote_receipt_validation"', self.raw)
        self.assertIn(
            'remote_receipt="$RUNNER_TEMP/backend-production-verification.remote.json"',
            self.raw,
        )
        self.assertIn("(.negative_guarantees | to_entries | all(.value == false))", self.raw)
        self.assertIn(
            '.status == "FAIL_BACKEND_PRODUCTION_VERIFY_ONLY" and',
            self.raw,
        )
        self.assertIn('(.failed_check | type) == "string"', self.raw)
        self.assertIn("2>/dev/null", self.remote_body)

    def test_remote_failure_path_emits_one_sanitized_v2_receipt(self):
        env = os.environ.copy()
        env.update(
            {
                "DEPLOY_PATH": "/invalid",
                "EXPECTED_RELEASE_SHA": "a" * 40,
                "RELEASE_NAME": "initial",
                "HEALTHCHECK_URL": "invalid",
            }
        )
        completed = subprocess.run(
            ["bash"],
            input=textwrap.dedent(self.remote_body),
            text=True,
            capture_output=True,
            env=env,
            check=False,
        )
        self.assertEqual(1, completed.returncode)
        self.assertEqual("", completed.stderr)
        receipt = json.loads(completed.stdout)
        self.assertEqual("backend-production-verify-only.v2", receipt["schema_version"])
        self.assertEqual("FAIL_BACKEND_PRODUCTION_VERIFY_ONLY", receipt["status"])
        self.assertEqual("healthcheck_url_contract", receipt["failed_check"])
        self.assertEqual("ssh_session", receipt["last_completed_check"])
        self.assertEqual(1, receipt["failure_exit_code"])
        self.assertFalse(receipt["writes_committed"])
        self.assertTrue(all(value is False for value in receipt["negative_guarantees"].values()))

    def test_remote_body_contains_no_mutating_or_raw_log_commands(self):
        command_body = self.remote_body.split("jq -n \\", 1)[0]
        forbidden_patterns = {
            "deployer": r"\bdep\s+deploy\b",
            "migration": r"\bartisan\s+migrate(?::|\s|$)",
            "queue restart": r"queue:restart",
            "supervisor restart": r"supervisorctl\s+restart",
            "system restart": r"systemctl\s+restart",
            "deploy unlock": r"deploy:unlock",
            "execute flag": r"(?:^|\s)--execute(?:\s|$)",
            "publisher command": r"artisan[^\n]*(?:publisher|publish)(?:\s|$)",
            "article publish": r"articles:publish",
            "search submission": r"search(?:[-_ ]submission|[-_:]submit)",
            "HTTP POST": r"curl[^\n]*(?:-X|--request)\s+POST",
            "remote mkdir": r"(?:^|[;&|]\s*)mkdir\s",
            "remote remove": r"(?:^|[;&|]\s*)rm\s",
            "remote move": r"(?:^|[;&|]\s*)mv\s",
            "remote copy": r"(?:^|[;&|]\s*)cp\s",
            "remote tee": r"(?:^|[;&|]\s*)tee\s",
            "raw access logs": r"(?:access\.log|journalctl|tail\s+-f)",
        }
        for name, pattern in forbidden_patterns.items():
            with self.subTest(name=name):
                self.assertIsNone(re.search(pattern, command_body, re.IGNORECASE | re.MULTILINE))

        self.assertNotIn("ops:healthz-snapshot", command_body)
        self.assertNotIn("/tmp/", command_body)

    def test_workflow_contains_no_mutating_execution_commands(self):
        forbidden_patterns = {
            "deployer": r"\bdep\s+deploy\b",
            "migration": r"\bphp\s+artisan\s+migrate(?::|\s|$)",
            "queue restart": r"queue:restart",
            "supervisor restart": r"supervisorctl\s+restart",
            "system restart": r"systemctl\s+restart",
            "deploy unlock": r"deploy:unlock",
            "execute flag": r"(?:^|\s)--execute(?:\s|$)",
            "publisher": r"php\s+artisan[^\n]*(?:publisher|publish)(?:\s|$)",
            "article publish": r"articles:publish",
            "HTTP POST": r"curl[^\n]*(?:-X|--request)\s+POST",
        }
        for name, pattern in forbidden_patterns.items():
            with self.subTest(name=name):
                self.assertIsNone(re.search(pattern, self.run_text, re.IGNORECASE | re.MULTILINE))

        self.assertNotIn("ops:healthz-snapshot", self.run_text)


if __name__ == "__main__":
    unittest.main()
