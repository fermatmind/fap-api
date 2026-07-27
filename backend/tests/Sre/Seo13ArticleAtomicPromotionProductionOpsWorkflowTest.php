<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Seo13ArticleAtomicPromotionProductionOpsWorkflowTest extends TestCase
{
    public function test_workflow_binds_latest_main_release_and_immutable_preflight_receipt(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/seo-13-article-atomic-promotion-production-ops.yml');

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_state_sha256:',
            'expected_revision_set_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'git merge-base --is-ancestor "$EXPECTED_RELEASE_SHA" origin/main',
            'gh run download "$PREFLIGHT_RUN_ID"',
            '.status == "PASS_PREFLIGHT"',
            '.status == "FAIL_CLOSED"',
            '.publish_count == 0',
            '.publish_count == 13',
            '.schema_write_count == 0',
            '.hreflang_write_count == 0',
            '.search_submission_count == 0',
            '.revalidation_count == 0',
            '.sitemap_eligibility_write_count == 0',
            '.llms_eligibility_write_count == 0',
            '.queue_dispatch_count == 0',
            '.gsc_request_count == 0',
            '.url_inspection_count == 0',
            '.deploy_count == 0',
            'if: always()',
            'vars.PRODUCTION_DEPLOY_HOST',
            'secrets.SSH_PRIVATE_KEY',
            'publish exactly 13 approved working revisions',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertSame(1, substr_count($workflow, 'fetch-depth: 0'));
        $this->assertStringNotContainsString('139.224.', $workflow);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('deploy:symlink', $workflow);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow));
        $this->assertStringNotContainsString('search-channel-queue', strtolower($workflow));
    }

    public function test_runner_uses_the_deployed_atomic_command_and_emits_only_sanitized_receipts(): void
    {
        $runner = $this->readRepoFile('backend/scripts/seo/seo_13_article_atomic_promotion_production_ops.sh');

        foreach ([
            'seo13.article_atomic_promotion.production_ops.v1',
            'b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e',
            '67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c',
            '--batch=seo13-20260726',
            '--expected-target-count=13',
            '--expected-state-sha256="$expected_state_sha256"',
            '--expected-revision-set-sha256="$expected_revision_set_sha256"',
            '--preview-approved',
            '--schema-hold',
            '--hreflang-hold',
            '--search-hold',
            '--no-revalidation',
            '--no-sitemap',
            '--no-llms',
            '.working_revision_status] | all(. == "approved")',
            '.working_revision_status] | all(. == "published")',
            '.published_revision_id == .working_revision_id',
            'production_write_execution: true',
            'publish_count: 13',
            'schema_write_count: 0',
            'hreflang_write_count: 0',
            'search_submission_count: 0',
            'revalidation_count: 0',
            'sitemap_eligibility_write_count: 0',
            'llms_eligibility_write_count: 0',
            'queue_dispatch_count: 0',
            'gsc_request_count: 0',
            'url_inspection_count: 0',
            'deploy_count: 0',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $this->assertSame(
            2,
            substr_count($runner, 'php artisan articles:promote-existing-working-revision'),
            'The runner may perform one dry-run and one atomic apply, never 13 per-article promotions.',
        );
        $this->assertStringNotContainsString('for target in', $runner);
        $this->assertStringNotContainsString('while read', $runner);
        $this->assertStringNotContainsString('articles:release-closeout', $runner);
        $this->assertStringNotContainsString('articles:discoverability-release', $runner);
        $this->assertStringNotContainsString('content-release:revalidate', $runner);
        $this->assertStringNotContainsString('php artisan migrate', $runner);
        $this->assertStringNotContainsString('queue:restart', $runner);
        $this->assertStringNotContainsString('deploy:symlink', $runner);
    }

    public function test_command_disables_per_article_audit_follow_up_and_discoverability_cache_flush(): void
    {
        $command = $this->readRepoFile(
            'backend/app/Console/Commands/ArticlePromoteExistingWorkingRevisionControlled.php',
        );
        $service = $this->readRepoFile('backend/app/Services/Cms/ArticlePublishService.php');

        $this->assertStringContainsString("private const SEO13_BATCH = 'seo13-20260726';", $command);
        $this->assertStringContainsString('promoteExistingWorkingRevisionsAtomically(', $command);
        $this->assertStringContainsString('DB::transaction(function () use (', $service);
        $this->assertStringContainsString('recordReleaseAudit: false', $service);
        $this->assertStringContainsString('invalidateDiscoverabilityCaches: false', $service);
        $this->assertStringContainsString('dispatchFollowUp: false', $service);
        $this->assertStringContainsString('assertBatchPromotionReadback', $command);
        $this->assertStringContainsString('previous_revision_not_stale', $command);
        $this->assertStringContainsString('sitemap_eligibility_write_count', $command);
        $this->assertStringContainsString('llms_eligibility_write_count', $command);
        $this->assertStringContainsString('recordReleaseAudit = true', $service);
        $this->assertStringContainsString('invalidateDiscoverabilityCaches = true', $service);
    }

    private function readRepoFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
        $this->assertIsString($contents);

        return $contents;
    }
}
