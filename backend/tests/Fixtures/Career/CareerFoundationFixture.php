<?php

declare(strict_types=1);

namespace Tests\Fixtures\Career;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\ProjectionLineageReason;
use App\Domain\Career\ReviewerStatus;
use App\Models\ContextSnapshot;
use App\Models\EditorialPatch;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Models\OccupationSkillGraph;
use App\Models\OccupationTruthMetric;
use App\Models\ProfileProjection;
use App\Models\ProjectionLineage;
use App\Models\RecommendationSnapshot;
use App\Models\SourceTrace;
use App\Models\TransitionPath;
use App\Models\TrustManifest;

final class CareerFoundationFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function seedMinimalChain(): array
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'software-engineering',
            'title_en' => 'Software Engineering',
            'title_zh' => '软件工程',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'backend-architect',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'trust_inheritance',
            'canonical_title_en' => 'Backend Architect',
            'canonical_title_zh' => '后端架构师',
            'search_h1_zh' => '后端架构师职业诊断',
            'structural_stability' => 0.84,
            'task_prototype_signature' => [
                'analysis' => 0.86,
                'build' => 0.74,
                'coordination' => 0.58,
            ],
            'market_semantics_gap' => 0.22,
            'regulatory_divergence' => 0.14,
            'toolchain_divergence' => 0.19,
            'skill_gap_threshold' => 0.40,
            'trust_inheritance_scope' => [
                'allow_task_truth' => true,
                'allow_pay_direct_inheritance' => false,
            ],
        ]);

        $alias = OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'alias' => '后端架构师',
            'normalized' => '后端架构师',
            'lang' => 'zh-CN',
            'register' => 'market_title',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.97,
            'confidence_score' => 0.98,
            'seniority_hint' => 'senior',
            'function_hint' => 'backend_architecture',
        ]);

        $crosswalk = OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => '15-1252',
            'source_title' => 'Software Developers',
            'mapping_type' => 'trust_inheritance',
            'confidence_score' => 0.84,
            'notes' => 'BLS proxy for specialized market title.',
        ]);

        $sourceTrace = SourceTrace::query()->create([
            'source_id' => 'bls_ooh_15_1252',
            'source_type' => 'bls_ooh',
            'title' => 'BLS Occupational Outlook Handbook',
            'url' => 'https://www.bls.gov/ooh/computer-and-information-technology/software-developers.htm',
            'fields_used' => ['median_pay_usd_annual', 'outlook_pct_2024_2034'],
            'retrieved_at' => now()->subDay(),
            'evidence_strength' => 0.92,
        ]);

        $truthMetric = OccupationTruthMetric::query()->create([
            'occupation_id' => $occupation->id,
            'source_trace_id' => $sourceTrace->id,
            'median_pay_usd_annual' => 133080,
            'jobs_2024' => 1693800,
            'projected_jobs_2034' => 1961400,
            'employment_change' => 267700,
            'outlook_pct_2024_2034' => 16,
            'outlook_description' => 'Much faster than average',
            'entry_education' => "Bachelor's degree",
            'work_experience' => 'None',
            'on_the_job_training' => 'None',
            'ai_exposure' => 9,
            'ai_rationale' => 'Routine coding work is being restructured by LLMs.',
            'truth_market' => 'US',
            'effective_at' => now()->subDays(7),
            'reviewed_at' => now()->subDay(),
        ]);

        $skillGraph = OccupationSkillGraph::query()->create([
            'occupation_id' => $occupation->id,
            'stack_key' => 'core',
            'skill_overlap_graph' => ['distributed_systems' => 0.82],
            'task_overlap_graph' => ['api_contracts' => 0.91],
            'tool_overlap_graph' => ['kubernetes' => 0.71],
        ]);

        $trustManifest = TrustManifest::query()->create([
            'occupation_id' => $occupation->id,
            'content_version' => 'v4.1',
            'data_version' => '2026.04',
            'logic_version' => 'foundation_only',
            'locale_context' => ['truth_market' => 'US', 'display_market' => 'CN'],
            'methodology' => ['source_program' => 'BLS OOH'],
            'reviewer_status' => ReviewerStatus::APPROVED,
            'reviewer_id' => 'editor-1',
            'reviewed_at' => now()->subHours(4),
            'ai_assistance' => ['used' => true, 'mode' => 'summary'],
            'quality' => ['confidence' => 0.88],
            'last_substantive_update_at' => now()->subDay(),
            'next_review_due_at' => now()->addMonths(3),
        ]);

        $editorialPatch = EditorialPatch::query()->create([
            'occupation_id' => $occupation->id,
            'required' => false,
            'status' => 'not_required',
            'patch_version' => null,
            'notes' => ['reason' => 'trust inheritance sufficient'],
        ]);

        $indexState = IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => IndexStateValue::TRUST_LIMITED,
            'index_eligible' => false,
            'canonical_path' => '/career/jobs/backend-architect',
            'canonical_target' => null,
            'reason_codes' => ['trust_limited'],
            'changed_at' => now()->subHour(),
        ]);

        $contextSnapshot = ContextSnapshot::query()->create([
            'identity_id' => 'identity-1',
            'visitor_id' => 'visitor-1',
            'captured_at' => now()->subMinutes(30),
            'current_occupation_id' => $occupation->id,
            'employment_status' => 'employed',
            'monthly_comp_band' => '25k_40k',
            'burnout_level' => 0.62,
            'switch_urgency' => 0.71,
            'risk_tolerance' => 0.45,
            'geo_region' => 'cn-east',
            'family_constraint_level' => 0.40,
            'manager_track_preference' => 0.32,
            'time_horizon_months' => 12,
            'context_payload' => [
                'trigger' => 'career_refresh',
                'notes' => ['wants higher autonomy'],
            ],
        ]);

        $parentProjection = ProfileProjection::query()->create([
            'identity_id' => 'identity-1',
            'visitor_id' => 'visitor-1',
            'context_snapshot_id' => $contextSnapshot->id,
            'projection_version' => 'career_projection_v1',
            'psychometric_axis_coverage' => 0.76,
            'projection_payload' => ['fit_axes' => ['abstraction' => 0.88]],
        ]);

        $childProjection = ProfileProjection::query()->create([
            'identity_id' => 'identity-1',
            'visitor_id' => 'visitor-1',
            'context_snapshot_id' => $contextSnapshot->id,
            'projection_version' => 'career_projection_v1',
            'psychometric_axis_coverage' => 0.81,
            'projection_payload' => ['fit_axes' => ['abstraction' => 0.92]],
        ]);

        $lineage = ProjectionLineage::query()->create([
            'parent_projection_id' => $parentProjection->id,
            'child_projection_id' => $childProjection->id,
            'trigger_context_snapshot_id' => $contextSnapshot->id,
            'trigger_assessment_id' => 'assessment-1',
            'lineage_reason' => ProjectionLineageReason::CONTEXT_REFRESH,
            'diff_summary' => ['abstraction' => ['from' => 0.88, 'to' => 0.92]],
        ]);

        $recommendationSnapshot = RecommendationSnapshot::query()->create([
            'profile_projection_id' => $childProjection->id,
            'context_snapshot_id' => $contextSnapshot->id,
            'occupation_id' => $occupation->id,
            'snapshot_payload' => ['fit_score' => 0.84, 'claim_permissions' => ['allow_strong_claim' => false]],
        ]);

        $transitionPath = TransitionPath::query()->create([
            'recommendation_snapshot_id' => $recommendationSnapshot->id,
            'from_occupation_id' => $occupation->id,
            'to_occupation_id' => $occupation->id,
            'path_type' => 'stable_upside',
            'path_payload' => ['steps' => ['deepen system design', 'lead more cross-team decisions']],
        ]);

        return [
            'family' => $family,
            'occupation' => $occupation,
            'alias' => $alias,
            'crosswalk' => $crosswalk,
            'sourceTrace' => $sourceTrace,
            'truthMetric' => $truthMetric,
            'skillGraph' => $skillGraph,
            'trustManifest' => $trustManifest,
            'editorialPatch' => $editorialPatch,
            'indexState' => $indexState,
            'contextSnapshot' => $contextSnapshot,
            'parentProjection' => $parentProjection,
            'childProjection' => $childProjection,
            'lineage' => $lineage,
            'recommendationSnapshot' => $recommendationSnapshot,
            'transitionPath' => $transitionPath,
        ];
    }
}
