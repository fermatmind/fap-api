import importlib.util
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Dict, Optional


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "backend" / "scripts" / "deploy" / "deploy_backend.sh"
RELEASE_TRAIN_DIR = ROOT / "ops" / "release-train"
RELEASE_TRAIN_PATH = RELEASE_TRAIN_DIR / "release_train.py"


def _head_sha() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=str(ROOT), text=True).strip()


def _run_adapter(env: Optional[Dict[str, str]] = None) -> subprocess.CompletedProcess:
    merged_env = os.environ.copy()
    if env:
        merged_env.update(env)

    return subprocess.run(
        ["bash", str(SCRIPT)],
        cwd=str(ROOT),
        env=merged_env,
        text=True,
        capture_output=True,
    )


def _load_release_train_module():
    sys.path.insert(0, str(RELEASE_TRAIN_DIR))
    spec = importlib.util.spec_from_file_location("release_train", RELEASE_TRAIN_PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)  # type: ignore[union-attr]
    return module


class BackendDeployAdapterTest(unittest.TestCase):
    def test_wrapper_fails_closed_by_default(self):
        proc = _run_adapter()

        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("deploy_result=failure", proc.stdout)
        self.assertIn("MISSING_RELEASE_NAME", proc.stdout)

    def test_wrapper_fails_without_release_name(self):
        proc = _run_adapter({"BACKEND_DEPLOY_SHA": _head_sha()})

        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("MISSING_RELEASE_NAME", proc.stdout)

    def test_wrapper_fails_without_backend_sha(self):
        proc = _run_adapter({"RELEASE_NAME": "adapter-test"})

        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("MISSING_BACKEND_DEPLOY_SHA", proc.stdout)

    def test_wrapper_fails_on_sha_mismatch(self):
        proc = _run_adapter({
            "RELEASE_NAME": "adapter-test",
            "BACKEND_DEPLOY_SHA": "0" * 40,
        })

        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("BACKEND_DEPLOY_SHA_MISMATCH", proc.stdout)

    def test_wrapper_dry_run_prints_command_without_executing_deployer(self):
        with tempfile.TemporaryDirectory() as tmp:
            marker = Path(tmp) / "deployer-called"
            fake_dep = Path(tmp) / "dep"
            fake_dep.write_text(f"#!/usr/bin/env bash\ntouch {marker}\n", encoding="utf-8")
            fake_dep.chmod(fake_dep.stat().st_mode | stat.S_IXUSR)

            proc = _run_adapter({
                "DEPLOY_DRY_RUN": "true",
                "ALLOW_PRODUCTION_DEPLOY": "true",
                "ALLOW_REAL_DEPLOY": "false",
                "DEPLOY_ENV": "production",
                "BACKEND_DEPLOY_SHA": _head_sha(),
                "RELEASE_NAME": "adapter-dry-run-test",
                "DEPLOYER_BIN": str(fake_dep),
            })

            self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
            self.assertIn("deploy_adapter_mode=dry-run", proc.stdout)
            self.assertIn("deploy_command_ready=true", proc.stdout)
            self.assertIn("deploy_result=skipped", proc.stdout)
            self.assertFalse(marker.exists(), proc.stdout)

    def test_wrapper_real_run_fails_without_real_deploy_flag(self):
        proc = _run_adapter({
            "ALLOW_PRODUCTION_DEPLOY": "true",
            "ALLOW_REAL_DEPLOY": "false",
            "DEPLOY_ENV": "production",
            "BACKEND_DEPLOY_SHA": _head_sha(),
            "RELEASE_NAME": "adapter-real-guard-test",
        })

        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("REAL_DEPLOY_NOT_ALLOWED", proc.stdout)

    def test_wrapper_real_run_fails_without_production_deploy_flag(self):
        proc = _run_adapter({
            "ALLOW_PRODUCTION_DEPLOY": "false",
            "ALLOW_REAL_DEPLOY": "true",
            "DEPLOY_ENV": "production",
            "BACKEND_DEPLOY_SHA": _head_sha(),
            "RELEASE_NAME": "adapter-production-guard-test",
        })

        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("PRODUCTION_DEPLOY_NOT_ALLOWED", proc.stdout)

    def test_wrapper_real_run_constructs_deployer_command_with_mock(self):
        with tempfile.TemporaryDirectory() as tmp:
            args_file = Path(tmp) / "args.txt"
            fake_dep = Path(tmp) / "dep"
            fake_dep.write_text(
                "#!/usr/bin/env bash\nprintf '%s\\n' \"$@\" > "
                + str(args_file)
                + "\n",
                encoding="utf-8",
            )
            fake_dep.chmod(fake_dep.stat().st_mode | stat.S_IXUSR)

            proc = _run_adapter({
                "ALLOW_PRODUCTION_DEPLOY": "true",
                "ALLOW_REAL_DEPLOY": "true",
                "DEPLOY_ENV": "production",
                "BACKEND_DEPLOY_SHA": _head_sha(),
                "RELEASE_NAME": "adapter-real-mock-test",
                "DEPLOYER_BIN": str(fake_dep),
            })

            self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
            self.assertIn("deploy_adapter_mode=real", proc.stdout)
            self.assertIn("deploy_result=success", proc.stdout)
            args = args_file.read_text(encoding="utf-8").splitlines()
            self.assertEqual(args[:6], ["deploy", "production", "-f", str(ROOT / "deploy.php"), "-o", "release_name=adapter-real-mock-test"])


class ReleaseTrainDeployAdapterIntegrationTest(unittest.TestCase):
    def test_release_train_dry_run_does_not_execute_deploy_wrapper(self):
        module = _load_release_train_module()

        calls = []
        original = module._run_command
        module._run_command = lambda cmd, env=None: calls.append((cmd, env)) or {"returncode": 0, "stdout": "", "stderr": ""}
        try:
            result = module.evaluate_item(
                {
                    "id": "adapter-dry-run",
                    "repo": "fap-api",
                    "pr_number": 1,
                    "expected_head_sha": _head_sha(),
                    "component": "backend",
                    "risk_level": "medium",
                    "deploy_required": True,
                    "deploy_order": "backend_first",
                    "required_checks_policy": "required_only",
                    "allowed_files": ["backend/scripts/deploy/deploy_backend.sh"],
                    "allowed_generated_paths": [],
                    "scope_validation": "required_scope_only",
                    "smoke_checks": [],
                    "rollback": {"enabled": False, "wrapper": "backend/scripts/deploy/rollback_backend.sh"},
                    "sidecar_policy": {"allow_nonblocking_sidecars": False},
                },
                manifest={"train_id": "adapter-test"},
                github=_FakeGitHub(),
                execute_smoke=False,
                allow_deploy=False,
                mode="dry-run",
            )
        finally:
            module._run_command = original

        self.assertEqual(calls, [])
        self.assertEqual(result["checks"]["deploy_status"], "deploy_skipped_in_non_run_mode")

    def test_release_train_run_passes_fail_closed_env_to_wrapper(self):
        module = _load_release_train_module()

        calls = []

        def fake_run(cmd, env=None):
            calls.append((cmd, env))
            return {"returncode": 0, "stdout": "deploy_result=success\n", "stderr": ""}

        original = module._run_command
        old_allow_deploy = os.environ.get("RELEASE_TRAIN_ALLOW_DEPLOY")
        os.environ["RELEASE_TRAIN_ALLOW_DEPLOY"] = "true"
        module._run_command = fake_run
        try:
            result = module.evaluate_item(
                {
                    "id": "adapter-run",
                    "release_name": "adapter-release-name",
                    "repo": "fap-api",
                    "pr_number": 1,
                    "expected_head_sha": _head_sha(),
                    "component": "backend",
                    "risk_level": "medium",
                    "deploy_required": True,
                    "deploy_target": "production",
                    "deploy_order": "backend_first",
                    "required_checks_policy": "required_only",
                    "allowed_files": ["backend/scripts/deploy/deploy_backend.sh"],
                    "allowed_generated_paths": [],
                    "scope_validation": "required_scope_only",
                    "smoke_checks": [],
                    "rollback": {"enabled": False, "wrapper": "backend/scripts/deploy/rollback_backend.sh"},
                    "sidecar_policy": {"allow_nonblocking_sidecars": False},
                },
                manifest={"train_id": "adapter-test"},
                github=_FakeGitHub(),
                execute_smoke=False,
                allow_deploy=True,
                mode="run",
            )
        finally:
            module._run_command = original
            if old_allow_deploy is None:
                os.environ.pop("RELEASE_TRAIN_ALLOW_DEPLOY", None)
            else:
                os.environ["RELEASE_TRAIN_ALLOW_DEPLOY"] = old_allow_deploy

        self.assertEqual(result["status"], "ok")
        self.assertEqual(len(calls), 1)
        env = calls[0][1]
        self.assertEqual(env["DEPLOY_DRY_RUN"], "false")
        self.assertEqual(env["ALLOW_PRODUCTION_DEPLOY"], "true")
        self.assertEqual(env["ALLOW_REAL_DEPLOY"], "true")
        self.assertEqual(env["DEPLOY_ENV"], "production")
        self.assertEqual(env["BACKEND_DEPLOY_SHA"], _head_sha())
        self.assertEqual(env["RELEASE_NAME"], "adapter-release-name")
        self.assertNotIn("ROLLBACK_COMMAND", env)


class _FakeGitHub:
    def get_pull_request(self, repo, pr_number):
        return {"head": {"sha": _head_sha()}}

    def get_required_checks(self, repo, expected_sha):
        return {}

    def get_pull_request_changed_files(self, repo, pr_number):
        return ["backend/scripts/deploy/deploy_backend.sh"]
