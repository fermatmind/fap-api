<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerDetailProductionCacheRepairWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_exact_control_plane_candidate_and_staging_bound(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('environment: production', $source);
        $this->assertStringContainsString('group: deploy-${{ github.repository }}-production', $source);
        $this->assertStringContainsString('timeout-minutes: 90', $source);
        $this->assertStringContainsString('actions: read', $source);
        $this->assertStringContainsString('test "$GITHUB_REF" = "refs/heads/main"', $source);
        $this->assertStringContainsString('ref: ${{ github.sha }}', $source);
        $this->assertStringContainsString('test "$control_plane_sha" = "$GITHUB_SHA"', $source);
        $this->assertStringContainsString('test "$control_plane_sha" = "$(git rev-parse origin/main)"', $source);
        $this->assertStringContainsString('test "$runner_sha256" = "$EXPECTED_RUNNER_SHA256"', $source);
        $this->assertStringContainsString('test "$EXPECTED_ACTIVE_REVISION" != "$CANDIDATE_RELEASE_REVISION"', $source);
        $this->assertStringContainsString('git merge-base --is-ancestor "$EXPECTED_ACTIVE_REVISION" "$CANDIDATE_RELEASE_REVISION"', $source);
        $this->assertStringContainsString(
            'AUDITED_RUNTIME46_PRODUCTION_SHA="bc0ed833bc9aae1473ab37f1dead2517e1aff618"',
            $source,
        );
        $this->assertStringContainsString(
            'AUDITED_RUNTIME46_BRIDGE_SHA="49038deb50cda789e4365ea42068832ed28d6023"',
            $source,
        );
        $this->assertStringContainsString(
            'git merge-base --is-ancestor "$AUDITED_RUNTIME46_BRIDGE_SHA" "$CANDIDATE_RELEASE_REVISION"',
            $source,
        );
        $this->assertStringContainsString('verify_audited_runtime46_subsumed_baseline', $source);
        $this->assertSame(5, substr_count($source, '$\'A\\tbackend/'));
        $this->assertSame(1, substr_count($source, '$\'M\\tbackend/'));
        $this->assertStringContainsString('[ "$production_blob" = "$candidate_blob" ]', $source);
        $this->assertStringContainsString('git merge-base --is-ancestor "$CANDIDATE_RELEASE_REVISION" origin/main', $source);
        $this->assertStringContainsString('actions/runs/${STAGING_RUN_ID}', $source);
        $this->assertStringContainsString('.head_sha == $candidate_sha', $source);
        $this->assertStringContainsString('Deploy checks (staging)', $source);
        $this->assertStringContainsString('Deploy (staging)', $source);
        $this->assertStringNotContainsString('/var/www/fap-api-staging', $source);
    }

    #[Test]
    public function verify_only_streams_the_versioned_runner_and_performs_zero_writes(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('verify_only refuses an operator approval phrase.', $source);
        $this->assertStringContainsString('FM_CAREER_MODE=preflight', $source);
        $this->assertStringContainsString('< "$RUNNER_PATH" > artifacts/preflight.json', $source);
        $this->assertStringContainsString('/usr/bin/timeout 600 /usr/bin/php', $source);
        $this->assertStringContainsString('.cache_write_count == 0', $source);
        $this->assertStringContainsString('.queue_dispatch_count == 0', $source);
        $this->assertStringContainsString('.database_write_count == 0', $source);
        $this->assertStringContainsString('career.candidate_exact_cache_bootstrap.authorization.v1', $source);
        $this->assertStringContainsString('control_plane_sha: $control_plane_sha', $source);
        $this->assertStringContainsString('runner_sha256: $runner_sha256', $source);
        $this->assertStringNotContainsString('direct_repair', $source);
        $this->assertStringNotContainsString('enqueue_and_wait', $source);
        $this->assertStringNotContainsString('--repair-missing-direct', $source);
        $this->assertStringNotContainsString('--repair-missing-sync', $source);
    }

    #[Test]
    public function bootstrap_mode_is_exactly_authorized_batched_and_candidate_inactive(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('- bootstrap_and_verify', $source);
        $this->assertStringContainsString(
            'production Career inactive-candidate exact cache bootstrap with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} runner SHA256 ${EXPECTED_RUNNER_SHA256}',
            $source,
        );
        $this->assertStringContainsString(
            'candidate-code synchronous cache-only batches, no active default worker/queue/CMS/DB-authority/publication/indexability/sitemap/llms/search/candidate activation.',
            $source,
        );
        $this->assertStringContainsString(
            'for offset in 0 250 500 750 1000 1250 1500 1750 2000; do',
            $source,
        );
        $this->assertStringContainsString('FM_CAREER_MODE=batch', $source);
        $this->assertStringContainsString('FM_CAREER_BATCH_SIZE=\'$BOOTSTRAP_BATCH_SIZE\'', $source);
        $this->assertStringContainsString('test "$total_writes" -eq "$EXPECTED_MISSING_POINTER_COUNT"', $source);
        $this->assertStringContainsString('test "$expected_remaining" -eq 0', $source);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'test \"\$current\" != \"\$candidate\"'));
        $this->assertGreaterThanOrEqual(3, substr_count($source, "test ! -e '\$DEPLOY_PATH/.dep/deploy.lock'"));
        $this->assertStringContainsString('sudo -n -u www-data -- env', $source);
        $this->assertStringContainsString('sudo -n -u www-data -- /usr/bin/timeout 600 /usr/bin/php artisan', $source);
        $this->assertStringContainsString('.covered_target_count == $expected_targets', $source);
        $this->assertStringContainsString('and .missing_count == 0', $source);
        $this->assertStringContainsString('and .broken_count == 0', $source);
        $this->assertStringNotContainsString('supervisorctl', $source);
        $this->assertStringNotContainsString('deploy:symlink', $source);
        $this->assertStringNotContainsString('php artisan migrate', $source);
        $this->assertStringNotContainsString('php artisan queue:', $source);
        $this->assertStringNotContainsString('php artisan search:', $source);
        $this->assertStringNotContainsString('WarmCareerJobDetailProjection', $source);
    }

    private function workflowSource(): string
    {
        return (string) file_get_contents(base_path('../.github/workflows/career-detail-production-cache-repair.yml'));
    }
}
