import json
import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "backend" / "scripts" / "deploy" / "deployer_task_timing.php"
STAGING_WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
PRODUCTION_WORKFLOW = ROOT / ".github" / "workflows" / "deploy-production.yml"
SHA = "a" * 40


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(path.stat().st_mode | stat.S_IXUSR)


def context_args(receipt: Path) -> list[str]:
    return [
        f"--receipt={receipt}",
        "--environment=staging",
        f"--sha={SHA}",
        "--run-id=123",
        "--run-attempt=2",
    ]


class DeployerTaskTimingTest(unittest.TestCase):
    def test_workflows_share_sha_bound_runner_and_always_publish_receipts(self):
        staging = STAGING_WORKFLOW.read_text(encoding="utf-8")
        production = PRODUCTION_WORKFLOW.read_text(encoding="utf-8")

        for source, environment, sha_variable in (
            (staging, "staging", "$DEPLOY_REVISION"),
            (production, "production", "$DEPLOY_SHA"),
        ):
            with self.subTest(environment=environment):
                self.assertIn(
                    'backend/scripts/deploy/deployer_task_timing.php" > "$timing_runner"',
                    source,
                )
                self.assertIn('php "$DEPLOY_TIMING_RUNNER" run', source)
                self.assertIn(f'--sha="{sha_variable}"', source)
                self.assertIn("      - name: Collect Deployer timing history\n        if: ${{ always() }}", source)
                self.assertIn("      - name: Write Deployer timing summary\n        if: ${{ always() }}", source)
                self.assertIn("      - name: Upload Deployer timing receipt\n        if: ${{ always() }}", source)
                self.assertIn(f"name: deployer-task-timing-{environment}", source)
                self.assertIn("overwrite: true", source)
                self.assertLess(
                    source.index('php "$DEPLOY_TIMING_RUNNER" run'),
                    source.index("      - name: Collect Deployer timing history"),
                )

        self.assertIn("permissions:\n  contents: read\n  actions: read", staging)
        self.assertIn("permissions:\n  contents: read\n  actions: read", production)

    def make_fake_deployer(self, directory: Path) -> Path:
        deployer = directory / "fake-deployer.php"
        write_executable(
            deployer,
            """#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === 'tree') {
    echo "The task-tree for deploy:\\n";
    echo "└── deploy\\n";
    echo "    ├── task:one\\n";
    echo "    ├── task:two\\n";
    echo "    └── task:three\\n";
    exit(0);
}
$mode = getenv('FAKE_DEPLOY_RESULT') ?: 'success';
echo "::group::task task:one\\n";
usleep(1000);
echo "::endgroup::\\n";
echo "::group::task task:two\\n";
usleep(1000);
if ($mode === 'failure') {
    fwrite(STDERR, "[staging] exit code 23 (failure)\\n");
    echo "::endgroup::\\n";
    fwrite(STDERR, "ERROR: Task task:two failed!\\n");
    exit(23);
}
echo "::endgroup::\\n";
echo "::group::task task:three\\n";
echo "::endgroup::\\n";
exit(0);
""",
        )
        return deployer

    def run_fixture(self, result: str) -> tuple[subprocess.CompletedProcess[str], dict]:
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            receipt = directory / "receipt.json"
            deployer = self.make_fake_deployer(directory)
            command = [
                "php",
                str(RUNNER),
                "run",
                *context_args(receipt),
                f"--deployer-bin={deployer}",
                f"--recipe={ROOT / 'deploy.php'}",
                "--task=deploy",
                "--",
                "php",
                str(deployer),
                "deploy",
            ]
            env = os.environ.copy()
            env["FAKE_DEPLOY_RESULT"] = result
            completed = subprocess.run(command, cwd=ROOT, env=env, text=True, capture_output=True)
            payload = json.loads(receipt.read_text(encoding="utf-8"))
        return completed, payload

    def test_success_receipt_has_stable_sha_bound_task_records(self):
        completed, payload = self.run_fixture("success")

        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        self.assertEqual(payload["schema_version"], "fermatmind.deployer-task-timing.v1")
        self.assertEqual(payload["sha"], SHA)
        self.assertEqual(payload["plan_status"], "complete")
        self.assertEqual([task["result"] for task in payload["tasks"]], ["success"] * 3)
        for task in payload["tasks"]:
            self.assertEqual(task["environment"], "staging")
            self.assertEqual(task["sha"], SHA)
            self.assertEqual(task["workflow_run_id"], "123")
            self.assertEqual(task["workflow_run_attempt"], "2")
            self.assertIsInstance(task["duration_ms"], int)
            self.assertEqual(task["exit_code"], 0)

        serialized = json.dumps(payload).lower()
        for forbidden in ("secret", "ssh_host", "private_path", "token", "credential"):
            self.assertNotIn(forbidden, serialized)

    def test_failure_preserves_exit_code_and_marks_remaining_task_skipped(self):
        completed, payload = self.run_fixture("failure")
        tasks = {task["task"]: task for task in payload["tasks"]}

        self.assertEqual(completed.returncode, 23)
        self.assertEqual(tasks["task:one"]["result"], "success")
        self.assertEqual(tasks["task:two"]["result"], "failure")
        self.assertEqual(tasks["task:two"]["exit_code"], 23)
        self.assertEqual(tasks["task:three"]["result"], "skipped")
        self.assertIsNone(tasks["task:three"]["started_at"])
        self.assertIsNone(tasks["task:three"]["duration_ms"])

    def test_receipt_write_failure_does_not_change_deployer_exit(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            deployer = self.make_fake_deployer(directory)
            command = [
                "php",
                str(RUNNER),
                "run",
                *context_args(directory / "missing-parent" / "receipt.json"),
                f"--deployer-bin={deployer}",
                f"--recipe={ROOT / 'deploy.php'}",
                "--task=deploy",
                "--",
                "php",
                str(deployer),
                "deploy",
            ]
            blocked_parent = directory / "missing-parent"
            blocked_parent.write_text("not a directory", encoding="utf-8")

            success = subprocess.run(command, cwd=ROOT, text=True, capture_output=True)
            failed_env = os.environ.copy()
            failed_env["FAKE_DEPLOY_RESULT"] = "failure"
            failure = subprocess.run(
                command,
                cwd=ROOT,
                env=failed_env,
                text=True,
                capture_output=True,
            )

        self.assertEqual(success.returncode, 0)
        self.assertEqual(failure.returncode, 23)
        self.assertIn("without changing Deployer exit 0", success.stderr)
        self.assertIn("without changing Deployer exit 23", failure.stderr)

    def test_skip_receipt_is_explicit_and_sha_bound(self):
        with tempfile.TemporaryDirectory() as tmp:
            receipt = Path(tmp) / "receipt.json"
            completed = subprocess.run(
                ["php", str(RUNNER), "skip", *context_args(receipt), "--task=deploy"],
                cwd=ROOT,
                text=True,
                capture_output=True,
            )
            payload = json.loads(receipt.read_text(encoding="utf-8"))

        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(payload["tasks"][0]["result"], "skipped")
        self.assertEqual(payload["tasks"][0]["sha"], SHA)
        self.assertIsNone(payload["tasks"][0]["exit_code"])

    def test_summary_uses_fixed_history_window_and_nearest_rank_percentiles(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            history = directory / "history"
            history.mkdir()
            current = directory / "current.json"
            summary = directory / "summary.md"

            def receipt(run_id: str, generated_at: str, duration: int | None) -> dict:
                return {
                    "schema_version": "fermatmind.deployer-task-timing.v1",
                    "environment": "staging",
                    "sha": SHA,
                    "workflow_run_id": run_id,
                    "workflow_run_attempt": "1",
                    "generated_at": generated_at,
                    "tasks": [
                        {
                            "task": "deploy:vendors",
                            "result": "success" if duration is not None else "skipped",
                            "duration_ms": duration,
                        },
                        {"task": "deploy:symlink", "result": "skipped", "duration_ms": None},
                    ],
                }

            current.write_text(json.dumps(receipt("999", "2026-07-29T10:00:00.000Z", 250)), encoding="utf-8")
            for index, duration in enumerate((100, 200, 300), start=1):
                (history / f"{index}.json").write_text(
                    json.dumps(receipt(str(index), f"2026-07-2{index}T10:00:00.000Z", duration)),
                    encoding="utf-8",
                )

            completed = subprocess.run(
                [
                    "php",
                    str(RUNNER),
                    "summary",
                    f"--current={current}",
                    f"--history-dir={history}",
                    f"--output={summary}",
                    "--window=20",
                    "--minimum-samples=3",
                ],
                cwd=ROOT,
                text=True,
                capture_output=True,
            )
            markdown = summary.read_text(encoding="utf-8")

        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertIn("fixed window: latest 20 receipts", markdown)
        self.assertIn("| `deploy:vendors` | 250 ms | success | 3 | 200 ms | 300 ms |", markdown)
        self.assertIn("| `deploy:symlink` | N/A | skipped | 0 | N/A | N/A |", markdown)


if __name__ == "__main__":
    unittest.main()
