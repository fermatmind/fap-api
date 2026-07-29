import json
import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy.yml"
RUNNER = ROOT / "backend" / "scripts" / "deploy" / "verify_ops_asset_smoke.sh"
REMOTE = ROOT / "backend" / "scripts" / "deploy" / "ops_asset_smoke_remote.sh"


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(path.stat().st_mode | stat.S_IXUSR)


class OpsAssetSmokeBatchTest(unittest.TestCase):
    def test_workflow_uses_one_batched_helper_without_asset_retry_loop(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        start = source.index("      - name: Ops asset smoke (post deploy)")
        end = source.index("      - name:", start + 20)
        step = source[start:end]

        self.assertIn("verify_ops_asset_smoke.sh", step)
        self.assertIn("ops_asset_smoke_remote.sh", step)
        self.assertNotIn("ssh ", step)
        self.assertNotIn("retry ", step)
        self.assertNotIn("for _ in", step)
        self.assertNotIn('retry "Ops theme smoke" 5', source)
        self.assertNotIn('retry "Ops app.css smoke" 5', source)
        self.assertEqual(RUNNER.read_text(encoding="utf-8").count('"$ssh_bin"'), 1)

    def test_runner_invokes_ssh_once_and_preserves_remote_business_failure(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            fake_ssh = directory / "ssh"
            sentinel = directory / "ssh-calls"
            output = directory / "receipt.json"
            write_executable(
                fake_ssh,
                """#!/usr/bin/env bash
set -euo pipefail
printf 'called\\n' >> "$SSH_SENTINEL"
cat >/dev/null
printf '%s\\n' '{"schema_version":"fermatmind.ops-asset-smoke.v1","result":"failure","assets":[{"path":"/required-1.css","requirement":"required","http_status":500,"latency_ms":12,"result":"failure"},{"path":"/required-2.css","requirement":"required","http_status":200,"latency_ms":12,"result":"success"},{"path":"/required-3.css","requirement":"required","http_status":200,"latency_ms":12,"result":"success"},{"path":"/required-4.css","requirement":"required","http_status":200,"latency_ms":12,"result":"success"},{"path":"/required-5.js","requirement":"required","http_status":200,"latency_ms":12,"result":"success"},{"path":"/optional.css","requirement":"optional","http_status":404,"latency_ms":12,"result":"skipped"}]}'
exit "${FAKE_SSH_EXIT:-0}"
""",
            )
            env = os.environ.copy()
            env.update(
                {
                    "SSH_BIN": str(fake_ssh),
                    "SSH_SENTINEL": str(sentinel),
                    "FAKE_SSH_EXIT": "21",
                    "DEPLOY_USER": "deploy",
                    "DEPLOY_PORT": "22",
                    "DEPLOY_HOST": "staging.example",
                    "OPS_HOST": "ops.example",
                }
            )
            completed = subprocess.run(
                ["bash", str(RUNNER), str(REMOTE), str(output)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
            )
            self.assertEqual(completed.returncode, 21, completed.stdout + completed.stderr)
            receipt = json.loads(output.read_text(encoding="utf-8"))
            sentinel_calls = sentinel.read_text(encoding="utf-8").splitlines()

        self.assertEqual(sentinel_calls, ["called"])
        self.assertEqual(receipt["result"], "failure")
        self.assertIn("asset business failure", completed.stderr)

    def test_runner_distinguishes_ssh_transport_failure(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            fake_ssh = directory / "ssh"
            sentinel = directory / "ssh-calls"
            output = directory / "receipt.json"
            write_executable(
                fake_ssh,
                """#!/usr/bin/env bash
set -euo pipefail
printf 'called\\n' >> "$SSH_SENTINEL"
cat >/dev/null
exit 255
""",
            )
            env = os.environ.copy()
            env.update(
                {
                    "SSH_BIN": str(fake_ssh),
                    "SSH_SENTINEL": str(sentinel),
                    "DEPLOY_USER": "deploy",
                    "DEPLOY_PORT": "22",
                    "DEPLOY_HOST": "staging.example",
                    "OPS_HOST": "ops.example",
                }
            )
            completed = subprocess.run(
                ["bash", str(RUNNER), str(REMOTE), str(output)],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
            )
            sentinel_calls = sentinel.read_text(encoding="utf-8").splitlines()
            output_exists = output.exists()

        self.assertEqual(completed.returncode, 255, completed.stdout + completed.stderr)
        self.assertEqual(sentinel_calls, ["called"])
        self.assertFalse(output_exists)
        self.assertIn("SSH transport failure", completed.stderr)

    def test_remote_batch_reports_optional_404_without_retrying(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            fake_curl = directory / "curl"
            write_executable(
                fake_curl,
                """#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) output="$2"; shift 2 ;;
    -w) shift 2 ;;
    http*) url="$1"; shift ;;
    *) shift ;;
  esac
done
path="/${url#*://*/}"
status=200
body='compiled'
if [ "$path" = "/ops/login" ]; then
  body='css/app/ops-theme.css css/filament/filament/app.css css/filament/forms/forms.css css/filament/support/support.css js/filament/filament/app.js'
elif [ "$path" = "/css/app/ops-theme.css" ]; then
  status="${FAKE_THEME_STATUS:-404}"
fi
printf '%s' "$body" > "$output"
printf '%s\\t0.012' "$status"
""",
            )
            env = os.environ.copy()
            env["PATH"] = f"{directory}:{env['PATH']}"
            completed = subprocess.run(
                ["bash", str(REMOTE), "ops.example"],
                cwd=ROOT,
                env=env,
                text=True,
                capture_output=True,
            )
            receipt = json.loads(completed.stdout)

        self.assertEqual(completed.returncode, 0, completed.stderr)
        assets = {asset["path"]: asset for asset in receipt["assets"]}
        self.assertEqual(assets["/css/app/ops-theme.css"]["requirement"], "optional")
        self.assertEqual(assets["/css/app/ops-theme.css"]["http_status"], 404)
        self.assertEqual(assets["/css/app/ops-theme.css"]["result"], "skipped")
        self.assertEqual(assets["/css/filament/filament/app.css"]["result"], "success")
        self.assertEqual(
            set(assets),
            {
                "/ops/login",
                "/css/app/ops-theme.css",
                "/css/filament/filament/app.css",
                "/css/filament/forms/forms.css",
                "/css/filament/support/support.css",
                "/js/filament/filament/app.js",
            },
        )
        self.assertNotIn("retry", REMOTE.read_text(encoding="utf-8"))

    def test_remote_batch_fails_closed_for_required_asset_and_curl_transport(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            fake_curl = directory / "curl"
            write_executable(
                fake_curl,
                """#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    -o) output="$2"; shift 2 ;;
    -w) shift 2 ;;
    http*) url="$1"; shift ;;
    *) shift ;;
  esac
done
path="/${url#*://*/}"
printf 'compiled' > "$output"
if [ "$path" = "${FAKE_TRANSPORT_PATH:-}" ]; then
  printf '000\\t0.010'
  exit 7
fi
status=200
if [ "$path" = "${FAKE_FAILURE_PATH:-}" ]; then status=500; fi
printf '%s\\t0.010' "$status"
""",
            )
            base_env = os.environ.copy()
            base_env["PATH"] = f"{directory}:{base_env['PATH']}"

            business_env = base_env.copy()
            business_env["FAKE_FAILURE_PATH"] = "/css/filament/forms/forms.css"
            business = subprocess.run(
                ["bash", str(REMOTE), "ops.example"],
                cwd=ROOT,
                env=business_env,
                text=True,
                capture_output=True,
            )
            business_receipt = json.loads(business.stdout)

            transport_env = base_env.copy()
            transport_env["FAKE_TRANSPORT_PATH"] = "/js/filament/filament/app.js"
            transport = subprocess.run(
                ["bash", str(REMOTE), "ops.example"],
                cwd=ROOT,
                env=transport_env,
                text=True,
                capture_output=True,
            )
            transport_receipt = json.loads(transport.stdout)

        business_assets = {asset["path"]: asset for asset in business_receipt["assets"]}
        transport_assets = {asset["path"]: asset for asset in transport_receipt["assets"]}
        self.assertEqual(business.returncode, 21)
        self.assertEqual(business_assets["/css/filament/forms/forms.css"]["result"], "failure")
        self.assertEqual(transport.returncode, 20)
        self.assertEqual(transport_assets["/js/filament/filament/app.js"]["result"], "transport_failure")


if __name__ == "__main__":
    unittest.main()
