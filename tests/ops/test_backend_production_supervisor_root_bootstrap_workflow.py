import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-supervisor-root-bootstrap.yml"


class BackendProductionSupervisorRootBootstrapWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.raw = WORKFLOW.read_text(encoding="utf-8")

    def test_manual_protected_exact_main_contract(self):
        self.assertIn("workflow_dispatch", self.raw)
        self.assertNotIn("\npush:", self.raw)
        self.assertIn("environment: production", self.raw)
        self.assertIn("group: deploy-${{ github.repository }}-production", self.raw)
        self.assertIn("cancel-in-progress: false", self.raw)
        self.assertIn("test \"$GITHUB_REF\" = \"refs/heads/main\"", self.raw)
        self.assertIn('test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"', self.raw)
        self.assertIn('test "$GITHUB_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"', self.raw)

    def test_source_receipt_chain_is_exact_and_fail_closed(self):
        for value in (
            "Backend Production Supervisor Read-only Sudo Control",
            "SOURCE_PREFLIGHT_CONTROL_PLANE_SHA",
            "backend-production-supervisor-read-only-sudo-preflight-${SOURCE_PREFLIGHT_RUN_ID}-${SOURCE_PREFLIGHT_RUN_ATTEMPT}",
            'and .status == "PASS_PREFLIGHT_MANUAL_ROOT_REQUIRED"',
            'and .rule_state == "ABSENT"',
            'and .supervisor_status_authorized_before == false',
            'and .apply_eligible == false',
            'and .writes_committed == false',
        ):
            self.assertIn(value, self.raw)

    def test_preflight_is_strict_zero_write_and_does_not_authenticate_secret(self):
        self.assertIn("PRODUCTION_ROOT_BOOTSTRAP_SUDO_PASSWORD", self.raw)
        self.assertIn("PRODUCTION_ROOT_BOOTSTRAP_SSH_USER", self.raw)
        self.assertIn("credential_present: false", self.raw)
        self.assertIn("credential_tested: false", self.raw)
        self.assertIn('echo "Protected one-time credential is present; no authentication was attempted."', self.raw)
        self.assertIn("Run strict zero-write root-bootstrap preflight", self.raw)
        preflight = self.raw.split("- name: Run strict zero-write root-bootstrap preflight", 1)[1].split(
            "- name: Apply exact one-time root bootstrap", 1
        )[0]
        self.assertNotIn("ROOT_BOOTSTRAP_SUDO_PASSWORD", preflight)
        self.assertNotIn("sudo -S", preflight)
        self.assertNotIn("sudo bash", preflight)
        self.assertIn("sudo -n /usr/bin/supervisorctl status", preflight)
        for value in (
            "production_write_execution: false",
            "sudoers_rule_write_count: 0",
            "remote_temp_file_write_count: 0",
            "supervisor_config_write_count: 0",
            "service_restart_count: 0",
            "deploy_count: 0",
            "database_or_cms_write_count: 0",
            "warm_or_publication_count: 0",
            "discoverability_write_count: 0",
            "writes_committed: false",
        ):
            self.assertIn(value, self.raw)

    def test_preflight_binds_active_state_toolchain_and_exact_rule(self):
        for value in (
            "PASS_ROOT_BOOTSTRAP_PREFLIGHT",
            "/etc/sudoers.d/fap-api-github-production-supervisor-status",
            '$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/supervisorctl status',
            "/usr/bin/install /usr/sbin/visudo /usr/bin/sha256sum /usr/bin/supervisorctl",
            "BLOCKED_BOOTSTRAP_IDENTITY",
            "BLOCKED_DEPLOY_IDENTITY",
            "BLOCKED_ACTIVE_REVISION",
            "BLOCKED_DEPLOY_LOCK",
            "BLOCKED_DEPLOY_PROCESS",
            "BLOCKED_UNSAFE_ROOT_TOOLCHAIN",
            "BLOCKED_EXISTING_RULE",
            "BLOCKED_ALREADY_AUTHORIZED",
            "toolchain_safe=true",
            "apply_eligible=true",
        ):
            self.assertIn(value, self.raw)

    def test_preflight_uses_distinct_bootstrap_and_deploy_ssh_identities(self):
        preflight = self.raw.split("- name: Run strict zero-write root-bootstrap preflight", 1)[1].split(
            "- name: Apply exact one-time root bootstrap", 1
        )[0]
        self.assertIn('"$BOOTSTRAP_SSH_USER@$DEPLOY_HOST"', preflight)
        self.assertIn('"$DEPLOY_USER@$DEPLOY_HOST"', preflight)
        self.assertIn("IFS='|' read -r bootstrap_user_sha", preflight)
        self.assertIn("IFS='|' read -r deploy_user_sha", preflight)
        self.assertNotIn("IFS=$'\\t'", preflight)
        self.assertIn(".checks.bootstrap_identity_match", preflight)
        self.assertIn(".checks.deploy_identity_match", preflight)
        self.assertLess(
            preflight.index('mv "$tmp" "$receipt"'),
            preflight.index('test "$bootstrap_user_sha" = "$bootstrap_expected_sha"'),
        )
        self.assertLess(
            preflight.index('mv "$tmp" "$receipt"'),
            preflight.index('test "$status" = PASS_ROOT_BOOTSTRAP_PREFLIGHT'),
        )

    def test_apply_is_bootstrap_receipt_and_exact_phrase_bound(self):
        for value in (
            "BOOTSTRAP_PREFLIGHT_RUN_ID",
            "BOOTSTRAP_PREFLIGHT_RUN_ATTEMPT",
            "BOOTSTRAP_PREFLIGHT_RECEIPT_SHA256",
            "backend-production-supervisor-root-bootstrap-preflight-${BOOTSTRAP_PREFLIGHT_RUN_ID}-${BOOTSTRAP_PREFLIGHT_RUN_ATTEMPT}",
            'and .status == "PASS_ROOT_BOOTSTRAP_PREFLIGHT"',
            "I explicitly approve one-time backend production Supervisor root bootstrap",
            "bootstrap-user fingerprint ${bootstrap_user_sha}",
            "installing only /etc/sudoers.d/fap-api-github-production-supervisor-status",
            "test \"$OPERATOR_APPROVAL_PHRASE\" = \"$expected_phrase\"",
        ):
            self.assertIn(value, self.raw)

    def test_apply_uses_only_fixed_password_sudo_commands_and_no_root_shell(self):
        apply = self.raw.split("- name: Apply exact one-time root bootstrap", 1)[1].split(
            "- name: Validate sanitized root-bootstrap receipt", 1
        )[0]
        expected = (
            "sudo -S -k -p '' /usr/bin/install",
            "sudo -S -k -p '' /usr/sbin/visudo -cf /etc/sudoers",
            "sudo -S -k -p '' /usr/bin/sha256sum",
        )
        for command in expected:
            self.assertIn(command, apply)
        self.assertEqual(apply.count("sudo -S -k -p ''"), 3)
        self.assertIn("bootstrap_remote=", apply)
        self.assertIn("deploy_remote=", apply)
        self.assertIn("| $bootstrap_remote", apply)
        self.assertIn('$deploy_remote "sudo -n /usr/bin/supervisorctl status"', apply)
        self.assertNotIn('$bootstrap_remote "sudo -n /usr/bin/supervisorctl status"', apply)
        for forbidden in ("sudo bash", "sudo -S bash", "sudo sh", "rm -rf", "systemctl", "supervisorctl restart"):
            self.assertNotIn(forbidden, apply)
        self.assertIn("sudo -n /usr/bin/supervisorctl status", apply)
        self.assertIn("set -o noclobber", apply)
        self.assertIn("FAIL_ROOT_BOOTSTRAP_INSTALL", apply)
        self.assertIn("FAIL_ROOT_BOOTSTRAP_CREDENTIAL_AUTH", apply)
        self.assertIn("FAIL_ROOT_BOOTSTRAP_FULL_POLICY", apply)
        self.assertIn("FAIL_ROOT_BOOTSTRAP_EXACT_READBACK", apply)
        self.assertIn("FAIL_ROOT_BOOTSTRAP_QUEUE_STATE", apply)
        self.assertNotIn("sudo -n rm", apply)

    def test_v2_receipt_tracks_credential_and_candidate_lifecycle(self):
        self.assertIn('backend.production_supervisor_root_bootstrap.v2', self.raw)
        self.assertNotIn('backend.production_supervisor_root_bootstrap.v1', self.raw)
        for value in (
            "bootstrap_user_sha256",
            "deploy_user_sha256",
            "credential_tested",
            "credential_accepted",
            "candidate_created",
            "candidate_cleanup_attempted",
            "candidate_absent_after",
        ):
            self.assertIn(value, self.raw)
        apply = self.raw.split("- name: Apply exact one-time root bootstrap", 1)[1].split(
            "- name: Validate sanitized root-bootstrap receipt", 1
        )[0]
        self.assertIn("trap cleanup_candidate EXIT", apply)
        self.assertIn('rm -f $q_candidate; test ! -e $q_candidate', apply)
        self.assertIn(".credential_tested = true", apply)
        self.assertIn(".credential_accepted = true", apply)
        self.assertNotIn('cat "$sudo_error"', apply)

    def test_failure_receipt_is_initialized_before_checkout_and_always_uploaded(self):
        initialize = self.raw.index("- name: Initialize sanitized failure receipt")
        checkout = self.raw.index("- name: Check out exact main control plane")
        self.assertLess(initialize, checkout)
        self.assertIn("if: ${{ always() }}", self.raw)
        self.assertIn("backend.production_supervisor_root_bootstrap.v2", self.raw)
        self.assertIn("secret_retirement_required: true", self.raw)
        self.assertIn(
            "actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7",
            self.raw,
        )
        self.assertIn("retention-days: 14", self.raw)


if __name__ == "__main__":
    unittest.main()
