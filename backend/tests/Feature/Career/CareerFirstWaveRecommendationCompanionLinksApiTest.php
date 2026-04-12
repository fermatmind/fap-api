<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

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

final class CareerFirstWaveRecommendationCompanionLinksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_a_first_wave_recommendation_companion_links_summary_for_supported_route_kinds_only(): void
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

        $response = $this->getJson('/api/v0.5/career/first-wave/recommendations/mbti/intj/companion-links');

        $response->assertOk()
            ->assertJsonPath('summary_kind', 'career_first_wave_recommendation_companion_links')
            ->assertJsonPath('summary_version', CareerFirstWaveRecommendationCompanionLinksService::SUMMARY_VERSION)
            ->assertJsonPath('scope', CareerFirstWaveRecommendationCompanionLinksService::SCOPE)
            ->assertJsonPath('subject_kind', 'recommendation_subject')
            ->assertJsonPath('subject_identity.type_code', 'INTJ-A')
            ->assertJsonPath('subject_identity.canonical_type_code', 'INTJ')
            ->assertJsonPath('subject_identity.public_route_slug', 'intj')
            ->assertJsonPath('counts.total', 4)
            ->assertJsonPath('counts.job_detail', 2)
            ->assertJsonPath('counts.family_hub', 1)
            ->assertJsonPath('counts.test_landing', 1)
            ->assertJsonStructure([
                'summary_kind',
                'summary_version',
                'scope',
                'subject_kind',
                'subject_identity' => ['type_code', 'canonical_type_code', 'public_route_slug', 'display_title'],
                'counts' => ['total', 'job_detail', 'family_hub', 'test_landing'],
                'companion_links' => [[
                    'route_kind',
                    'canonical_path',
                    'canonical_slug',
                    'link_reason_code',
                ]],
            ])
            ->assertJsonMissingPath('recommended_action')
            ->assertJsonMissingPath('why_this_path');

        $links = collect($response->json('companion_links'));

        $this->assertSame(
            ['career_family_hub', 'career_job_detail', 'test_landing'],
            $links->pluck('route_kind')->unique()->sort()->values()->all()
        );
        $testLanding = $links->firstWhere('route_kind', 'test_landing');
        $this->assertSame('/en/tests/mbti-personality-test-16-personality-types', $testLanding['canonical_path']);
        $this->assertSame('recommendation_test_support', $testLanding['link_reason_code']);
        $this->assertSame('MBTI', $testLanding['scale_code']);
        $this->assertArrayNotHasKey('source_of_truth', $testLanding);
        $this->assertArrayNotHasKey('is_active', $testLanding);
        $this->assertFalse($links->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/recommendations')));
        $this->assertFalse($links->contains(static fn (array $row): bool => str_starts_with((string) ($row['canonical_path'] ?? ''), '/career/search')));
        $this->assertFalse($links->contains(static fn (array $row): bool => str_contains((string) ($row['canonical_path'] ?? ''), '/career/tests/')));
        $this->assertFalse($links->contains(static fn (array $row): bool => str_contains((string) ($row['canonical_path'] ?? ''), '/topics/')));
        $this->assertFalse($links->contains(static fn (array $row): bool => str_ends_with((string) ($row['canonical_path'] ?? ''), '/take')));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['canonical_slug'] ?? null) === 'backend-architect-cn-market'));
        $this->assertSame(1, $links->where('canonical_slug', 'accountants-and-auditors')->count());
    }

    public function test_it_returns_not_found_for_unknown_recommendation_subjects(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $this->getJson('/api/v0.5/career/first-wave/recommendations/mbti/unknown-type/companion-links')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_resolves_test_landing_paths_with_request_locale(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $subjectMeta = [
            'type_code' => 'INTJ-A',
            'canonical_type_code' => 'INTJ',
            'display_title' => 'INTJ-A Career Match',
            'public_route_slug' => 'intj',
        ];

        CareerFoundationFixture::seedTrustLimitedCrossMarketChain();

        $this->compileRecommendationSnapshotForOccupation('accountants-and-auditors', $subjectMeta, 1);

        $response = $this->getJson('/api/v0.5/career/first-wave/recommendations/mbti/intj/companion-links?locale=zh-CN');

        $response->assertOk();

        $links = collect($response->json('companion_links'));
        $testLanding = $links->firstWhere('route_kind', 'test_landing');

        $this->assertSame('/zh/tests/mbti-personality-test-16-personality-types', $testLanding['canonical_path']);
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
            'dataset_checksum' => 'checksum-b38-api-'.$occupationSlug.'-'.strtolower((string) ($subjectMeta['canonical_type_code'] ?? 'unknown')),
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
            'identity_id' => 'identity-b38-api-'.$occupationSlug,
            'visitor_id' => 'visitor-b38-api-'.$occupationSlug,
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
            'identity_id' => 'identity-b38-api-'.$occupationSlug,
            'visitor_id' => 'visitor-b38-api-'.$occupationSlug,
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
