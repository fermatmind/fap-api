<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerGenerateCanonicalDeltaRolloutManifestCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:generate-canonical-delta-rollout-manifest', Artisan::all());
    }

    public function test_missing_target_delta_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => sys_get_temp_dir().'/missing-target-delta.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('target_delta_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_invalid_json_blocks(): void
    {
        $path = $this->tempPath('invalid');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand([
            '--target-delta' => $path,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('target_delta_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_generates_manifest_and_writes_output(): void
    {
        $output = $this->tempPath('manifest');
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['baseline-001'], ['delta-001', 'delta-002'], target: 3),
            '--target-public-total' => 3,
            '--expect-delta-count' => 2,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_delta_rollout_manifest.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(3, $payload['target_public_total']);
        $this->assertSame(1, $payload['published_baseline_count']);
        $this->assertSame(2, $payload['delta_slug_count']);
        $this->assertSame(['delta-001', 'delta-002'], $payload['slugs']);
        $this->assertSame($payload['slugs'], $payload['rollback_group']);
        $this->assertFileExists($output);
    }

    public function test_target_delta_blocked_blocks_manifest(): void
    {
        $path = $this->writeTargetDelta(['baseline-001'], ['delta-001'], target: 2, status: 'blocked');

        $exitCode = $this->callCommand([
            '--target-delta' => $path,
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('target_delta_not_passed', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['dry_run_allowed']);
    }

    public function test_target_delta_policy_blockers_and_apply_authority_block_manifest(): void
    {
        $path = $this->writeTargetDelta(
            ['baseline-001'],
            ['delta-001'],
            target: 2,
            extra: [
                'blockers' => [['reason' => 'audit_policy_blocked']],
                'rollout' => ['apply_allowed' => true],
            ],
        );

        $exitCode = $this->callCommand([
            '--target-delta' => $path,
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $reasons = array_column($payload['blockers'], 'reason');
        $this->assertContains('target_delta_blockers_present', $reasons);
        $this->assertContains('target_delta_apply_must_not_be_allowed', $reasons);
        $this->assertFalse($payload['dry_run_allowed']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_rejects_baseline_slug_in_delta_list(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['shared-slug'], ['shared-slug'], target: 2),
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('baseline_slug_in_delta_manifest', array_column($payload['blockers'], 'reason'));
    }

    public function test_expected_delta_locale_rows_and_batch_id_are_stable(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['baseline-001'], ['delta-001', 'delta-002'], target: 3),
            '--target-public-total' => 3,
            '--expect-delta-count' => 2,
            '--locales' => 'en,zh,es',
            '--batch-id' => 'custom-delta-batch',
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('custom-delta-batch', $payload['batch_id']);
        $this->assertSame(['en', 'es', 'zh'], $payload['locales']);
        $this->assertSame(6, $payload['expected_delta_locale_rows']);
        $this->assertSame(6, $payload['batches'][0]['expected_delta_locale_rows']);
    }

    public function test_apply_is_never_allowed_and_rollout_is_not_executed(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['baseline-001'], ['delta-001'], target: 2),
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['rollout_allowed']);
        $this->assertTrue($payload['dry_run_allowed']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['rollout_dry_run_executed']);
        $this->assertFalse($payload['rollout_apply_executed']);
    }

    public function test_candidate_prep_plan_mismatch_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeTargetDelta(['baseline-001'], ['delta-001', 'delta-002'], target: 3),
            '--candidate-prep-plan' => $this->writeCandidatePrepPlan(deltaCount: 1),
            '--target-public-total' => 3,
            '--expect-delta-count' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('candidate_prep_delta_count_mismatch', array_column($payload['blockers'], 'reason'));
    }

    public function test_generates_detail_ready_1048_manifest_with_dynamic_defaults(): void
    {
        $output = $this->tempPath('detail-ready-manifest');
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeDetailReadyTargetDelta($this->slugs('public', 30), $this->slugs('ready', 1018)),
            '--candidate-prep-plan' => $this->writeCandidatePrepPlan(deltaCount: 1018, target: 'detail_ready_1048', expectedLocaleRows: 2036),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('detail_ready_1048', $payload['target']);
        $this->assertSame('detail_ready_1048', $payload['target_key']);
        $this->assertSame(1048, $payload['target_public_total']);
        $this->assertSame(30, $payload['published_baseline_count']);
        $this->assertSame(1018, $payload['delta_slug_count']);
        $this->assertSame(2036, $payload['expected_delta_locale_rows']);
        $this->assertSame($payload['slugs'], $payload['rollback_group']);
        $this->assertSame('detail_ready_1048', $payload['target_authority']['target_key']);
        $this->assertSame('DETAIL_READY_1048_ROLLOUT_GATE_DRY_RUN', $payload['next_required_action']);
        $this->assertFileExists($output);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:generate-canonical-delta-rollout-manifest', [
            '--json' => true,
            ...$options,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $delta
     */
    private function writeTargetDelta(
        array $baseline,
        array $delta,
        int $target,
        string $status = 'pass',
        array $extra = [],
    ): string {
        sort($baseline);
        sort($delta);

        return $this->writeJson('target-delta', array_replace_recursive([
            'schema_version' => 'career_80_target_delta.v1',
            'status' => $status,
            'target_public_total' => $target,
            'published_baseline_count' => count($baseline),
            'delta_promotion_count' => count($delta),
            'published_baseline_slugs' => $baseline,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
            'rollout' => [
                'delta_manifest_allowed' => true,
                'apply_allowed' => false,
            ],
        ], $extra));
    }

    private function writeCandidatePrepPlan(int $deltaCount, ?string $target = null, ?int $expectedLocaleRows = null): string
    {
        return $this->writeJson('candidate-prep', array_filter([
            'schema_version' => 'career_runtime_candidate_prep_plan.v1',
            'status' => 'planned',
            'delta_slug_count' => $deltaCount,
            'target' => $target,
            'expected_delta_locale_rows' => $expectedLocaleRows,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $delta
     */
    private function writeDetailReadyTargetDelta(array $baseline, array $delta): string
    {
        sort($baseline);
        sort($delta);

        return $this->writeJson('detail-ready-target-delta', [
            'schema_version' => 'career_detail_ready_publication_candidates.v1',
            'status' => 'pass',
            'target_key' => 'detail_ready_1048',
            'current_public_total' => count($baseline),
            'target_public_total' => 1048,
            'published_baseline_slugs' => $baseline,
            'ready_not_public_1018' => [
                'count' => count($delta),
                'slugs' => $delta,
            ],
            'manual_hold' => [
                'ready_slugs' => [],
            ],
            'rollout' => [
                'delta_manifest_allowed' => true,
                'apply_allowed' => false,
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-delta-rollout-manifest-'.Str::uuid().'-'.$name.'.json';
    }
}
