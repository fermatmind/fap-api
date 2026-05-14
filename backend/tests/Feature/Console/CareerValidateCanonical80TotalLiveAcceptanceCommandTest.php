<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerValidateCanonical80TotalLiveAcceptanceCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:validate-canonical-80-total-live-acceptance', Artisan::all());
    }

    public function test_missing_target_delta_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => sys_get_temp_dir().'/missing-career-target-delta.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('target_delta_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['live_crawl_executed']);
    }

    public function test_invalid_json_blocks(): void
    {
        $path = $this->tempPath('invalid');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand(['--target-delta' => $path]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('target_delta_artifact_json_invalid', $payload['blockers'][0]['reason']);
    }

    public function test_plans_80_total_report_and_writes_output_file(): void
    {
        $output = $this->tempPath('report');
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->targetDelta()),
            '--delta-manifest' => $this->writeJson('manifest', $this->deltaManifest()),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('career_80_total_live_acceptance.v1', $payload['schema_version']);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(29, $payload['baseline_count']);
        $this->assertSame(51, $payload['delta_count']);
        $this->assertSame(80, $payload['total_slug_count']);
        $this->assertSame(160, $payload['expected_locale_rows']);
        $this->assertFalse($payload['accepted']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['live_crawl_executed']);
        $this->assertFileExists($output);
    }

    public function test_passes_when_accepted_live_acceptance_artifact_is_supplied(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->targetDelta()),
            '--delta-manifest' => $this->writeJson('manifest', $this->deltaManifest()),
            '--live-acceptance' => $this->writeJson('live-acceptance', $this->liveAcceptance(accepted: true)),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['accepted']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['live_crawl_executed']);
    }

    public function test_live_acceptance_expected_rows_mismatch_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->targetDelta()),
            '--live-acceptance' => $this->writeJson('live-acceptance', $this->liveAcceptance(accepted: true, expectedRows: 102)),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('live_acceptance_expected_rows_mismatch', array_column($payload['blockers'], 'reason'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:validate-canonical-80-total-live-acceptance', [
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
     * @return array<string, mixed>
     */
    private function targetDelta(): array
    {
        $baseline = $this->slugs('baseline', 29);
        $delta = $this->slugs('delta', 51);

        return [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'published_baseline_count' => 29,
            'delta_promotion_count' => 51,
            'published_baseline_slugs' => $baseline,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deltaManifest(): array
    {
        $delta = $this->slugs('delta', 51);

        return [
            'schema_version' => 'career_delta_rollout_manifest.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'published_baseline_count' => 29,
            'delta_slug_count' => 51,
            'slugs' => $delta,
            'rollback_group' => $delta,
            'apply_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveAcceptance(bool $accepted, int $expectedRows = 160): array
    {
        return [
            'status' => $accepted ? 'pass' : 'fail',
            'accepted' => $accepted,
            'expected_rows' => $expectedRows,
            'read_only' => true,
            'writes_database' => false,
        ];
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
        return sys_get_temp_dir().'/career-80-total-live-acceptance-'.Str::uuid().'-'.$name.'.json';
    }
}
