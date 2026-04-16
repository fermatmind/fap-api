<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerExportFullReleaseLedgerCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = storage_path('app/private/career_release_ledger');
        File::deleteDirectory($this->rootDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->rootDir);

        parent::tearDown();
    }

    public function test_command_materializes_internal_full_release_ledger_and_supports_json_output(): void
    {
        $timestamp = 'b80-test-export';

        $exitCode = Artisan::call('career:export-full-release-ledger', [
            '--timestamp' => $timestamp,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('materialized', $payload['status'] ?? null);

        $artifactPath = $payload['artifacts'][CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? null;
        $this->assertIsString($artifactPath);
        $this->assertFileExists($artifactPath);

        $ledger = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($ledger);
        $this->assertSame('career_full_release_ledger', $ledger['ledger_kind'] ?? null);
        $this->assertSame('career.release_ledger.full_342.v1', $ledger['ledger_version'] ?? null);
        $this->assertSame(342, (int) data_get($ledger, 'counts.tracking_counts.tracked_total_occupations'));
        $this->assertCount(342, (array) ($ledger['members'] ?? []));
    }
}
