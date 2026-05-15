<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerPlanCanonicalProgressiveReadinessSelection;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPlanCanonicalProgressiveReadinessSelectionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerPlanCanonicalProgressiveReadinessSelection::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:plan-canonical-progressive-readiness-selection', Artisan::all());
    }

    public function test_missing_source_plan_blocks(): void
    {
        $currentSlugs = $this->writeSlugs('current', ['career-001']);
        $exitCode = Artisan::call('career:plan-canonical-progressive-readiness-selection', [
            '--source-plan' => sys_get_temp_dir().'/missing-career-source-plan.json',
            '--closeout' => $this->writeCloseout(1, $currentSlugs),
            '--current-total' => '1',
            '--target-total' => '300',
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('source_plan_invalid', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_writes_80_to_300_selection_output(): void
    {
        $current = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $currentSlugs = $this->writeSlugs('current', $current);
        $output = $this->tempPath('output');

        $exitCode = Artisan::call('career:plan-canonical-progressive-readiness-selection', [
            '--source-plan' => $this->writeSourcePlan([...$current, ...$delta]),
            '--closeout' => $this->writeCloseout(80, $currentSlugs),
            '--current-total' => '80',
            '--target-total' => '300',
            '--locales' => 'en,zh',
            '--json' => true,
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_progressive_readiness_selection.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(220, $payload['selected_count']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame($delta, $payload['selected_slugs']);
        $this->assertCount(300, $payload['selection']['slugs']);
        $this->assertFileExists($output);
    }

    public function test_target_less_than_current_blocks_without_mutation(): void
    {
        $currentSlugs = $this->writeSlugs('current', $this->slugs('current', 80));

        $exitCode = Artisan::call('career:plan-canonical-progressive-readiness-selection', [
            '--source-plan' => $this->writeSourcePlan($this->slugs('current', 80)),
            '--closeout' => $this->writeCloseout(80, $currentSlugs),
            '--current-total' => '80',
            '--target-total' => '80',
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_not_greater_than_current', array_column($payload['blockers'], 'reason'));
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
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
    private function writeSlugs(string $name, array $slugs): string
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
    private function writeSourcePlan(array $slugs): string
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = [
                'row_number' => $index + 1,
                'canonical_slug' => $slug,
                'status' => 'ready_for_pilot',
                'canonical_public_type' => 'public_canonical_job',
                'locales' => ['en', 'zh'],
            ];
        }

        return $this->writeJson('source-plan', [
            'schema_version' => 'career_public_resolution_plan.v1',
            'expected_rows' => count($rows),
            'rows' => $rows,
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
        return sys_get_temp_dir().'/career-progressive-readiness-selection-'.Str::uuid().'-'.$name.'.json';
    }
}
