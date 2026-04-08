<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\IndexStateValue;
use Tests\TestCase;

abstract class CareerScoringTestCase extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    protected function sampleContext(array $overrides = []): array
    {
        return array_merge([
            'task_prototype_signature' => ['analysis' => 0.86, 'build' => 0.74, 'coordination' => 0.54],
            'task_analysis' => 0.86,
            'task_build' => 0.74,
            'task_coordination' => 0.54,
            'structural_stability' => 0.84,
            'market_semantics_gap' => 0.12,
            'regulatory_divergence' => 0.08,
            'toolchain_divergence' => 0.16,
            'skill_gap_threshold' => 0.22,
            'psychometric_axis_coverage' => 0.82,
            'pref_abstraction' => 0.9,
            'pref_autonomy' => 0.78,
            'pref_collaboration' => 0.42,
            'pref_variability' => 0.66,
            'variant_trigger_load' => 0.1,
            'risk_tolerance' => 0.46,
            'switch_urgency' => 0.38,
            'family_constraint_level' => 0.24,
            'manager_track_preference' => 0.28,
            'time_horizon_fit' => 0.72,
            'skill_overlap' => 0.84,
            'task_overlap' => 0.79,
            'tool_overlap' => 0.76,
            'crosswalk_confidence' => 0.88,
            'median_pay_usd_annual' => 148000,
            'entry_education' => "Bachelor's degree",
            'work_experience' => 'None',
            'on_the_job_training' => 'None',
            'ai_exposure' => 0.42,
            'source_trace_evidence' => 0.92,
            'source_fields_used_count' => 7,
            'reviewer_status' => 'approved',
            'quality_confidence' => 0.88,
            'truth_manifest' => true,
            'truth_metric_id' => 'truth-1',
            'source_trace_id' => 'source-1',
            'skill_graph_id' => 'graph-1',
            'crosswalk_ids' => ['crosswalk-1'],
            'occupation_state_hash' => 'hash-1',
            'cross_market_mismatch' => false,
            'allow_pay_direct_inheritance' => true,
            'editorial_patch_required' => false,
            'editorial_patch_complete' => true,
            'index_state' => IndexStateValue::INDEXABLE,
            'index_eligible' => true,
            'last_substantive_update_at' => now()->subDays(7),
            'truth_reviewed_at' => now()->subDays(7),
            'methodology_key_count' => 4,
        ], $overrides);
    }
}
