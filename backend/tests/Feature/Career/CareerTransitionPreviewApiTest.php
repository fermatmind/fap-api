<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

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
            'path_payload' => ['steps' => ['fixture-only transition evidence']],
        ]);

        $this->getJson('/api/v0.5/career/transition-preview?type=intj')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_transition_preview')
            ->assertJsonPath('bundle_version', 'career.protocol.transition_preview.v1')
            ->assertJsonPath('path_type', 'stable_upside')
            ->assertJsonPath('target_job.canonical_slug', 'registered-nurses')
            ->assertJsonPath('trust_summary.allow_transition_recommendation', true)
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonStructure([
                'path_type',
                'target_job' => ['occupation_uuid', 'canonical_slug', 'title'],
                'score_summary' => [
                    'mobility_score' => ['value', 'integrity_state', 'band'],
                    'confidence_score' => ['value', 'integrity_state', 'band'],
                ],
                'trust_summary' => ['allow_transition_recommendation', 'reviewer_status', 'reason_codes'],
                'seo_contract' => ['canonical_path', 'canonical_target', 'index_state', 'index_eligible', 'reason_codes'],
                'provenance_meta' => ['recommendation_snapshot_id', 'transition_path_id', 'compiler_version', 'compile_run_id'],
            ])
            ->assertJsonMissingPath('why_this_path')
            ->assertJsonMissingPath('what_is_lost')
            ->assertJsonMissingPath('bridge_steps_90d');
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
            'path_payload' => ['steps' => ['fixture-only transition evidence']],
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
