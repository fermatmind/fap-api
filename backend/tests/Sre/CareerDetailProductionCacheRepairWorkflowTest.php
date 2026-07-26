<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerDetailProductionCacheRepairWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_remains_exact_control_plane_candidate_and_staging_bound(): void
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
        $this->assertStringContainsString(
            'test "$CANDIDATE_RELEASE_REVISION" != "88dedb58f341e6c92d07754eac7862fa3454dc7c"',
            $source,
        );
        $this->assertStringContainsString('verify_audited_runtime46_subsumed_baseline', $source);
        $this->assertStringContainsString('git merge-base --is-ancestor "$CANDIDATE_RELEASE_REVISION" origin/main', $source);
        $this->assertStringContainsString('actions/runs/${STAGING_RUN_ID}', $source);
        $this->assertStringContainsString('.head_sha == $candidate_sha', $source);
        $this->assertStringContainsString('Deploy checks (staging)', $source);
        $this->assertStringContainsString('Deploy (staging)', $source);
        $this->assertStringNotContainsString('/var/www/fap-api-staging', $source);
    }

    #[Test]
    public function same_revision_requires_a_distinct_inactive_candidate_release_path(): void
    {
        $source = $this->workflowSource();

        $this->assertStringNotContainsString(
            'test "$EXPECTED_ACTIVE_REVISION" != "$CANDIDATE_RELEASE_REVISION"',
            $source,
        );
        $this->assertStringContainsString(
            'The revisions may match when the candidate is a separately',
            $source,
        );
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'test \"\$current\" != \"\$candidate\"'));
        $this->assertGreaterThanOrEqual(3, substr_count($source, '\$current/REVISION'));
        $this->assertGreaterThanOrEqual(3, substr_count($source, "'\$EXPECTED_ACTIVE_REVISION'"));
        $this->assertGreaterThanOrEqual(3, substr_count($source, '\$candidate/REVISION'));
        $this->assertGreaterThanOrEqual(3, substr_count($source, "'\$CANDIDATE_RELEASE_REVISION'"));
    }

    #[Test]
    public function verify_only_emits_an_immutable_v2_authorization_packet(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('verify_only refuses an operator approval phrase.', $source);
        $this->assertStringContainsString('test -z "$EXPECTED_MISSING_POINTER_COUNT"', $source);
        $this->assertStringContainsString('runner_expected_missing=""', $source);
        $this->assertStringContainsString(
            'observed_missing_pointer_count="$(jq -r \'.coverage.missing_pointer_count\' "$preflight_receipt")"',
            $source,
        );
        $this->assertStringContainsString(
            '--argjson missing_pointer_count "$observed_missing_pointer_count"',
            $source,
        );
        $this->assertStringContainsString('FM_CAREER_MODE=preflight', $source);
        $this->assertStringContainsString('/usr/bin/timeout 720 /usr/bin/php', $source);
        $this->assertStringContainsString('career.candidate_exact_cache_bootstrap.v2', $source);
        $this->assertStringContainsString('career.candidate_exact_cache_bootstrap.authorization.v2', $source);
        $this->assertStringContainsString('preflight_run_id: $preflight_run_id', $source);
        $this->assertStringContainsString('preflight_run_attempt: $preflight_run_attempt', $source);
        $this->assertStringContainsString('coverage_fingerprint_sha256: $coverage_fingerprint_sha256', $source);
        $this->assertStringContainsString('offline_build_budget_ms: $offline_build_budget_ms', $source);
        $this->assertStringContainsString('retry_limit: $retry_limit', $source);
        $this->assertStringContainsString('batch_size: $batch_size', $source);
        $this->assertStringContainsString('.cache_write_count == 0', $source);
        $this->assertStringContainsString('.queue_dispatch_count == 0', $source);
        $this->assertStringContainsString('.database_write_count == 0', $source);
        $this->assertStringNotContainsString('authorization.v1', $source);
        $this->assertStringNotContainsString('bootstrap.v1', $source);
    }

    #[Test]
    public function bootstrap_requires_the_exact_successful_preflight_artifact_and_no_intervening_run(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('authorization_preflight_run_id:', $source);
        $this->assertStringContainsString('authorization_preflight_run_attempt:', $source);
        $this->assertStringContainsString('expected_coverage_fingerprint_sha256:', $source);
        $this->assertStringContainsString('Bind immutable v2 verify-only authorization', $source);
        $this->assertStringContainsString('.conclusion == "success"', $source);
        $this->assertStringContainsString('Career detail production cache repair (verify_only)', $source);
        $this->assertStringContainsString('gh run download "$AUTHORIZATION_PREFLIGHT_RUN_ID"', $source);
        $this->assertStringContainsString('artifacts/authorized-preflight/authorization-packet.json', $source);
        $this->assertStringContainsString('.coverage_fingerprint_sha256 == $coverage_fingerprint', $source);
        $this->assertStringContainsString('INTERVENING_BOOTSTRAP_RUN', $source);
        $this->assertStringContainsString('Career detail production cache repair (bootstrap_and_verify)', $source);
    }

    #[Test]
    public function bootstrap_is_v2_authorized_bounded_and_candidate_inactive(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString(
            'production Career inactive-candidate exact cache bootstrap with authorization preflight run ${AUTHORIZATION_PREFLIGHT_RUN_ID} coverage fingerprint ${EXPECTED_COVERAGE_FINGERPRINT_SHA256}',
            $source,
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, 'test "$EXPECTED_MISSING_POINTER_COUNT" -le "$MINIMUM_TARGETS"'),
        );
        $this->assertStringContainsString(
            'with offline build budget ${OFFLINE_BUILD_BUDGET_MS}ms, retry limit ${BOOTSTRAP_RETRY_LIMIT} and batch size ${BOOTSTRAP_BATCH_SIZE}',
            $source,
        );
        $this->assertStringContainsString('BOOTSTRAP_BATCH_SIZE: "50"', $source);
        $this->assertStringContainsString('OFFLINE_BUILD_BUDGET_MS: "5000"', $source);
        $this->assertStringContainsString('BOOTSTRAP_RETRY_LIMIT: "1"', $source);
        $this->assertStringContainsString('while [ "$offset" -lt "$MINIMUM_TARGETS" ]; do', $source);
        $this->assertStringContainsString('offset=$((offset + BOOTSTRAP_BATCH_SIZE))', $source);
        $this->assertStringContainsString(
            'FM_CAREER_EXPECTED_COVERAGE_FINGERPRINT=\'$expected_fingerprint\'',
            $source,
        );
        $this->assertStringContainsString('FM_CAREER_MODE=batch', $source);
        $this->assertStringContainsString('/usr/bin/timeout 720 /usr/bin/php', $source);
        $this->assertStringContainsString(
            'total_coverage_gain=$((total_owned_writes + total_concurrent_coverage_gain))',
            $source,
        );
        $this->assertStringContainsString(
            'test "$total_coverage_gain" -eq "$EXPECTED_MISSING_POINTER_COUNT"',
            $source,
        );
        $this->assertStringContainsString('test "$expected_remaining" -eq 0', $source);
        $this->assertStringContainsString('artifacts/cache-bootstrap-progress.json', $source);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'test \"\$current\" != \"\$candidate\"'));
        $this->assertGreaterThanOrEqual(3, substr_count($source, "test ! -e '\$DEPLOY_PATH/.dep/deploy.lock'"));
        $this->assertStringContainsString('sudo -n -u www-data -- env', $source);
        $this->assertStringNotContainsString('supervisorctl', $source);
        $this->assertStringNotContainsString('deploy:symlink', $source);
        $this->assertStringNotContainsString('php artisan migrate', $source);
        $this->assertStringNotContainsString('php artisan queue:', $source);
        $this->assertStringNotContainsString('php artisan search:', $source);
        $this->assertStringNotContainsString('WarmCareerJobDetailProjection', $source);
    }

    #[Test]
    public function bootstrap_advances_from_observed_monotonic_coverage_instead_of_owned_write_arithmetic(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString(
            'owned_writes="$(jq -r \'.owned_cache_write_count\' "$receipt")"',
            $source,
        );
        $this->assertStringContainsString(
            'concurrent_gain="$(jq -r \'.concurrent_coverage_gain_count\' "$receipt")"',
            $source,
        );
        $this->assertStringContainsString(
            'post_remaining="$(jq -r \'.post_batch_coverage.missing_pointer_count\' "$receipt")"',
            $source,
        );
        $this->assertStringContainsString(
            'test "$coverage_gain" -eq $((owned_writes + concurrent_gain))',
            $source,
        );
        $this->assertStringContainsString('expected_remaining="$post_remaining"', $source);
        $this->assertStringContainsString(
            'expected_fingerprint="$(jq -r \'.post_coverage_fingerprint_sha256\' "$receipt")"',
            $source,
        );
        $this->assertStringContainsString(
            '.owned_cache_write_count + .concurrent_coverage_gain_count',
            $source,
        );
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($source, '.owned_cache_write_count'),
        );
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($source, '.concurrent_coverage_gain_count'),
        );
        $this->assertStringNotContainsString(
            'post_remaining=$((expected_remaining - writes))',
            $source,
        );
        $this->assertStringNotContainsString('artifacts/total-cache-writes.txt', $source);
    }

    #[Test]
    public function single_target_diagnostic_is_preflight_bound_one_write_and_identity_redacted(): void
    {
        $source = $this->workflowSource();

        $this->assertStringContainsString('- diagnose_target', $source);
        $this->assertStringContainsString('diagnostic_target_slug:', $source);
        $this->assertStringContainsString('diagnostic_target_locale:', $source);
        $this->assertStringContainsString('execute_diagnostic_write:', $source);
        $this->assertStringContainsString(
            'production Career single-target inactive-candidate cache diagnosis with authorization preflight run ${AUTHORIZATION_PREFLIGHT_RUN_ID} coverage fingerprint ${EXPECTED_COVERAGE_FINGERPRINT_SHA256}',
            $source,
        );
        $this->assertStringContainsString('FM_CAREER_MODE=diagnose_target', $source);
        $this->assertStringContainsString('FM_CAREER_DIAGNOSTIC_WRITE=\'$diagnostic_write\'', $source);
        $this->assertStringContainsString('artifacts/single-target-diagnostic.json', $source);
        $this->assertStringContainsString('.target_index_sha256 | test("^[0-9a-f]{64}$")', $source);
        $this->assertStringContainsString('and (.target? == null)', $source);
        $this->assertStringContainsString('and (.slug? == null)', $source);
        $this->assertStringContainsString('and (.locale? == null)', $source);
        $this->assertStringContainsString('and (.message? == null)', $source);
        $this->assertStringContainsString('and (.cache_key? == null)', $source);
        $this->assertStringContainsString('and .cache_write_count == 1', $source);
        $this->assertStringContainsString('and .queue_dispatch_count == 0', $source);
        $this->assertStringContainsString('and .database_write_count == 0', $source);
    }

    #[Test]
    public function workflow_contains_no_deploy_publication_or_search_execution_surface(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'deploy:symlink',
            'php artisan migrate',
            'php artisan queue:',
            'php artisan search:',
            'indexnow',
            'baidu',
            'sitemap:generate',
            'articles:publish',
            'career:publish',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($source));
        }
    }

    #[Test]
    public function ssh_routing_is_secret_only_step_scoped_and_not_artifacted(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'PRODUCTION_DEPLOY_USER',
            'PRODUCTION_DEPLOY_PORT',
            'PRODUCTION_DEPLOY_HOST',
            'PRODUCTION_RETIRED_DEPLOY_HOST',
            'PRODUCTION_DEPLOY_PATH',
        ] as $name) {
            $this->assertStringContainsString('${{ secrets.'.$name.' }}', $source);
            $this->assertStringNotContainsString('${{ vars.'.$name.' }}', $source);
        }

        $jobEnv = preg_split('/\n    steps:\n/', $source, 2)[0] ?? '';
        foreach (['DEPLOY_USER:', 'DEPLOY_PORT:', 'DEPLOY_HOST:', 'RETIRED_DEPLOY_HOST:', 'DEPLOY_PATH:'] as $name) {
            $this->assertStringNotContainsString($name, $jobEnv);
        }
        $this->assertStringNotContainsString('known_hosts:', $source);
        $this->assertStringNotContainsString('exception_message', $source);
    }

    private function workflowSource(): string
    {
        return (string) file_get_contents(base_path('../.github/workflows/career-detail-production-cache-repair.yml'));
    }
}
