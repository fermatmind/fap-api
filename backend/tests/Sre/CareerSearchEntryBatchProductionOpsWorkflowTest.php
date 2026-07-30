<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class CareerSearchEntryBatchProductionOpsWorkflowTest extends TestCase
{
    public function test_review_workflow_requires_exact_preflight_and_holds_apply_and_release_surfaces(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-review-production-ops.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_review_production_ops.sh'
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_state_sha256:',
            'expected_quality_package_sha256:',
            'expected_review_package_sha256:',
            'expected_target_set_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'git merge-base --is-ancestor "$EXPECTED_RELEASE_SHA" origin/main',
            'gh run download "$PREFLIGHT_RUN_ID"',
            '.status == "PASS_PREFLIGHT"',
            'expected_state_sha256: ${{ steps.validate.outputs.expected_state_sha256 }}',
            'EXPECTED_STATE_SHA256: ${{ needs.eligibility.outputs.expected_state_sha256 }}',
            'EXPECTED_QUALITY_PACKAGE_SHA256: ${{ needs.eligibility.outputs.expected_quality_package_sha256 }}',
            'EXPECTED_REVIEW_PACKAGE_SHA256: ${{ needs.eligibility.outputs.expected_review_package_sha256 }}',
            'EXPECTED_TARGET_SET_SHA256: ${{ needs.eligibility.outputs.expected_target_set_sha256 }}',
            'bind exactly 300 approved-all review targets',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
            'career.search_entry_batch.review.production_ops.v1',
            'career:build-search-entry-quality-batch',
            'career:review-search-entry-quality-batch',
            '--bind',
            'stage="exact_preflight_receipt_match"',
            '.preflight_state_sha256 == $state',
            '.quality_package_sha256 == $quality',
            '.review_package_sha256 == $review',
            '.target_set_sha256 == $targets',
            '.candidate_count == 50',
            '.bilingual_url_count == 100',
            '.review_target_count == 300',
            'publication_write_count: 0',
            'indexability_write_count: 0',
            'cache_write_count: 0',
            'queue_dispatch_count: 0',
            'sitemap_write_count: 0',
            'llms_write_count: 0',
            'search_channel_action_count: 0',
            'url_submission_count: 0',
            'deploy_count: 0',
            'readlink -f "$deploy_path/current"',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_apply_workflow_binds_review_and_preflight_receipts_and_uses_exact_public_readback(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-apply-production-ops.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_apply_production_ops.sh'
        );

        foreach ([
            'review_bind_run_id:',
            'review_bind_run_attempt:',
            'expected_review_evidence_sha256:',
            'expected_apply_receipt_sha256:',
            'expected_rollback_authorization_sha256:',
            'Career Search Entry Batch Review Production Ops',
            'career-search-entry-batch-review-bind-${REVIEW_BIND_RUN_ID}-${REVIEW_BIND_RUN_ATTEMPT}',
            'gh run download "$PREFLIGHT_RUN_ID"',
            '.status == "PASS_BIND"',
            '.status == "PASS_PREFLIGHT"',
            'append exactly one apply receipt for 50 slugs and 100 bilingual URLs',
            'append exactly one rollback receipt',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
            'career.search_entry_batch.apply.production_ops.v1',
            'career:control-search-entry-quality-batch',
            '--mode=readback',
            'revalidate_active_release_before_write',
            'latest_current_release="$(readlink -f "$deploy_path/current")"',
            '.cache_backed_detail_readback_count == 100',
            '.cache_backed_index_readback_count == 100',
            'https://api.fermatmind.com/api/v0.5/career/jobs/',
            'https://fermatmind.com/sitemaps/career',
            'test "$public_detail_readback_count" -eq 100',
            'test "$sitemap_membership_readback_count" -eq 100',
            'publication_write_count: 0',
            'indexability_write_count: 0',
            'cache_write_count: 0',
            'queue_dispatch_count: 0',
            'sitemap_write_count: 0',
            'llms_write_count: 0',
            'search_channel_action_count: 0',
            'url_submission_count: 0',
            'non_target_write_count: 0',
            'deploy_count: 0',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_cache_refresh_workflow_is_exact_preflight_bound_and_cache_only(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-cache-refresh-production-ops.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_cache_refresh_production_ops.sh'
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_manifest_sha256:',
            'expected_pre_refresh_readback_sha256:',
            'expected_bad_href_url_count:',
            'expected_low_module_url_count:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'operator_approval_phrase:',
            'Career Search Entry Batch Cache Refresh Production Ops',
            'career-search-entry-batch-cache-refresh-preflight-${PREFLIGHT_RUN_ID}-${PREFLIGHT_RUN_ATTEMPT}',
            '.status == "PASS_PREFLIGHT_REFRESH_REQUIRED"',
            'refresh exactly 50 slugs and 100 bilingual detail cache targets',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
            'career.search_entry_batch.cache_refresh.production_ops.v1',
            'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json',
            '.expected_candidate_count == 50',
            'https://api.fermatmind.com/api/v0.5/career/jobs/',
            'pre_refresh_public_readback',
            'bound_pre_refresh_state',
            'career:warm-public-authority-cache',
            '--job-detail-locales=en,zh-CN',
            '--job-detail-only',
            'post_refresh_exact_package',
            'career:build-search-entry-quality-batch',
            'post_refresh_public_readback',
            '.bad_href_url_count == 0',
            '.low_module_url_count == 0',
            'cache_refresh_target_count: 100',
            'database_write_count: 0',
            'cms_write_count: 0',
            'publication_write_count: 0',
            'indexability_write_count: 0',
            'queue_dispatch_count: 0',
            'sitemap_write_count: 0',
            'llms_write_count: 0',
            'search_channel_action_count: 0',
            'url_submission_count: 0',
            'non_target_write_count: 0',
            'deploy_count: 0',
            'rollback_count: 0',
            'write_state="indeterminate"',
            'write_state="committed"',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('--forget-job-detail', $workflow.$runner);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
