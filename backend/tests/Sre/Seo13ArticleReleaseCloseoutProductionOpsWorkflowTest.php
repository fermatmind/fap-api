<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13ArticleReleaseCloseoutProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_is_exact_latest_main_read_only_closeout_control(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/seo-13-article-release-closeout-production-ops.yml');

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'operator_approval_phrase:',
            'actions/checkout@df4cb1c069e1874edd31b4311f1884172cec0e10',
            'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_RELEASE_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'I explicitly approve read-only SEO 13 release closeout',
            'seo13.article_release_closeout.production_ops.v1',
            '.status == "PASS_CLOSEOUT"',
            '.article_ids == [1,2,5,6,7,9,10,11,12,13,14,15,16]',
            '.published_revision_ids == [446,445,444,443,442,441,440,436,437,439,438,434,435]',
            '.old_published_revision_ids == [341,347,5,6,7,9,10,30,31,32,33,34,35]',
            '.public_api_readback_count == 13',
            '.public_html_readback_count == 13',
            '.public_sitemap_exact_count == 13',
            '.public_llms_exact_count == 13',
            '.public_llms_full_exact_count == 13',
            '.production_write_execution == false',
            '.search_submission_count == 0',
            '.gsc_request_count == 0',
            '.url_inspection_count == 0',
            '.queue_dispatch_count == 0',
            '.deploy_count == 0',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'group: deploy-${{ github.repository }}-production',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('deploy:symlink', $workflow);
    }

    public function test_runner_checks_authority_api_html_and_discoverability_without_writes(): void
    {
        $runner = $this->readRepoFile(
            'backend/scripts/seo/seo_13_article_release_closeout_production_ops.sh',
        );

        foreach ([
            'seo13.article_release_closeout.production_ops.v1',
            'articles:seo13-release-closeout --json',
            'SEO13_RELEASE_CLOSEOUT_COMPLETE_MONITORING_PENDING',
            '.search_hold.queue_item_count == 0',
            '.search_hold.indexnow_submission_count == 0',
            '.search_hold.baidu_submission_count == 0',
            '.cannibalization.ok == true',
            'https://api.fermatmind.com/api/v0.5/articles/${slug}',
            'https://fermatmind.com/sitemap.xml',
            'https://fermatmind.com/llms.txt',
            'https://fermatmind.com/llms-full.txt',
            'public_api_readback_count: $public_api_count',
            'public_html_readback_count: $public_html_count',
            'public_sitemap_exact_count: $public_sitemap_exact_count',
            'public_llms_exact_count: $public_llms_exact_count',
            'public_llms_full_exact_count: $public_llms_full_exact_count',
            'monitoring_windows: ["D1","D7","D14","D28"]',
            'production_write_execution: false',
            'cms_authority_write_count: 0',
            'database_authority_write_count: 0',
            'publication_write_count: 0',
            'schema_write_count: 0',
            'hreflang_write_count: 0',
            'revalidation_count: 0',
            'sitemap_eligibility_write_count: 0',
            'llms_eligibility_write_count: 0',
            'search_submission_count: 0',
            'gsc_request_count: 0',
            'url_inspection_count: 0',
            'queue_dispatch_count: 0',
            'deploy_count: 0',
            '2>/dev/null',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $this->assertStringNotContainsString('php artisan migrate', $runner);
        $this->assertStringNotContainsString('queue:restart', $runner);
        $this->assertStringNotContainsString('deploy:symlink', $runner);
        $this->assertStringNotContainsString('content_md:', $runner);
        $this->assertStringNotContainsString('seo_description:', $runner);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
