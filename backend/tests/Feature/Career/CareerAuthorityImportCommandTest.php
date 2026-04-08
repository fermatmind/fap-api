<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationTruthMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAuthorityImportCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_reports_counts_without_writing_authority_rows(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('rows_seen=3')
            ->expectsOutputToContain('rows_accepted=2')
            ->expectsOutputToContain('rows_skipped=1')
            ->expectsOutputToContain('rows_failed=0')
            ->assertExitCode(0);

        $run = CareerImportRun::query()->latest('created_at')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->dry_run);
        $this->assertSame(0, Occupation::query()->count());
        $this->assertSame(0, OccupationTruthMetric::query()->count());
    }

    #[Test]
    public function it_imports_exact_and_trust_inheritance_rows_into_authority_tables(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
        ])->assertExitCode(0);

        $run = CareerImportRun::query()->latest('created_at')->firstOrFail();

        $this->assertSame(3, $run->rows_seen);
        $this->assertSame(2, $run->rows_accepted);
        $this->assertSame(1, $run->rows_skipped);
        $this->assertSame(0, $run->rows_failed);
        $this->assertSame(2, Occupation::query()->count());
        $this->assertSame(2, OccupationTruthMetric::query()->count());
        $this->assertDatabaseHas('occupation_truth_metrics', ['import_run_id' => $run->id]);
        $this->assertDatabaseHas('trust_manifests', ['import_run_id' => $run->id]);
        $this->assertDatabaseHas('index_states', ['import_run_id' => $run->id, 'index_state' => 'noindex']);
        $this->assertDatabaseHas('index_states', ['import_run_id' => $run->id, 'index_state' => 'trust_limited']);
    }
}
