import unittest
from pathlib import Path

import yaml


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/release-train.yml"


class ReleaseTrainWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = yaml.safe_load(WORKFLOW.read_text())
        cls.jobs = cls.workflow["jobs"]

    def test_dry_run_job_does_not_use_production_environment(self):
        dry_run = self.jobs["dry-run"]

        self.assertNotIn("environment", dry_run)
        self.assertIn("dry_run == 'true'", dry_run["if"])

    def test_run_train_is_the_production_environment_job(self):
        run_train = self.jobs["run-train"]

        self.assertEqual(run_train["needs"], "validate")
        self.assertEqual(run_train["environment"], "production")
        self.assertIn("mode == 'run'", run_train["if"])
        self.assertIn("allow_deploy == 'true'", run_train["if"])
        self.assertIn("dry_run != 'true'", run_train["if"])

    def test_no_separate_approval_only_production_gate_remains(self):
        self.assertNotIn("production-gate", self.jobs)


if __name__ == "__main__":
    unittest.main()
