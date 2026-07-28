<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnneagramPr23CacheOnlyResumeWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_exact_main_checks_and_runtime_config_evidence_bound(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('environment: production', $source);
        $this->assertStringContainsString('group: deploy-${{ github.repository }}-production', $source);
        $this->assertStringContainsString('test "$GITHUB_REF" = "refs/heads/main"', $source);
        $this->assertStringContainsString(
            'test "$control_plane_sha" = "$(git rev-parse origin/main)"',
            $source,
        );
        $this->assertStringContainsString('Verify required checks are green', $source);
        $this->assertStringContainsString('test "$RUNTIME_CONFIG_APPLY_RUN_ID" = "30333691762"', $source);
        $this->assertStringContainsString('test "$RUNTIME_CONFIG_APPLY_RUN_ATTEMPT" = "1"', $source);
        $this->assertStringContainsString(
            'test "$(sha256sum "$receipt" | awk \'{print $1}\')" = "$RUNTIME_CONFIG_APPLY_RECEIPT_SHA256"',
            $source,
        );
        $this->assertStringContainsString(
            'repos/fermatmind/fap-web/actions/runs/${RUNTIME_CONFIG_APPLY_RUN_ID}',
            $source,
        );
    }

    #[Test]
    public function execute_requires_immutable_preflight_phrase_and_no_intervening_execute(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('authorization_preflight_run_id:', $source);
        $this->assertStringContainsString('authorization_preflight_run_attempt:', $source);
        $this->assertStringContainsString('expected_state_fingerprint_sha256:', $source);
        $this->assertStringContainsString('Bind immutable cache-only preflight', $source);
        $this->assertStringContainsString(
            'gh run download "$AUTHORIZATION_PREFLIGHT_RUN_ID"',
            $source,
        );
        $this->assertStringContainsString('.published_count == 116', $source);
        $this->assertStringContainsString('.working_count == 116', $source);
        $this->assertStringContainsString('.approved_review_count == 116', $source);
        $this->assertStringContainsString('.authorization_phrase == $phrase', $source);
        $this->assertStringContainsString('INTERVENING_CACHE_ONLY_EXECUTE_RUN', $source);
    }

    #[Test]
    public function workflow_streams_only_the_cache_resume_runner_and_checks_exact_receipts(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString(
            'backend/scripts/deploy/enneagram_pr23_cache_only_resume.php',
            $source,
        );
        $this->assertStringContainsString('/usr/bin/timeout 5400 /usr/bin/php', $source);
        $this->assertStringContainsString('FM_ENNEAGRAM_RUNNER_EXECUTE=1', $source);
        $this->assertStringContainsString('< "$RUNNER_PATH" > "$receipt"', $source);
        $this->assertStringContainsString('.accepted_revalidation_path_count == 116', $source);
        $this->assertStringContainsString('.rejected_revalidation_path_count == 0', $source);
        $this->assertStringContainsString('.readback_batch_count == 10', $source);
        $this->assertStringContainsString('.api_read_count == 116', $source);
        $this->assertStringContainsString('.html_read_count == 116', $source);
        $this->assertStringContainsString('.backend_cache_invalidation_committed == false', $source);
        $this->assertStringContainsString('.pr23_rerun == false', $source);
        $this->assertStringContainsString(
            'AUTHORIZED_PUBLIC_PROJECTION_FINGERPRINT',
            $source,
        );
        $this->assertStringContainsString(
            'AUTHORIZED_DISCOVERABILITY_FINGERPRINT',
            $source,
        );
        $this->assertStringContainsString('AUTHORIZED_URL_SETS_SHA256', $source);
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $source);
        $this->assertStringNotContainsString('supervisorctl', $source);
        $this->assertStringNotContainsString('deploy:symlink', $source);
        $this->assertStringNotContainsString('php artisan migrate', $source);
        $this->assertStringNotContainsString('queue:restart', $source);
    }

    #[Test]
    public function routing_metadata_is_secret_backed_and_step_scoped(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString(
            'DEPLOY_HOST: ${{ secrets.PRODUCTION_DEPLOY_HOST }}',
            $source,
        );
        $this->assertStringContainsString(
            'DEPLOY_USER: ${{ secrets.PRODUCTION_DEPLOY_USER }}',
            $source,
        );
        $this->assertStringContainsString(
            'DEPLOY_PORT: ${{ secrets.PRODUCTION_DEPLOY_PORT }}',
            $source,
        );
        $this->assertStringContainsString(
            'DEPLOY_PATH: ${{ secrets.PRODUCTION_DEPLOY_PATH }}',
            $source,
        );
        $this->assertStringNotContainsString('PRODUCTION_DEPLOY_HOST: ${{ secrets.', $source);
        $this->assertStringNotContainsString('/var/www/', $source);
        $this->assertStringNotContainsString('/opt/', $source);
    }

    private function workflowSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/enneagram-pr23-cache-only-resume.yml',
        );
    }
}
