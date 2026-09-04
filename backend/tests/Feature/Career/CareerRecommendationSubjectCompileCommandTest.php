<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
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

    #[Test]
    public function it_recovers_a_formal_import_run_from_the_bound_published_cms_baseline(): void
    {
        $this->seedRecommendationRecoveryAuthority();

        $this->artisan('career:compile-recommendation-subjects')
            ->expectsOutputToContain('types_requested=16')
            ->expectsOutputToContain('occupations_requested=6')
            ->expectsOutputToContain('snapshots_created=96')
            ->expectsOutputToContain('public_entry_count=16')
            ->assertExitCode(0);

        $importRun = CareerImportRun::query()->where('dataset_version', 'career_recommendation_authority_recovery.v1')->firstOrFail();
        $this->assertSame(6, $importRun->rows_accepted);
        $this->assertSame(0, $importRun->rows_failed);
        $this->assertDatabaseCount('career_import_runs', 1);
        $this->assertDatabaseCount('career_compile_runs', 1);
        $this->assertDatabaseCount('recommendation_snapshots', 96);

        $this->artisan('career:compile-recommendation-subjects')
            ->expectsOutputToContain('replay=1')
            ->expectsOutputToContain('snapshots_created=0')
            ->assertExitCode(0);
        $this->assertDatabaseCount('career_import_runs', 1);
        $this->assertDatabaseCount('career_compile_runs', 1);
        $this->assertDatabaseCount('recommendation_snapshots', 96);
    }

    #[Test]
    public function recommendation_authority_recovery_rolls_back_on_unknown_cms_baseline_drift(): void
    {
        $this->seedRecommendationRecoveryAuthority();
        $job = \App\Models\CareerJob::query()
            ->withoutGlobalScopes()
            ->where('slug', 'accountants-and-auditors')
            ->where('locale', 'zh-CN')
            ->firstOrFail();
        $salary = $job->salary_json;
        $salary['annual_median_usd'] = 1;
        $job->forceFill(['salary_json' => $salary])->save();

        $this->artisan('career:compile-recommendation-subjects')
            ->expectsOutputToContain('career_recommendation_authority_recovery_cms_baseline_conflict')
            ->assertExitCode(1);

        $this->assertDatabaseCount('career_import_runs', 0);
        $this->assertDatabaseCount('career_compile_runs', 0);
        $this->assertDatabaseCount('occupation_truth_metrics', 0);
    }

    private function seedRecommendationRecoveryAuthority(): void
    {
        $targets = [
            'accountants-and-auditors' => ['383cccee-0364-4866-9dc1-f7cd1cfcdf51', '37ec69bd-655e-4ab4-b8ea-349632e87159'],
            'data-scientists' => ['fdffb946-2c8f-4f3d-91c4-d351bf6bf6b2', 'f639c622-1a90-4b6d-a67c-081f6de2f48f'],
            'human-resources-specialists' => ['615bdd41-1b3d-40c0-94cc-ab6ee08dbbe7', '37ec69bd-655e-4ab4-b8ea-349632e87159'],
            'management-analysts' => ['bf7beb7c-9881-456e-a65e-447c9477e9cc', 'a0352070-41e8-4cc9-b54d-06d6401d097c'],
            'project-management-specialists' => ['36060017-d3cb-42f7-bdc6-ead26c925cf5', '37ec69bd-655e-4ab4-b8ea-349632e87159'],
            'registered-nurses' => ['be1bd5aa-353b-4760-a11d-0d44b1fe1810', '3d7712d5-9ab5-4eda-88b0-e295149a7a9d'],
        ];

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--job' => array_keys($targets),
            '--status' => 'published',
        ])->assertExitCode(0);

        foreach ($targets as $slug => [$occupationId, $familyId]) {
            OccupationFamily::query()->firstOrCreate(['id' => $familyId], [
                'canonical_slug' => 'family-'.substr($familyId, 0, 8),
                'title_en' => 'Recovery family',
                'title_zh' => '恢复职业族',
            ]);
            $job = \App\Models\CareerJob::query()->withoutGlobalScopes()->where('slug', $slug)->where('locale', 'zh-CN')->firstOrFail();
            $occupation = Occupation::query()->create([
                'id' => $occupationId,
                'family_id' => $familyId,
                'canonical_slug' => $slug,
                'entity_level' => 'market_child',
                'truth_market' => 'US',
                'display_market' => 'US',
                'crosswalk_mode' => 'exact',
                'canonical_title_en' => $job->subtitle,
                'canonical_title_zh' => $job->title,
                'search_h1_zh' => $job->title.'职业诊断',
            ]);
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => 'us_soc',
                'source_code' => 'test-'.$slug,
                'source_title' => $job->subtitle,
                'mapping_type' => 'exact',
                'confidence_score' => 1,
            ]);
        }
    }
}
