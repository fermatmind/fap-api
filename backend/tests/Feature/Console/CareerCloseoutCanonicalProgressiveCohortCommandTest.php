<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CareerCloseoutCanonicalProgressiveCohort;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerCloseoutCanonicalProgressiveCohortCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(CareerCloseoutCanonicalProgressiveCohort::class),
        );
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:closeout-canonical-progressive-cohort', Artisan::all());
    }

    public function test_missing_live_acceptance_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--live-acceptance' => sys_get_temp_dir().'/missing-progressive-live-acceptance.json',
            '--total-slugs' => '/tmp/career_300_total_slugs.txt',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('live_acceptance_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_writes_300_closeout_artifact(): void
    {
        $output = $this->tempPath('closeout');
        $exitCode = $this->callCommand([
            '--live-acceptance' => $this->writeJson('live-acceptance', $this->liveAcceptance(300, 80, 220)),
            '--baseline-slugs' => '/tmp/career_80_total_slugs.txt',
            '--delta-slugs' => '/tmp/career_80_to_300_delta_slugs.txt',
            '--total-slugs' => '/tmp/career_300_total_slugs.txt',
            '--output' => $output,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('career_progressive_cohort_closeout.v1', $payload['schema_version']);
        $this->assertSame('complete', $payload['status']);
        $this->assertTrue($payload['accepted']);
        $this->assertSame(300, $payload['target_public_total']);
        $this->assertSame(600, $payload['expected_locale_rows']);
        $this->assertSame('/tmp/career_300_total_slugs.txt', $payload['total_slugs_path']);
        $this->assertSame('800_READINESS_1', $payload['next_required_action']);
        $this->assertFileExists($output);
    }

    public function test_failed_acceptance_artifact_blocks(): void
    {
        $artifact = $this->liveAcceptance(300, 80, 220);
        $artifact['accepted'] = false;
        $artifact['status'] = 'blocked';

        $exitCode = $this->callCommand([
            '--live-acceptance' => $this->writeJson('live-acceptance', $artifact),
            '--total-slugs' => '/tmp/career_300_total_slugs.txt',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('live_acceptance_not_accepted', array_column($payload['blockers'], 'reason'));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:closeout-canonical-progressive-cohort', [
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
     * @return array<string, mixed>
     */
    private function liveAcceptance(int $target, int $baseline, int $delta): array
    {
        return [
            'status' => 'pass',
            'accepted' => true,
            'read_only' => true,
            'writes_database' => false,
            'target_public_total' => $target,
            'baseline_count' => $baseline,
            'delta_count' => $delta,
            'total_slug_count' => $target,
            'expected_locale_rows' => $target * 2,
            'locales' => ['en', 'zh'],
            'projection_truth' => [
                'found_published' => $target * 2,
            ],
            'release_gate' => [
                'pass_count' => $target * 2,
                'blocked_count' => 0,
            ],
            'failures' => [],
            'sidecars' => [],
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
        return sys_get_temp_dir().'/career-progressive-closeout-'.Str::uuid().'-'.$name.'.json';
    }
}
