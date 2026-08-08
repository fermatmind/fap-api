import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/backend-production-bootstrap-ssh-key-authorization.yml"


class BackendProductionBootstrapSshKeyAuthorizationWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.raw = WORKFLOW.read_text(encoding="utf-8")

    def test_manual_protected_exact_main_contract(self):
        for value in (
            "workflow_dispatch",
            "environment: production",
            "group: deploy-${{ github.repository }}-production",
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
        ):
            self.assertIn(value, self.raw)
        self.assertNotIn("\npush:", self.raw)

    def test_failed_v2_bootstrap_receipt_is_exactly_chained(self):
        for value in (
            "Backend Production Supervisor Root Bootstrap",
            "FAIL_ROOT_BOOTSTRAP_BOOTSTRAP_SSH",
            "backend.production_supervisor_root_bootstrap.v2",
            "FAILED_BOOTSTRAP_RECEIPT_SHA256",
            "credential_tested == false",
            "sudoers_rule_write_count == 0",
            "writes_committed == false",
        ):
            self.assertIn(value, self.raw)

    def test_preflight_uses_password_only_and_is_zero_write(self):
        preflight = self.raw.split("- name: Run strict zero-write authorized_keys preflight", 1)[1].split(
            "- name: Validate apply receipt and exact approval", 1
        )[0]
        for value in (
            "PubkeyAuthentication=no",
            "PreferredAuthentications=password",
            "SSH_ASKPASS_REQUIRE=force",
            "existing_key_login_rejected",
            "PASS_SSH_KEY_AUTHORIZATION_PREFLIGHT",
            "production_write_execution: false",
            "authorized_keys_write_count: 0",
            "remote_temp_file_write_count: 0",
            "writes_committed: false",
        ):
            self.assertIn(value, self.raw)
        for forbidden in ("sudo -S", "sudo -n", "authorized_keys >", "mv $", "chmod 600 $auth"):
            self.assertNotIn(forbidden, preflight)

    def test_receipt_exposes_fingerprints_not_users_or_key_material(self):
        for value in (
            "backend.production_bootstrap_ssh_key_authorization.v1",
            "target_user_sha256",
            "public_key_sha256",
            "public_key_fingerprint",
            "authorized_keys_sha256_before",
            "target_key_present_before",
        ):
            self.assertIn(value, self.raw)
        summary = self.raw.split("- name: Publish safe SSH key authorization summary", 1)[1]
        self.assertNotIn("BOOTSTRAP_SSH_USER", summary)
        self.assertNotIn("BOOTSTRAP_PASSWORD", summary)
        self.assertNotIn("normalized-key.pub", summary)

    def test_apply_is_receipt_and_exact_phrase_bound(self):
        for value in (
            "KEY_PREFLIGHT_RUN_ID",
            "KEY_PREFLIGHT_RECEIPT_SHA256",
            "PASS_SSH_KEY_AUTHORIZATION_PREFLIGHT",
            "I explicitly approve atomic authorization of the existing GitHub fap-api production SSH public key fingerprint",
            "append exactly one key by same-directory atomic rename",
            'test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"',
        ):
            self.assertIn(value, self.raw)

    def test_apply_is_bounded_to_one_key_and_no_privilege_or_service_changes(self):
        apply = self.raw.split("- name: Apply exact authorized_keys addition", 1)[1].split(
            "- name: Validate sanitized SSH key authorization receipt", 1
        )[0]
        for value in ("authorized_keys.codex-", "target_key_present_after", "mv \"$candidate\" \"$auth\"", "key_ssh_authorized_after"):
            self.assertIn(value, apply)
        for forbidden in ("sudo", "rm -rf", "systemctl", "supervisorctl", "sshd_config", "service restart"):
            self.assertNotIn(forbidden, apply)

    def test_failure_receipt_precedes_checkout_and_is_always_uploaded(self):
        self.assertLess(
            self.raw.index("- name: Initialize sanitized failure receipt"),
            self.raw.index("- name: Check out exact main control plane"),
        )
        self.assertIn("if: ${{ always() }}", self.raw)
        self.assertIn("actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7", self.raw)
        self.assertIn("retention-days: 14", self.raw)


if __name__ == "__main__":
    unittest.main()
