import json
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
RECEIPT_HELPER = ROOT / "backend" / "scripts" / "deploy" / "ci_parity_receipt.php"
CI_WORKFLOW = ROOT / ".github" / "workflows" / "ci.yml"
DEPLOY_WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
DEPLOYER_RECIPE = ROOT / "deploy.php"
VERIFY_SCRIPT = ROOT / "backend" / "scripts" / "ci_verify_mbti.sh"
SHA = "a" * 40
REPO = "fermatmind/fap-api"
RUN_ID = "123"
RUN_ATTEMPT = "2"


def helper_command(command: str, receipt: Path) -> list[str]:
    if command == "create":
        return [
            "php",
            str(RECEIPT_HELPER),
            "create",
            f"--output={receipt}",
            f"--repo={REPO}",
            f"--sha={SHA}",
            f"--run-id={RUN_ID}",
            f"--run-attempt={RUN_ATTEMPT}",
        ]

    return [
        "php",
        str(RECEIPT_HELPER),
        "verify",
        f"--receipt={receipt}",
        f"--expected-repo={REPO}",
        f"--expected-sha={SHA}",
        f"--expected-run-id={RUN_ID}",
        f"--expected-run-attempt={RUN_ATTEMPT}",
    ]


class CiParityReceiptTest(unittest.TestCase):
    def create_receipt(self, path: Path) -> dict:
        completed = subprocess.run(
            helper_command("create", path),
            cwd=ROOT,
            text=True,
            capture_output=True,
        )
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        return json.loads(path.read_text(encoding="utf-8"))

    def test_create_and_verify_exact_sha_receipt(self):
        with tempfile.TemporaryDirectory() as tmp:
            receipt = Path(tmp) / f"parity-receipt-{SHA}.json"
            payload = self.create_receipt(receipt)
            verified = subprocess.run(
                helper_command("verify", receipt),
                cwd=ROOT,
                text=True,
                capture_output=True,
            )

        self.assertEqual(verified.returncode, 0, verified.stdout + verified.stderr)
        self.assertEqual(payload["schema_version"], "fermatmind.api.ci-parity-receipt.v1")
        self.assertEqual(payload["repo"], REPO)
        self.assertEqual(payload["source_sha"], SHA)
        self.assertEqual(payload["ci_workflow_run_id"], RUN_ID)
        self.assertEqual(payload["ci_workflow_run_attempt"], RUN_ATTEMPT)
        self.assertEqual(payload["topology_identity"], "mysql:8.0|redis:6-alpine")
        self.assertEqual(payload["matrix_identity"], "staging-parity:legacy+v2+bigfive+enneagram")
        self.assertEqual(payload["result"], "success")
        for field in (
            "ci_verify_mbti_sha256",
            "composer_lock_sha256",
            "config_fingerprint",
        ):
            self.assertRegex(payload[field], r"^[a-f0-9]{64}$")

    def test_wrong_identity_and_tampering_fail_closed(self):
        cases = {
            "wrong SHA": ("source_sha", "b" * 40, "source_sha"),
            "wrong script digest": ("ci_verify_mbti_sha256", "c" * 64, "ci_verify_mbti_sha256"),
            "wrong composer digest": ("composer_lock_sha256", "d" * 64, "composer_lock_sha256"),
            "wrong config fingerprint": ("config_fingerprint", "e" * 64, "config_fingerprint"),
            "wrong CI run": ("ci_workflow_run_id", "999", "ci_workflow_run_id"),
            "wrong CI attempt": ("ci_workflow_run_attempt", "9", "ci_workflow_run_attempt"),
            "failed result": ("result", "failure", "result"),
            "wrong schema": ("schema_version", "unsupported.v9", "schema_version"),
        }
        for label, (field, value, expected_error) in cases.items():
            with self.subTest(label=label), tempfile.TemporaryDirectory() as tmp:
                receipt = Path(tmp) / "receipt.json"
                payload = self.create_receipt(receipt)
                payload[field] = value
                receipt.chmod(0o600)
                receipt.write_text(json.dumps(payload), encoding="utf-8")
                completed = subprocess.run(
                    helper_command("verify", receipt),
                    cwd=ROOT,
                    text=True,
                    capture_output=True,
                )
                self.assertNotEqual(completed.returncode, 0)
                self.assertIn(expected_error, completed.stderr)

    def test_extra_receipt_field_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            receipt = Path(tmp) / "receipt.json"
            payload = self.create_receipt(receipt)
            payload["unverified"] = True
            receipt.chmod(0o600)
            receipt.write_text(json.dumps(payload), encoding="utf-8")
            completed = subprocess.run(
                helper_command("verify", receipt),
                cwd=ROOT,
                text=True,
                capture_output=True,
            )

        self.assertNotEqual(completed.returncode, 0)
        self.assertIn("fields do not match", completed.stderr)

    def test_ci_runs_real_mysql_redis_gate_and_attests_the_receipt(self):
        source = CI_WORKFLOW.read_text(encoding="utf-8")
        parity = source[source.index("  verify-staging-parity:") : source.index("  verify-bigfive:")]
        verify_script = VERIFY_SCRIPT.read_text(encoding="utf-8")

        self.assertIn("image: mysql:8.0", parity)
        self.assertIn("image: redis:6-alpine", parity)
        self.assertIn("CI_VERIFY_TOPOLOGY: mysql8-redis6", parity)
        self.assertIn('CI_VERIFY_PARITY_ONLY: "1"', parity)
        self.assertIn("DB_CONNECTION: mysql", parity)
        self.assertIn("QUEUE_CONNECTION: redis", parity)
        self.assertIn("RUN_BIG5_OCEAN_GATE: \"1\"", parity)
        self.assertIn("RUN_ENNEAGRAM_GATE: \"1\"", parity)
        self.assertIn("bash ./backend/scripts/ci_verify_mbti.sh", parity)
        self.assertIn("tests/Feature/V0_3/MbtiFormVersionFlowTest.php", verify_script)
        self.assertIn("name: fap-api-parity-receipt-${{ github.sha }}", parity)
        self.assertIn("subject-digest: sha256:${{ steps.upload-parity-receipt.outputs.artifact-digest }}", parity)
        self.assertIn("actions/attest@f7c74d28b9d84cb8768d0b8ca14a4bac6ef463e6 # v4", parity)

    def test_staging_verifies_exact_receipt_artifact_without_repeating_parity(self):
        source = DEPLOY_WORKFLOW.read_text(encoding="utf-8")

        self.assertNotIn("bash ./backend/scripts/ci_verify_mbti.sh", source)
        self.assertIn("Resolve exact-SHA successful CI parity receipt", source)
        self.assertIn("artifact.name === expectedName && !artifact.expired", source)
        self.assertIn('test "$actual_artifact_digest" = "$PARITY_ARTIFACT_DIGEST"', source)
        self.assertIn("gh attestation verify", source)
        self.assertIn('--source-digest "$PARITY_EXPECTED_SHA"', source)
        self.assertIn("ci_parity_receipt.php verify", source)
        self.assertIn('--expected-sha="$PARITY_EXPECTED_SHA"', source)
        self.assertIn("CI_PARITY_RECEIPT_CONFIG_FINGERPRINT", source)
        self.assertNotIn("image: mysql:8.0", source)
        self.assertNotIn("image: redis:6-alpine", source)

    def test_deployer_timing_tree_contains_the_receipt_gate(self):
        source = DEPLOYER_RECIPE.read_text(encoding="utf-8")

        self.assertIn("task('guard:ci-parity-receipt'", source)
        self.assertIn("before('deploy:prepare', 'guard:ci-parity-receipt')", source)
        self.assertIn("CI_PARITY_RECEIPT_ARTIFACT_DIGEST", source)
        self.assertIn("CI_PARITY_RECEIPT_CONFIG_FINGERPRINT", source)


if __name__ == "__main__":
    unittest.main()
