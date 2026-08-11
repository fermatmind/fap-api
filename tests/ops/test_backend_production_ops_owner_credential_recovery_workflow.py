import re
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-ops-owner-credential-recovery.yml"
RUNNER = ROOT / "backend/scripts/deploy/control_ops_owner_credential_recovery.php"


class BackendProductionOpsOwnerCredentialRecoveryWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.runner = RUNNER.read_text(encoding="utf-8")

    def test_workflow_is_manual_main_exact_and_uses_shared_production_boundary(self):
        trigger = re.search(r"(?ms)^on:\n(?P<body>.*?)(?=^permissions:)", self.workflow)
        self.assertIsNotNone(trigger)
        self.assertIn("workflow_dispatch:", trigger.group("body"))
        self.assertNotRegex(trigger.group("body"), r"(?m)^\s{2}(?:push|pull_request|schedule):")
        self.assertIn("environment:\n      name: production", self.workflow)
        self.assertIn("group: deploy-${{ github.repository }}-production", self.workflow)
        self.assertIn("cancel-in-progress: false", self.workflow)
        self.assertIn('test "$GITHUB_REF" = refs/heads/main', self.workflow)
        self.assertIn('test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"', self.workflow)

    def test_password_is_never_a_dispatch_input_or_command_argument(self):
        trigger = self.workflow.split("permissions:", 1)[0]
        self.assertNotIn("password", trigger.lower())
        self.assertIn("${{ secrets.PRODUCTION_OPS_OWNER_RECOVERY_PASSWORD }}", self.workflow)
        self.assertIn("${{ secrets.PRODUCTION_OPS_OWNER_RECOVERY_EMAIL }}", self.workflow)
        self.assertNotIn("${{ vars.PRODUCTION_OPS_OWNER_RECOVERY", self.workflow)
        self.assertIn("printf '%s' \"$payload\"", self.workflow)
        self.assertIn('tail -n +2 "$runner" | base64 -w0', self.workflow)
        self.assertIn("stream_get_contents(STDIN, 4097)", self.runner)
        self.assertNotIn("RECOVERY_PASSWORD=$q_", self.workflow)
        self.assertNotIn("RECOVERY_EMAIL=$q_", self.workflow)

    def test_preflight_is_read_only_and_apply_is_receipt_bound(self):
        self.assertIn('mode == "preflight"', self.workflow)
        self.assertIn('.status == "PASS_PREFLIGHT"', self.workflow)
        self.assertIn('.production_write_execution == false', self.workflow)
        self.assertIn('.write_state == "confirmed_zero_write"', self.workflow)
        self.assertIn("EXPIRED_PREFLIGHT_RECEIPT", self.workflow)
        self.assertIn('test "$age" -ge 0 && test "$age" -le 1800', self.workflow)
        self.assertIn("I explicitly approve one bounded production Ops owner credential recovery", self.workflow)
        self.assertIn("EXPECTED_ACCOUNT_SHA256", self.workflow)
        self.assertIn("EXPECTED_STATE_SHA256", self.workflow)
        self.assertIn("PREFLIGHT_RUN_ID", self.workflow)
        self.assertIn("PREFLIGHT_RUN_ATTEMPT", self.workflow)

    def test_apply_changes_only_login_recovery_fields_and_preserves_security_state(self):
        force_fill = re.search(
            r"\$account->forceFill\(\[(?P<body>.*?)\]\)->save\(\);",
            self.runner,
            re.DOTALL,
        )
        self.assertIsNotNone(force_fill)
        body = force_fill.group("body")
        for field in (
            "'password'",
            "'password_changed_at'",
            "'failed_login_count'",
            "'locked_until'",
            "'is_active'",
        ):
            self.assertIn(field, body)
        for forbidden in ("totp_secret", "totp_enabled_at", "roles", "permissions", "email", "name"):
            self.assertNotIn(forbidden, body)
        self.assertIn("$totpPreserved", self.runner)
        self.assertIn("$rolesPreserved", self.runner)
        self.assertIn("POST_WRITE_VERIFICATION_FAILED", self.runner)
        self.assertIn(".admin_user_write_count=1", self.workflow.replace(" | ", "|"))

    def test_receipt_is_sanitized_failure_safe_and_always_uploaded(self):
        self.assertLess(
            self.workflow.index("Initialize failure-safe sanitized receipt"),
            self.workflow.index("Checkout exact main control plane"),
        )
        self.assertIn("if: always()", self.workflow)
        self.assertIn("secret_retirement_required: true", self.workflow)
        self.assertIn("remote_file_write_count: 0", self.workflow)
        self.assertIn("deploy_count: 0", self.workflow)
        self.assertIn("migration_count: 0", self.workflow)
        self.assertIn("cms_write_count: 0", self.workflow)
        self.assertIn("publication_count: 0", self.workflow)
        self.assertIn("discoverability_write_count: 0", self.workflow)
        self.assertIn("search_submit_count: 0", self.workflow)
        self.assertNotIn("response_body", self.workflow)
        self.assertNotIn("raw_log", self.workflow)

    def test_runner_is_valid_php_and_emits_only_bounded_protocols(self):
        result = subprocess.run(
            ["php", "-l", str(RUNNER)],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("OPS_OWNER_RECOVERY_FAILED:", self.runner)
        self.assertIn("Hash::check", self.runner)
        self.assertIn("DB::transaction", self.runner)
        self.assertNotIn("fwrite(STDOUT", self.runner)
        self.assertNotIn("var_dump", self.runner)
        self.assertNotIn("print_r", self.runner)


if __name__ == "__main__":
    unittest.main()
