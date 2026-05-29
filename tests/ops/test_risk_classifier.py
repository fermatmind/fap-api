import importlib.util
from pathlib import Path
import unittest


MODULE_PATH = Path(__file__).resolve().parents[2] / "ops" / "release-train" / "risk_classifier.py"


def _load_module():
    spec = importlib.util.spec_from_file_location("risk_classifier", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[union-attr]
    return module


mod = _load_module()


class RiskClassifierTest(unittest.TestCase):
    def test_high_risk_detection(self):
        result = mod.classify_path("backend/scripts/deploy/deploy_backend.sh")
        self.assertEqual(result["risk_level"], "high")
        self.assertTrue(result["manual_approval_required"])

    def test_medium_risk_detection(self):
        result = mod.classify_path("backend/app/Services/SEO/SitemapSourceService.php")
        self.assertIn(result["risk_level"], {"medium", "high"})
        self.assertIn("sitemap", "".join(result["matched_patterns"]).lower())

    def test_low_risk_default(self):
        result = mod.classify_path("docs/README.md")
        self.assertEqual(result["risk_level"], "low")
