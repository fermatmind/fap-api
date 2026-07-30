import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = (
    ROOT
    / ".github"
    / "workflows"
    / "backend-production-shared-permissions-provisioning.yml"
)


class ProductionSharedPermissionsWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_workflow_binds_exact_control_release_and_failed_deploy(self):
        for required_input in (
            "expected_control_plane_sha",
            "expected_active_revision",
            "expected_inactive_release_sha",
            "inactive_release_name",
            "failed_deploy_run_id",
            "failed_deploy_run_attempt",
            "operator_approval_phrase",
        ):
            self.assertRegex(
                self.workflow,
                rf"(?m)^      {re.escape(required_input)}:\n"
                r"(?:        .*\n)*?        required: true$",
            )

        self.assertIn(
            '.name == "Deploy API Production"\n'
            '             and .path == ".github/workflows/deploy-production.yml"',
            self.workflow,
        )
        self.assertIn('and .conclusion == "failure"', self.workflow)
        self.assertIn('and .head_sha == $sha', self.workflow)
        self.assertIn('and .run_attempt == $attempt', self.workflow)
        self.assertIn(
            'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
            self.workflow,
        )

    def test_workflow_is_fixed_to_manifest_provision_and_verify(self):
        self.assertIn('test "$(grep -cve \'^$\' "$manifest")" -eq 15', self.workflow)
        self.assertIn("SHARED_PERMISSIONS_APPLY=true", self.workflow)
        self.assertIn('bash "$provisioner"', self.workflow)
        self.assertIn('bash "$verifier"', self.workflow)
        self.assertIn("shared_permissions_provisioned=16", self.workflow)
        self.assertIn("shared_permissions_checked=16", self.workflow)
        self.assertNotIn("php /tmp/dep.phar", self.workflow)
        self.assertNotIn("queue:restart", self.workflow)
        self.assertNotIn("artisan migrate", self.workflow)

    def test_workflow_preserves_active_release_and_records_negative_guarantees(self):
        self.assertIn(
            'test "$(readlink -f "$DEPLOY_PATH/current")" = "$current_release"',
            self.workflow,
        )
        for receipt_field in (
            "application_deploy_count: 0",
            "candidate_activation_count: 0",
            "rollback_count: 0",
            "cms_or_database_write_count: 0",
            "migration_count: 0",
            "queue_restart_count: 0",
            "search_channel_operation_count: 0",
        ):
            self.assertIn(receipt_field, self.workflow)

    def test_workflow_uses_protected_production_secrets_only(self):
        self.assertIn("environment: production", self.workflow)
        for secret in (
            "secrets.PRODUCTION_DEPLOY_USER",
            "secrets.PRODUCTION_DEPLOY_PORT",
            "secrets.PRODUCTION_DEPLOY_HOST",
            "secrets.PRODUCTION_RETIRED_DEPLOY_HOST",
            "secrets.PRODUCTION_DEPLOY_PATH",
            "secrets.SSH_PRIVATE_KEY",
            "secrets.SSH_KNOWN_HOSTS",
        ):
            self.assertIn(secret, self.workflow)
        self.assertNotIn("vars.PRODUCTION_DEPLOY_", self.workflow)


if __name__ == "__main__":
    unittest.main()
