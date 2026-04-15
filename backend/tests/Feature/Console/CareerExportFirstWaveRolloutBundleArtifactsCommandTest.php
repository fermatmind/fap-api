<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Career\CareerFirstWaveRolloutBundleArtifactMaterializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportFirstWaveRolloutBundleArtifactsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path(CareerFirstWaveRolloutBundleArtifactMaterializationService::OUTPUT_ROOT);
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_exports_rollout_bundle_and_primary_lists_to_internal_storage(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T131000Z';
        $this->artisan('career:export-first-wave-rollout-bundle-artifacts', [
            '--timestamp' => $timestamp,
        ])
            ->expectsOutputToContain('status=materialized')
            ->expectsOutputToContain('output_dir=')
            ->expectsOutputToContain('career-rollout-bundle=')
            ->expectsOutputToContain('career-stable-whitelist=')
            ->expectsOutputToContain('career-candidate-whitelist=')
            ->expectsOutputToContain('career-hold-list=')
            ->expectsOutputToContain('career-blocked-list=')
            ->assertExitCode(0);

        $finalDir = $this->rootDir.DIRECTORY_SEPARATOR.$timestamp;
        $this->assertDirectoryExists($finalDir);
        $this->assertStringStartsWith($this->rootDir, $finalDir);
        $this->assertStringStartsWith(storage_path('app/private'), $finalDir);
        $this->assertStringNotContainsString(base_path('docs'), $finalDir);
        $this->assertStringNotContainsString(base_path('public'), $finalDir);

        foreach ([
            'career-rollout-bundle.json',
            'career-stable-whitelist.json',
            'career-candidate-whitelist.json',
            'career-hold-list.json',
            'career-blocked-list.json',
        ] as $filename) {
            $this->assertFileExists($finalDir.DIRECTORY_SEPARATOR.$filename);
        }
    }

    public function test_command_can_emit_json_summary(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $timestamp = '20260415T131100Z';
        $exitCode = Artisan::call('career:export-first-wave-rollout-bundle-artifacts', [
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

        foreach ([
            'career-rollout-bundle.json',
            'career-stable-whitelist.json',
            'career-candidate-whitelist.json',
            'career-hold-list.json',
            'career-blocked-list.json',
        ] as $filename) {
            $this->assertArrayHasKey($filename, $payload['artifacts']);
            $this->assertFileExists((string) $payload['artifacts'][$filename]);
        }
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
