import grp
import json
import os
import platform
import pwd
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "backend/scripts/deploy/career_runtime_authority_permission_control.sh"
PREFLIGHT = ROOT / ".github/workflows/career-runtime-authority-permission-preflight.yml"
REPAIR = ROOT / ".github/workflows/career-runtime-authority-permission-repair.yml"


class CareerRuntimeAuthorityPermissionControlTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.script = SCRIPT.read_text(encoding="utf-8")
        cls.preflight = PREFLIGHT.read_text(encoding="utf-8")
        cls.repair = REPAIR.read_text(encoding="utf-8")

    def test_workflows_are_manual_production_controls_with_exact_lineage(self):
        for workflow in (self.preflight, self.repair):
            trigger = re.search(
                r"(?ms)^on:\n(?P<body>.*?)(?=^permissions:)", workflow
            )
            self.assertIsNotNone(trigger)
            self.assertIn("workflow_dispatch:", trigger.group("body"))
            self.assertNotRegex(
                trigger.group("body"),
                r"(?m)^  (?:push|pull_request|workflow_run|schedule):",
            )
            self.assertIn("environment: production", workflow)
            self.assertIn(
                "group: deploy-${{ github.repository }}-production", workflow
            )
            self.assertIn("cancel-in-progress: false", workflow)
            self.assertIn('test "$GITHUB_REF" = "refs/heads/main"', workflow)
            self.assertIn(
                'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
                workflow,
            )

        for required_input in (
            "expected_control_plane_sha",
            "expected_active_revision",
            "preflight_run_id",
            "preflight_run_attempt",
            "expected_preflight_receipt_sha256",
            "expected_preflight_artifact_digest",
            "operator_approval_phrase",
        ):
            self.assertRegex(
                self.repair,
                rf"(?m)^      {re.escape(required_input)}:\n"
                r"(?:        .*\n)*?        required: true$",
            )

    def test_preflight_is_zero_write_and_repair_is_receipt_bound(self):
        self.assertNotIn("sudo -n env", self.preflight)
        self.assertIn("CAREER_AUTHORITY_PERMISSION_MODE=inspect", self.preflight)
        self.assertIn("remote_write_count: 0", self.preflight)
        self.assertIn("metadata_write_count: 0", self.preflight)
        self.assertIn('status "HOLD_PREFLIGHT_INCOMPLETE"', self.preflight)
        self.assertIn(
            'PREFLIGHT_RECEIPT="$RUNNER_TEMP/career-runtime-authority-permission-preflight.json"',
            self.preflight,
        )
        self.assertIn(
            "printf 'PREFLIGHT_RECEIPT=%s\\n' \"$PREFLIGHT_RECEIPT\" >> \"$GITHUB_ENV\"",
            self.preflight,
        )
        self.assertIn(
            "path: ${{ runner.temp }}/career-runtime-authority-permission-preflight.json",
            self.preflight,
        )
        self.assertNotIn(
            "> artifacts/career-runtime-authority-permission-preflight.json",
            self.preflight,
        )
        self.assertIn('safe_reason="INSPECTION_TRANSPORT_OR_OUTPUT_INVALID"', self.preflight)
        self.assertIn('status = "HOLD_PREFLIGHT_INSPECTION"', self.preflight)

        for binding in (
            'and .path == ".github/workflows/career-runtime-authority-permission-preflight.yml"',
            'and .head_sha == $sha',
            'and .run_attempt == $attempt',
            'and .[0].digest == $digest',
            'test "$(sha256sum "$receipt" | awk \'{print $1}\')" = "$EXPECTED_PREFLIGHT_RECEIPT_SHA256"',
            'and .status == "PASS_PERMISSION_REPAIR_REQUIRED"',
            'and .inspector_sha256 == $inspector',
            'CAREER_AUTHORITY_PERMISSION_EXPECTED_TARGET_SET_SHA256',
            'CAREER_AUTHORITY_PERMISSION_EXPECTED_SNAPSHOT_SHA256',
            'CAREER_AUTHORITY_PERMISSION_EXPECTED_PROJECTION_SHA256',
            'CAREER_AUTHORITY_PERMISSION_EXPECTED_LEDGER_SHA256',
            'CAREER_AUTHORITY_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT',
        ):
            self.assertIn(binding, self.repair)

        self.assertIn("HOLD_APPLY_DISPATCHED_NO_VERIFIED_READBACK", self.repair)
        self.assertIn("automatic_retry_allowed: false", self.repair)
        self.assertIn("PASS_PERMISSION_REPAIR_VERIFIED", self.repair)
        self.assertIn(
            'REPAIR_RECEIPT="$RUNNER_TEMP/career-runtime-authority-permission-repair.json"',
            self.repair,
        )
        self.assertIn(
            "printf 'REPAIR_RECEIPT=%s\\n' \"$REPAIR_RECEIPT\" >> \"$GITHUB_ENV\"",
            self.repair,
        )
        self.assertIn(
            "path: ${{ runner.temp }}/career-runtime-authority-permission-repair.json",
            self.repair,
        )
        self.assertNotIn(
            "> artifacts/career-runtime-authority-permission-repair.json", self.repair
        )

    def test_failure_receipts_survive_checkout_cleanup(self):
        for workflow, initialize_name, checkout_name, receipt_name in (
            (
                self.preflight,
                "- name: Initialize failure-safe receipt",
                "- name: Checkout exact control plane",
                "career-runtime-authority-permission-preflight.json",
            ),
            (
                self.repair,
                "- name: Initialize failure-safe no-retry receipt",
                "- name: Checkout exact control plane",
                "career-runtime-authority-permission-repair.json",
            ),
        ):
            with self.subTest(receipt_name=receipt_name):
                initialize_at = workflow.index(initialize_name)
                checkout_at = workflow.index(checkout_name)
                self.assertLess(initialize_at, checkout_at)
                initialize_step = workflow[initialize_at:checkout_at]
                self.assertIn(f'$RUNNER_TEMP/{receipt_name}', initialize_step)
                self.assertIn('$GITHUB_ENV', initialize_step)
                upload_step = workflow[workflow.rindex("- name: Upload") :]
                self.assertIn("if: always()", upload_step)
                self.assertIn(f"${{{{ runner.temp }}}}/{receipt_name}", upload_step)

    def test_control_is_limited_to_selected_node_metadata(self):
        for prohibited in (
            "chmod -R",
            "chown -R",
            "rm -",
            "rm --",
            "cp ",
            "mv ",
            "find ",
            "php artisan",
            "curl ",
        ):
            with self.subTest(prohibited=prohibited):
                self.assertNotIn(prohibited, self.script)

        self.assertEqual(1, self.script.count('chmod 2750 "${selected_directories[$index]}"'))
        self.assertEqual(1, self.script.count('chmod 0640 "${selected_files[$index]}"'))
        self.assertIn('for index in 0 1; do', self.script)
        self.assertIn(
            '([.items[].locale] | unique | sort) == ["en", "zh"]', self.script
        )
        self.assertNotIn('== ["en", "zh-CN"]', self.script)
        self.assertIn('.status == "PASS_PERMISSION_REPAIR_REQUIRED"', self.repair)
        self.assertIn("content_write_count:0", self.script)
        self.assertIn("database_write_count:0", self.script)
        self.assertIn("cache_write_count:0", self.script)
        self.assertIn("publication_write_count:0", self.script)
        self.assertIn("discoverability_write_count:0", self.script)

    @unittest.skipUnless(
        platform.system() == "Linux"
        and shutil.which("jq") is not None
        and shutil.which("getent") is not None,
        "behavior test requires the Linux production toolchain",
    )
    def test_inspect_apply_and_readback_preserve_artifact_bytes(self):
        user = pwd.getpwuid(os.getuid()).pw_name
        group = grp.getgrgid(os.getgid()).gr_name
        with tempfile.TemporaryDirectory() as temporary:
            root = (
                Path(temporary)
                / "shared"
                / "backend"
                / "storage"
                / "app"
                / "private"
            )
            projection_dir = root / "career_runtime_publish_projection" / "20260810T000000Z"
            ledger_dir = root / "career_release_ledger" / "20260810T000000Z"
            projection_dir.mkdir(parents=True)
            ledger_dir.mkdir(parents=True)
            projection_path = projection_dir / "career-runtime-publish-projection.json"
            ledger_path = ledger_dir / "career-full-release-ledger.json"
            projection_path.write_text(
                json.dumps(
                    {
                        "projection_kind": "career_runtime_publish_projection",
                        "projection_version": "career.runtime_publish_projection.v1",
                        "items": [
                            {"slug": "alpha", "locale": "en"},
                            {"slug": "alpha", "locale": "zh"},
                            {"slug": "beta", "locale": "en"},
                            {"slug": "beta", "locale": "zh"},
                        ],
                    },
                    sort_keys=True,
                ),
                encoding="utf-8",
            )
            ledger_path.write_text(
                json.dumps(
                    {
                        "ledger_kind": "career_full_release_ledger",
                        "ledger_version": "career.release_ledger.test.v1",
                        "members": [{"slug": "alpha"}, {"slug": "beta"}],
                    },
                    sort_keys=True,
                ),
                encoding="utf-8",
            )
            os.chmod(projection_dir, 0o700)
            os.chmod(ledger_dir, 0o700)
            os.chmod(projection_path, 0o600)
            os.chmod(ledger_path, 0o600)
            before = {
                projection_path: projection_path.read_bytes(),
                ledger_path: ledger_path.read_bytes(),
            }

            base_env = {
                **os.environ,
                "CAREER_AUTHORITY_PERMISSION_ROOT": str(root),
                "CAREER_AUTHORITY_PERMISSION_OWNER": user,
                "CAREER_AUTHORITY_PERMISSION_GROUP": group,
                "CAREER_AUTHORITY_PERMISSION_RUNTIME_USER": user,
            }
            inspected = self._run_control(base_env)
            self.assertEqual("PASS_PERMISSION_REPAIR_REQUIRED", inspected["status"])
            self.assertEqual(2, inspected["projection_unique_slug_count"])
            self.assertEqual(4, inspected["projection_locale_row_count"])
            self.assertEqual(2, inspected["ledger_member_count"])
            self.assertEqual(4, inspected["repair_target_count"])
            self.assertEqual(0, inspected["metadata_write_count"])

            apply_env = {
                **base_env,
                "CAREER_AUTHORITY_PERMISSION_MODE": "apply",
                "CAREER_AUTHORITY_PERMISSION_APPLY_CONFIRMATION": "true",
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_TARGET_SET_SHA256": inspected[
                    "target_set_sha256"
                ],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_SNAPSHOT_SHA256": inspected[
                    "snapshot_sha256"
                ],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_PROJECTION_SHA256": inspected[
                    "projection_sha256"
                ],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_LEDGER_SHA256": inspected[
                    "ledger_sha256"
                ],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT": str(
                    inspected["repair_target_count"]
                ),
            }
            applied = self._run_control(apply_env)
            self.assertEqual("PASS_PERMISSION_REPAIR_VERIFIED", applied["status"])
            self.assertEqual(4, applied["metadata_write_count"])
            self.assertEqual(0, applied["repair_target_count"])
            self.assertTrue(applied["runtime_readable"])
            self.assertTrue(applied["bytes_unchanged"])
            self.assertEqual(0o2750, projection_dir.stat().st_mode & 0o7777)
            self.assertEqual(0o2750, ledger_dir.stat().st_mode & 0o7777)
            self.assertEqual(0o640, projection_path.stat().st_mode & 0o7777)
            self.assertEqual(0o640, ledger_path.stat().st_mode & 0o7777)
            for path, expected_bytes in before.items():
                self.assertEqual(expected_bytes, path.read_bytes())

            readback = self._run_control(base_env)
            self.assertEqual("PASS_ALREADY_RUNTIME_READABLE", readback["status"])
            self.assertEqual(0, readback["repair_target_count"])
            self.assertEqual(inspected["target_set_sha256"], readback["target_set_sha256"])
            self.assertEqual(inspected["projection_sha256"], readback["projection_sha256"])
            self.assertEqual(inspected["ledger_sha256"], readback["ledger_sha256"])

    def _run_control(self, env):
        completed = subprocess.run(
            ["bash", str(SCRIPT)],
            cwd=ROOT,
            env=env,
            check=True,
            capture_output=True,
            text=True,
        )
        return json.loads(completed.stdout)


if __name__ == "__main__":
    unittest.main()
