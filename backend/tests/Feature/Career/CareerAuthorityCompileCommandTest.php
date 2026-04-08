<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\ContextSnapshot;
use App\Models\ProfileProjection;
use App\Models\RecommendationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAuthorityCompileCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_compiles_imported_first_wave_rows_into_run_pinned_recommendation_snapshots(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
        ])->assertExitCode(0);

        $importRun = CareerImportRun::query()->latest('created_at')->firstOrFail();

        $this->artisan('career:compile-authority-wave', [
            '--import-run' => $importRun->id,
        ])
            ->expectsOutputToContain('subjects_seen=2')
            ->expectsOutputToContain('snapshots_created=2')
            ->assertExitCode(0);

        $compileRun = CareerCompileRun::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $snapshot = RecommendationSnapshot::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        $this->assertSame($importRun->id, $compileRun->import_run_id);
        $this->assertSame(2, $compileRun->snapshots_created);
        $this->assertSame(2, ContextSnapshot::query()->where('compile_run_id', $compileRun->id)->count());
        $this->assertSame(2, ProfileProjection::query()->where('compile_run_id', $compileRun->id)->count());
        $this->assertSame(2, RecommendationSnapshot::query()->where('compile_run_id', $compileRun->id)->count());
        $this->assertSame($compileRun->id, $snapshot->compile_run_id);
        $this->assertSame($importRun->id, data_get($snapshot->snapshot_payload, 'compile_refs.import_run_id'));
        $this->assertSame($compileRun->id, data_get($snapshot->snapshot_payload, 'compile_refs.compile_run_id'));
        $this->assertIsArray(data_get($snapshot->snapshot_payload, 'score_bundle'));
    }
}
