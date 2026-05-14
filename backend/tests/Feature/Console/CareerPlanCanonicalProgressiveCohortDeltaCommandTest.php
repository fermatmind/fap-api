<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPlanCanonicalProgressiveCohortDelta;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonicalProgressiveCohortDeltaCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerPlanCanonicalProgressiveCohortDelta::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-progressive-cohort-delta', Artisan::all());
    }

    public function test_missing_current_closeout_blocks(): void
    {
        $exitCode = Artisan::call('career:plan-canonical-progressive-cohort-delta', [
            '--current-closeout' => sys_get_temp_dir().'/missing-closeout.json',
            '--current-slugs' => $this->writeSlugText('current', ['current-001']),
            '--target-selection' => $this->writeTargetSelection(['current-001', 'delta-001']),
            '--target' => 2,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('current_closeout_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_generates_80_to_300_plan_and_writes_output(): void
    {
        $current = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $currentSlugs = $this->writeSlugText('current', $current);
        $output = $this->tempPath('output');

        $exitCode = Artisan::call('career:plan-canonical-progressive-cohort-delta', [
            '--current-closeout' => $this->writeCloseout(80, $currentSlugs),
            '--target-selection' => $this->writeTargetSelection([...$current, ...$delta]),
            '--target' => 300,
            '--locales' => 'en,zh',
            '--json' => true,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_progressive_cohort_delta_plan.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(80, $payload['current_public_total']);
        $this->assertSame(300, $payload['target_public_total']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame(600, $payload['expected_total_locale_rows']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFileExists($output);
    }

    public function test_current_slug_count_mismatch_blocks(): void
    {
        $currentSlugs = $this->writeSlugText('current', ['current-001']);

        $exitCode = Artisan::call('career:plan-canonical-progressive-cohort-delta', [
            '--current-closeout' => $this->writeCloseout(2, $currentSlugs),
            '--target-selection' => $this->writeTargetSelection(['current-001', 'delta-001']),
            '--target' => 2,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('current_public_total_mismatch', array_column($payload['blockers'], 'reason'));
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
     * @param  list<string>  $slugs
     */
    private function writeSlugText(string $name, array $slugs): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, implode(PHP_EOL, $slugs).PHP_EOL);

        return $path;
    }

    private function writeCloseout(int $total, string $totalSlugsPath): string
    {
        return $this->writeJson('closeout', [
            'schema_version' => 'career_progressive_cohort_closeout.v1',
            'status' => 'complete',
            'accepted' => true,
            'target_public_total' => $total,
            'total_slug_count' => $total,
            'total_slugs_path' => $totalSlugsPath,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeTargetSelection(array $slugs): string
    {
        sort($slugs);

        return $this->writeJson('target-selection', [
            'schema_version' => 'career_progressive_target_selection.v1',
            'status' => 'pass',
            'selection' => [
                'slugs' => $slugs,
            ],
        ]);
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
        return sys_get_temp_dir().'/career-progressive-cohort-delta-'.Str::uuid().'-'.$name.'.json';
    }
}
