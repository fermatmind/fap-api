<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Career\CareerFirstWaveRolloutWavePlanArtifactMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportFirstWaveRolloutWavePlanArtifactCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path(CareerFirstWaveRolloutWavePlanArtifactMaterializationService::OUTPUT_ROOT);
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_exports_rollout_wave_plan_artifact_to_internal_storage(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T111000Z';
        $this->artisan('career:export-first-wave-rollout-wave-plan-artifact', [
            '--timestamp' => $timestamp,
        ])
            ->expectsOutputToContain('status=materialized')
            ->expectsOutputToContain('output_dir=')
            ->expectsOutputToContain('career-rollout-wave-plan=')
            ->assertExitCode(0);

        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $artifactPath = $finalDir.DIRECTORY_SEPARATOR.'career-rollout-wave-plan.json';

        $this->assertDirectoryExists($finalDir);
        $this->assertFileExists($artifactPath);
        $this->assertStringStartsWith($this->rootDir, $finalDir);
        $this->assertStringStartsWith(storage_path('app/private'), $artifactPath);
        $this->assertStringNotContainsString(base_path('docs'), $finalDir);
        $this->assertStringNotContainsString(base_path('public'), $finalDir);
    }

    public function test_command_can_emit_json_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T111100Z';
        $exitCode = Artisan::call('career:export-first-wave-rollout-wave-plan-artifact', [
            '--timestamp' => $timestamp,
            '--json' => true,
        ]);
        $output = trim((string) Artisan::output());
        $payload = json_decode($output, true);

        $this->assertSame(0, $exitCode, $output);
        $this->assertIsArray($payload);
        $this->assertSame('materialized', $payload['status'] ?? null);
        $this->assertArrayHasKey('output_dir', $payload);
        $this->assertArrayHasKey('artifacts', $payload);
        $this->assertArrayHasKey('career-rollout-wave-plan.json', $payload['artifacts']);
        $this->assertFileExists((string) $payload['artifacts']['career-rollout-wave-plan.json']);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
