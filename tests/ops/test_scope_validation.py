import importlib.util
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).resolve().parents[2] / "ops" / "release-train" / "scope_validation.py"
spec = importlib.util.spec_from_file_location("scope_validation", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)  # type: ignore[union-attr]


class ScopeValidationTest(unittest.TestCase):
    def test_explicit_scope_allows_known_and_generated(self):
        changed = [
            "backend/routes/api.php",
            "backend/artifacts/sitemap.lock",
            "docs/README.md",
        ]
        result = module.validate_changed_files(
            changed,
            ["backend/routes/api.php", "backend/app/**"],
            ["backend/artifacts/**", "docs/**"],
        )
        self.assertTrue(result["ok"])

    def test_empty_allowed_files_is_blocked(self):
        changed = ["backend/routes/api.php"]
        result = module.validate_changed_files(changed, [], [])
        self.assertFalse(result["ok"])
        self.assertIn("explicit scope is required", result["warnings"][0])

    def test_high_risk_out_of_scope(self):
        changed = ["backend/scripts/deploy/deploy_backend.sh"]
        result = module.validate_changed_files(changed, ["backend/routes/api.php"], [])
        self.assertFalse(result["ok"])
        self.assertIn("backend/scripts/deploy/deploy_backend.sh", result["high_risk_files"])
