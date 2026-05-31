import importlib.util
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
MODULE_DIR = ROOT / "ops" / "release-train"
if str(MODULE_DIR) not in sys.path:
    sys.path.insert(0, str(MODULE_DIR))

MODULE_PATH = MODULE_DIR / "release_train.py"
spec = importlib.util.spec_from_file_location("release_train", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)  # type: ignore[union-attr]


class FakeGitHub:
    def get_pull_request(self, repo, pr_number):
        return {"head": {"sha": "abc123"}}

    def get_required_checks(self, repo, sha):
        return {"CI": "success"}

    def get_pull_request_changed_files(self, repo, pr_number):
        return ["ops/release-train/release_train.py"]


class ReleaseTrainSoftAlertTest(unittest.TestCase):
    def test_llms_full_soft_alert_does_not_block_train(self):
        original = module.run_smoke_checks
        module.run_smoke_checks = lambda checks: [
            {
                "name": "llms-full",
                "url": "https://fermatmind.com/llms-full.txt",
                "success": False,
                "errors": ["attempt 1: request timeout"],
                "surface": "llms-full",
                "soft_alert": True,
                "hard_block": False,
                "core_smoke": False,
                "private_or_held_exposure_guard": False,
                "search_channel_or_staging_guard": False,
                "owner": "seo-ops",
                "recommended_followup": "Inspect llms-full artifact cache.",
                "response_snippet": "",
            }
        ]
        try:
            result = module.evaluate_item(
                self.item(),
                manifest={"train_id": "test-train"},
                github=FakeGitHub(),
                execute_smoke=True,
                mode="run",
            )
        finally:
            module.run_smoke_checks = original

        self.assertEqual(result["status"], "ok")
        self.assertEqual(result["failures"], [])
        self.assertEqual(len(result.get("sidecar_records", [])), 1)
        self.assertTrue(result["sidecars"][0]["allow_nonblocking"])

    def test_staging_guard_still_blocks_even_if_marked_soft_alert(self):
        original = module.run_smoke_checks
        module.run_smoke_checks = lambda checks: [
            {
                "name": "staging-containment",
                "url": "https://staging.fermatmind.com/llms-full.txt",
                "success": False,
                "errors": ["attempt 1: request timeout"],
                "surface": "llms-full",
                "soft_alert": True,
                "hard_block": False,
                "core_smoke": False,
                "private_or_held_exposure_guard": False,
                "search_channel_or_staging_guard": True,
                "owner": "ops",
                "recommended_followup": "Inspect staging containment.",
                "response_snippet": "",
            }
        ]
        try:
            result = module.evaluate_item(
                self.item(),
                manifest={"train_id": "test-train"},
                github=FakeGitHub(),
                execute_smoke=True,
                mode="run",
            )
        finally:
            module.run_smoke_checks = original

        self.assertEqual(result["status"], "blocked")
        self.assertIn("smoke_failed", result["failures"])
        self.assertFalse(result["sidecars"][0]["allow_nonblocking"])

    def item(self):
        return {
            "id": "test-item",
            "repo": "fap-api",
            "pr_number": 1,
            "expected_head_sha": "abc123",
            "component": "ops",
            "risk_level": "low",
            "deploy_required": False,
            "deploy_order": "none",
            "required_checks_policy": "required_only",
            "allowed_files": ["ops/release-train/**"],
            "allowed_generated_paths": [],
            "scope_validation": "required_scope_only",
            "smoke_checks": [{"url": "https://fermatmind.com/llms-full.txt", "expected_status": 200}],
            "rollback": {"enabled": False, "wrapper": "backend/scripts/deploy/rollback_backend.sh"},
            "sidecar_policy": {
                "allow_nonblocking_sidecars": True,
                "allow_discoverability_artifact_soft_alerts": True,
                "create_issue": False,
                "issue_title_prefix": "release-train-sidecar",
            },
        }


if __name__ == "__main__":
    unittest.main()
