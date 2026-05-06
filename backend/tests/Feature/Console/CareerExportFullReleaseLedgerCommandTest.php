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

    public function test_command_can_export_2786_row_public_resolution_ledger_fields(): void
    {
        $timestamp = 'career-public-resolution-test-export';
        $planPath = $this->writePublicResolutionPlanFixture();

        $exitCode = Artisan::call('career:export-full-release-ledger', [
            '--timestamp' => $timestamp,
            '--public-resolution-plan' => $planPath,
            '--json' => true,
        ]);

        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);

        $artifactPath = $payload['artifacts'][CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? null;
        $this->assertIsString($artifactPath);
        $this->assertFileExists($artifactPath);

        $ledger = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($ledger);

        $publicResolution = (array) data_get($ledger, 'public_resolution');
        $this->assertSame('career_public_resolution_ledger', $publicResolution['ledger_kind'] ?? null);
        $this->assertSame(2786, (int) data_get($publicResolution, 'counts.total_rows'));
        $this->assertSame(793, (int) data_get($publicResolution, 'counts.public_canonical_job'));
        $this->assertSame(1992, (int) data_get($publicResolution, 'counts.blocked_until_governance_approval'));
        $this->assertSame(1, (int) data_get($publicResolution, 'counts.keep_non_public_with_policy'));
        $this->assertSame(0, (int) data_get($publicResolution, 'counts.software_developers_public'));

        $softwareDevelopers = collect((array) data_get($publicResolution, 'rows'))->firstWhere('source_slug', 'software-developers');
        $this->assertIsArray($softwareDevelopers);
        $this->assertSame('manual_hold', $softwareDevelopers['current_status'] ?? null);
        $this->assertSame('keep_non_public_with_policy', $softwareDevelopers['public_resolution_type'] ?? null);
        $this->assertFalse((bool) ($softwareDevelopers['public_eligible'] ?? true));
        $this->assertFalse((bool) ($softwareDevelopers['sitemap_eligible'] ?? true));
        $this->assertFalse((bool) ($softwareDevelopers['llms_eligible'] ?? true));
    }

    private function writePublicResolutionPlanFixture(): string
    {
        $path = storage_path('framework/testing/career-public-resolution-command-plan.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode([
            'workbook' => [
                'path' => '/tmp/career_full_upload_repaired.xlsx',
                'sha256' => 'fixture-workbook-sha',
                'sheet' => 'Career_Assets_v4_1',
                'rows' => 2786,
            ],
            'rows' => $this->publicResolutionFixtureRows(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicResolutionFixtureRows(): array
    {
        $rows = [];
        $rowNumber = 2;

        for ($index = 1; $index <= 793; $index++) {
            $slug = sprintf('canonical-%04d', $index);
            $rows[] = $this->planRow($rowNumber++, $slug, $index <= 311 ? 'already_imported_validated' : 'upload_candidate');
        }

        for ($index = 1; $index <= 1663; $index++) {
            $rows[] = $this->planRow($rowNumber++, sprintf('cn-proxy-%04d', $index), 'CN_proxy_hold');
        }

        for ($index = 1; $index <= 254; $index++) {
            $rows[] = $this->planRow($rowNumber++, sprintf('duplicate-%04d', $index), 'duplicate_identity_hold');
        }

        for ($index = 1; $index <= 75; $index++) {
            $rows[] = $this->planRow($rowNumber++, sprintf('broad-group-%04d', $index), 'broad_group_hold');
        }

        $rows[] = $this->planRow($rowNumber, 'software-developers', 'manual_hold');

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(int $rowNumber, string $slug, string $status): array
    {
        return [
            'row_number' => $rowNumber,
            'slug' => $slug,
            'status' => $status,
            'canonical_slug' => $slug,
            'hold_reason' => str_ends_with($status, '_hold') ? $status : null,
            'import_eligible' => $status === 'upload_candidate',
        ];
    }
}
