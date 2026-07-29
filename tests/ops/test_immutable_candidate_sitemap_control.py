import hashlib
import re
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "deploy-production.yml"
WRAPPER = (
    ROOT
    / "backend"
    / "scripts"
    / "deploy"
    / "immutable_candidate_sitemap_control.php"
)
HELPER = (
    ROOT
    / "backend"
    / "scripts"
    / "deploy"
    / "verify_sitemap_source_cache_warm.sh"
)

CANDIDATE_SHA = "49038deb50cda789e4365ea42068832ed28d6023"
STAGING_RUN_ID = "29977064260"
CANDIDATE_RECIPE_SHA256 = (
    "e814b6ff4996669097db0f32fd3caebc1fcd05dd9015e2260016e3f4ece3c068"
)
HELPER_SHA256 = "04f9d6b6b66b0be10a4996064cdf9150e1b0f1ec300edf93f87e8c0368ea0713"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class ImmutableCandidateSitemapControlTest(unittest.TestCase):
    def setUp(self):
        self.workflow = WORKFLOW.read_text(encoding="utf-8")
        self.wrapper = WRAPPER.read_text(encoding="utf-8")

    def workflow_step(self, start_name: str, end_name: str) -> str:
        start = self.workflow.index(f"- name: {start_name}")
        end = self.workflow.index(f"- name: {end_name}", start)
        return self.workflow[start:end]

    def test_candidate_recipe_hash_matches_the_exact_reviewed_commit(self):
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
        self.assertEqual(HELPER_SHA256, sha256(HELPER))

    def test_prepare_step_is_exact_candidate_and_staging_bound(self):
        step = self.workflow_step(
            "Prepare exact immutable candidate deployment control",
            "Setup PHP",
        )

        for expected in (
            CANDIDATE_SHA,
            STAGING_RUN_ID,
            CANDIDATE_RECIPE_SHA256,
            HELPER_SHA256,
            "WORKFLOW_CONTROL_SHA: ${{ github.sha }}",
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$DEPLOY_MODE" = "standard"',
            'git merge-base --is-ancestor "$DEPLOY_SHA" "$WORKFLOW_CONTROL_SHA"',
            'test "$(git rev-parse HEAD)" = "$candidate_sha"',
            'test -z "$(git status --porcelain=v1)"',
            'git show "${WORKFLOW_CONTROL_SHA}:${wrapper_path}"',
            'git show "${WORKFLOW_CONTROL_SHA}:${helper_path}"',
            "backend-immutable-candidate-control-receipt.v1",
            "candidate_tree_unchanged: true",
            "release_overlay: false",
            "remote_control_file_write: false",
        ):
            self.assertIn(expected, self.workflow)

        for forbidden in (
            "git apply",
            "patch ",
            "sed -i",
            "cp ",
            "rsync ",
            "tee ",
        ):
            self.assertNotIn(forbidden, step)

    def test_wrapper_and_helper_hashes_are_locked_to_checked_in_bytes(self):
        step = self.workflow_step(
            "Prepare exact immutable candidate deployment control",
            "Setup PHP",
        )
        wrapper_match = re.search(r'wrapper_sha256="([0-9a-f]{64})"', step)
        classifier_match = re.search(
            r'EXPECTED_IMMUTABLE_SITEMAP_CONTROL_SHA256="([0-9a-f]{64})"',
            self.workflow,
        )

        self.assertIsNotNone(wrapper_match)
        self.assertIsNotNone(classifier_match)
        self.assertEqual(sha256(WRAPPER), wrapper_match.group(1))
        self.assertEqual(sha256(WRAPPER), classifier_match.group(1))
        self.assertEqual(wrapper_match.group(1), classifier_match.group(1))
        self.assertIn(
            f'helper_sha256="{sha256(HELPER)}"',
            step,
        )

    def test_wrapper_changes_only_the_two_sitemap_control_tasks(self):
        self.assertEqual(
            1,
            self.wrapper.count("task('seo:warm-sitemap-source-cache'"),
        )
        self.assertEqual(
            1,
            self.wrapper.count("task('healthcheck:sitemap-source'"),
        )
        self.assertEqual(2, self.wrapper.count("task('"))
        self.assertIn(
            "before('healthcheck:public-dns', 'healthcheck:sitemap-source')",
            self.wrapper,
        )
        self.assertLess(
            self.wrapper.index("require $candidateRecipe;"),
            self.wrapper.index("task('seo:warm-sitemap-source-cache'"),
        )

    def test_wrapper_streams_the_locked_helper_without_remote_file_writes(self):
        for expected in (
            "base64_encode",
            'php_bin="$(command -v {{bin/php}})"',
            'test -n "$php_bin"',
            "printf %s ",
            "| base64 -d",
            "sudo -n -u www-data -- env",
            'SITEMAP_SOURCE_WARM_PHP_BIN="$php_bin"',
            "SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS=180",
            "SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS=30",
            "SITEMAP_SOURCE_WARM_STRICT=false",
            "bash -s",
            "/api/v0.5/seo/sitemap-source",
            "backend_sitemap_generator_fallback",
        ):
            self.assertIn(expected, self.wrapper)

        for forbidden in (
            "file_put_contents",
            "mkdir(",
            "rename(",
            "unlink(",
            "copy(",
            "upload(",
            "scp ",
            "rsync ",
            "SITEMAP_SOURCE_WARM_PHP_BIN={{bin/php}}",
        ):
            self.assertNotIn(forbidden, self.wrapper)

    def test_other_candidates_keep_their_original_deployer_recipe(self):
        deploy_step = self.workflow_step(
            "Deploy production with Deployer",
            "Upload immutable candidate control receipt",
        )

        self.assertIn('deployer_recipe="deploy.php"', deploy_step)
        self.assertIn(
            'if [ "${{ steps.immutable_candidate_control.outputs.enabled }}" = "true" ]',
            deploy_step,
        )
        self.assertIn(
            'export IMMUTABLE_CANDIDATE_RECIPE_PATH="$GITHUB_WORKSPACE/deploy.php"',
            deploy_step,
        )
        self.assertIn(
            'php /tmp/dep.phar "$DEPLOY_TASK" production -f "$deployer_recipe"',
            deploy_step,
        )
        self.assertNotIn("--revision \"$WORKFLOW_CONTROL_SHA\"", deploy_step)
        self.assertIn('--revision "$DEPLOY_SHA"', deploy_step)

    def test_control_receipt_is_uploaded_without_exposing_topology(self):
        upload_step = self.workflow_step(
            "Upload immutable candidate control receipt",
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
