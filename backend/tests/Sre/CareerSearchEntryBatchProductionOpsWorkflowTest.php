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
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
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
            'career_cache_refresh_status=running',
            'sleep 20',
            'for curl_attempt in 1 2',
            'if [[ "$curl_attempt" -lt 2 ]]',
            'sleep 1',
            '--connect-timeout 5 --max-time 30',
            'observed_readback_url_count',
            'observed_http_200_count',
            'observed_transport_failure_count',
            'observed_non_200_response_count',
            'observed_canonical_ok_count',
            'observed_robots_ok_count',
            'observed_locale_ok_count',
            'observed_bad_href_url_count',
            'observed_low_module_url_count',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('--max-time 20', $runner);
        $this->assertStringNotContainsString('--retry', $runner);
        $this->assertStringNotContainsString('--fail', $runner);
        $this->assertStringNotContainsString('--forget-job-detail', $workflow.$runner);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_cache_refresh_recovery_preflight_binds_indeterminate_failure_and_has_no_write_path(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-cache-refresh-recovery-preflight.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_cache_refresh_recovery_preflight.sh'
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'failed_run_id:',
            'failed_run_attempt:',
            'Career Search Entry Batch Cache Refresh Production Ops',
            'career-search-entry-batch-cache-refresh-execute-${FAILED_RUN_ID}-${FAILED_RUN_ATTEMPT}',
            '.conclusion == "failure"',
            '.failed_stage == "exact_cache_refresh"',
            '.write_state == "indeterminate"',
            '.production_write_execution == true',
            '[.workflow_runs[] | select(.id > $failed_run)] | length == 0',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'this authorizes control-plane design only and does not authorize production retry, write, rollback, deploy, or Search Channel operation',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
            'career.search_entry_batch.cache_refresh.recovery_preflight.v1',
            'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json',
            '.expected_candidate_count == 50',
            'https://api.fermatmind.com/api/v0.5/career/jobs/',
            'current_public_readback',
            'PASS_RECOVERY_RESUME_REQUIRED',
            'PASS_RECOVERY_CURRENT_STATE_COMPLETE',
            'HOLD_RECOVERY_STATE_UNCERTAIN',
            'career:build-search-entry-quality-batch',
            '--connect-timeout 5 --max-time 30',
            'for curl_attempt in 1 2',
            'if [[ "$curl_attempt" -lt 2 ]]',
            'sleep 1',
            'write_state: "none"',
            'production_write_execution: false',
            'recovery_action_authorized: false',
            'retry_execution_count: 0',
            'cache_refresh_target_count: 0',
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
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('career:warm-public-authority-cache', $workflow.$runner);
        $this->assertStringNotContainsString('--job-detail-only', $workflow.$runner);
        $this->assertStringNotContainsString('--forget-job-detail', $workflow.$runner);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_cache_refresh_resume_requires_separate_preflight_and_bounded_offline_publication(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-cache-refresh-resume.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_cache_refresh_resume.php'
        );

        foreach ([
            'mode:',
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'failed_run_id:',
            'failed_run_attempt:',
            'recovery_run_id:',
            'recovery_run_attempt:',
            'expected_manifest_sha256:',
            'expected_recovery_readback_sha256:',
            'expected_bad_href_url_count:',
            'expected_low_module_url_count:',
            'failed_preflight_run_id:',
            'failed_preflight_run_attempt:',
            'expected_failed_preflight_receipt_sha256:',
            'diagnostic_run_id:',
            'diagnostic_run_attempt:',
            'expected_diagnostic_receipt_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'expected_preflight_state_sha256:',
            'expected_resume_target_set_sha256:',
            'expected_resume_target_count:',
            'expected_resume_batch_count:',
            'operator_approval_phrase:',
            'Career Search Entry Batch Cache Refresh Recovery Preflight',
            'career-search-entry-batch-cache-refresh-recovery-preflight-${RECOVERY_RUN_ID}-${RECOVERY_RUN_ATTEMPT}',
            '.status == "PASS_RECOVERY_RESUME_REQUIRED"',
            'career-search-entry-batch-cache-refresh-resume-preflight-${FAILED_PREFLIGHT_RUN_ID}-${FAILED_PREFLIGHT_RUN_ATTEMPT}',
            '.failure_category == "PUBLIC_SNAPSHOT_INCOMPLETE"',
            'Career Search Entry Batch Cache Refresh Resume Preflight Diagnostic',
            'career-search-entry-batch-cache-refresh-resume-preflight-diagnostic-${DIAGNOSTIC_RUN_ID}-${DIAGNOSTIC_RUN_ATTEMPT}',
            '.status == "PASS_DIAGNOSTIC_COMPLETE_STATE_DRIFT"',
            '.observed_payload_set_sha256 == $payload_set',
            '.recovery_baseline_match == false',
            '[.workflow_runs[] | select(.id > $diagnostic_run)] | length == 0',
            '[.workflow_runs[] | select(.id > $diagnostic_run and .id != $current_run)] | length == 0',
            'career-search-entry-batch-cache-refresh-resume-preflight-${PREFLIGHT_RUN_ID}-${PREFLIGHT_RUN_ATTEMPT}',
            '.status == "PASS_PREFLIGHT_RESUME_REQUIRED"',
            'diagnostic-rebased cache-only resume preflight',
            'rebase, diagnose, and plan only, with zero cache write, retry, rollback, deploy, or Search Channel operation',
            'baseline_source: "diagnostic"',
            'diagnostic_payload_set_sha256: $diagnostic_payload_set',
            'EXPECTED_BASELINE_PAYLOAD_SET_SHA256',
            'diagnostic-rebased cache-only resume execute',
            'refresh exactly ${target_count} residual targets in ${batch_count} batches of at most 5 slugs and 10 URLs',
            'using the 5000ms offline build budget and zero per-target retry',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
            'career.search_entry_batch.cache_refresh.resume.v1',
            'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json',
            'MAX_BATCH_TARGETS = 10',
            'MAX_BATCH_SLUGS = 5',
            'OFFLINE_BUILD_BUDGET_MS = 5000',
            'PASS_PREFLIGHT_RESUME_REQUIRED',
            'PASS_EXECUTE_AND_READBACK',
            'FAIL_PARTIAL',
            'per_target_retry_limit',
            'warmJobDetailPayloadForOfflineBootstrap',
            'buildForSubjectSlugs',
            'installDatabaseWriteGuard',
            'DATABASE_WRITE_BLOCKED',
            'CareerSearchEntryQualityBatchPlanner',
            'EXPECTED_REVIEW_TARGETS',
            'sleep(1)',
            'sleep(2)',
            'CURLOPT_CONNECTTIMEOUT => 5',
            'CURLOPT_TIMEOUT => 30',
            'EXPECTED_BASELINE_PAYLOAD_SET_SHA256',
            'write_state',
            'cache_refresh_target_count',
            'completed_batch_count',
            'database_write_count',
            'cms_write_count',
            'publication_write_count',
            'indexability_write_count',
            'queue_dispatch_count',
            'sitemap_write_count',
            'llms_write_count',
            'search_channel_action_count',
            'url_submission_count',
            'non_target_write_count',
            'deploy_count',
            'rollback_count',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('warmJobDetailPayload(', $runner);
        $this->assertStringNotContainsString('forgetJobDetailPayload', $runner);
        $this->assertStringNotContainsString('dispatchJobDetailWarm', $runner);
        $this->assertStringNotContainsString('EXPECTED_RECOVERY_PAYLOAD_SET_SHA256', $workflow.$runner);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_resume_preflight_diagnostic_binds_failed_receipt_and_exposes_only_safe_aggregates(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-cache-refresh-resume-preflight-diagnostic.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_cache_refresh_resume.php'
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'failed_preflight_run_id:',
            'failed_preflight_run_attempt:',
            'expected_failed_preflight_receipt_sha256:',
            'operator_approval_phrase:',
            'Career Search Entry Batch Cache Refresh Resume',
            'career-search-entry-batch-cache-refresh-resume-preflight-${FAILED_PREFLIGHT_RUN_ID}-${FAILED_PREFLIGHT_RUN_ATTEMPT}',
            '.conclusion == "failure"',
            '.failure_category == "PUBLIC_SNAPSHOT_INCOMPLETE"',
            'actual_failed_receipt_sha256',
            'Career Search Entry Batch Cache Refresh Recovery Preflight',
            'career-search-entry-batch-cache-refresh-recovery-preflight-${recovery_run_id}-${recovery_run_attempt}',
            '[.workflow_runs[] | select(.id > $failed_preflight)] | length == 0',
            'inspect only safe aggregate 100-URL snapshot state',
            'zero cache write, preflight retry, execute, rollback, deploy, or Search Channel operation',
            'CAREER_CACHE_RESUME_MODE=diagnose',
            'EXPECTED_BASELINE_PAYLOAD_SET_SHA256',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
            "'diagnose'",
            'HOLD_DIAGNOSTIC_SNAPSHOT_INCOMPLETE',
            'PASS_DIAGNOSTIC_COMPLETE_BASELINE_MATCH',
            'PASS_DIAGNOSTIC_COMPLETE_STATE_DRIFT',
            'diagnostic_state_sha256',
            'observed_payload_set_sha256',
            'observed_url_count',
            'observed_http_200_count',
            'observed_transport_failure_count',
            'observed_non_200_response_count',
            'observed_canonical_ok_count',
            'observed_robots_ok_count',
            'observed_locale_ok_count',
            'observed_bad_href_url_count',
            'observed_low_module_url_count',
            'snapshot_complete',
            'recovery_baseline_match',
            "'write_state' => 'none'",
            "'production_write_execution' => false",
            "'cache_refresh_target_count' => 0",
            "'database_write_count' => 0",
            "'queue_dispatch_count' => 0",
            "'deploy_count' => 0",
            "'rollback_count' => 0",
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }
        $this->assertStringNotContainsString('exact_resume_execute_approval_phrase', $workflow);
        $this->assertStringNotContainsString('warmJobDetailPayloadForOfflineBootstrap', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_resume_execute_failure_diagnostic_is_receipt_bound_and_read_only(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-cache-refresh-resume-execute-failure-diagnostic.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_cache_refresh_resume.php'
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'failed_resume_execute_run_id:',
            'failed_resume_execute_run_attempt:',
            'expected_failed_resume_execute_receipt_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'expected_preflight_receipt_sha256:',
            'expected_manifest_sha256:',
            'expected_preflight_state_sha256:',
            'expected_resume_target_set_sha256:',
            'expected_failed_target_index_sha256:',
            'expected_observed_payload_set_sha256:',
            'operator_approval_phrase:',
            'Career Search Entry Batch Cache Refresh Resume',
            'career-search-entry-batch-cache-refresh-resume-execute-${FAILED_RESUME_EXECUTE_RUN_ID}-${FAILED_RESUME_EXECUTE_RUN_ATTEMPT}',
            'career-search-entry-batch-cache-refresh-resume-preflight-${PREFLIGHT_RUN_ID}-${PREFLIGHT_RUN_ATTEMPT}',
            '.status == "PASS_PREFLIGHT_RESUME_REQUIRED"',
            '.mode == "execute"',
            '.status == "FAIL_CLOSED"',
            '.failed_stage == "publish_cache_payload"',
            '.failure_category == "cache_publish_failed"',
            '.write_state == "none"',
            '.cache_refresh_target_count == 0',
            '.completed_batch_count == 0',
            'failed_execute_log="$(gh run view "$FAILED_RESUME_EXECUTE_RUN_ID" --log)"',
            '"PREFLIGHT_RUN_ID: ${PREFLIGHT_RUN_ID}"',
            '"PREFLIGHT_RUN_ATTEMPT: ${PREFLIGHT_RUN_ATTEMPT}"',
            '"EXPECTED_PREFLIGHT_STATE_SHA256: ${EXPECTED_PREFLIGHT_STATE_SHA256}"',
            '"EXPECTED_RESUME_TARGET_SET_SHA256: ${EXPECTED_RESUME_TARGET_SET_SHA256}"',
            'length($0) >= length(binding) && substr($0, length($0) - length(binding) + 1) == binding {',
            'END { exit(found ? 0 : 1) }',
            'failed_execute_input_binding_sha256',
            'unset failed_execute_log',
            '[.workflow_runs[] | select(.id > $failed_execute)] | length == 0',
            'inspect only safe aggregate cache runtime-identity and 100-URL snapshot state',
            'zero cache write, retry, rollback, deploy, CMS/DB write, or Search Channel operation',
            'runner_cache_readable',
            'runner_cache_writable',
            'runner_cache_tree_scan_complete',
            'runner_cache_tree_permission_ok',
            'runtime_identity_available',
            'runtime_cache_readable',
            'runtime_cache_writable',
            'runtime_cache_tree_scan_complete',
            'runtime_cache_tree_permission_ok',
            'runtime_identity_remediation_candidate',
            '/usr/bin/timeout 15s /usr/bin/find',
            '-maxdepth 3',
            '-printf issue -quit 2>/dev/null',
            'CAREER_CACHE_RESUME_MODE=diagnose',
            'PASS_DIAGNOSTIC_RUNTIME_IDENTITY_MISMATCH',
            'PASS_DIAGNOSTIC_PERMISSION_STATE_COMPLETE',
            'HOLD_DIAGNOSTIC_RUNTIME_CACHE_UNAVAILABLE',
            'HOLD_DIAGNOSTIC_SNAPSHOT_INCOMPLETE',
            'HOLD_DIAGNOSTIC_PUBLIC_STATE_DRIFT',
            'failed_execute_baseline_match',
            'career.search_entry_batch.cache_refresh.resume_execute_failure_diagnostic.v1',
            'retry_execution_count: 0',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        $this->assertStringNotContainsString("length(binding)\n               &&", $workflow);
        foreach ([
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
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        $this->assertStringNotContainsString('warmJobDetailPayloadForOfflineBootstrap', $workflow);
        $this->assertStringNotContainsString('exact_resume_execute_approval_phrase', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_resume_execute_failure_diagnostic_eligibility_recovery_is_run_bound_and_read_only(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-batch-cache-refresh-resume-execute-failure-diagnostic-eligibility-recovery.yml'
        );
        $runner = $this->repoFile(
            'backend/scripts/career/career_search_entry_batch_cache_refresh_resume.php'
        );

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'failed_diagnostic_run_id:',
            'failed_diagnostic_run_attempt:',
            'expected_failed_diagnostic_control_plane_sha:',
            'failed_resume_execute_run_id:',
            'failed_resume_execute_run_attempt:',
            'expected_failed_resume_execute_receipt_sha256:',
            'preflight_run_id:',
            'preflight_run_attempt:',
            'expected_preflight_receipt_sha256:',
            'expected_manifest_sha256:',
            'expected_preflight_state_sha256:',
            'expected_resume_target_set_sha256:',
            'expected_failed_target_index_sha256:',
            'expected_observed_payload_set_sha256:',
            'operator_approval_phrase:',
            '.name == "Career Search Entry Batch Cache Refresh Resume Execute Failure Diagnostic"',
            '.path == ".github/workflows/career-search-entry-batch-cache-refresh-resume-execute-failure-diagnostic.yml"',
            '.conclusion == "failure"',
            '.name == "eligibility"',
            '.name == "Bind latest main, failed execute receipt, and exact diagnostic authorization"',
            '.name == "diagnose"',
            '.conclusion == "skipped"',
            '(.steps | length == 0)',
            '.total_count == 0 and (.artifacts | length == 0)',
            '3e7049e6ce679ae60531bc93abd33b9c91644224298c73706e6537ac2fd1e746',
            "grep -F 'awk: cmd. line:2:'",
            "grep -F '^ syntax error'",
            'unset failed_diagnostic_log',
            'failed_diagnostic_failure_category=awk_portability_control_error',
            'failed_diagnostic_diagnose_skipped=true',
            'failed_diagnostic_artifact_count=0',
            'failed_diagnostic_workflow_sha256',
            'failed_diagnostic_eligibility_job_id',
            'failed_diagnostic_eligibility_attestation_sha256',
            '] == [$failed_diagnostic]',
            'career-search-entry-batch-cache-refresh-resume-execute-failure-diagnostic-eligibility-recovery.yml/runs?event=workflow_dispatch',
            'select(.id > $failed_diagnostic and .id != $current_run)',
            'eligibility recovery for failed diagnostic run',
            'with diagnose skipped and zero artifacts',
            'inspect only safe aggregate cache runtime-identity and 100-URL snapshot state',
            'zero cache write, retry, rollback, deploy, CMS/DB write, or Search Channel operation',
            'CAREER_CACHE_RESUME_MODE=diagnose',
            'runner_cache_tree_permission_ok',
            'runtime_cache_tree_permission_ok',
            'runtime_identity_remediation_candidate',
            'if [ \"\$runner_cache_tree_scan_complete\" = true ]',
            'elif $permissions.runner_cache_tree_scan_complete != true',
            'HOLD_DIAGNOSTIC_RUNTIME_CACHE_UNAVAILABLE',
            'PASS_DIAGNOSTIC_RUNTIME_IDENTITY_MISMATCH',
            'PASS_DIAGNOSTIC_PERMISSION_STATE_COMPLETE',
            'career.search_entry_batch.cache_refresh.resume_execute_failure_diagnostic_eligibility_recovery.v1',
            'failed_diagnostic_run_id: $failed_diagnostic_run',
            'failed_diagnostic_run_attempt: $failed_diagnostic_attempt',
            'failed_diagnostic_control_plane_sha: $failed_diagnostic_control',
            'failed_diagnostic_workflow_sha256: $failed_diagnostic_workflow',
            'failed_diagnostic_eligibility_job_id: $failed_diagnostic_job',
            'failed_diagnostic_failure_category: "awk_portability_control_error"',
            'failed_diagnostic_diagnose_skipped: true',
            'failed_diagnostic_artifact_count: 0',
            'retry_execution_count: 0',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        foreach ([
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
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
        $this->assertStringNotContainsString('warmJobDetailPayloadForOfflineBootstrap', $workflow);
        $this->assertStringNotContainsString('exact_resume_execute_approval_phrase', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$runner);
        $this->assertStringNotContainsString('queue:restart', $workflow.$runner);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$runner);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$runner));
        $this->assertStringNotContainsString('139.224.', $workflow.$runner);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$runner);
    }

    public function test_cache_permission_diagnostic_is_source_receipt_bound_bounded_and_read_only(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-cache-permission-diagnostic.yml'
        );
        $probe = $this->repoFile(
            'backend/scripts/career/career_search_entry_cache_permission_probe.php'
        );
        $runbook = $this->repoFile(
            'docs/ops/career-search-entry-cache-permission-recovery.md'
        );

        foreach ([
            'expected_control_plane_sha:',
            'source_diagnostic_run_id:',
            'expected_source_receipt_sha256:',
            'expected_source_artifact_digest:',
            'expected_source_diagnostic_state_sha256:',
            'expected_manifest_sha256:',
            'expected_preflight_state_sha256:',
            'expected_resume_target_set_sha256:',
            'expected_observed_payload_set_sha256:',
            '.status == "HOLD_DIAGNOSTIC_RUNTIME_CACHE_UNAVAILABLE"',
            '.digest == $digest',
            'inspect only bounded aggregate file-cache directory and exact stable-key permission state as deploy runner and www-data',
            'zero server write, cache write, retry, rollback, deploy, CMS/DB write, or Search Channel operation',
            'PROBE_IDENTITY_ROLE=deploy_runner php',
            'PROBE_IDENTITY_ROLE=php_runtime php',
            'sudo -n -u www-data',
            '< "$probe"',
            'PASS_PERMISSION_REPAIR_REQUIRED_FIXED_CACHE_CHAIN',
            'PASS_PERMISSION_REPAIR_REQUIRED_BOUNDED_CACHE_TREE',
            'PASS_PERMISSION_STATE_COMPLETE_NO_REPAIR',
            'repair_scope:',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        foreach ([
            'career.search_entry_batch.cache_permission_probe.v1',
            'MAX_FIRST_LEVEL_DIRECTORIES = 256',
            'MAX_SECOND_LEVEL_DIRECTORIES = 65536',
            'career:public-authority:job-detail:v3:',
            'career:public-authority:job-detail:v1:',
            'exact_stable_cache_key_count',
            'repair_candidate_count',
            'repair_candidate_set_sha256',
            'server_write_count',
            'cache_write_count',
            'database_write_count',
            'deploy_count',
            'rollback_count',
        ] as $required) {
            $this->assertStringContainsString($required, $probe);
        }

        foreach ([
            'The one authorized read-only diagnostic completed',
            'one exact production permission-write authorization',
            'one exact cache-only refresh',
            'individual PHP `chown`',
            'recursive `chmod`',
        ] as $required) {
            $this->assertStringContainsString($required, $runbook);
        }

        foreach ([
            'file_put_contents',
            'mkdir(',
            'chmod(',
            'chown(',
            'Cache::',
            'bootstrap/app.php',
            'warmJobDetailPayloadForOfflineBootstrap',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $probe);
        }
        $this->assertStringNotContainsString('php artisan migrate', $workflow.$probe);
        $this->assertStringNotContainsString('queue:restart', $workflow.$probe);
        $this->assertStringNotContainsString('deploy:symlink', $workflow.$probe);
        $this->assertStringNotContainsString('indexnow', strtolower($workflow.$probe));
        $this->assertStringNotContainsString('googleapis', strtolower($workflow.$probe));
        $this->assertStringNotContainsString('139.224.', $workflow.$probe);
        $this->assertStringNotContainsString('/var/www/fap-api', $workflow.$probe);
    }

    public function test_cache_permission_repair_is_exact_diagnostic_bound_non_recursive_and_payload_preserving(): void
    {
        $workflow = $this->repoFile(
            '.github/workflows/career-search-entry-cache-permission-repair.yml'
        );
        $repair = $this->repoFile(
            'backend/scripts/career/career_search_entry_cache_permission_repair.php'
        );
        $runbook = $this->repoFile(
            'docs/ops/career-search-entry-cache-permission-recovery.md'
        );

        foreach ([
            'expected_control_plane_sha:',
            'permission_diagnostic_run_id:',
            'expected_permission_diagnostic_receipt_sha256:',
            'expected_permission_diagnostic_artifact_digest:',
            'failed_repair_run_id:',
            'expected_failed_repair_receipt_sha256:',
            'expected_failed_repair_artifact_digest:',
            'expected_repair_candidate_count:',
            'expected_repair_candidate_set_sha256:',
            '.status == "PASS_PERMISSION_REPAIR_REQUIRED_BOUNDED_CACHE_TREE"',
            '.repair_scope == "attested_hash_directories_and_exact_stable_key_files"',
            '.deploy_runner.hash_directory_policy_mismatch_count == 9432',
            '.php_runtime.hash_directory_policy_mismatch_count == 9432',
            '.deploy_runner.existing_target_file_count == 100',
            '.php_runtime.existing_target_file_count == 100',
            '.status == "FAIL_PERMISSION_REPAIR"',
            '.permission_metadata_write_count == 0',
            '.pre_deploy_runner.second_level_hash_directory_count == 9198',
            '.pre_php_runtime.second_level_hash_directory_count == 9198',
            'at execution permit only up to 256 additional well-formed hash directories',
            'no additional stable-key files',
            'change only owner/group/mode metadata for the live dual-attested set',
            'zero-candidate verification and unchanged cache payload aggregate',
            'zero retry, rollback, deploy, CMS/DB write, or Search Channel operation',
            'prior_repairs',
            '[.workflow_runs[] | select(.id != $current) | .id] == [$failed]',
            'Re-attest exact dual-identity repair set',
            'Apply exact non-recursive permission metadata repair',
            'Verify dual-identity zero-candidate state',
            'sudo -n -- env',
            'PERMISSION_REPAIR_APPLY=true',
            'PASS_PERMISSION_REPAIR_VERIFIED',
            'permission_metadata_write_count == .live_repair_candidate_count',
            'LIVE_REPAIR_CANDIDATE_COUNT',
            'LIVE_REPAIR_CANDIDATE_SET_SHA256',
            '.second_level_hash_directory_count >= 9198',
            '.second_level_hash_directory_count <= 9454',
            'cache_payload_write_count == 0',
            'group: deploy-${{ github.repository }}-production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'ServerAliveInterval=20',
            'ServerAliveCountMax=30',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        foreach ([
            'career.search_entry_batch.cache_permission_repair.v1',
            'MAX_FIRST_LEVEL_DIRECTORIES = 256',
            'MAX_SECOND_LEVEL_DIRECTORIES = 65536',
            'DIRECTORY_MODE = 02775',
            'FILE_MODE = 0664',
            'EXPECTED_REPAIR_CANDIDATE_COUNT',
            'EXPECTED_REPAIR_CANDIDATE_SET_SHA256',
            'EXPECTED_STABLE_FILE_COUNT',
            'ROOT_IDENTITY_REQUIRED',
            'REPAIR_SET_DRIFT',
            'REPAIR_TARGET_DRIFT',
            'PERMISSION_METADATA_WRITE_FAILED',
            'POST_REPAIR_VERIFY_FAILED',
            'chown($candidate[\'path\'], $ids[\'uid\'])',
            'chgrp($candidate[\'path\'], $ids[\'gid\'])',
            'chmod($candidate[\'path\'], $candidate[\'directory\'] ? DIRECTORY_MODE : FILE_MODE)',
            'pre_repair_payload_set_sha256',
            'post_repair_payload_set_sha256',
            'payload_unchanged',
            'post_repair_candidate_count',
            'cache_payload_write_count',
            'rollback_count',
        ] as $required) {
            $this->assertStringContainsString($required, $repair);
        }

        foreach ([
            'PASS_PERMISSION_REPAIR_REQUIRED_BOUNDED_CACHE_TREE',
            '9,532 nodes',
            'd7d4911bdfaccdb26117e48c3608f87a2a96e66977fc8f1800c92ee7ff4edd54',
            '30585526098',
            'c8eda9c16ac765642c2ca88961bdde5a121ccdb0dbd03cbc6be8a181e268d7c2',
            '0ad0272441088dbf22516e5727fbb632c9d656c074e7ff58763196276a223e17',
            'at most 256 additional hash-directory candidates',
            'one exact production',
            'one exact cache-only refresh',
        ] as $required) {
            $this->assertStringContainsString($required, $runbook);
        }

        foreach ([
            ' -R ',
            'find -exec',
            'glob(',
            'shell_exec',
            'exec(',
            'system(',
            'passthru(',
            'file_put_contents',
            'Cache::',
            'bootstrap/app.php',
            'warmJobDetailPayloadForOfflineBootstrap',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $repair);
        }
        foreach ([
            'php artisan migrate',
            'queue:restart',
            'deploy:symlink',
            'indexnow',
            'googleapis',
            '139.224.',
            '/var/www/fap-api',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($workflow.$repair));
        }
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
