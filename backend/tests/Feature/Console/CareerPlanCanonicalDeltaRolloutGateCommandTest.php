<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonicalDeltaRolloutGateCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-delta-rollout-gate', Artisan::all());
    }

    public function test_missing_manifest_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--manifest' => sys_get_temp_dir().'/missing-delta-rollout-manifest.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('delta_rollout_manifest_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_invalid_json_blocks(): void
    {
        $path = $this->tempPath('invalid');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand(['--manifest' => $path]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('delta_rollout_manifest_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_outputs_delta_rollout_gate_and_writes_file(): void
    {
        $output = $this->tempPath('gate');
        $exitCode = $this->callCommand([
            '--manifest' => $this->writeManifest(['baseline-001'], ['delta-001', 'delta-002'], target: 3),
            '--target-public-total' => 3,
            '--expect-delta-count' => 2,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_delta_rollout_gate.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(3, $payload['target_public_total']);
        $this->assertSame(1, $payload['published_baseline_count']);
        $this->assertSame(2, $payload['delta_slug_count']);
        $this->assertSame(4, $payload['expected_delta_locale_rows']);
        $this->assertSame(['delta-001', 'delta-002'], $payload['delta_slugs']);
        $this->assertSame($payload['delta_slugs'], $payload['rollback_group']);
        $this->assertTrue($payload['future_rollout_dry_run']['allowed']);
        $this->assertFalse($payload['future_rollout_dry_run']['apply_allowed']);
        $this->assertFileExists($output);
    }

    public function test_rejects_baseline_slug_in_delta_list(): void
    {
        $exitCode = $this->callCommand([
            '--manifest' => $this->writeManifest(['shared-slug'], ['shared-slug'], target: 2),
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('baseline_slug_in_delta_rollout', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['future_rollout_dry_run']['allowed']);
    }

    public function test_rejects_missing_rollback_group(): void
    {
        $manifest = $this->manifest(['baseline-001'], ['delta-001'], target: 2);
        $manifest['rollback_group'] = [];

        $exitCode = $this->callCommand([
            '--manifest' => $this->writeJson('manifest', $manifest),
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('rollback_group_missing', array_column($payload['blockers'], 'reason'));
    }

    public function test_does_not_execute_rollout_or_allow_apply(): void
    {
        $exitCode = $this->callCommand([
            '--manifest' => $this->writeManifest(['baseline-001'], ['delta-001'], target: 2),
            '--target-public-total' => 2,
            '--expect-delta-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['rollout_apply_allowed']);
        $this->assertFalse($payload['rollout_dry_run_executed']);
        $this->assertFalse($payload['rollout_apply_executed']);
        $this->assertSame('career:execute-canonical-rollout-batch', $payload['future_rollout_dry_run']['command']);
    }

    public function test_outputs_detail_ready_1048_rollout_gate_with_dynamic_defaults(): void
    {
        $output = $this->tempPath('detail-ready-gate');
        $exitCode = $this->callCommand([
            '--manifest' => $this->writeDetailReadyManifest($this->slugs('public', 30), $this->slugs('ready', 1018)),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('detail_ready_1048', $payload['target']);
        $this->assertSame(1048, $payload['target_public_total']);
        $this->assertSame(30, $payload['published_baseline_count']);
        $this->assertSame(1018, $payload['delta_slug_count']);
        $this->assertSame(2036, $payload['expected_delta_locale_rows']);
        $this->assertSame('detail_ready_1048', $payload['target_authority']['target_key']);
        $this->assertSame('DETAIL_READY_1048_ROLLOUT_DRY_RUN', $payload['next_required_action']);
        $this->assertFileExists($output);
    }

    public function test_detail_ready_1048_blocks_manual_hold_before_rollout_dry_run(): void
    {
        $delta = $this->slugs('ready', 1017);
        $delta[] = 'software-developers';

        $exitCode = $this->callCommand([
            '--manifest' => $this->writeDetailReadyManifest($this->slugs('public', 30), $delta),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('detail_ready_1048_delta_contains_manual_hold_policy_slugs', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['future_rollout_dry_run']['allowed']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:plan-canonical-delta-rollout-gate', [
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
    private function writeManifest(array $baseline, array $delta, int $target): string
    {
        return $this->writeJson('manifest', $this->manifest($baseline, $delta, $target));
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $delta
     */
    private function writeDetailReadyManifest(array $baseline, array $delta): string
    {
        $manifest = $this->manifest($baseline, $delta, 1048);
        $manifest['target'] = 'detail_ready_1048';
        $manifest['target_key'] = 'detail_ready_1048';
        $manifest['batch_id'] = 'career_detail_ready_1048_canonical_001';

        return $this->writeJson('detail-ready-manifest', $manifest);
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $delta
     * @return array<string, mixed>
     */
    private function manifest(array $baseline, array $delta, int $target): array
    {
        sort($baseline);
        sort($delta);
        $locales = ['en', 'zh'];

        return [
            'schema_version' => 'career_delta_rollout_manifest.v1',
            'status' => 'pass',
            'target' => 'career_80_delta',
            'target_public_total' => $target,
            'published_baseline_count' => count($baseline),
            'delta_slug_count' => count($delta),
            'expected_delta_locale_rows' => count($delta) * count($locales),
            'batch_id' => 'career_80_delta_canonical_001',
            'locales' => $locales,
            'published_baseline_slugs' => $baseline,
            'slugs' => $delta,
            'rollback_group' => $delta,
            'read_only' => true,
            'writes_database' => false,
            'dry_run_allowed' => true,
            'apply_allowed' => false,
        ];
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
        return sys_get_temp_dir().'/career-delta-rollout-gate-'.Str::uuid().'-'.$name.'.json';
    }
}
