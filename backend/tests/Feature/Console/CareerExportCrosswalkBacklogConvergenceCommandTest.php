<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Operations\CareerCrosswalkBacklogConvergenceProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportCrosswalkBacklogConvergenceCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path('app/private/career_crosswalk_backlog_convergence');
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_materializes_internal_crosswalk_convergence_snapshot_and_supports_json_output(): void
    {
        $timestamp = 'b82-test-export';

        $exitCode = Artisan::call('career:export-crosswalk-backlog-convergence', [
            '--timestamp' => $timestamp,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('materialized', $payload['status'] ?? null);

        $artifactPath = $payload['artifacts'][CareerCrosswalkBacklogConvergenceProjectionService::SNAPSHOT_FILENAME] ?? null;
        $this->assertIsString($artifactPath);
        $this->assertFileExists($artifactPath);

        $snapshot = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($snapshot);
        $this->assertSame('career_crosswalk_backlog_convergence', $snapshot['authority_kind'] ?? null);
        $this->assertSame('career.crosswalk_convergence.v1', $snapshot['authority_version'] ?? null);
        $this->assertSame('career_all_342', $snapshot['scope'] ?? null);
        $this->assertSame('latest_unresolved_patch_created_at', data_get($snapshot, 'aging.metric_basis'));

        $counts = (array) ($snapshot['counts'] ?? []);
        $this->assertArrayHasKey('unresolved_local_heavy_interpretation', $counts);
        $this->assertArrayHasKey('unresolved_family_proxy', $counts);
        $this->assertArrayHasKey('unresolved_unmapped', $counts);
        $this->assertArrayHasKey('unresolved_functional_equivalent', $counts);
    }
}
