import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-supervisor-read-only-sudo-control.yml"


class BackendProductionSupervisorReadOnlySudoControlWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.raw = WORKFLOW.read_text(encoding="utf-8")

    def test_manual_protected_exact_main_contract(self):
        trigger = re.search(r"(?ms)^on:\n(?P<body>.*?)(?=^permissions:)", self.raw)
        self.assertIsNotNone(trigger)
        self.assertIn("workflow_dispatch:", trigger.group("body"))
        self.assertNotRegex(trigger.group("body"), r"(?m)^\s{2}(push|pull_request|schedule):")
        self.assertIn("environment: production", self.raw)
        self.assertIn("group: deploy-${{ github.repository }}-production", self.raw)
        self.assertIn("cancel-in-progress: false", self.raw)
        self.assertIn('test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"', self.raw)
        self.assertIn('test "$GITHUB_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"', self.raw)

    def test_routing_and_identity_are_secrets_only(self):
        for name in (
            "PRODUCTION_DEPLOY_USER",
            "PRODUCTION_DEPLOY_PORT",
            "PRODUCTION_DEPLOY_HOST",
            "PRODUCTION_DEPLOY_PATH",
            "SSH_KNOWN_HOSTS",
            "SSH_PRIVATE_KEY",
        ):
            self.assertIn(f"${{{{ secrets.{name} }}}}", self.raw)
            self.assertNotIn(f"${{{{ vars.{name} }}}}", self.raw)

    def test_preflight_is_remote_zero_write_and_sanitized(self):
        preflight = re.search(
            r"(?ms)^      - name: Run zero-write Supervisor sudo preflight\n(?P<body>.*?)(?=^      - name:)",
            self.raw,
        )
        self.assertIsNotNone(preflight)
        body = preflight.group("body")
        for required in (
            "sudo -n -l",
            "/usr/bin/supervisorctl status",
            "deploy_user_sha256",
            "rule_sha256",
            "PASS_PREFLIGHT_APPLY_ELIGIBLE",
            "PASS_PREFLIGHT_ALREADY_AUTHORIZED",
            "PASS_PREFLIGHT_MANUAL_ROOT_REQUIRED",
            "apply_eligible",
        ):
            self.assertIn(required, body)
        for forbidden in (
            "sudoers_rule_write_count = 1",
            "supervisorctl restart",
            "supervisorctl update",
            "systemctl restart",
            "service supervisor restart",
            "queue:restart",
            "rm -rf",
        ):
            self.assertNotIn(forbidden, body)

    def test_apply_is_exact_receipt_and_phrase_bound(self):
        self.assertIn("expected_preflight_receipt_sha256:", self.raw)
        self.assertIn("expected_deploy_user_sha256:", self.raw)
        self.assertIn("expected_rule_sha256:", self.raw)
        self.assertIn("PASS_PREFLIGHT_APPLY_ELIGIBLE", self.raw)
        self.assertIn("operator_approval_phrase:", self.raw)
        self.assertIn(
            "allow only /usr/bin/supervisorctl status without password, validate with visudo and exact readback",
            self.raw,
        )
        self.assertIn("sudo -n /usr/sbin/visudo -cf", self.raw)
        self.assertIn("sudo -n /usr/bin/install -o root -g root -m 0440", self.raw)
        self.assertIn("test \"$status\" = PASS_APPLY", self.raw)

    def test_only_one_live_permission_rule_is_allowed(self):
        expected_rule = '$target_user ALL=(root) NOPASSWD: /usr/bin/supervisorctl status'
        self.assertEqual(2, self.raw.count(expected_rule))
        self.assertNotIn("NOPASSWD: ALL", self.raw)
        self.assertNotIn("/usr/bin/supervisorctl *", self.raw)
        self.assertNotIn("supervisorctl restart", self.raw)
        self.assertNotIn("supervisorctl update", self.raw)

    def test_failure_receipt_exists_before_checkout_and_is_always_uploaded(self):
        initialize = self.raw.index("- name: Initialize sanitized failure receipt")
        checkout = self.raw.index("- name: Check out exact main control plane")
        self.assertLess(initialize, checkout)
        self.assertIn("if: ${{ always() }}", self.raw)
        self.assertIn("path: ${{ runner.temp }}/backend-production-supervisor-read-only-sudo-control.json", self.raw)
        self.assertIn("retention-days: 30", self.raw)
        for value in (
            "sudoers_rule_write_count",
            "remote_temp_file_write_count",
            "supervisor_config_write_count",
            "service_restart_count",
            "deploy_count",
            "database_or_cms_write_count",
            "warm_or_publication_count",
            "discoverability_write_count",
            "writes_committed",
        ):
            self.assertIn(value, self.raw)

if __name__ == "__main__":
    unittest.main()
