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
        self.assertIn("github.ref == 'refs/heads/main'", run_train["if"])
        self.assertIn("mode == 'run'", run_train["if"])
        self.assertIn("allow_deploy == 'true'", run_train["if"])
        self.assertIn("dry_run != 'true'", run_train["if"])

    def test_workflow_does_not_interpolate_manifest_path_inside_shell_commands(self):
        for job_name in ["validate", "dry-run", "run-train"]:
            with self.subTest(job_name=job_name):
                steps = self.jobs[job_name]["steps"]
                run_steps = [step for step in steps if "run" in step]
                joined = "\n".join(step["run"] for step in run_steps)

                self.assertIn("RELEASE_TRAIN_MANIFEST_PATH", joined)
                self.assertIn("invalid release train manifest path", joined)
                self.assertNotIn("${{ github.event.inputs.manifest_path }}", joined)

    def test_run_train_passes_input_values_via_environment(self):
        run_step = next(step for step in self.jobs["run-train"]["steps"] if step.get("name") == "Execute train")

        self.assertEqual(run_step["env"]["RELEASE_TRAIN_MANIFEST_PATH"], "${{ github.event.inputs.manifest_path }}")
        self.assertEqual(run_step["env"]["RELEASE_TRAIN_TRAIN_ID"], "${{ github.event.inputs.train_id }}")
        self.assertIn('--manifest "${RELEASE_TRAIN_MANIFEST_PATH}"', run_step["run"])
        self.assertIn('--train-id "${RELEASE_TRAIN_TRAIN_ID}"', run_step["run"])

    def test_no_separate_approval_only_production_gate_remains(self):
        self.assertNotIn("production-gate", self.jobs)


if __name__ == "__main__":
    unittest.main()
