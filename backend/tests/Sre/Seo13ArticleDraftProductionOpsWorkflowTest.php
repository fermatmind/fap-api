<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13ArticleDraftProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_binds_exact_receipt_and_keeps_all_downstream_surfaces_held(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/seo-13-article-draft-production-ops.yml');

        $this->assertStringContainsString('expected_control_plane_sha:', $workflow);
        $this->assertStringContainsString('expected_release_sha:', $workflow);
        $this->assertStringContainsString('expected_release_name:', $workflow);
        $this->assertStringContainsString('preflight_run_id:', $workflow);
        $this->assertStringContainsString('preflight_run_attempt:', $workflow);
        $this->assertStringContainsString('expected_state_sha256:', $workflow);
        $this->assertStringContainsString('permissions:', $workflow);
        $this->assertStringContainsString('actions: read', $workflow);
        $this->assertStringContainsString('contents: read', $workflow);
        $this->assertSame(1, substr_count($workflow, 'fetch-depth: 0'));
        $this->assertStringContainsString('git merge-base --is-ancestor "$EXPECTED_RELEASE_SHA" origin/main', $workflow);
        $this->assertStringContainsString('gh run download "$PREFLIGHT_RUN_ID"', $workflow);
        $this->assertStringContainsString('.status == "PASS_PREFLIGHT"', $workflow);
        $this->assertStringContainsString('.cms_write_count == 0', $workflow);
        $this->assertStringContainsString('.publish_count == 0', $workflow);
        $this->assertStringContainsString('.schema_write_count == 0', $workflow);
        $this->assertStringContainsString('.hreflang_write_count == 0', $workflow);
        $this->assertStringContainsString('.search_submission_count == 0', $workflow);
        $this->assertStringContainsString('.revalidation_count == 0', $workflow);
        $this->assertStringContainsString('.sitemap_write_count == 0', $workflow);
        $this->assertStringContainsString('.llms_write_count == 0', $workflow);
        $this->assertStringContainsString('vars.PRODUCTION_DEPLOY_HOST', $workflow);
        $this->assertStringContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
        $this->assertStringNotContainsString('139.224.', $workflow);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('deploy:symlink', $workflow);
    }

    public function test_streamed_runner_locks_exact_thirteen_and_applies_only_working_revisions(): void
    {
        $runner = $this->readRepoFile('backend/scripts/seo/seo_13_article_draft_production_ops.sh');

        $this->assertStringContainsString('COHORT_LOCK_FILE_SHA256="212b4b298244ba3ed89a1a999d5ea2019332d33694e67e73093b45f275a56166"', $runner);
        $this->assertStringContainsString('TARGET_SET_SHA256="67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c"', $runner);
        $this->assertStringContainsString('CONTENT_SET_SHA256="b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e"', $runner);
        $this->assertSame(1, substr_count($runner, 'articles:update-existing-seo-content-package'));
        $this->assertStringContainsString('--slug-lock', $runner);
        $this->assertStringContainsString('--canonical-lock', $runner);
        $this->assertStringContainsString('--schema-hold', $runner);
        $this->assertStringContainsString('--hreflang-hold', $runner);
        $this->assertStringContainsString('--search-hold', $runner);
        $this->assertStringContainsString('--no-revalidation', $runner);
        $this->assertStringContainsString('--no-sitemap', $runner);
        $this->assertStringContainsString('--no-llms', $runner);
        $this->assertStringContainsString('and .articles[0].created_isolated_working_revision == true', $runner);
        $this->assertStringContainsString('and .articles[0].working_revision_status == "human_review"', $runner);
        $this->assertStringContainsString('cms_write_count: 13', $runner);
        $this->assertStringContainsString('publish_count: 0', $runner);
        $this->assertStringNotContainsString('articles:promote-existing-working-revision', $runner);
        $this->assertStringNotContainsString('articles:release-closeout', $runner);
        $this->assertStringNotContainsString('php artisan migrate', $runner);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
