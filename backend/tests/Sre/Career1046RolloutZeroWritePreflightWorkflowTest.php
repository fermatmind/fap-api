<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class Career1046RolloutZeroWritePreflightWorkflowTest extends TestCase
{
    public function test_workflow_is_latest_main_release_and_manifest_bound(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-rollout-zero-write-preflight.yml');

        foreach ([
            'expected_control_plane_sha:',
            'expected_release_sha:',
            'expected_release_name:',
            'expected_manifest_sha256:',
            'operator_approval_phrase:',
            'contents: read',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'git merge-base --is-ancestor "$EXPECTED_RELEASE_SHA" origin/main',
            'I explicitly approve zero-write Career 1046 rollout preflight',
            'group: deploy-${{ github.repository }}-production',
            'environment: production',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'secrets.SSH_PRIVATE_KEY',
            'vars.PRODUCTION_HEALTHCHECK_URL',
            'StrictHostKeyChecking=yes',
            'backend/scripts/career/career_1046_rollout_preflight.sh',
            'EXPECTED_CONTROL_PLANE_SHA=$q_expected_control_plane_sha',
            'WORKFLOW_RUN_ID=$q_workflow_run_id',
            'WORKFLOW_RUN_ATTEMPT=$q_workflow_run_attempt',
            '.status == "PASS_ZERO_WRITE_PREFLIGHT_CAPTURED"',
            '.automatic_retry_allowed == false',
            'if: always()',
        ] as $required) {
            $this->assertStringContainsString($required, $workflow);
        }

        $this->assertStringNotContainsString('secrets.PRODUCTION_HEALTHCHECK_URL', $workflow);
    }

    public function test_runner_captures_current_authorities_and_has_no_write_path(): void
    {
        $workflow = $this->repoFile('.github/workflows/career-1046-rollout-zero-write-preflight.yml');
        $runner = $this->repoFile('backend/scripts/career/career_1046_rollout_preflight.sh');

        foreach ([
            'career.1046_rollout.zero_write_preflight.v1',
            'EXPECTED_CONTROL_PLANE_SHA',
            'WORKFLOW_RUN_ID',
            'WORKFLOW_RUN_ATTEMPT',
            'control_plane_sha: $control_plane_sha',
            'workflow_run_id: $workflow_run_id',
            'workflow_run_attempt: $workflow_run_attempt',
            'detail-ready-1046-rollout-manifest.v1.json',
            '.current_public_detail_count == 30',
            '.clean_delta_count == 1016',
            '.target_public_total == 1046',
            'accountants-and-auditors',
            'software-developers',
            'digital-forensics-analysts',
            'computer-occupations-all-other',
            'career:audit-detail-ready-1048-candidates',
            'career:audit-canonical-eligibility',
            'career:execute-canonical-rollout-batch',
            'CareerFullReleaseLedgerProjectionService::class',
            'CareerRuntimePublishProjectionService::class',
            'CareerCanonicalRuntimeTruthExporter::class',
            'aa_projection_truth_review_authority',
            '--dry-run --no-audit-write --json',
            '/api/v0.5/career/jobs?locale=en',
            '/api/v0.5/career/directory?locale=en',
            '/api/v0.5/career-jobs/accountants-and-auditors/seo?locale=en',
            'MemAvailable snapshot only; no warm or full rollout executed',
            'search-entry eligibility only; not publication authority',
            'production_apply: false',
            'database_write: false',
            'cms_write: false',
            'publication: false',
            'warm: false',
            'deploy: false',
            'sitemap_write: false',
            'llms_write: false',
            'search_channel_action: false',
            'remote_file_write: false',
            'apply_authorized: false',
            'writes_committed: false',
            'automatic_retry_allowed: false',
        ] as $required) {
            $this->assertStringContainsString($required, $runner);
        }

        foreach ([
            ' --apply',
            'career:warm-public-authority-cache',
            'php artisan migrate',
            'queue:restart',
            'deploy:symlink',
            'indexnow',
            'googleapis',
            'mysql ',
            'psql ',
            'INSERT ',
            'UPDATE ',
            'DELETE ',
            '139.224.',
            '/var/www/fap-api',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow.$runner);
        }
    }

    public function test_control_does_not_treat_search_entry_review_as_publish_approval(): void
    {
        $runner = $this->repoFile('backend/scripts/career/career_1046_rollout_preflight.sh');

        $this->assertStringContainsString('cross_pipeline_inference_allowed: false', $runner);
        $this->assertStringContainsString('apply_allowed: false', $runner);
        $this->assertStringContainsString('rollout_apply_allowed: false', $runner);
        $this->assertStringNotContainsString('explicitly approve production apply', strtolower($runner));
    }

    private function repoFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$path);
        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
