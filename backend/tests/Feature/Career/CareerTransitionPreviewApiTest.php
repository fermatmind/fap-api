<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\DTO\Career\CareerTransitionPreviewBundle;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use App\Models\TransitionPath;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_compact_transition_preview_for_an_eligible_subject(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-api-publish-ready');
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
                    TransitionPathPayload::STEP_SKILL_OVERLAP,
                    TransitionPathPayload::STEP_TASK_OVERLAP,
                    TransitionPathPayload::STEP_TOOL_OVERLAP,
                ],
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
            ],
        ]);

        $response = $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_transition_preview')
            ->assertJsonPath('bundle_version', 'career.protocol.transition_preview.v1')
            ->assertJsonPath('path_type', 'stable_upside')
            ->assertJsonPath('steps.0', TransitionPathPayload::STEP_SKILL_OVERLAP)
            ->assertJsonPath('steps.1', TransitionPathPayload::STEP_TASK_OVERLAP)
            ->assertJsonPath('steps.2', TransitionPathPayload::STEP_TOOL_OVERLAP)
            ->assertJsonPath('target_job.canonical_slug', 'registered-nurses')
            ->assertJsonPath('trust_summary.allow_transition_recommendation', true)
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('delta.entry_education_delta.source_value', "Bachelor's degree")
            ->assertJsonPath('delta.entry_education_delta.target_value', "Master's degree")
            ->assertJsonPath('delta.entry_education_delta.direction', TransitionPathPayload::DELTA_DIRECTION_HIGHER)
            ->assertJsonPath('rationale_codes.0', TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET)
            ->assertJsonPath('rationale_codes.1', TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET)
            ->assertJsonPath('tradeoff_codes.0', TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED)
            ->assertJsonPath('bridge_steps_90d.0.step_key', TransitionPathPayload::STEP_SKILL_OVERLAP)
            ->assertJsonPath('bridge_steps_90d.0.time_horizon', \App\Services\Career\Transition\CareerTransitionContractBuilder::TIME_HORIZON_DAYS_0_30)
            ->assertJsonStructure([
                'path_type',
                'steps',
                'delta' => [
                    'entry_education_delta' => ['source_value', 'target_value', 'direction'],
                ],
                'target_job' => ['occupation_uuid', 'canonical_slug', 'title'],
                'score_summary' => [
                    'mobility_score' => ['value', 'integrity_state', 'band'],
                    'confidence_score' => ['value', 'integrity_state', 'band'],
                ],
                'trust_summary' => ['allow_transition_recommendation', 'reviewer_status', 'reason_codes'],
                'why_this_path',
                'what_is_lost',
                'bridge_steps_90d' => [[
                    'step_key',
                    'title',
                    'description',
                    'time_horizon',
                ]],
                'rationale_codes',
                'tradeoff_codes',
                'seo_contract' => ['canonical_path', 'canonical_target', 'index_state', 'index_eligible', 'reason_codes'],
                'provenance_meta' => ['recommendation_snapshot_id', 'transition_path_id', 'compiler_version', 'compile_run_id'],
            ]);

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        $this->assertSame(CareerTransitionPreviewBundle::publicTopLevelKeys(), array_keys($payload));
        $this->assertSame(TransitionPathPayload::allowedStepLabels(), $payload['steps']);
        $this->assertContains(
            data_get($payload, 'delta.entry_education_delta.direction'),
            TransitionPathPayload::allowedDeltaDirections(),
        );
    }

    public function test_it_returns_not_found_when_no_safe_preview_exists(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-api-blocked');
        $target = $this->seedTargetOccupation('software-developers', 'Software Developers');
        $this->mockReadinessSummary([
            $this->readinessRow('software-developers', 'blocked_override_eligible', false, 'noindex', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'stable_upside',
            'path_payload' => [
                'steps' => [TransitionPathPayload::STEP_SKILL_OVERLAP],
            ],
        ]);

        $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_validates_the_required_type_query_param(): void
    {
        $this->getJson('/api/v0.5/career/transition-preview')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    public function test_it_returns_not_found_for_unknown_internal_path_taxonomy_even_when_transition_rows_exist(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-api-invalid-taxonomy');
        $target = $this->seedTargetOccupation('registered-nurses', 'Registered Nurses');
        $this->mockReadinessSummary([
            $this->readinessRow('registered-nurses', 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $target->id,
            'path_type' => 'bridge_path',
            'path_payload' => [
                'steps' => [TransitionPathPayload::STEP_SKILL_OVERLAP],
            ],
        ]);

        $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_returns_not_found_for_invalid_non_array_payload_shape_even_when_transition_rows_exist(): void
    {
        $snapshot = $this->compileRecommendationChain('transition-api-invalid-payload-shape');
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

        $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
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
            'dataset_checksum' => 'checksum-transition-api-'.$slug,
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
