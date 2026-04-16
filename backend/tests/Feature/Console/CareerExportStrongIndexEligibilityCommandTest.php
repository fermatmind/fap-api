<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerStrongIndexEligibilityProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportStrongIndexEligibilityCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path('app/private/career_strong_index_eligibility');
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_materializes_internal_full_342_strong_index_snapshot_and_supports_json_output(): void
    {
        $timestamp = 'b81-test-export';

        $exitCode = Artisan::call('career:export-strong-index-eligibility', [
            '--timestamp' => $timestamp,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('materialized', $payload['status'] ?? null);

        $artifactPath = $payload['artifacts'][CareerStrongIndexEligibilityProjectionService::SNAPSHOT_FILENAME] ?? null;
        $this->assertIsString($artifactPath);
        $this->assertFileExists($artifactPath);

        $snapshot = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($snapshot);
        $this->assertSame('career_strong_index_eligibility', $snapshot['snapshot_kind'] ?? null);
        $this->assertSame('career.strong_index.v1', $snapshot['snapshot_version'] ?? null);
        $this->assertSame('career_all_342', $snapshot['scope'] ?? null);
        $this->assertCount(342, (array) ($snapshot['members'] ?? []));
        $this->assertSame(342, array_sum(array_map(static fn ($value): int => (int) $value, (array) ($snapshot['counts'] ?? []))));
    }
}
