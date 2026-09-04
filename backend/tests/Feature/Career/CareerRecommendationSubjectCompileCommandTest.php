<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\IndexState;
use App\Models\RecommendationSnapshot;
use App\Models\TrustManifest;
use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationSubjectCompileCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_materializes_mbti_recommendation_subject_snapshots_for_detail_pages(): void
    {
        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
        ])->assertExitCode(0);

        $importRun = CareerImportRun::query()->latest('created_at')->firstOrFail();
        TrustManifest::query()->where('import_run_id', $importRun->id)->update([
            'reviewer_status' => 'approved',
            'reviewed_at' => now(),
        ]);
        IndexState::query()->where('import_run_id', $importRun->id)->update([
            'index_state' => 'indexable',
            'index_eligible' => true,
        ]);

        $this->artisan('career:compile-recommendation-subjects', [
            '--import-run' => $importRun->id,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('types_requested=16')
            ->expectsOutputToContain('occupations_requested=1')
            ->expectsOutputToContain('snapshots_created=16')
            ->expectsOutputToContain('public_entry_count=16')
            ->assertExitCode(0);

        $compileRun = CareerCompileRun::query()
            ->where('scope_mode', 'like', '%:recommendation_subjects')
            ->latest('created_at')
            ->firstOrFail();
        $snapshots = RecommendationSnapshot::query()
            ->where('compile_run_id', $compileRun->id)
            ->get();

        $this->assertCount(16, $snapshots);
        $snapshot = $snapshots->firstOrFail();
        $this->assertSame('mbti_recommendation_subject', data_get($snapshot->snapshot_payload, 'compile_refs.materialization_kind'));
        $this->assertNotNull(app(CareerRecommendationDetailBundleBuilder::class)->buildByType('INTJ'));

        $this->getJson('/api/v0.5/career/recommendations/mbti?locale=zh-CN')
            ->assertOk()
            ->assertJsonCount(16, 'items');
        $this->getJson('/api/v0.5/career/recommendations/mbti?locale=en')
            ->assertOk()
            ->assertJsonCount(16, 'items');
        $this->getJson('/api/v0.5/career/recommendations/mbti/intj?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('recommendation_subject_meta.type_code', 'INTJ-A')
            ->assertJsonCount(1, 'matched_jobs');
        $this->getJson('/api/v0.5/career/recommendations/mbti/intj?locale=en')
            ->assertOk()
            ->assertJsonPath('recommendation_subject_meta.type_code', 'INTJ-A')
            ->assertJsonCount(1, 'matched_jobs');

        $this->artisan('career:compile-recommendation-subjects', [
            '--import-run' => $importRun->id,
            '--limit' => 1,
        ])
            ->expectsOutputToContain('replay=1')
            ->expectsOutputToContain('snapshots_created=0')
            ->assertExitCode(0);
        $this->assertSame(1, CareerCompileRun::query()->where('meta->publication_state', 'published_complete')->count());
        $this->assertSame(16, RecommendationSnapshot::query()->count());
    }

    #[Test]
    public function it_fails_closed_without_a_complete_public_authority_input_or_type_set(): void
    {
        $this->artisan('career:compile-recommendation-subjects')->assertExitCode(1);
        $this->assertDatabaseCount('career_compile_runs', 0);

        $this->artisan('career:import-authority-wave', [
            '--source' => CareerFoundationFixture::firstWaveCsvPath(),
            '--manifest' => CareerFoundationFixture::firstWaveManifestPath(),
        ])->assertExitCode(0);
        $importRun = CareerImportRun::query()->latest('created_at')->firstOrFail();

        $this->artisan('career:compile-recommendation-subjects', [
            '--import-run' => $importRun->id,
        ])->assertExitCode(1);
        $this->assertDatabaseCount('career_compile_runs', 0);

        TrustManifest::query()->where('import_run_id', $importRun->id)->update(['reviewer_status' => 'approved']);
        IndexState::query()->where('import_run_id', $importRun->id)->update([
            'index_state' => 'indexable',
            'index_eligible' => true,
        ]);
        $this->artisan('career:compile-recommendation-subjects', [
            '--import-run' => $importRun->id,
            '--types' => 'INTJ-A',
        ])->assertExitCode(1);
        $this->assertDatabaseCount('career_compile_runs', 0);
    }
}
