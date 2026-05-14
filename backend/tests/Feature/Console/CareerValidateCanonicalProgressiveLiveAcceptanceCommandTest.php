<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerValidateCanonicalProgressiveLiveAcceptance;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerValidateCanonicalProgressiveLiveAcceptanceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerValidateCanonicalProgressiveLiveAcceptance::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:validate-canonical-progressive-live-acceptance', Artisan::all());
    }

    public function test_missing_target_delta_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => sys_get_temp_dir().'/missing-progressive-target-delta.json',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('target_delta_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['live_crawl_executed']);
    }

    public function test_generates_300_progressive_report_and_writes_output_file(): void
    {
        $output = $this->tempPath('report');
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->progressiveTargetDelta(80, 300, 220)),
            '--delta-manifest' => $this->writeJson('manifest', $this->deltaManifest(80, 300, 220)),
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('career_80_total_live_acceptance.v1', $payload['schema_version']);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame('career_300_total', $payload['target']);
        $this->assertSame(300, $payload['target_public_total']);
        $this->assertSame(80, $payload['baseline_count']);
        $this->assertSame(220, $payload['delta_count']);
        $this->assertSame(600, $payload['expected_locale_rows']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['live_crawl_executed']);
        $this->assertSame('RUN_PROGRESSIVE_LIVE_ACCEPTANCE_READ_ONLY', $payload['next_required_action']);
        $this->assertFileExists($output);
    }

    public function test_800_target_expected_rows_are_computed(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->progressiveTargetDelta(300, 800, 500)),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(800, $payload['target_public_total']);
        $this->assertSame(1600, $payload['expected_locale_rows']);
    }

    public function test_2786_target_expected_rows_are_computed(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->progressiveTargetDelta(800, 2786, 1986)),
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('planned', $payload['status']);
        $this->assertSame(2786, $payload['target_public_total']);
        $this->assertSame(5572, $payload['expected_locale_rows']);
    }

    public function test_passes_when_accepted_live_acceptance_artifact_is_supplied(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->progressiveTargetDelta(80, 300, 220)),
            '--live-acceptance' => $this->writeJson('live-acceptance', $this->liveAcceptance(accepted: true, expectedRows: 600)),
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['accepted']);
        $this->assertSame('PROGRESSIVE_LIVE_ACCEPTANCE_COMPLETE', $payload['next_required_action']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_live_acceptance_expected_rows_mismatch_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--target-delta' => $this->writeJson('target-delta', $this->progressiveTargetDelta(80, 300, 220)),
            '--live-acceptance' => $this->writeJson('live-acceptance', $this->liveAcceptance(accepted: true, expectedRows: 160)),
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
        return Artisan::call('career:validate-canonical-progressive-live-acceptance', [
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
            $slugs[] = sprintf('%s-%04d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @return array<string, mixed>
     */
    private function progressiveTargetDelta(int $current, int $target, int $delta): array
    {
        $currentSlugs = $this->slugs('current', $current);
        $deltaSlugs = $this->slugs('delta', $delta);

        return [
            'schema_version' => 'career_progressive_cohort_delta_plan.v1',
            'status' => 'pass',
            'read_only' => true,
            'writes_database' => false,
            'current_public_total' => $current,
            'target_public_total' => $target,
            'delta_slug_count' => $delta,
            'current_public_slugs' => $currentSlugs,
            'delta_promotion_slugs' => $deltaSlugs,
            'recommended_rollout_delta_slugs' => $deltaSlugs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deltaManifest(int $current, int $target, int $delta): array
    {
        $deltaSlugs = $this->slugs('delta', $delta);

        return [
            'schema_version' => 'career_delta_rollout_manifest.v1',
            'status' => 'pass',
            'target' => 'career_'.$current.'_to_'.$target.'_delta',
            'target_public_total' => $target,
            'published_baseline_count' => $current,
            'delta_slug_count' => $delta,
            'slugs' => $deltaSlugs,
            'rollback_group' => $deltaSlugs,
            'apply_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveAcceptance(bool $accepted, int $expectedRows): array
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
        return sys_get_temp_dir().'/career-progressive-live-acceptance-'.Str::uuid().'-'.$name.'.json';
    }
}
