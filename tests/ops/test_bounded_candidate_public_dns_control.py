import hashlib
import re
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy-production.yml"
DEPLOYER = ROOT / "deploy.php"
WRAPPER = (
    ROOT
    / "backend"
    / "scripts"
    / "deploy"
    / "bounded_candidate_public_dns_control.php"
)
HELPER = (
    ROOT
    / "backend"
    / "scripts"
    / "deploy"
    / "verify_public_dns_business_evidence.sh"
)

CANDIDATE_SHA = "363bbba54f7cac78b9cbb6118c1800dd0c6b7340"
STAGING_RUN_ID = "31384127889"
CANDIDATE_RECIPE_SHA256 = (
    "e27282825c2074e56067e6ec4cb9a8a3951ad8d4207c0c3f598fc93a1d02128b"
)
INCIDENT_RUN_ID = "31415836351"
INCIDENT_ARTIFACT_DIGEST = (
    "sha256:667b65a99f1a035a20971f237874b1545187584caa767ea0d9d9bc99f0fcde6e"
)
INCIDENT_RECEIPT_SHA256 = (
    "2d6d822615481b3280fbc1f92ce00d3ce731e556f5b419ca98eb8d185d4cf50e"
)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class BoundedCandidatePublicDnsControlTest(unittest.TestCase):
    def setUp(self):
        self.workflow = WORKFLOW.read_text(encoding="utf-8")
        self.wrapper = WRAPPER.read_text(encoding="utf-8")

    def workflow_step(self, start_name: str, end_name: str) -> str:
        start = self.workflow.index(f"- name: {start_name}")
        end = self.workflow.index(f"- name: {end_name}", start)
        return self.workflow[start:end]

    def test_candidate_recipe_and_control_wrapper_hashes_are_exact(self):
        candidate_recipe = subprocess.run(
            ["git", "show", f"{CANDIDATE_SHA}:deploy.php"],
            cwd=ROOT,
            capture_output=True,
            check=True,
        ).stdout

        self.assertEqual(
            CANDIDATE_RECIPE_SHA256,
            hashlib.sha256(candidate_recipe).hexdigest(),
        )

        step = self.workflow_step(
            "Prepare bounded candidate public-DNS control",
            "Setup PHP",
        )
        wrapper_match = re.search(r'wrapper_sha256="([0-9a-f]{64})"', step)
        helper_match = re.search(r'helper_sha256="([0-9a-f]{64})"', step)
        classifier_match = re.search(
            r'EXPECTED_BOUNDED_PUBLIC_DNS_CONTROL_SHA256="([0-9a-f]{64})"',
            self.workflow,
        )

        self.assertIsNotNone(wrapper_match)
        self.assertIsNotNone(helper_match)
        self.assertIsNotNone(classifier_match)
        self.assertEqual(sha256(WRAPPER), wrapper_match.group(1))
        self.assertEqual(sha256(HELPER), helper_match.group(1))
        self.assertEqual(sha256(WRAPPER), classifier_match.group(1))

    def test_prepare_step_binds_candidate_staging_and_failed_incident(self):
        step = self.workflow_step(
            "Prepare bounded candidate public-DNS control",
            "Setup PHP",
        )

        for expected in (
            CANDIDATE_SHA,
            STAGING_RUN_ID,
            CANDIDATE_RECIPE_SHA256,
            INCIDENT_RUN_ID,
            INCIDENT_ARTIFACT_DIGEST,
            INCIDENT_RECEIPT_SHA256,
            "WORKFLOW_CONTROL_SHA: ${{ github.sha }}",
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$DEPLOY_MODE" = "standard"',
            'git merge-base --is-ancestor "$DEPLOY_SHA" "$WORKFLOW_CONTROL_SHA"',
            'test "$(git rev-parse HEAD)" = "$candidate_sha"',
            'test "$(sha256sum deploy.php | awk',
            'gh api "repos/${GITHUB_REPOSITORY}/actions/runs/${incident_run_id}/artifacts"',
            'gh run download "$incident_run_id"',
            '.task_evidence.public_dns_guard == "failure"',
            '.task_evidence.symlink == "skipped"',
            '.activation_completed == false',
            'git show "${WORKFLOW_CONTROL_SHA}:${wrapper_path}"',
            'git show "${WORKFLOW_CONTROL_SHA}:${helper_path}"',
            "public_dns_helper_sha256: $helper_sha256",
            "echo \"helper_path=$control_helper\" >> \"$GITHUB_OUTPUT\"",
            "backend-bounded-public-dns-control-receipt.v1",
            'overridden_tasks: ["guard:public-dns-health"]',
            "candidate_tree_unchanged: true",
            "release_overlay: false",
            "remote_control_file_write: false",
            "activation_authorized: false",
        ):
            self.assertIn(expected, self.workflow)

        for forbidden in (
            "git apply",
            "patch ",
            "sed -i",
            "rsync ",
        ):
            self.assertNotIn(forbidden, step)

    def test_wrapper_replaces_only_the_public_dns_guard(self):
        self.assertEqual(1, self.wrapper.count("task('guard:public-dns-health'"))
        self.assertEqual(1, self.wrapper.count("task('"))
        self.assertLess(
            self.wrapper.index("require $candidateRecipe;"),
            self.wrapper.index("task('guard:public-dns-health'"),
        )

        for expected in (
            "BOUNDED_PUBLIC_DNS_HELPER_SHA256",
            "BOUNDED_PUBLIC_DNS_HELPER_PATH",
            "PUBLIC_DNS_PROBE_ATTEMPTS' => '3'",
            "PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS' => '2 5'",
            "PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS' => '3'",
            "PUBLIC_DNS_PROBE_MAX_TIME_SECONDS' => '10'",
            "base64_encode",
            "| base64 -d",
            "| env ",
            "bash -s",
        ):
            self.assertIn(expected, self.wrapper)

        for forbidden in (
            "set +e; raw=",
            "file_put_contents",
            "mkdir(",
            "rename(",
            "unlink(",
            "copy(",
            "upload(",
            "scp ",
            "rsync ",
            "--resolve",
            "curl ",
            "bash -lc",
        ):
            self.assertNotIn(forbidden, self.wrapper)

    def test_wrapper_task_resolves_current_host_in_deployer_worker_namespace(self):
        task_start = self.wrapper.index("task('guard:public-dns-health'")
        worker_source = self.wrapper[: self.wrapper.index("$candidateSha =")]
        worker_source += "\n$publicDnsHelperPayload = base64_encode('test helper');\n\n"
        worker_source += self.wrapper[task_start:]
        worker_source = worker_source.removeprefix("<?php\n\n")
        worker_source = worker_source.replace(
            "declare(strict_types=1);\n\n",
            "",
            1,
        )
        harness = """<?php
declare(strict_types=1);

namespace Deployer {
    final class TestHost
    {
        public function getAlias(): string
        {
            return 'staging';
        }
    }

    function currentHost(): TestHost
    {
        return new TestHost();
    }

    function get(string $name): mixed
    {
        throw new \\RuntimeException('get must not run for staging');
    }

    function run(string $command): void
    {
        throw new \\RuntimeException('run must not execute for staging');
    }

    function task(string $name, callable $callback): void
    {
        $GLOBALS['bounded_public_dns_task'] = $callback;
    }
}

namespace {
""" + worker_source + """

($GLOBALS['bounded_public_dns_task'])();
echo "worker namespace ok\\n";
}
"""

        with tempfile.TemporaryDirectory() as temporary_directory:
            harness_path = Path(temporary_directory) / "worker-harness.php"
            harness_path.write_text(harness, encoding="utf-8")
            result = subprocess.run(
                ["php", str(harness_path)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("worker namespace ok", result.stdout.strip())

    def test_wrapper_task_resolves_all_candidate_helpers_in_production_namespace(self):
        task_start = self.wrapper.index("task('guard:public-dns-health'")
        worker_source = self.wrapper[: self.wrapper.index("$candidateSha =")]
        worker_source += "\n$publicDnsHelperPayload = base64_encode('test helper');\n\n"
        worker_source += self.wrapper[task_start:]
        worker_source = worker_source.removeprefix("<?php\n\n")
        worker_source = worker_source.replace(
            "declare(strict_types=1);\n\n",
            "",
            1,
        )
        harness = """<?php
declare(strict_types=1);

namespace Deployer {
    final class TestHost
    {
        public function getAlias(): string
        {
            return 'production';
        }
    }

    function currentHost(): TestHost
    {
        return new TestHost();
    }

    function deploySafeHost(string $host, string $label): string
    {
        $GLOBALS['safe_host_calls'] = ($GLOBALS['safe_host_calls'] ?? 0) + 1;

        return $host;
    }

    function deployShellArg(string $value): string
    {
        $GLOBALS['shell_arg_calls'] = ($GLOBALS['shell_arg_calls'] ?? 0) + 1;

        return escapeshellarg($value);
    }

    function get(string $name): mixed
    {
        if ($name === 'healthcheck_host') {
            return 'api.example.test';
        }

        throw new \\RuntimeException("unexpected setting: {$name}");
    }

    function run(string $command): void
    {
        $GLOBALS['bounded_public_dns_command'] = $command;
    }

    function task(string $name, callable $callback): void
    {
        $GLOBALS['bounded_public_dns_task'] = $callback;
    }
}

namespace {
""" + worker_source + """

($GLOBALS['bounded_public_dns_task'])();

if (($GLOBALS['safe_host_calls'] ?? 0) !== 1) {
    throw new \\RuntimeException('deploySafeHost was not resolved exactly once');
}
if (($GLOBALS['shell_arg_calls'] ?? 0) < 6) {
    throw new \\RuntimeException('deployShellArg was not resolved');
}
if (! str_contains(
    (string) ($GLOBALS['bounded_public_dns_command'] ?? ''),
    'https://api.example.test'
)) {
    throw new \\RuntimeException('production base URL was not assembled');
}
if (! str_contains(
    (string) ($GLOBALS['bounded_public_dns_command'] ?? ''),
    '| base64 -d | env '
)) {
    throw new \\RuntimeException('production command was not assembled');
}

echo "production worker namespace ok\n";
}
"""

        with tempfile.TemporaryDirectory() as temporary_directory:
            harness_path = Path(temporary_directory) / "production-worker-harness.php"
            harness_path.write_text(harness, encoding="utf-8")
            result = subprocess.run(
                ["php", str(harness_path)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(
            "production worker namespace ok",
            result.stdout.strip(),
        )

    def test_wrapper_uses_the_exact_current_public_dns_helper(self):
        helper_match = re.search(
            r"const BOUNDED_PUBLIC_DNS_HELPER_SHA256 = '([0-9a-f]{64})';",
            self.wrapper,
        )

        self.assertIsNotNone(helper_match)
        self.assertEqual(sha256(HELPER), helper_match.group(1))
        self.assertIn(f'helper_sha256="{sha256(HELPER)}"', self.workflow)

    def test_business_contract_failure_is_terminal_without_retry(self):
        with tempfile.TemporaryDirectory() as temporary_directory:
            temporary_path = Path(temporary_directory)
            counter = temporary_path / "curl-count"
            counter.write_text("0\n", encoding="utf-8")
            fake_curl = temporary_path / "curl"
            fake_curl.write_text(
                """#!/usr/bin/env bash
set -euo pipefail
count=$(cat "$CURL_COUNTER")
count=$((count + 1))
printf '%s\n' "$count" > "$CURL_COUNTER"
url="${!#}"
case "$url" in
  */api/healthz) body='{"ok":false}'; status=404 ;;
  */api/v0.3/flags) body='{"ok":true}'; status=200 ;;
  *personality-content-assets*)
    status=200
    if [ "$count" -le 3 ]; then
      body='{"ok":true,"personality_public_content_asset_v1":{}}'
    else
      body='{"ok":true,"personality_public_content_asset_v1":{"source_hash":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"}}'
    fi
    ;;
  *) exit 22 ;;
esac
printf '%s\n%s' "$body" "$status"
""",
                encoding="utf-8",
            )
            fake_sleep = temporary_path / "sleep"
            fake_sleep.write_text("#!/usr/bin/env bash\nexit 0\n", encoding="utf-8")
            fake_jq = temporary_path / "jq"
            fake_jq.write_text(
                """#!/usr/bin/env bash
set -euo pipefail
count=$(cat "$CURL_COUNTER")
cat >/dev/null
if [ "$count" -le 3 ]; then
  exit 1
fi
exit 0
""",
                encoding="utf-8",
            )
            fake_curl.chmod(0o700)
            fake_sleep.chmod(0o700)
            fake_jq.chmod(0o700)
            environment = {
                "PATH": f"{temporary_path}:/usr/bin:/bin",
                "CURL_COUNTER": str(counter),
                "PUBLIC_DNS_PROBE_BASE_URL": "https://api.example.test",
                "PUBLIC_DNS_PROBE_ATTEMPTS": "3",
                "PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS": "0 0",
                "PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS": "3",
                "PUBLIC_DNS_PROBE_MAX_TIME_SECONDS": "10",
            }
            result = subprocess.run(
                ["bash", str(HELPER)],
                cwd=ROOT,
                env=environment,
                capture_output=True,
                text=True,
            )

            self.assertEqual(1, result.returncode, result.stderr)
            self.assertEqual("3", counter.read_text(encoding="utf-8").strip())
            self.assertIn(
                "failed terminally: stage=public_bigfive_contract status=200 rc=1",
                result.stderr,
            )
            self.assertNotIn("retrying", result.stderr)

    def test_deploy_uses_runner_recipe_without_changing_release_revision(self):
        deploy_step = self.workflow_step(
            "Deploy production with Deployer",
            "Upload immutable candidate control receipt",
        )

        for expected in (
            'deployer_recipe="deploy.php"',
            'elif [ "${{ steps.bounded_public_dns_control.outputs.enabled }}" = "true" ]',
            'deployer_recipe="${{ steps.bounded_public_dns_control.outputs.recipe_path }}"',
            'BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_PATH="$GITHUB_WORKSPACE/deploy.php"',
            'BOUNDED_PUBLIC_DNS_HELPER_PATH="${{ steps.bounded_public_dns_control.outputs.helper_path }}"',
            'php /tmp/dep.phar "$DEPLOY_TASK" production -f "$deployer_recipe"',
            '--revision "$DEPLOY_SHA"',
        ):
            self.assertIn(expected, deploy_step)

        self.assertNotIn('--revision "$WORKFLOW_CONTROL_SHA"', deploy_step)

    def test_control_receipt_upload_exposes_no_topology(self):
        upload_step = self.workflow_step(
            "Upload bounded public-DNS control receipt",
            "Verify exact inactive candidate materialization",
        )

        self.assertIn("if: ${{ always()", upload_step)
        self.assertIn("retention-days: 30", upload_step)
        for forbidden in (
            "DEPLOY_HOST",
            "DEPLOY_PORT",
            "DEPLOY_PATH",
            "SSH_PRIVATE_KEY",
            "HEALTHCHECK_URL",
        ):
            self.assertNotIn(forbidden, upload_step)


if __name__ == "__main__":
    unittest.main()
