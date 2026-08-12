import grp
import hashlib
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
DEPLOY = ROOT / ".github/workflows/deploy.yml"


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_text(value: str) -> str:
    return sha256_bytes(value.encode())


def set_hash(values) -> str:
    return sha256_text("".join(f"{value.lower().strip()}\n" for value in sorted(set(values))))


class CareerRuntimeAuthorityPermissionControlTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.script = SCRIPT.read_text(encoding="utf-8")
        cls.preflight = PREFLIGHT.read_text(encoding="utf-8")
        cls.repair = REPAIR.read_text(encoding="utf-8")
        cls.deploy = DEPLOY.read_text(encoding="utf-8")

    def test_workflows_are_manual_exact_main_production_controls(self):
        for workflow in (self.preflight, self.repair):
            trigger = re.search(r"(?ms)^on:\n(?P<body>.*?)(?=^permissions:)", workflow)
            self.assertIsNotNone(trigger)
            self.assertIn("workflow_dispatch:", trigger.group("body"))
            self.assertNotRegex(trigger.group("body"), r"(?m)^  (?:push|pull_request|workflow_run|schedule):")
            self.assertIn("environment: production", workflow)
            self.assertIn("group: deploy-${{ github.repository }}-production", workflow)
            self.assertIn("cancel-in-progress: false", workflow)
            self.assertIn('test "$GITHUB_REF" = refs/heads/main', workflow)
            self.assertIn('test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"', workflow)
            self.assertIn('git diff --quiet "$EXPECTED_ACTIVE_REVISION" "$CONTROL_PLANE_SHA" -- backend/database/migrations', workflow)
            self.assertNotIn("curl -k", workflow)
            self.assertNotIn("--insecure", workflow)

        for required_input in (
            "expected_control_plane_sha",
            "expected_active_revision",
            "preflight_run_id",
            "preflight_run_attempt",
            "expected_preflight_receipt_sha256",
            "expected_preflight_artifact_digest",
            "operator_approval_phrase",
        ):
            self.assertRegex(self.repair, rf"(?m)^      {required_input}:\n(?:        .*\n)*?        required: true$")

    def test_preflight_and_apply_are_v2_lineage_bound(self):
        self.assertIn("career.runtime_authority_pointer_permission_preflight.v2", self.preflight)
        self.assertIn("career.runtime_authority_pointer_permission_repair.v2", self.repair)
        self.assertNotIn("permission_preflight.v1", self.repair)
        self.assertIn("31593321673", self.preflight)
        self.assertIn("sha256:101508066a741afd44d29b4c28bd866b1fa3d4772dfda14c71da71c320c545c7", self.preflight)
        self.assertIn("e0898f6fbb438495319cf0acc8bd6f808eba18c3f3beeb4a4e7d690312c27bc4", self.preflight)
        self.assertNotIn("e0898f6fbb438495319cf0acc8bd866b1fa3d4772dfda14c71da71c320c545c7", self.preflight)
        self.assertIn("31627080908", self.preflight)
        self.assertIn("PROJECTION_UNREADABLE", self.preflight)
        self.assertIn("PASS_POINTER_BOUND_PERMISSION_REPAIR_REQUIRED", self.preflight)
        self.assertIn("PASS_POINTER_BOUND_PERMISSION_REPAIR_VERIFIED", self.repair)
        self.assertIn("HOLD_APPLY_DISPATCHED_NO_VERIFIED_READBACK", self.repair)
        self.assertIn("automatic_retry_allowed:false", self.repair)
        self.assertIn("automatic_rollback_allowed:false", self.repair)
        self.assertIn("expected_phrase=", self.repair)
        self.assertIn('test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"', self.repair)
        self.assertIn('test "$(sha256sum "$receipt" | awk \'{print $1}\')" = "$EXPECTED_PREFLIGHT_RECEIPT_SHA256"', self.repair)

    def test_control_resolves_only_pointer_descriptors(self):
        for forbidden in (
            "select_latest_artifact",
            "mtime",
            "latest_dir",
            "scandir",
            "glob(",
            "find ",
            "chmod -R",
            "chown -R",
            "cp ",
            "mv ",
            "curl ",
            "php artisan",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, self.script)
        for required in (
            'active_pointer="$pointer_root/active-generation.json"',
            'immutable_pointer="$pointer_root/generations/$generation_id/generation-pointer.json"',
            'cmp -s "$active_pointer" "$immutable_pointer"',
            "career.generation_pointer.v1",
            "legacy_exact_bytes_v1",
            "CAREER_AUTHORITY_PERMISSION_PROJECTION_PATH_SHA256",
            "CAREER_AUTHORITY_PERMISSION_LEDGER_PATH_SHA256",
            'stat -c \'%h\' "$target"',
            "POINTER_BOUND_FILE_ESCAPE",
            "PROJECTION_SLUG_SET_MISMATCH",
            "PROJECTION_LOCALE_SET_MISMATCH",
            "LEDGER_SLUG_SET_MISMATCH",
        ):
            self.assertIn(required, self.script)
        self.assertEqual(1, self.script.count('chmod 2750 "${selected_directories[$index]}"'))
        self.assertEqual(1, self.script.count('chmod 0640 "${selected_files[$index]}"'))

    def test_pr1_control_paths_are_ignored_by_automatic_staging(self):
        for path in (
            '.github/workflows/career-runtime-authority-permission-preflight.yml',
            '.github/workflows/career-runtime-authority-permission-repair.yml',
            'backend/scripts/deploy/career_runtime_authority_permission_control.sh',
            'tests/ops/test_career_runtime_authority_permission_control.py',
            '.github/workflows/deploy.yml',
        ):
            self.assertIn(f'- "{path}"', self.deploy)

    def test_receipts_are_runner_side_failure_safe_and_sanitized(self):
        for workflow, receipt in (
            (self.preflight, "career-runtime-authority-permission-preflight.json"),
            (self.repair, "career-runtime-authority-permission-repair.json"),
        ):
            initialize = workflow.index("- name: Initialize failure-safe")
            checkout = workflow.index("- name: Checkout exact control plane")
            self.assertLess(initialize, checkout)
            self.assertIn(f'$RUNNER_TEMP/{receipt}', workflow[initialize:checkout])
            upload = workflow[workflow.rindex("- name: Upload"):]
            self.assertIn("if: always()", upload)
            self.assertIn(f"${{{{ runner.temp }}}}/{receipt}", upload)
            self.assertNotIn("owner_uid", workflow)
            self.assertNotIn("owner_gid", workflow)
            self.assertNotIn("artifact_path:", workflow)

    @unittest.skipUnless(
        platform.system() == "Linux" and shutil.which("jq") and shutil.which("getent"),
        "behavior tests require the Linux production toolchain",
    )
    def test_pointer_bound_inspect_apply_readback_is_no_clobber_and_byte_stable(self):
        with self._fixture() as fixture:
            before_files = fixture["file_inventory"]()
            before_bytes = fixture["artifact_bytes"]()
            inspected = self._run_control(fixture["env"])
            self.assertEqual("PASS_POINTER_BOUND_PERMISSION_REPAIR_REQUIRED", inspected["status"])
            self.assertEqual(4, inspected["repair_target_count"])
            self.assertEqual(0, inspected["metadata_write_count"])
            apply_env = {
                **fixture["env"],
                "CAREER_AUTHORITY_PERMISSION_MODE": "apply",
                "CAREER_AUTHORITY_PERMISSION_APPLY_CONFIRMATION": "true",
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_TARGET_SET_SHA256": inspected["target_set_sha256"],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_SNAPSHOT_SHA256": inspected["snapshot_sha256"],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT": "4",
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_POINTER_SHA256": inspected["pointer_document_sha256"],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_PROJECTION_PATH_SHA256": inspected["projection_path_sha256"],
                "CAREER_AUTHORITY_PERMISSION_EXPECTED_LEDGER_PATH_SHA256": inspected["ledger_path_sha256"],
            }
            applied = self._run_control(apply_env)
            self.assertEqual("PASS_POINTER_BOUND_PERMISSION_REPAIR_VERIFIED", applied["status"])
            self.assertEqual(4, applied["metadata_write_count"])
            self.assertEqual(0, applied["repair_target_count"])
            self.assertTrue(applied["runtime_readable"])
            self.assertTrue(applied["bytes_unchanged"])
            self.assertEqual(before_files, fixture["file_inventory"]())
            self.assertEqual(before_bytes, fixture["artifact_bytes"]())
            self.assertEqual(0o2750, fixture["projection_dir"].stat().st_mode & 0o7777)
            self.assertEqual(0o640, fixture["projection_path"].stat().st_mode & 0o7777)
            readback = self._run_control(fixture["env"])
            self.assertEqual("PASS_POINTER_BOUND_ALREADY_RUNTIME_READABLE", readback["status"])
            self.assertEqual(0, readback["repair_target_count"])
            self.assertEqual(inspected["target_set_sha256"], readback["target_set_sha256"])

    @unittest.skipUnless(platform.system() == "Linux" and shutil.which("jq") and shutil.which("getent"), "Linux only")
    def test_symlink_hardlink_path_hash_contract_and_count_drift_fail_closed(self):
        mutators = {
            "symlink": self._mutate_symlink,
            "hardlink": self._mutate_hardlink,
            "path_escape": self._mutate_path_escape,
            "byte_hash": self._mutate_byte_hash,
            "contract": self._mutate_contract,
            "count": self._mutate_count,
        }
        for name, mutate in mutators.items():
            with self.subTest(name=name), self._fixture() as fixture:
                mutate(fixture)
                completed = self._run_control_raw(fixture["env"])
                self.assertNotEqual(0, completed.returncode)
                receipt = json.loads(completed.stdout)
                self.assertEqual("HOLD_POINTER_BOUND_PERMISSION_CONTROL", receipt["status"])
                self.assertRegex(receipt["reason"], r"^[A-Z0-9_]+$")

    def _mutate_symlink(self, fixture):
        path = fixture["projection_path"]
        copy = path.with_name("copy.json")
        copy.write_bytes(path.read_bytes())
        path.unlink()
        path.symlink_to(copy.name)

    def _mutate_hardlink(self, fixture):
        os.link(fixture["projection_path"], fixture["projection_path"].with_name("hardlink.json"))

    def _mutate_path_escape(self, fixture):
        fixture["pointer_payload"]["payload"]["artifacts"]["projection"]["path"] = "../escape.json"
        fixture["rewrite_pointer"]()

    def _mutate_byte_hash(self, fixture):
        fixture["projection_path"].write_bytes(fixture["projection_path"].read_bytes() + b"\n")

    def _mutate_contract(self, fixture):
        projection = json.loads(fixture["projection_path"].read_text())
        projection["source_authority"] = "OtherAuthority"
        fixture["projection_path"].write_text(json.dumps(projection, sort_keys=True), encoding="utf-8")
        fixture["sync_projection_hash"]()

    def _mutate_count(self, fixture):
        projection = json.loads(fixture["projection_path"].read_text())
        projection["items"].pop()
        fixture["projection_path"].write_text(json.dumps(projection, sort_keys=True), encoding="utf-8")
        fixture["sync_projection_hash"]()

    class _fixture:
        def __init__(self, outer=None):
            self.temporary = None

        def __enter__(self):
            self.temporary = tempfile.TemporaryDirectory()
            base = Path(self.temporary.name)
            deploy = base / "deploy"
            release = deploy / "releases" / "active-release"
            backend = release / "backend"
            root = deploy / "shared" / "backend" / "storage" / "app" / "private"
            release.mkdir(parents=True)
            backend.mkdir()
            (release / "REVISION").write_text("a" * 40 + "\n")
            (deploy / "current").symlink_to(release)
            projection_relative = "career_runtime_publish_projection/frozen/career-runtime-publish-projection.json"
            ledger_relative = "career_release_ledger/frozen/career-full-release-ledger.json"
            projection_path = root / projection_relative
            ledger_path = root / ledger_relative
            projection_path.parent.mkdir(parents=True)
            ledger_path.parent.mkdir(parents=True)
            items = []
            for slug in ("alpha", "beta"):
                for locale in ("en", "zh"):
                    item = {"slug": slug, "locale": locale, "runtime_publish_state": "held"}
                    if slug == "alpha":
                        item.update(runtime_publish_state="published", public_resolution_type="public_canonical_job", release_gate_pass=True)
                    items.append(item)
            projection = {"projection_kind": "career_runtime_publish_projection", "projection_version": "career.runtime_publish_projection.v1", "source_authority": "CareerFullReleaseLedger", "items": items}
            ledger = {"ledger_kind": "career_full_release_ledger", "ledger_version": "test.v1", "members": [{"slug": "alpha"}, {"slug": "beta"}]}
            projection_path.write_text(json.dumps(projection, sort_keys=True), encoding="utf-8")
            ledger_path.write_text(json.dumps(ledger, sort_keys=True), encoding="utf-8")
            slug_hash = set_hash(["alpha", "beta"])
            locale_hash = set_hash(["alpha|en", "alpha|zh", "beta|en", "beta|zh"])
            manifest_hash = "1" * 64
            receipt_hash = "2" * 64
            generation = "test-generation-v1"
            pointer = {
                "schema_version": "career.generation_pointer.v1",
                "payload_sha256": "3" * 64,
                "payload": {
                    "generation_id": generation,
                    "artifact_format": "legacy_exact_bytes_v1",
                    "artifacts": {
                        "projection": {"identity": f"career-runtime-publish-projection@{generation}", "path": projection_relative, "sha256": sha256_bytes(projection_path.read_bytes())},
                        "ledger": {"identity": f"career-full-release-ledger@{generation}", "path": ledger_relative, "sha256": sha256_bytes(ledger_path.read_bytes())},
                    },
                    "authority": {"frozen_manifest_sha256": manifest_hash, "receipt_set_sha256": receipt_hash, "target_slug_set_sha256": slug_hash, "target_locale_row_set_sha256": locale_hash},
                    "counts": {"public_slug_count": 1, "public_locale_row_count": 2},
                    "lineage": {"previous_generation_id": None, "previous_pointer_sha256": None},
                    "rollback": {"eligible": False},
                    "discoverability": {"sitemap_mutated": False, "llms_mutated": False, "search_mutated": False},
                },
            }
            pointer_root = root / "career_generation_authority"
            immutable = pointer_root / "generations" / generation / "generation-pointer.json"
            immutable.parent.mkdir(parents=True)
            active = pointer_root / "active-generation.json"
            user = pwd.getpwuid(os.getuid()).pw_name
            group = grp.getgrgid(os.getgid()).gr_name
            env = {
                **os.environ,
                "DEPLOY_PATH": str(deploy),
                "EXPECTED_ACTIVE_REVISION": "a" * 40,
                "CAREER_AUTHORITY_PERMISSION_ROOT": str(root),
                "CAREER_AUTHORITY_PERMISSION_OWNER": user,
                "CAREER_AUTHORITY_PERMISSION_GROUP": group,
                "CAREER_AUTHORITY_PERMISSION_RUNTIME_USER": user,
                "CAREER_AUTHORITY_PERMISSION_GENERATION_ID": generation,
                "CAREER_AUTHORITY_PERMISSION_FROZEN_MANIFEST_SHA256": manifest_hash,
                "CAREER_AUTHORITY_PERMISSION_RECEIPT_SET_SHA256": receipt_hash,
                "CAREER_AUTHORITY_PERMISSION_SLUG_SET_SHA256": slug_hash,
                "CAREER_AUTHORITY_PERMISSION_LOCALE_ROW_SET_SHA256": locale_hash,
                "CAREER_AUTHORITY_PERMISSION_SLUG_COUNT": "2",
                "CAREER_AUTHORITY_PERMISSION_LOCALE_ROW_COUNT": "4",
                "CAREER_AUTHORITY_PERMISSION_PUBLISHED_SLUG_COUNT": "1",
                "CAREER_AUTHORITY_PERMISSION_PUBLISHED_LOCALE_ROW_COUNT": "2",
                "CAREER_AUTHORITY_PERMISSION_PROJECTION_PATH_SHA256": sha256_text(projection_relative),
                "CAREER_AUTHORITY_PERMISSION_LEDGER_PATH_SHA256": sha256_text(ledger_relative),
                "CAREER_AUTHORITY_PERMISSION_LEDGER_SHA256": sha256_bytes(ledger_path.read_bytes()),
            }

            fixture = {"env": env, "pointer_payload": pointer, "projection_path": projection_path, "ledger_path": ledger_path, "projection_dir": projection_path.parent, "ledger_dir": ledger_path.parent}

            def rewrite_pointer():
                raw = (json.dumps(pointer, sort_keys=True) + "\n").encode()
                active.write_bytes(raw)
                immutable.write_bytes(raw)
                env["CAREER_AUTHORITY_PERMISSION_POINTER_SHA256"] = sha256_bytes(raw)
                env["CAREER_AUTHORITY_PERMISSION_PROJECTION_PATH_SHA256"] = sha256_text(pointer["payload"]["artifacts"]["projection"]["path"])

            def sync_projection_hash():
                digest = sha256_bytes(projection_path.read_bytes())
                pointer["payload"]["artifacts"]["projection"]["sha256"] = digest
                env["CAREER_AUTHORITY_PERMISSION_PROJECTION_SHA256"] = digest
                rewrite_pointer()

            fixture["rewrite_pointer"] = rewrite_pointer
            fixture["sync_projection_hash"] = sync_projection_hash
            rewrite_pointer()
            env["CAREER_AUTHORITY_PERMISSION_PROJECTION_SHA256"] = sha256_bytes(projection_path.read_bytes())
            os.chmod(projection_path.parent, 0o700)
            os.chmod(ledger_path.parent, 0o700)
            os.chmod(projection_path, 0o600)
            os.chmod(ledger_path, 0o600)
            fixture["file_inventory"] = lambda: sorted(str(path.relative_to(root)) for path in root.rglob("*") if path.is_file())
            fixture["artifact_bytes"] = lambda: (projection_path.read_bytes(), ledger_path.read_bytes())
            return fixture

        def __exit__(self, exc_type, exc, tb):
            self.temporary.cleanup()

    def _run_control_raw(self, env):
        return subprocess.run(["bash", str(SCRIPT)], cwd=ROOT, env=env, capture_output=True, text=True)

    def _run_control(self, env):
        completed = self._run_control_raw(env)
        if completed.returncode != 0:
            self.fail(f"control failed: {completed.stdout} {completed.stderr}")
        return json.loads(completed.stdout)


if __name__ == "__main__":
    unittest.main()
