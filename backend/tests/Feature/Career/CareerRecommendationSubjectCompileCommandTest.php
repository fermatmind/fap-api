<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\RecommendationSnapshot;
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

        $this->artisan('career:compile-recommendation-subjects', [
            '--import-run' => $importRun->id,
            '--types' => 'INTJ-A',
            '--limit' => 1,
        ])
            ->expectsOutputToContain('types_requested=1')
            ->expectsOutputToContain('occupations_requested=1')
            ->expectsOutputToContain('snapshots_created=1')
            ->assertExitCode(0);

        $compileRun = CareerCompileRun::query()
            ->where('scope_mode', 'like', '%:recommendation_subjects')
            ->latest('created_at')
            ->firstOrFail();
        $snapshot = RecommendationSnapshot::query()
            ->where('compile_run_id', $compileRun->id)
            ->firstOrFail();

        $this->assertSame('mbti_recommendation_subject', data_get($snapshot->snapshot_payload, 'compile_refs.materialization_kind'));
        $this->assertSame('INTJ', data_get($snapshot->profileProjection?->projection_payload, 'recommendation_subject_meta.canonical_type_code'));
        $this->assertNotNull(app(CareerRecommendationDetailBundleBuilder::class)->buildByType('INTJ'));
    }
}
