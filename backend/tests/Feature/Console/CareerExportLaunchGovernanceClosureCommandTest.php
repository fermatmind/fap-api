<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportLaunchGovernanceClosureCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path('app/private/career_launch_governance_closure');
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_materializes_internal_launch_governance_closure_snapshot_and_supports_json_output(): void
    {
        $timestamp = 'b85-test-export';

        $exitCode = Artisan::call('career:export-launch-governance-closure', [
            '--timestamp' => $timestamp,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('materialized', $payload['status'] ?? null);

        $artifactPath = $payload['artifacts'][CareerLaunchGovernanceClosureProjectionService::SNAPSHOT_FILENAME] ?? null;
        $this->assertIsString($artifactPath);
        $this->assertFileExists($artifactPath);

        $closure = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($closure);
        $this->assertSame('career_launch_governance_closure', $closure['governance_kind'] ?? null);
        $this->assertSame('career.governance.v1', $closure['governance_version'] ?? null);
        $this->assertSame('career_all_342', $closure['scope'] ?? null);
        $this->assertCount(342, (array) ($closure['members'] ?? []));
        $this->assertArrayHasKey('public_statement', $closure);
    }
}
