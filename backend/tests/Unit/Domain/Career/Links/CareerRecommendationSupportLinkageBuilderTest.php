<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Links;

use App\Domain\Career\Links\CareerRecommendationSupportLinkageBuilder;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\ContextSnapshot;
use App\Models\Occupation;
use App\Models\ProfileProjection;
use App\Models\TopicProfile;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationSupportLinkageBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_internal_support_linkage_for_recommendation_subjects(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $this->seedCanonicalMbtiScale();
        $this->createPublishedTopicProfile('mbti', 'mbti');

        $subjectMeta = [
            'type_code' => 'INTJ-A',
            'canonical_type_code' => 'INTJ',
            'display_title' => 'INTJ-A Career Match',
            'public_route_slug' => 'intj',
        ];

        CareerFoundationFixture::seedTrustLimitedCrossMarketChain();
        $this->compileRecommendationSnapshotForOccupation('accountants-and-auditors', $subjectMeta, 1);

        $payload = app(CareerRecommendationSupportLinkageBuilder::class)->buildByType('intj', 'en');

        $this->assertIsArray($payload);
        $this->assertSame('recommendation_subject', $payload['subject_kind']);
        $this->assertSame('INTJ-A', data_get($payload, 'subject_identity.type_code'));
        $this->assertSame('INTJ', data_get($payload, 'subject_identity.canonical_type_code'));
        $this->assertSame('intj', data_get($payload, 'subject_identity.public_route_slug'));

        $links = collect((array) ($payload['support_links'] ?? []));
        $this->assertSame(
            ['test_landing', 'topic_detail'],
            $links->pluck('route_kind')->unique()->sort()->values()->all()
        );
        $this->assertTrue($links->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'test_landing'
            && ($row['canonical_path'] ?? null) === '/en/tests/mbti-personality-test-16-personality-types'
            && ($row['link_reason_code'] ?? null) === 'canonical_test_landing'));
        $this->assertTrue($links->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'topic_detail'
            && ($row['canonical_path'] ?? null) === '/en/topics/mbti'
            && ($row['link_reason_code'] ?? null) === 'canonical_topic_detail'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['subject_kind'] ?? null) === 'occupation'));
    }

    public function test_it_does_not_promote_controller_cta_fallbacks_into_topic_truth_when_topic_registry_rows_are_missing(): void
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

        $payload = app(CareerRecommendationSupportLinkageBuilder::class)->buildByType('intj', 'en');

        $this->assertIsArray($payload);
        $links = collect((array) ($payload['support_links'] ?? []));

        $this->assertTrue($links->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'test_landing'));
        $this->assertFalse($links->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'topic_detail'));
    }

    public function test_it_rejects_non_mbti_recommendation_subject_identity_even_if_snapshot_shape_matches(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $this->seedCanonicalMbtiScale();
        $this->createPublishedTopicProfile('mbti', 'mbti');

        $subjectMeta = [
            'type_code' => 'DISC-A',
            'canonical_type_code' => 'DISC',
            'display_title' => 'DISC-A Career Match',
            'public_route_slug' => 'disc',
        ];

        CareerFoundationFixture::seedTrustLimitedCrossMarketChain();
        $this->compileRecommendationSnapshotForOccupation('accountants-and-auditors', $subjectMeta, 1);

        $payload = app(CareerRecommendationSupportLinkageBuilder::class)->buildByType('disc', 'en');

        $this->assertNull($payload);
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

    private function seedCanonicalMbtiScale(): void
    {
        Artisan::call('fap:scales:seed-default');
        Artisan::call('fap:scales:sync-slugs');
    }

    private function createPublishedTopicProfile(string $topicCode, string $slug): void
    {
        TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => $topicCode,
            'slug' => $slug,
            'locale' => 'en',
            'title' => strtoupper($topicCode).' Topic',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => 'v1',
        ]);
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
            'dataset_checksum' => 'checksum-b39a-'.$occupationSlug.'-'.strtolower((string) ($subjectMeta['canonical_type_code'] ?? 'unknown')),
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
            'identity_id' => 'identity-b39a-'.$occupationSlug,
            'visitor_id' => 'visitor-b39a-'.$occupationSlug,
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
            'identity_id' => 'identity-b39a-'.$occupationSlug,
            'visitor_id' => 'visitor-b39a-'.$occupationSlug,
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
