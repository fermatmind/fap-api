<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\OccupationTruthMetric;
use App\Models\RecommendationSnapshot;
use App\Models\SourceTrace;
use App\Models\TransitionPath;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\CareerTransitionPreviewReadinessLookup;
use App\Services\Career\Transition\CareerTransitionPathWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionPathWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_a_minimal_stable_upside_path_for_a_publish_ready_first_wave_snapshot(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $target = $this->createSameFamilyTarget(
            $snapshot,
            'backend-platform-engineer',
            'Backend Platform Engineer',
            "Master's degree",
            '5 years or more',
            'Moderate-term on-the-job training',
        );
        $this->mockReadinessRows([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($target->canonical_slug, 'publish_ready', true, 'indexable', 'approved'),
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(1, $written);
        $path = TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->sole();
        $this->assertSame($snapshot->occupation_id, $path->from_occupation_id);
        $this->assertSame($target->id, $path->to_occupation_id);
        $this->assertNotSame($path->from_occupation_id, $path->to_occupation_id);
        $this->assertSame('stable_upside', $path->path_type);
        $this->assertSame(TransitionPathPayload::allowedStepLabels(), $path->normalizedPathPayload()->steps);
        $this->assertSame([
            'skill_overlap',
            'task_overlap',
            'tool_overlap',
            'same_family_target',
            'publish_ready_target',
            'index_eligible_target',
            'approved_reviewer_target',
            'safe_crosswalk_target',
        ], $path->normalizedPathPayload()->rationaleCodes);
        $this->assertSame([
            'higher_entry_education_required',
            'higher_work_experience_required',
            'higher_training_required',
        ], $path->normalizedPathPayload()->tradeoffCodes);
        $this->assertSame([
            'steps' => TransitionPathPayload::allowedStepLabels(),
            'rationale_codes' => [
                'skill_overlap',
                'task_overlap',
                'tool_overlap',
                'same_family_target',
                'publish_ready_target',
                'index_eligible_target',
                'approved_reviewer_target',
                'safe_crosswalk_target',
            ],
            'tradeoff_codes' => [
                'higher_entry_education_required',
                'higher_work_experience_required',
                'higher_training_required',
            ],
            'delta' => [
                'entry_education_delta' => [
                    'source_value' => "Bachelor's degree",
                    'target_value' => "Master's degree",
                    'direction' => 'higher',
                ],
                'work_experience_delta' => [
                    'source_value' => 'None',
                    'target_value' => '5 years or more',
                    'direction' => 'higher',
                ],
                'training_delta' => [
                    'source_value' => 'None',
                    'target_value' => 'Moderate-term on-the-job training',
                    'direction' => 'higher',
                ],
            ],
        ], $path->normalizedPathPayload()->toArray());
    }

    public function test_it_rewrites_stale_rows_for_the_same_snapshot_without_duplicates(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $target = $this->createSameFamilyTarget($snapshot, 'backend-site-reliability-engineer', 'Backend Site Reliability Engineer');
        $this->mockReadinessRows([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($target->canonical_slug, 'publish_ready', true, 'indexable', 'approved'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $snapshot->occupation_id,
            'path_type' => 'bridge_path',
            'path_payload' => ['steps' => ['invalid legacy path']],
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(1, $written);
        $this->assertSame(1, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
        $path = TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->sole();
        $this->assertSame('stable_upside', $path->path_type);
        $this->assertSame($target->id, $path->to_occupation_id);
        $this->assertNotSame($path->from_occupation_id, $path->to_occupation_id);
        $this->assertSame(TransitionPathPayload::allowedStepLabels(), $path->normalizedPathPayload()->steps);
        $this->assertContains('same_family_target', $path->normalizedPathPayload()->rationaleCodes);
    }

    public function test_it_rejects_snapshots_without_transition_claim_permission(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $payload = is_array($snapshot->snapshot_payload) ? $snapshot->snapshot_payload : [];
        $payload['claim_permissions']['allow_transition_recommendation'] = false;
        $snapshot->forceFill(['snapshot_payload' => $payload])->save();
        $target = $this->createSameFamilyTarget($snapshot, 'backend-analytics-engineer', 'Backend Analytics Engineer');
        $this->mockReadinessRows([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($target->canonical_slug, 'publish_ready', true, 'indexable', 'approved'),
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(0, $written);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
    }

    public function test_it_writes_zero_rows_when_no_safe_non_self_target_exists(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot();
        $target = $this->createSameFamilyTarget($snapshot, 'backend-ops-engineer', 'Backend Ops Engineer');
        $this->mockReadinessRows([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($target->canonical_slug, 'partial_raw', false, 'noindex', 'pending'),
        ]);

        TransitionPath::query()->create([
            'recommendation_snapshot_id' => $snapshot->id,
            'from_occupation_id' => $snapshot->occupation_id,
            'to_occupation_id' => $snapshot->occupation_id,
            'path_type' => 'stable_upside',
            'path_payload' => ['steps' => ['legacy self row']],
        ]);

        $written = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);

        $this->assertSame(0, $written);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->count());
    }

    public function test_it_rejects_blocked_targets_including_override_eligible_and_not_safely_remediable(): void
    {
        $overrideSnapshot = $this->compiledFirstWaveSnapshot(['slug' => 'software-developers']);
        $overrideTarget = $this->createSameFamilyTarget($overrideSnapshot, 'software-developers-staff', 'Software Developers Staff');
        $this->mockReadinessRows([
            $this->readinessRow($overrideSnapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($overrideTarget->canonical_slug, 'blocked_override_eligible', false, 'noindex', 'approved'),
        ]);

        $overrideWritten = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($overrideSnapshot);

        $this->assertSame(0, $overrideWritten);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $overrideSnapshot->id)->count());

        $blockedSnapshot = $this->compiledFirstWaveSnapshot(['slug' => 'marketing-managers']);
        $blockedTarget = $this->createSameFamilyTarget($blockedSnapshot, 'marketing-managers-staff', 'Marketing Managers Staff');
        $this->mockReadinessRows([
            $this->readinessRow($blockedSnapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($blockedTarget->canonical_slug, 'blocked_not_safely_remediable', false, 'noindex', 'pending'),
        ]);

        $blockedWritten = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($blockedSnapshot);

        $this->assertSame(0, $blockedWritten);
        $this->assertSame(0, TransitionPath::query()->where('recommendation_snapshot_id', $blockedSnapshot->id)->count());
    }

    public function test_it_selects_the_same_non_self_target_deterministically_on_rewrite(): void
    {
        $snapshot = $this->compiledFirstWaveSnapshot(['slug' => 'backend-architect-deterministic']);
        $alpha = $this->createSameFamilyTarget($snapshot, 'backend-alpha', 'Backend Alpha');
        $omega = $this->createSameFamilyTarget($snapshot, 'backend-omega', 'Backend Omega');

        $this->mockReadinessRows([
            $this->readinessRow($snapshot->occupation?->canonical_slug ?? '', 'publish_ready', true, 'indexable', 'approved'),
            $this->readinessRow($alpha->canonical_slug, 'publish_ready', true, 'indexable', 'approved', 'exact'),
            $this->readinessRow($omega->canonical_slug, 'publish_ready', true, 'indexable', 'approved', 'trust_inheritance'),
        ]);

        $firstWritten = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);
        $firstTarget = TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->sole()->to_occupation_id;

        $secondWritten = app(CareerTransitionPathWriter::class)->rewriteForSnapshot($snapshot);
        $secondTarget = TransitionPath::query()->where('recommendation_snapshot_id', $snapshot->id)->sole()->to_occupation_id;

        $this->assertSame(1, $firstWritten);
        $this->assertSame(1, $secondWritten);
        $this->assertSame($alpha->id, $firstTarget);
        $this->assertSame($alpha->id, $secondTarget);
    }

    /**
     * @param  array<string, mixed>  $scenarioOverrides
     */
    private function compiledFirstWaveSnapshot(array $scenarioOverrides = []): RecommendationSnapshot
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain($scenarioOverrides);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'career_first_wave_fixture',
            'dataset_version' => 'fixture.v1',
            'dataset_checksum' => 'fixture-checksum',
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
                ['materialization' => 'career_first_wave'],
            ),
        ]);

        return app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);
    }

    private function createSameFamilyTarget(
        RecommendationSnapshot $snapshot,
        string $slug,
        string $title,
        ?string $entryEducation = null,
        ?string $workExperience = null,
        ?string $training = null,
    ): \App\Models\Occupation {
        $source = $snapshot->occupation()->firstOrFail();

        $target = \App\Models\Occupation::query()->create([
            'family_id' => $source->family_id,
            'parent_id' => null,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => $source->truth_market,
            'display_market' => $source->display_market,
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
            'structural_stability' => $source->structural_stability,
            'task_prototype_signature' => $source->task_prototype_signature,
            'market_semantics_gap' => $source->market_semantics_gap,
            'regulatory_divergence' => $source->regulatory_divergence,
            'toolchain_divergence' => $source->toolchain_divergence,
            'skill_gap_threshold' => $source->skill_gap_threshold,
            'trust_inheritance_scope' => $source->trust_inheritance_scope,
        ]);

        $sourceTruthMetric = $source->truthMetrics()->latest('id')->first();
        if ($sourceTruthMetric instanceof OccupationTruthMetric) {
            $sourceTrace = SourceTrace::query()->create([
                'source_id' => 'source_'.$slug,
                'source_type' => 'fixture_dataset',
                'title' => 'Transition writer fixture source',
                'url' => 'https://example.test/sources/'.$slug,
                'fields_used' => ['entry_education', 'work_experience', 'on_the_job_training'],
                'retrieved_at' => now()->subDay(),
                'evidence_strength' => 0.93,
            ]);

            OccupationTruthMetric::query()->create([
                'occupation_id' => $target->id,
                'source_trace_id' => $sourceTrace->id,
                'median_pay_usd_annual' => $sourceTruthMetric->median_pay_usd_annual,
                'jobs_2024' => $sourceTruthMetric->jobs_2024,
                'projected_jobs_2034' => $sourceTruthMetric->projected_jobs_2034,
                'employment_change' => $sourceTruthMetric->employment_change,
                'outlook_pct_2024_2034' => $sourceTruthMetric->outlook_pct_2024_2034,
                'outlook_description' => $sourceTruthMetric->outlook_description,
                'entry_education' => $entryEducation ?? $sourceTruthMetric->entry_education,
                'work_experience' => $workExperience ?? $sourceTruthMetric->work_experience,
                'on_the_job_training' => $training ?? $sourceTruthMetric->on_the_job_training,
                'ai_exposure' => $sourceTruthMetric->ai_exposure,
                'ai_rationale' => 'fixture',
                'truth_market' => $sourceTruthMetric->truth_market,
                'effective_at' => now()->subDays(5),
                'reviewed_at' => now()->subDay(),
            ]);
        }

        return $target;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function mockReadinessRows(array $rows): void
    {
        $lookup = Mockery::mock(CareerTransitionPreviewReadinessLookup::class);
        foreach ($rows as $row) {
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
        string $crosswalkMode = 'exact',
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
            'crosswalk_mode' => $crosswalkMode,
            'reviewer_status' => $reviewerStatus,
            'index_state' => $indexState,
            'index_eligible' => $indexEligible,
            'reason_codes' => [$status],
        ];
    }
}
