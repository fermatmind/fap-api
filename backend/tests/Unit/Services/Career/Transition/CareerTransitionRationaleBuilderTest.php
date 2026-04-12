<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Models\Occupation;
use App\Models\OccupationTruthMetric;
use App\Models\SourceTrace;
use App\Services\Career\Transition\CareerTransitionRationaleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerTransitionRationaleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_machine_coded_rationale_and_tradeoff_fields_for_a_safe_target(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'transition-rationale-source']);
        $source = $chain['occupation']->fresh();
        $target = $this->createSameFamilyTargetWithTruth(
            $source,
            'transition-rationale-target',
            "Master's degree",
            '5 years or more',
            'Moderate-term on-the-job training',
        );

        $payload = app(CareerTransitionRationaleBuilder::class)->build(
            $source,
            $target,
            TransitionPathPayload::allowedStepLabels(),
            [
                'status' => 'publish_ready',
                'index_eligible' => true,
                'reviewer_status' => 'approved',
                'crosswalk_mode' => 'exact',
            ],
        );

        $this->assertSame([
            'skill_overlap',
            'task_overlap',
            'tool_overlap',
            'same_family_target',
            'publish_ready_target',
            'index_eligible_target',
            'approved_reviewer_target',
            'safe_crosswalk_target',
        ], $payload['rationale_codes']);
        $this->assertSame([
            'higher_entry_education_required',
            'higher_work_experience_required',
            'higher_training_required',
        ], $payload['tradeoff_codes']);
        $this->assertSame([
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
        ], $payload['delta']);
        $this->assertArrayNotHasKey('why_this_path', $payload);
        $this->assertArrayNotHasKey('what_is_lost', $payload);
        $this->assertArrayNotHasKey('bridge_steps_90d', $payload);
    }

    public function test_it_omits_delta_and_tradeoff_when_truth_is_missing_or_not_rankable(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'transition-rationale-thin-source']);
        $source = $chain['occupation']->fresh();
        $target = Occupation::query()->create([
            'family_id' => $source->family_id,
            'canonical_slug' => 'transition-rationale-thin-target',
            'entity_level' => $source->entity_level,
            'truth_market' => $source->truth_market,
            'display_market' => $source->display_market,
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => 'Thin Target',
            'canonical_title_zh' => 'Thin Target',
            'search_h1_zh' => 'Thin Target',
            'structural_stability' => $source->structural_stability,
            'task_prototype_signature' => $source->task_prototype_signature,
            'market_semantics_gap' => $source->market_semantics_gap,
            'regulatory_divergence' => $source->regulatory_divergence,
            'toolchain_divergence' => $source->toolchain_divergence,
            'skill_gap_threshold' => $source->skill_gap_threshold,
            'trust_inheritance_scope' => $source->trust_inheritance_scope,
        ]);

        $payload = app(CareerTransitionRationaleBuilder::class)->build(
            $source,
            $target,
            [TransitionPathPayload::STEP_SKILL_OVERLAP],
            [
                'status' => 'publish_ready',
                'index_eligible' => true,
                'reviewer_status' => 'approved',
                'crosswalk_mode' => 'exact',
            ],
        );

        $this->assertSame([
            'skill_overlap',
            'same_family_target',
            'publish_ready_target',
            'index_eligible_target',
            'approved_reviewer_target',
            'safe_crosswalk_target',
        ], $payload['rationale_codes']);
        $this->assertArrayNotHasKey('tradeoff_codes', $payload);
        $this->assertArrayNotHasKey('delta', $payload);
    }

    private function createSameFamilyTargetWithTruth(
        Occupation $source,
        string $slug,
        string $entryEducation,
        string $workExperience,
        string $training,
    ): Occupation {
        $target = Occupation::query()->create([
            'family_id' => $source->family_id,
            'canonical_slug' => $slug,
            'entity_level' => $source->entity_level,
            'truth_market' => $source->truth_market,
            'display_market' => $source->display_market,
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => $slug,
            'canonical_title_zh' => $slug,
            'search_h1_zh' => $slug,
            'structural_stability' => $source->structural_stability,
            'task_prototype_signature' => $source->task_prototype_signature,
            'market_semantics_gap' => $source->market_semantics_gap,
            'regulatory_divergence' => $source->regulatory_divergence,
            'toolchain_divergence' => $source->toolchain_divergence,
            'skill_gap_threshold' => $source->skill_gap_threshold,
            'trust_inheritance_scope' => $source->trust_inheritance_scope,
        ]);

        $sourceTrace = SourceTrace::query()->create([
            'source_id' => 'source_'.$slug,
            'source_type' => 'fixture_dataset',
            'title' => 'Transition rationale fixture source',
            'url' => 'https://example.test/sources/'.$slug,
            'fields_used' => ['entry_education', 'work_experience', 'on_the_job_training'],
            'retrieved_at' => now()->subDay(),
            'evidence_strength' => 0.94,
        ]);

        OccupationTruthMetric::query()->create([
            'occupation_id' => $target->id,
            'source_trace_id' => $sourceTrace->id,
            'median_pay_usd_annual' => 170000,
            'jobs_2024' => 1000,
            'projected_jobs_2034' => 1200,
            'employment_change' => 200,
            'outlook_pct_2024_2034' => 10.0,
            'outlook_description' => 'Faster than average',
            'entry_education' => $entryEducation,
            'work_experience' => $workExperience,
            'on_the_job_training' => $training,
            'ai_exposure' => 3.5,
            'ai_rationale' => 'fixture',
            'truth_market' => $source->truth_market,
            'effective_at' => now()->subDays(5),
            'reviewed_at' => now()->subDay(),
        ]);

        return $target;
    }
}
