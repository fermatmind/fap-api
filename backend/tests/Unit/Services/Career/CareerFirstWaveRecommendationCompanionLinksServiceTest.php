<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRecommendationCompanionLinksService;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\ContextSnapshot;
use App\Models\Occupation;
use App\Models\ProfileProjection;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerFirstWaveRecommendationCompanionLinksServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_machine_safe_companion_links_for_a_first_wave_recommendation_subject(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subjectMeta = [
            'type_code' => 'INTJ-A',
            'canonical_type_code' => 'INTJ',
            'display_title' => 'INTJ-A Career Match',
            'public_route_slug' => 'intj',
        ];

        CareerFoundationFixture::seedTrustLimitedCrossMarketChain();

        $this->compileRecommendationSnapshotForOccupation('human-resources-specialists', $subjectMeta, 3);
        $this->compileRecommendationSnapshotForOccupation('backend-architect-cn-market', $subjectMeta, 2);
        $this->compileRecommendationSnapshotForOccupation('accountants-and-auditors', $subjectMeta, 1);

        $summary = app(CareerFirstWaveRecommendationCompanionLinksService::class)->buildByType('intj')?->toArray();

        $this->assertIsArray($summary);
        $this->assertSame('career_first_wave_recommendation_companion_links', $summary['summary_kind']);
        $this->assertSame(CareerFirstWaveRecommendationCompanionLinksService::SUMMARY_VERSION, $summary['summary_version']);
        $this->assertSame(CareerFirstWaveRecommendationCompanionLinksService::SCOPE, $summary['scope']);
        $this->assertSame('recommendation_subject', $summary['subject_kind']);
        $this->assertSame('INTJ-A', data_get($summary, 'subject_identity.type_code'));
        $this->assertSame('INTJ', data_get($summary, 'subject_identity.canonical_type_code'));
        $this->assertSame('intj', data_get($summary, 'subject_identity.public_route_slug'));
        $this->assertSame(3, data_get($summary, 'counts.total'));
        $this->assertSame(2, data_get($summary, 'counts.job_detail'));
        $this->assertSame(1, data_get($summary, 'counts.family_hub'));

        $links = collect($summary['companion_links']);
        $this->assertSame(
            ['career_family_hub', 'career_job_detail'],
            $links->pluck('route_kind')->unique()->sort()->values()->all()
        );

        $targetJob = $links->first(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'accountants-and-auditors');
        $familyLink = $links->firstWhere('route_kind', 'career_family_hub');
        $jobLinks = $links->where('route_kind', 'career_job_detail')->values();

        $this->assertSame('target_job_detail_companion', $targetJob['link_reason_code']);
        $this->assertSame('/career/jobs/accountants-and-auditors', $targetJob['canonical_path']);
        $this->assertSame('/career/family/business-and-financial-37ec69bd', $familyLink['canonical_path']);
        $this->assertSame('target_family_hub_companion', $familyLink['link_reason_code']);
        $this->assertSame(
            ['accountants-and-auditors', 'human-resources-specialists'],
            $jobLinks->pluck('canonical_slug')->sort()->values()->all()
        );
        $this->assertTrue($jobLinks->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'human-resources-specialists'
            && ($row['link_reason_code'] ?? null) === 'matched_job_detail_companion'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'backend-architect-cn-market'));
        $this->assertSame(1, $jobLinks->where('canonical_slug', 'accountants-and-auditors')->count());
    }

    public function test_it_returns_null_for_unknown_or_unavailable_recommendation_subjects(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $service = app(CareerFirstWaveRecommendationCompanionLinksService::class);

        $this->assertNull($service->buildByType(''));
        $this->assertNull($service->buildByType('unknown-type'));
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @param  array<string, mixed>  $subjectMeta
     */
    private function compileRecommendationSnapshotForOccupation(string $occupationSlug, array $subjectMeta, int $compiledAtOffsetMinutes): void
    {
        $occupation = Occupation::query()->where('canonical_slug', $occupationSlug)->firstOrFail();

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b38-'.$occupationSlug.'-'.strtolower((string) ($subjectMeta['canonical_type_code'] ?? 'unknown')),
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);

        $contextSnapshot = ContextSnapshot::query()->create([
            'identity_id' => 'identity-b38-'.$occupationSlug,
            'visitor_id' => 'visitor-b38-'.$occupationSlug,
            'captured_at' => now()->subMinutes(30),
            'current_occupation_id' => $occupation->id,
            'employment_status' => 'employed',
            'monthly_comp_band' => '25k_40k',
            'burnout_level' => 0.48,
            'switch_urgency' => 0.54,
            'risk_tolerance' => 0.45,
            'geo_region' => 'cn-east',
            'family_constraint_level' => 0.40,
            'manager_track_preference' => 0.32,
            'time_horizon_months' => 12,
            'compile_run_id' => $compileRun->id,
            'context_payload' => [
                'materialization' => 'career_first_wave',
                'trigger' => 'career_refresh',
            ],
        ]);

        $profileProjection = ProfileProjection::query()->create([
            'identity_id' => 'identity-b38-'.$occupationSlug,
            'visitor_id' => 'visitor-b38-'.$occupationSlug,
            'context_snapshot_id' => $contextSnapshot->id,
            'projection_version' => 'career_projection_v1',
            'compile_run_id' => $compileRun->id,
            'psychometric_axis_coverage' => 0.81,
            'projection_payload' => [
                'materialization' => 'career_first_wave',
                'recommendation_subject_meta' => $subjectMeta,
                'fit_axes' => [
                    'abstraction' => 0.88,
                    'autonomy' => 0.78,
                    'collaboration' => 0.42,
                    'variability' => 0.68,
                    'variant_trigger_load' => 0.12,
                ],
            ],
        ]);

        $snapshot = app(CareerRecommendationCompiler::class)->compile($profileProjection, $occupation, [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);

        $snapshot->forceFill([
            'compiled_at' => now()->subMinutes($compiledAtOffsetMinutes),
            'created_at' => now()->subMinutes($compiledAtOffsetMinutes),
        ])->save();
    }
}
