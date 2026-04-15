<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\DTO\Career\CareerTransitionPreviewBundle;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\Bundles\CareerTransitionPreviewBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionPreviewBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_minimal_publish_ready_transition_preview(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-publish-ready');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => [
                'steps' => [
                    \App\Domain\Career\Transition\TransitionPathPayload::STEP_SKILL_OVERLAP,
                    \App\Domain\Career\Transition\TransitionPathPayload::STEP_TASK_OVERLAP,
                    \App\Domain\Career\Transition\TransitionPathPayload::STEP_TOOL_OVERLAP,
                ],
            ],
        ]);

        $payload = app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj')?->toArray() ?? [];

        $this->assertSame(
            array_values(array_filter(
                CareerTransitionPreviewBundle::publicTopLevelKeys(),
                static fn (string $key): bool => $key !== 'delta'
            )),
            array_keys($payload)
        );
        $this->assertSame('career_transition_preview', $payload['bundle_kind']);
        $this->assertSame('career.protocol.transition_preview.v1', $payload['bundle_version']);
        $this->assertSame('stable_upside', $payload['path_type']);
        $this->assertSame('registered-nurses', data_get($payload, 'target_job.canonical_slug'));
        $this->assertSame(true, data_get($payload, 'trust_summary.allow_transition_recommendation'));
        $this->assertSame('publish_ready', data_get($payload, 'seo_contract.reason_codes.0'));
        $this->assertSame(true, data_get($payload, 'seo_contract.index_eligible'));
        $this->assertSame($snapshot->id, data_get($payload, 'provenance_meta.recommendation_snapshot_id'));
        $this->assertNotNull(data_get($payload, 'score_summary.mobility_score.value'));
        $this->assertNull(data_get($payload, 'delta'));
        $this->assertArrayNotHasKey('why_this_path', $payload);
        $this->assertArrayNotHasKey('what_is_lost', $payload);
        $this->assertSame([
            \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_0_30,
            \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_31_60,
            \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_61_90,
        ], array_column((array) ($payload['bridge_steps_90d'] ?? []), 'time_horizon'));
    }

    public function test_it_excludes_blocked_override_eligible_targets(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-override');
        $target = $this->seedTargetOccupation('software-developers', 'Software Developers');
        $this->mockReadinessSummary([
            $this->readinessRow('software-developers', 'blocked_override_eligible', false, 'noindex', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => ['steps' => ['fixture-only transition evidence']],
        ]);

        $this->assertNull(app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj'));
    }

    public function test_it_excludes_blocked_not_safely_remediable_targets(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-blocked');
        $target = $this->seedTargetOccupation('marketing-managers', 'Marketing Managers');
        $this->mockReadinessSummary([
            $this->readinessRow('marketing-managers', 'blocked_not_safely_remediable', false, 'noindex', 'pending'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => ['steps' => ['fixture-only transition evidence']],
        ]);

        $this->assertNull(app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj'));
    }

    public function test_it_excludes_paths_when_transition_claim_permission_is_not_allowed(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-no-claim');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $payload['claim_permissions']['allow_transition_recommendation'] = false;
        $snapshot->update(['snapshot_payload' => $payload]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => ['steps' => ['fixture-only transition evidence']],
        ]);

        $this->assertNull(app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj'));
    }

    public function test_it_excludes_paths_with_blank_or_unknown_internal_path_taxonomy(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-invalid-taxonomy');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'bridge_path',
            'path_payload' => ['steps' => ['fixture-only transition evidence']],
        ]);

        $this->assertNull(app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj'));
    }

    public function test_it_exposes_only_allowlisted_delta_fields_without_expanding_other_public_preview_fields(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-sanitized-payload');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => [
                'steps' => ['first valid step', 99, ' second valid step '],
                'rationale_codes' => [
                    TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
                    TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET,
                ],
                'tradeoff_codes' => [
                    TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED,
                ],
                'delta' => [
                    TransitionPathPayload::DELTA_ENTRY_EDUCATION => [
                        'source_value' => "Bachelor's degree",
                        'target_value' => "Master's degree",
                        'direction' => TransitionPathPayload::DELTA_DIRECTION_HIGHER,
                    ],
                ],
                'why_this_path' => 'fixture-only narrative',
                'what_is_lost' => 'fixture-only tradeoff',
                'bridge_steps_90d' => ['not authoritative'],
            ],
        ]);

        $payload = app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj')?->toArray() ?? [];

        $expectedKeys = array_values(array_filter(
            CareerTransitionPreviewBundle::publicTopLevelKeys(),
            static fn (string $key): bool => ! in_array($key, ['steps', 'bridge_steps_90d'], true)
        ));
        $this->assertSame($expectedKeys, array_keys($payload));
        $this->assertSame('stable_upside', $payload['path_type'] ?? null);
        $this->assertArrayNotHasKey('steps', $payload);
        $this->assertSame([
            'entry_education_delta' => [
                'source_value' => "Bachelor's degree",
                'target_value' => "Master's degree",
                'direction' => 'higher',
            ],
        ], $payload['delta'] ?? null);
        $this->assertSame([
            TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
            TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET,
        ], $payload['rationale_codes'] ?? null);
        $this->assertSame([
            TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED,
        ], $payload['tradeoff_codes'] ?? null);
        $this->assertIsString($payload['why_this_path'] ?? null);
        $this->assertIsString($payload['what_is_lost'] ?? null);
        $this->assertArrayNotHasKey('bridge_steps_90d', $payload);
    }

    public function test_it_omits_delta_when_internal_truth_is_missing_or_unrankable(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-thin-delta');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => [
                'delta' => [
                    TransitionPathPayload::DELTA_ENTRY_EDUCATION => [
                        'source_value' => '',
                        'target_value' => "Master's degree",
                        'direction' => TransitionPathPayload::DELTA_DIRECTION_HIGHER,
                    ],
                    TransitionPathPayload::DELTA_WORK_EXPERIENCE => [
                        'source_value' => 'None',
                        'target_value' => '5 years or more',
                        'direction' => 'unsafe',
                    ],
                ],
                'rationale_codes' => [
                    TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
                ],
                'tradeoff_codes' => [
                    TransitionPathPayload::TRADEOFF_HIGHER_WORK_EXPERIENCE_REQUIRED,
                ],
            ],
        ]);

        $payload = app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj')?->toArray() ?? [];

        $this->assertArrayNotHasKey('delta', $payload);
        $this->assertSame([
            TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET,
        ], $payload['rationale_codes'] ?? null);
        $this->assertSame([
            TransitionPathPayload::TRADEOFF_HIGHER_WORK_EXPERIENCE_REQUIRED,
        ], $payload['tradeoff_codes'] ?? null);
        $this->assertIsString($payload['why_this_path'] ?? null);
        $this->assertIsString($payload['what_is_lost'] ?? null);
    }

    public function test_it_excludes_paths_with_invalid_non_array_payload_shape(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-source-invalid-payload-shape');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => 'invalid-string-payload',
        ]);

        $this->assertNull(app(CareerTransitionPreviewBundleBuilder::class)->buildByType('intj'));
    }

    private function seedTargetOccupation(string $slug, string $title): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);
        $chain['occupation']->update([
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
        ]);

        return $chain['occupation']->fresh();
    }

    private function compileRecommendationChain(string $slug): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-transition-preview-'.$slug,
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

        $chain['contextSnapshot']->update([
            'compile_run_id' => $compileRun->id,
            'context_payload' => ['materialization' => 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                [
                    'materialization' => 'career_first_wave',
                    'recommendation_subject_meta' => [
                        'type_code' => 'INTJ-A',
                        'canonical_type_code' => 'INTJ',
                        'display_title' => 'INTJ-A Career Match',
                        'public_route_slug' => 'intj',
                    ],
                ],
            ),
        ]);

        return app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $occupations
     */
    private function mockReadinessSummary(array $occupations): void
    {
        $lookup = Mockery::mock(CareerTransitionPreviewReadinessLookup::class);
        foreach ($occupations as $row) {
            $lookup->shouldReceive('bySlug')
                ->with((string) ($row['canonical_slug'] ?? ''))
                ->andReturn($row);
        }
        $lookup->shouldReceive('bySlug')->andReturn(null);

        $this->app->instance(CareerTransitionPreviewReadinessLookup::class, $lookup);
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessRow(
        string $canonicalSlug,
        string $status,
        bool $indexEligible,
        string $indexState,
        string $reviewerStatus,
    ): array {
        return [
            'occupation_uuid' => 'uuid-'.$canonicalSlug,
            'canonical_slug' => $canonicalSlug,
            'canonical_title_en' => $canonicalSlug,
            'status' => $status,
            'blocker_type' => null,
            'remediation_class' => null,
            'authority_override_supplied' => false,
            'review_required' => false,
            'crosswalk_mode' => 'exact',
            'reviewer_status' => $reviewerStatus,
            'index_state' => $indexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => [$status],
        ];
    }
}
