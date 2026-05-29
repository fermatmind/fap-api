import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
MANIFEST = ROOT / "ops" / "release-train" / "examples" / "release-train.example.json"


class ReleaseTrainManifestTest(unittest.TestCase):
    def _run_cmd(self, args):
        cmd = ["python3", str(ROOT / "ops" / "release-train" / "release_train.py")] + args
        proc = subprocess.run(cmd, cwd=str(ROOT), text=True, capture_output=True)
        return proc.returncode, proc.stdout + proc.stderr

    def test_example_manifest_exists_and_is_json(self):
        with open(MANIFEST, "r", encoding="utf-8") as handle:
            self.assertIn("schema_version", handle.read())

    def test_validate_manifest(self):
        code, output = self._run_cmd(["validate-manifest", "--manifest", str(MANIFEST)])
        self.assertEqual(code, 0, output)

    def test_plan_manifest(self):
        code, output = self._run_cmd(["plan", "--manifest", str(MANIFEST)])
        self.assertEqual(code, 0, output)

    def test_dry_run_manifest(self):
        code, output = self._run_cmd(["dry-run", "--manifest", str(MANIFEST)])
        self.assertEqual(code, 0, output)
