<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Career1046PublicationControlWorkflowTest extends TestCase
{
    public function test_workflow_binds_latest_main_active_release_and_immutable_receipts(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-publication-control.yml');

        foreach ([
            'options: [verify, apply, incident_assessment]',
            'options: [canary, batch]',
            'options: [rollback, quarantine]',
            'expected_control_plane_sha:',
            'expected_active_revision:',
            'expected_active_release_name:',
            'expected_manifest_sha256:',
            'expected_batch_slug_set_sha256:',
            'expected_rollback_group_sha256:',
            'expected_preflight_receipt_sha256:',
            'expected_preflight_artifact_digest:',
            'expected_verify_receipt_sha256:',
            'expected_verify_artifact_digest:',
            'expected_before_projection_sha256:',
            'expected_failed_apply_receipt_sha256:',
            'expected_failed_apply_artifact_digest:',
            'operator_approval_phrase:',
            'actions: read',
            'contents: read',
            'group: deploy-${{ github.repository }}-production',
            'cancel-in-progress: false',
            'environment: production',
            'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
            'git merge-base --is-ancestor "$EXPECTED_ACTIVE_REVISION" "$CONTROL_PLANE_SHA"',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'StrictHostKeyChecking=yes',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
    }

    public function test_verify_requires_pass_preflight_and_all_aa_gates(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-publication-control.yml');

        foreach ([
            '.github/workflows/career-1046-rollout-zero-write-preflight.yml',
            'career-1046-rollout-zero-write-preflight-${PREFLIGHT_RUN_ID}-${PREFLIGHT_RUN_ATTEMPT}',
            '.status == "PASS_ZERO_WRITE_PREFLIGHT_CAPTURED"',
            '.production_read_only_observations.aa_projection_truth_review_authority.valid == true',
            '.production_read_only_observations.detail_ready_scan.valid == true',
            '.production_read_only_observations.aa_eligibility.valid == true',
            '.production_read_only_observations.aa_eligibility.summary.blocked_count == 0',
            '.production_read_only_observations.aa_rollout_dry_run.summary.status == "planned"',
            '.production_read_only_observations.aa_public_surface.seo_authority_http.en == "200"',
            '.production_read_only_observations.aa_public_surface.seo_authority_http.zh_CN == "200"',
            '.negative_guarantees.database_write == false',
            '.negative_guarantees.publication == false',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }
    }

    public function test_apply_is_exact_verify_bound_and_has_no_automatic_retry(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-publication-control.yml');

        foreach ([
            'career-1046-publication-control-verify-${VERIFY_RUN_ID}-${VERIFY_RUN_ATTEMPT}',
            '.before_projection_sha256 == $before',
            '.batch_slug_set_sha256 == $batch_sha',
            '.rollback_group_sha256 == $rollback',
            '.failure_policy == $policy',
            'test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"',
            'execute exactly one SHA-bound batch',
            'authorize one receipt-bound ${FAILURE_POLICY} remediation only if post-commit validation fails',
            'do not automatically retry an ambiguous run',
            'Execute bounded remote control without automatic retry',
            'HOLD_AMBIGUOUS_DISCONNECT',
            'incident_assessment_only',
            'automatic_retry_allowed: false',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        foreach (['--retry ', '--retry-all-errors', 'cancel-in-progress: true'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }
    }

    public function test_runner_enforces_manifest_sequence_and_materializes_ledger_before_projection(): void
    {
        $runner = $this->repoFile('backend/scripts/operations/career_1046_publication_control.php');

        foreach ([
            'career.1046.publication_control.runner.v1',
            'private const ALLOWED_BATCH_SIZES = [10, 25, 50, 100]',
            "private const CANARY_SLUG = 'accountants-and-auditors'",
            'count($baseline) !== 30',
            'count($delta) !== 1016',
            'count($target) !== 1046',
            '$rollbackGroup !== $delta',
            'BATCH_NOT_NEXT_CONTIGUOUS_DELTA',
            'AA_CANARY_NOT_PUBLISHED',
            'LIVE_1046_AUTHORITY_INCOMPLETE',
            'ARTIFACT_LIVE_PUBLISHED_SET_DRIFT',
            'LOADER_ARTIFACT_PUBLISHED_SET_DRIFT',
            'PUBLISHED_LOCALE_ROW_PARITY_DRIFT',
            'EXTRA_PUBLISHED_CANONICAL_SLUGS',
            "'--dry-run' => true",
            "'--no-audit-write' => true",
            "'career:execute-canonical-rollout-batch'",
            "'career:export-full-release-ledger'",
            "'career:export-runtime-publish-projection'",
            "'--ledger' => \$ledgerPath",
            'POST_COMMIT_RUNTIME_READBACK_DRIFT',
            "'automatic_retry_allowed' => false",
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        $ledgerOffset = strpos($runner, "Artisan::call('career:export-full-release-ledger'");
        $projectionOffset = strpos($runner, "Artisan::call('career:export-runtime-publish-projection'");
        $this->assertIsInt($ledgerOffset);
        $this->assertIsInt($projectionOffset);
        $this->assertLessThan($projectionOffset, $ledgerOffset);
    }

    public function test_runner_keeps_deploy_migration_and_search_submission_out_of_scope(): void
    {
        $runner = $this->repoFile('backend/scripts/operations/career_1046_publication_control.php');

        foreach ([
            "'deploy_count' => 0",
            "'migration_count' => 0",
            "'sitemap_submission_count' => 0",
            "'llms_submission_count' => 0",
            "'search_submission_count' => 0",
            "'required_next_action' => 'incident_assessment_only'",
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        foreach ([
            "Artisan::call('migrate'",
            'php artisan migrate',
            'indexnow',
            'googleapis',
            'sitemap:submit',
            'queue:restart',
            'deploy:symlink',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($runner));
        }
    }

    public function test_runbook_preserves_task_three_and_search_channel_boundaries(): void
    {
        $runbook = $this->repoFile('docs/operations/career-1046-publication-control.md');

        foreach ([
            'new controlled publication',
            '2092 locale rows',
            '`PASS_CANARY_READY`',
            '`PASS_BATCH_READY`',
            '`HOLD_AMBIGUOUS_DISCONNECT`',
            '`automatic_retry_allowed=false`',
            'detail coverage 2092/2092',
            'directory-only rebuild',
            'sitemap-source warm',
            'Search Channel',
        ] as $required) {
            $this->assertStringContainsString($required, $runbook);
        }
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
