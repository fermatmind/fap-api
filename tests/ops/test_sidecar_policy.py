import importlib.util
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).resolve().parents[2] / "ops" / "release-train" / "sidecar.py"
spec = importlib.util.spec_from_file_location("sidecar", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)  # type: ignore[union-attr]


class SidecarPolicyTest(unittest.TestCase):
    def test_required_failure_blocks_train(self):
        result = module.classify_check_failure(
            check_name="required_checks",
            required=True,
            observed_failure="CI failed",
            is_core_smoke=False,
        )
        self.assertFalse(result["allow_nonblocking"])
        self.assertTrue(result["required"])

    def test_external_nonrequired_is_sidecar_when_allowed(self):
        result = module.classify_check_failure(
            check_name="lint",
            required=False,
            observed_failure="lint style issue",
            is_core_smoke=False,
            likely_external=True,
        )
        self.assertTrue(result["allow_nonblocking"])

    def test_timeout_is_blocking(self):
        result = module.classify_check_failure(
            check_name="smoke:healthz",
            required=False,
            observed_failure="request timeout",
            is_core_smoke=False,
            likely_external=False,
        )
        self.assertFalse(result["allow_nonblocking"])

    def test_llms_full_timeout_can_be_discoverability_soft_alert(self):
        result = module.classify_check_failure(
            check_name="smoke:llms-full",
            required=False,
            observed_failure="request timeout",
            is_core_smoke=False,
            is_discoverability_artifact=True,
            allow_discoverability_soft_alert=True,
            likely_external=True,
        )
        self.assertTrue(result["allow_nonblocking"])
        self.assertIn("discoverability artifact", result["reason"])

    def test_discoverability_soft_alert_does_not_override_private_or_staging_guard(self):
        private_result = module.classify_check_failure(
            check_name="smoke:llms-full",
            required=False,
            observed_failure="request timeout",
            is_core_smoke=False,
            is_private_or_held_exposure=True,
            is_discoverability_artifact=True,
            allow_discoverability_soft_alert=True,
            likely_external=True,
        )
        staging_result = module.classify_check_failure(
            check_name="smoke:staging",
            required=False,
            observed_failure="request timeout",
            is_core_smoke=False,
            is_discoverability_artifact=True,
            allow_discoverability_soft_alert=True,
            is_search_channel_or_staging_guard=True,
            likely_external=True,
        )

        self.assertFalse(private_result["allow_nonblocking"])
        self.assertFalse(staging_result["allow_nonblocking"])
