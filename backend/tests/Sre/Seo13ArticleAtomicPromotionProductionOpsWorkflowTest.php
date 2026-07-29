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
            'test "$EXPECTED_RELEASE_SHA" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'SEO 13 Article Review Approval Production Ops',
            'seo-13-article-review-approval-apply-${REVIEW_RUN_ID}-${REVIEW_RUN_ATTEMPT}',
            '.contract_version == "seo13.article_review_approval.production_ops.v1"',
            '.review_approval_write_count == 13',
            '.review_approval_run_id == 30231516428',
            '.review_approval_run_attempt == 1',
            '.review_approval_control_plane_sha == "685ab5bf90b7168854f6f200f058400f37bed99e"',
            '.review_approval_state_sha256 == "ab9ce8dbf7292f1630b1dc28f0c209febf936160978f44fd2ce0f355596c262d"',
            '.review_approval_revision_set_sha256 == "b6851fd8cdbacedafb5d7d3dfa30ae65320ec636c0765f90c38fc9f1f8581466"',
            'seo13.authenticated_preview_qa.v1',
            'd8ec2e4ba7bbc3c920cadcddfb7dabf5c632a006bb168c7ce51fee8b888f1fa9',
            'ffbfd7f0396a7adce52e050642bb05050e25693e092b078cd67d75efe2d7ca95',
            '320601d73f8726046ef4ee662f9025cb97334db5',
            '94e54aef74ba974e383d21c0b59b5bacdaeded13',
            '217d6bb81fdf7229df471b4aadbf3a9a2dec8fbda8d5b0fe20ab6cfdfda29e6d',
            '.authenticated_preview_status] | all(. == "passed")',
            '.rendered_h1_count] | all(. == 1)',
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
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.SSH_PRIVATE_KEY',
            'group: deploy-${{ github.repository }}-production',
            'publish exactly 13 approved working revisions',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertSame(1, substr_count($workflow, 'fetch-depth: 0'));
        $this->assertStringNotContainsString('139.224.', $workflow);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow);
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
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
            'd8ec2e4ba7bbc3c920cadcddfb7dabf5c632a006bb168c7ce51fee8b888f1fa9',
            'ffbfd7f0396a7adce52e050642bb05050e25693e092b078cd67d75efe2d7ca95',
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
            "write_state='indeterminate'",
            "write_state='committed'",
            "stage='revalidate_active_release_before_apply'",
            'latest_current_release="$(readlink -f "$deploy_path/current")"',
            "stage='command_preflight_rejected'",
            "stage='command_apply_rejected'",
            'command_error_count',
            'command_error_set_sha256',
            'command_error_codes',
            'failure_category',
            'atomic_batch_database_failed',
            'atomic_batch_validation_failed',
            'atomic_batch_runtime_failed',
            'sort_by(.article_id, .field, .code)',
            'test("^[A-Za-z0-9_.-]{1,128}$")',
            'test("^[a-z0-9_]{1,128}$")',
            'install_error_trap()',
            'install_error_trap',
            'trap - ERR',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $this->assertSame(
            2,
            substr_count($runner, 'php artisan articles:promote-existing-working-revision'),
            'The runner may perform one dry-run and one atomic apply, never 13 per-article promotions.',
        );
        $this->assertSame(
            4,
            substr_count($runner, 'install_error_trap'),
            'The runner must declare the trap installer, install it initially, and restore it after both bounded command captures.',
        );
        $this->assertSame(
            3,
            substr_count($runner, 'trap - ERR'),
            'The runner must clear the trap in its handler and around both bounded command captures.',
        );
        $this->assertStringNotContainsString('for target in', $runner);
        $this->assertStringNotContainsString('while read', $runner);
        $this->assertStringNotContainsString('articles:release-closeout', $runner);
        $this->assertStringNotContainsString('articles:discoverability-release', $runner);
        $this->assertStringNotContainsString('content-release:revalidate', $runner);
        $this->assertStringNotContainsString('php artisan migrate', $runner);
        $this->assertStringNotContainsString('queue:restart', $runner);
        $this->assertStringNotContainsString('deploy:symlink', $runner);
        $this->assertStringNotContainsString('.message', $runner);
        $this->assertStringNotContainsString('exception', strtolower($runner));
        $this->assertStringNotContainsString('content_md', $runner);
    }

    public function test_command_disables_per_article_audit_follow_up_and_discoverability_cache_flush(): void
    {
        $command = $this->readRepoFile(
            'backend/app/Console/Commands/ArticlePromoteExistingWorkingRevisionControlled.php',
        );
        $service = $this->readRepoFile('backend/app/Services/Cms/ArticlePublishService.php');

        $this->assertStringContainsString("private const SEO13_BATCH = 'seo13-20260726';", $command);
        $this->assertStringContainsString('big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61', $command);
        $this->assertStringContainsString('big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970', $command);
        $this->assertStringContainsString("'locale' => 'zh-CN'", $command);
        $this->assertStringContainsString('SEO13_COHORT_LOCK_FILE_SHA256', $command);
        $this->assertStringContainsString('lockedContentTargets()', $command);
        $this->assertStringContainsString('contentLockErrors(', $command);
        $this->assertStringContainsString('lockedPreviewTargets()', $command);
        $this->assertStringContainsString('previewLockErrors(', $command);
        $this->assertStringContainsString('content_set_working_revision_title_hash_mismatch', $this->readRepoFile(
            'backend/tests/Feature/Console/Seo13ArticleAtomicPromotionCommandTest.php',
        ));
        $this->assertStringNotContainsString("'translation_group_id' => 'article-'.\$target[0]", $command);
        $this->assertStringContainsString('promoteExistingWorkingRevisionsAtomically(', $command);
        $this->assertStringContainsString('DB::transaction(function () use (', $service);
        $this->assertStringContainsString('recordReleaseAudit: false', $service);
        $this->assertStringContainsString('invalidateDiscoverabilityCaches: false', $service);
        $this->assertStringContainsString('dispatchFollowUp: false', $service);
        $this->assertStringContainsString('assertBatchPromotionReadback', $command);
        $this->assertStringContainsString('previous_revision_not_stale', $command);
        $this->assertStringContainsString('failure_category', $command);
        $this->assertStringContainsString('QueryException', $command);
        $this->assertStringContainsString('atomic_batch_database_failed', $command);
        $this->assertStringContainsString('atomic_batch_validation_failed', $command);
        $this->assertStringContainsString('atomic_batch_runtime_failed', $command);
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
