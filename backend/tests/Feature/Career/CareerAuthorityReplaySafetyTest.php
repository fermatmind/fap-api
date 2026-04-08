<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\RecommendationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAuthorityReplaySafetyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function replay_creates_new_compile_run_and_new_snapshots_without_overwriting_prior_history(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
        ])->assertExitCode(0);

        $importRun = CareerImportRun::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        $this->artisan('career:compile-authority-wave', [
            '--import-run' => $importRun->id,
        ])->assertExitCode(0);

        $firstRun = CareerCompileRun::query()->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $firstSnapshotIds = RecommendationSnapshot::query()
            ->where('compile_run_id', $firstRun->id)
            ->pluck('id')
            ->all();

        $this->artisan('career:compile-authority-wave', [
            '--import-run' => $importRun->id,
        ])->assertExitCode(0);

        $secondRun = CareerCompileRun::query()->whereKeyNot($firstRun->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertSame(4, RecommendationSnapshot::query()->count());
        foreach ($firstSnapshotIds as $snapshotId) {
            $this->assertDatabaseHas('recommendation_snapshots', ['id' => $snapshotId]);
        }
        $this->assertSame(2, RecommendationSnapshot::query()->where('compile_run_id', $secondRun->id)->count());
    }
}
