<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13ArticleDiscoverabilityCacheRefreshProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_binds_latest_release_and_exact_read_only_preflight_receipt(): void
    {
        $workflow = $this->readRepoFile(
            '.github/workflows/seo-13-article-discoverability-cache-refresh-production-ops.yml',
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_state_sha256:',
            'expected_content_set_sha256:',
            'expected_target_set_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_RELEASE_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'gh run download "$PREFLIGHT_RUN_ID"',
            '.contract_version == "seo13.article_discoverability_cache_refresh.production_ops.v1"',
            '.status == "PASS_PREFLIGHT"',
            '.status == "PASS_APPLY"',
            '.operator_approval_phrase',
            '.article_ids == [1,2,5,6,7,9,10,11,12,13,14,15,16]',
            '.published_revision_ids == [446,445,444,443,442,441,440,436,437,439,438,434,435]',
            '.schema_released_count == 13',
            '.cache_invalidation_count == 6',
            '.cache_warm_write_count == 5',
            '.sitemap_cache_refresh_count == 4',
            '.llms_cache_refresh_count == 2',
            '.frontend_revalidation_count == 3',
            '.public_sitemap_exact_count == 13',
            '.public_llms_exact_count == 13',
            '.public_llms_full_exact_count == 13',
            '.cms_authority_write_count == 0',
            '.database_authority_write_count == 0',
            '.publication_write_count == 0',
            '.schema_write_count == 0',
            '.hreflang_write_count == 0',
            '.sitemap_eligibility_write_count == 0',
            '.llms_eligibility_write_count == 0',
            '.search_submission_count == 0',
            '.gsc_request_count == 0',
            '.url_inspection_count == 0',
            '.queue_dispatch_count == 0',
            '.deploy_count == 0',
            'refresh only six backend sitemap/llms cache keys',
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

    public function test_runner_refreshes_only_bounded_derived_cache_and_public_surfaces(): void
    {
        $runner = $this->readRepoFile(
            'backend/scripts/seo/seo_13_article_discoverability_cache_refresh_production_ops.sh',
        );

        foreach ([
            'seo13.article_discoverability_cache_refresh.production_ops.v1',
            'articles:seo13-discoverability-cache-refresh',
            '--expected-state-sha256="$expected_state_sha256"',
            '--expected-content-set-sha256="$expected_content_set_sha256"',
            '--expected-target-set-sha256="$expected_target_set_sha256"',
            '--no-authority-change',
            '--no-eligibility-change',
            '--no-hreflang',
            '--no-search',
            '--no-deploy',
            'article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16]',
            'published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435]',
            'cache_invalidation_count: 6',
            'cache_warm_write_count: 5',
            'sitemap_cache_refresh_count: 4',
            'llms_cache_refresh_count: 2',
            'frontend_revalidation_count: 3',
            'public_sitemap_exact_count: 13',
            'public_llms_exact_count: 13',
            'public_llms_full_exact_count: 13',
            'cms_authority_write_count: 0',
            'database_authority_write_count: 0',
            'publication_write_count: 0',
            'schema_write_count: 0',
            'hreflang_write_count: 0',
            'sitemap_eligibility_write_count: 0',
            'llms_eligibility_write_count: 0',
            'search_submission_count: 0',
            'gsc_request_count: 0',
            'url_inspection_count: 0',
            'queue_dispatch_count: 0',
            'deploy_count: 0',
            "write_state='indeterminate'",
            "write_state='committed'",
            "stage='revalidate_active_release_before_apply'",
            'latest_current_release="$(readlink -f "$deploy_path/current")"',
            'for surface in sitemap.xml llms.txt llms-full.txt',
            '2>/dev/null',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $this->assertSame(
            2,
            substr_count($runner, 'php artisan articles:seo13-discoverability-cache-refresh'),
        );
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
