import grp
import os
import pwd
import stat
import subprocess
import tempfile
import time
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
PROVISIONER = ROOT / "backend" / "scripts" / "deploy" / "provision_shared_permissions.sh"
VERIFIER = ROOT / "backend" / "scripts" / "deploy" / "verify_shared_permissions.sh"
PATHS = ROOT / "backend" / "scripts" / "deploy" / "shared_permissions_paths.txt"


class SharedPermissionsTest(unittest.TestCase):
    def setUp(self):
        self.owner = pwd.getpwuid(os.getuid()).pw_name
        self.group = grp.getgrgid(os.getgid()).gr_name

    def _env(self, shared_root: Path, *, apply: bool = False):
        env = os.environ.copy()
        env.update(
            {
                "SHARED_PERMISSIONS_ROOT": str(shared_root),
                "SHARED_PERMISSIONS_OWNER": self.owner,
                "SHARED_PERMISSIONS_GROUP": self.group,
                "SHARED_PERMISSIONS_RUNTIME_USER": self.owner,
                "SHARED_PERMISSIONS_APPLY": "true" if apply else "false",
            }
        )
        return env

    def _run(self, script: Path, shared_root: Path, *, apply: bool = False):
        return subprocess.run(
            ["bash", str(script)],
            cwd=str(ROOT),
            env=self._env(shared_root, apply=apply),
            text=True,
            capture_output=True,
            timeout=5,
        )

    @staticmethod
    def _snapshot(shared_root: Path):
        paths = [shared_root]
        paths.extend(
            shared_root / line
            for line in PATHS.read_text(encoding="utf-8").splitlines()
            if line
        )
        return {
            str(path.relative_to(shared_root.parent)): (
                path.stat().st_mode,
                path.stat().st_uid,
                path.stat().st_gid,
                path.stat().st_mtime_ns,
                path.stat().st_ctime_ns,
            )
            for path in paths
        }

    def test_provisioning_requires_explicit_apply_and_is_idempotent(self):
        with tempfile.TemporaryDirectory() as tmp:
            shared_root = Path(tmp) / "shared"

            refused = self._run(PROVISIONER, shared_root)
            self.assertNotEqual(refused.returncode, 0)
            self.assertIn("EXPLICIT_PROVISIONING_REQUIRED", refused.stderr)
            self.assertFalse(shared_root.exists())

            first = self._run(PROVISIONER, shared_root, apply=True)
            self.assertEqual(first.returncode, 0, first.stdout + first.stderr)
            first_snapshot = self._snapshot(shared_root)
            second = self._run(PROVISIONER, shared_root, apply=True)
            self.assertEqual(second.returncode, 0, second.stdout + second.stderr)
            self.assertEqual(first.stdout, second.stdout)
            self.assertEqual(first_snapshot, self._snapshot(shared_root))
            self.assertNotIn(str(shared_root), first.stdout + first.stderr)

            expected_paths = [line for line in PATHS.read_text(encoding="utf-8").splitlines() if line]
            for relative_path in [".", *expected_paths]:
                target = shared_root if relative_path == "." else shared_root / relative_path
                self.assertTrue(target.is_dir(), relative_path)
                self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o2775, relative_path)

    def test_correct_fixture_passes_bounded_read_only_verification(self):
        with tempfile.TemporaryDirectory() as tmp:
            shared_root = Path(tmp) / "shared"
            provisioned = self._run(PROVISIONER, shared_root, apply=True)
            self.assertEqual(provisioned.returncode, 0, provisioned.stdout + provisioned.stderr)

            before = self._snapshot(shared_root)
            started = time.monotonic()
            verified = self._run(VERIFIER, shared_root)
            elapsed = time.monotonic() - started

            self.assertEqual(verified.returncode, 0, verified.stdout + verified.stderr)
            self.assertLess(elapsed, 5)
            self.assertEqual(before, self._snapshot(shared_root))
            self.assertIn("shared_permissions_status=success", verified.stdout)
            self.assertNotIn(str(shared_root), verified.stdout + verified.stderr)

    def test_incorrect_fixture_fails_closed_without_disclosing_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            shared_root = Path(tmp) / "shared"
            provisioned = self._run(PROVISIONER, shared_root, apply=True)
            self.assertEqual(provisioned.returncode, 0, provisioned.stdout + provisioned.stderr)

            (shared_root / "backend/storage/framework/cache").chmod(0o700)
            verified = self._run(VERIFIER, shared_root)

            self.assertNotEqual(verified.returncode, 0)
            self.assertIn("shared_permissions_status=failure", verified.stderr)
            self.assertIn("DIRECTORY_MODE_INVALID", verified.stderr)
            self.assertIn("run_explicit_shared_permissions_provisioning", verified.stderr)
            self.assertNotIn(str(shared_root), verified.stdout + verified.stderr)
            self.assertEqual(
                stat.S_IMODE((shared_root / "backend/storage/framework/cache").stat().st_mode),
                0o700,
            )
