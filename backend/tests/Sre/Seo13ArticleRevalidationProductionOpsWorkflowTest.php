<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13ArticleRevalidationProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_requires_latest_active_release_and_immutable_preflight_receipt(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/seo-13-article-revalidation-production-ops.yml');

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_state_sha256:',
            'expected_content_set_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_RELEASE_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'gh run download "$PREFLIGHT_RUN_ID"',
            '.contract_version == "seo13.article_revalidation.production_ops.v1"',
            '.status == "PASS_PREFLIGHT"',
            '.status == "PASS_APPLY"',
            '.operator_approval_phrase',
            '.article_ids == [1,2,5,6,7,9,10,11,12,13,14,15,16]',
            '.published_revision_ids == [446,445,444,443,442,441,440,436,437,439,438,434,435]',
            '.revalidation_path_count == 14',
            '.cms_authority_write_count == 0',
            '.database_authority_write_count == 0',
            '.publication_write_count == 0',
            '.schema_write_count == 0',
            '.hreflang_write_count == 0',
            '.sitemap_eligibility_write_count == 0',
            '.llms_eligibility_write_count == 0',
            '.sitemap_cache_refresh_count == 0',
            '.llms_cache_refresh_count == 0',
            '.search_submission_count == 0',
            '.gsc_request_count == 0',
            '.url_inspection_count == 0',
            '.deploy_count == 0',
            'refresh exactly 13 zh article detail paths and /zh/articles',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'group: deploy-${{ github.repository }}-production',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertSame(1, substr_count($workflow, 'fetch-depth: 0'));
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('deploy:symlink', $workflow);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow));
        $this->assertStringNotContainsString('search-channel-queue', strtolower($workflow));
    }

    public function test_runner_uses_one_locked_preflight_and_one_bounded_revalidation_dispatch(): void
    {
        $runner = $this->readRepoFile(
            'backend/scripts/seo/seo_13_article_revalidation_production_ops.sh',
        );

        foreach ([
            'seo13.article_revalidation.production_ops.v1',
            'content-release:revalidate',
            '--type=article-taxonomy',
            '--article-ids="$article_ids"',
            '--expected-slugs="$expected_slugs"',
            '--expected-published-revision-ids="$published_revision_ids"',
            '--expected-state-sha256="$expected_state_sha256"',
            '--expected-content-set-sha256="$expected_content_set_sha256"',
            '--require-state-lock',
            '--include-index=/zh/articles',
            '--dry-run',
            '--execute',
            'article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16]',
            'published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435]',
            'revalidation_path_count: 14',
            'cms_authority_write_count: 0',
            'database_authority_write_count: 0',
            'publication_write_count: 0',
            'schema_write_count: 0',
            'hreflang_write_count: 0',
            'sitemap_eligibility_write_count: 0',
            'llms_eligibility_write_count: 0',
            'sitemap_cache_refresh_count: 0',
            'llms_cache_refresh_count: 0',
            'search_submission_count: 0',
            'gsc_request_count: 0',
            'url_inspection_count: 0',
            'deploy_count: 0',
            "write_state='indeterminate'",
            "write_state='committed'",
            "stage='revalidate_active_release_before_apply'",
            'latest_current_release="$(readlink -f "$deploy_path/current")"',
            '2>/dev/null',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $this->assertSame(2, substr_count($runner, 'php artisan content-release:revalidate'));
        $this->assertStringNotContainsString('for article_id in', $runner);
        $this->assertStringNotContainsString('php artisan migrate', $runner);
        $this->assertStringNotContainsString('queue:restart', $runner);
        $this->assertStringNotContainsString('deploy:symlink', $runner);
        $this->assertStringNotContainsString('content_md', $runner);
        $this->assertStringNotContainsString('seo_description', $runner);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
