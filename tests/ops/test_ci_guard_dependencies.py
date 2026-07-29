import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CI_WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"
DEPLOY_WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
MASTER_GUARD = ROOT / "backend" / "scripts" / "ci_verify_mbti.sh"
TOOL_GUARD = ROOT / "backend" / "scripts" / "ci" / "require_tools.sh"
PR_GUARDS = (
    ROOT / "backend" / "scripts" / "pr73_verify.sh",
    ROOT / "backend" / "scripts" / "pr75_verify.sh",
)


def _write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(path.stat().st_mode | stat.S_IXUSR)


class CiGuardDependencyTest(unittest.TestCase):
    def test_mbti_ci_and_staging_install_rg_before_master_guard(self):
        install_step = "sudo apt-get install --no-install-recommends --yes ripgrep"
        version_check = "rg --version"
        master_guard = "bash ./backend/scripts/ci_verify_mbti.sh"

        ci_source = CI_WORKFLOW.read_text(encoding="utf-8")
        guarded_workflows = {
            "ci": ci_source[ci_source.index("  verify-mbti:") :],
            "deploy-staging": DEPLOY_WORKFLOW.read_text(encoding="utf-8"),
        }

        for workflow, source in guarded_workflows.items():
            with self.subTest(workflow=workflow):
                self.assertIn(install_step, source)
                self.assertIn(version_check, source)
                self.assertIn(master_guard, source)
                self.assertLess(source.index(install_step), source.index(version_check))
                self.assertLess(source.index(version_check), source.index(master_guard))

    def test_shared_tool_guard_fails_closed_when_rg_is_missing(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = subprocess.run(
                ["/bin/bash", str(TOOL_GUARD), "rg"],
                cwd=ROOT,
                env={"PATH": tmp},
                text=True,
                capture_output=True,
            )

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("missing required tool: rg", result.stderr)
        self.assertNotIn("[OK]", result.stdout + result.stderr)

    def test_pr_guards_fail_closed_when_rg_is_missing(self):
        for guard in PR_GUARDS:
            with self.subTest(guard=guard.name), tempfile.TemporaryDirectory() as tmp:
                result = subprocess.run(
                    ["/bin/bash", str(guard)],
                    cwd=ROOT,
                    env={"PATH": tmp},
                    text=True,
                    capture_output=True,
                )

                self.assertNotEqual(result.returncode, 0)
                self.assertIn("missing required tool: rg", result.stderr)
                self.assertNotIn("[OK]", result.stdout + result.stderr)

    def test_master_guard_checks_rg_php_and_composer_before_side_effects(self):
        source = MASTER_GUARD.read_text(encoding="utf-8")
        preflight = 'require_tools rg php composer curl grep sed lsof cmp'

        self.assertIn(preflight, source)
        self.assertLess(source.index(preflight), source.index('mkdir -p "$XDG_CONFIG_HOME"'))

        for missing_tool in ("rg", "php", "composer"):
            with self.subTest(missing_tool=missing_tool), tempfile.TemporaryDirectory() as tmp:
                tool_bin = Path(tmp)
                required_prefix = ("rg", "php", "composer")
                for tool in required_prefix:
                    if tool == missing_tool:
                        break
                    _write_executable(tool_bin / tool, "#!/bin/bash\nexit 0\n")

                result = subprocess.run(
                    ["/bin/bash", str(MASTER_GUARD)],
                    cwd=ROOT,
                    env={"PATH": str(tool_bin)},
                    text=True,
                    capture_output=True,
                )

                self.assertNotEqual(result.returncode, 0)
                self.assertIn(f"missing required tool: {missing_tool}", result.stderr)
                self.assertNotIn("[CI] legacy service request coupling gate OK", result.stdout)
                self.assertNotIn("[CI] app env() usage gate OK", result.stdout)

    def test_pr_guards_execute_rg_before_reporting_ok(self):
        for source_guard in PR_GUARDS:
            with self.subTest(guard=source_guard.name), tempfile.TemporaryDirectory() as tmp:
                sandbox = Path(tmp)
                scripts_dir = sandbox / "backend" / "scripts"
                (scripts_dir / "ci").mkdir(parents=True)
                (sandbox / "backend" / "app" / "Services" / "Legacy" / "Mbti" / "Attempt").mkdir(
                    parents=True
                )
                shutil.copy2(source_guard, scripts_dir / source_guard.name)
                shutil.copy2(TOOL_GUARD, scripts_dir / "ci" / TOOL_GUARD.name)

                fake_bin = sandbox / "bin"
                fake_bin.mkdir()
                sentinel = sandbox / "rg-called"
                _write_executable(
                    fake_bin / "rg",
                    "#!/bin/bash\n"
                    'printf "%s\\n" "$*" >> "$RG_SENTINEL"\n'
                    'exit "${FAKE_RG_EXIT:-1}"\n',
                )

                env = os.environ.copy()
                env.update(
                    {
                        "PATH": f"{fake_bin}:/usr/bin:/bin",
                        "RG_SENTINEL": str(sentinel),
                        "FAKE_RG_EXIT": "1",
                    }
                )
                result = subprocess.run(
                    ["/bin/bash", str(scripts_dir / source_guard.name)],
                    cwd=sandbox,
                    env=env,
                    text=True,
                    capture_output=True,
                )

                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertTrue(sentinel.exists(), result.stdout + result.stderr)
                self.assertIn("[OK]", result.stdout)

    def test_pr_guard_rg_failure_propagates_without_ok(self):
        with tempfile.TemporaryDirectory() as tmp:
            sandbox = Path(tmp)
            scripts_dir = sandbox / "backend" / "scripts"
            (scripts_dir / "ci").mkdir(parents=True)
            (sandbox / "backend" / "app" / "Services" / "Legacy" / "Mbti" / "Attempt").mkdir(
                parents=True
            )
            shutil.copy2(PR_GUARDS[0], scripts_dir / PR_GUARDS[0].name)
            shutil.copy2(TOOL_GUARD, scripts_dir / "ci" / TOOL_GUARD.name)

            fake_bin = sandbox / "bin"
            fake_bin.mkdir()
            _write_executable(
                fake_bin / "rg",
                "#!/bin/bash\n"
                "exit 2\n",
            )

            env = os.environ.copy()
            env.update({"PATH": f"{fake_bin}:/usr/bin:/bin"})
            result = subprocess.run(
                ["/bin/bash", str(scripts_dir / PR_GUARDS[0].name)],
                cwd=sandbox,
                env=env,
                text=True,
                capture_output=True,
            )

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("[PR73][FAIL] rg guard execution failed (exit 2)", result.stderr)
        self.assertNotIn("[PR73][OK]", result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
