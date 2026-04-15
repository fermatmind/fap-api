<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Career\CareerFirstWaveReleaseArtifactMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportFirstWaveReleaseArtifactsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path(CareerFirstWaveReleaseArtifactMaterializationService::OUTPUT_ROOT);
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_exports_both_internal_artifacts_in_one_run(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T100000Z';
        $this->artisan('career:export-first-wave-release-artifacts', [
            '--timestamp' => $timestamp,
        ])
            ->expectsOutputToContain('status=materialized')
            ->expectsOutputToContain('output_dir=')
            ->expectsOutputToContain('career-launch-manifest=')
            ->expectsOutputToContain('career-smoke-matrix=')
            ->assertExitCode(0);

        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $launchPath = $finalDir.DIRECTORY_SEPARATOR.'career-launch-manifest.json';
        $smokePath = $finalDir.DIRECTORY_SEPARATOR.'career-smoke-matrix.json';

        $this->assertDirectoryExists($finalDir);
        $this->assertFileExists($launchPath);
        $this->assertFileExists($smokePath);
        $this->assertStringStartsWith($this->rootDir, $finalDir);
        $this->assertStringStartsWith(storage_path('app/private'), $launchPath);
        $this->assertStringStartsWith(storage_path('app/private'), $smokePath);
        $this->assertStringNotContainsString(base_path('docs'), $finalDir);
        $this->assertStringNotContainsString(base_path('public'), $finalDir);
    }

    public function test_command_can_emit_json_summary_for_internal_export(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T100100Z';
        $exitCode = Artisan::call('career:export-first-wave-release-artifacts', [
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
        $this->assertArrayHasKey('career-launch-manifest.json', $payload['artifacts']);
        $this->assertArrayHasKey('career-smoke-matrix.json', $payload['artifacts']);
        $this->assertFileExists((string) $payload['artifacts']['career-launch-manifest.json']);
        $this->assertFileExists((string) $payload['artifacts']['career-smoke-matrix.json']);
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
