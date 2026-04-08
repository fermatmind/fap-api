<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAuthorityRunLedgerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function import_and_compile_runs_capture_checksum_scope_and_error_summaries(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
        ])->assertExitCode(0);

        $importRun = CareerImportRun::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $this->assertSame('first_wave_mixed', $importRun->scope_mode);
        $this->assertNotSame('', $importRun->dataset_checksum);
        $this->assertIsArray($importRun->error_summary);
        $this->assertNotEmpty($importRun->error_summary);

        $this->artisan('career:compile-authority-wave', [
            '--import-run' => $importRun->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $compileRun = CareerCompileRun::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $this->assertSame($importRun->id, $compileRun->import_run_id);
        $this->assertSame('completed', $compileRun->status);
        $this->assertTrue($compileRun->dry_run);
        $this->assertSame(2, $compileRun->subjects_seen);
        $this->assertSame(0, $compileRun->snapshots_created);
        $this->assertSame(2, (int) ($compileRun->output_counts['snapshots_planned'] ?? 0));
    }
}
