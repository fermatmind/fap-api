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
        return self::seedHighTrustCompleteChain([
            'identity_id' => 'identity-1',
            'visitor_id' => 'visitor-1',
            'trigger_assessment_id' => 'assessment-1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function seedHighTrustCompleteChain(array $overrides = []): array
    {
        return self::seedScenario(array_replace([
            'slug' => 'backend-architect',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'direct_match',
            'structural_stability' => 0.86,
            'task_signature' => [
                'analysis' => 0.88,
                'build' => 0.75,
                'coordination' => 0.54,
            ],
            'crosswalk_confidence' => 0.91,
            'source_evidence_strength' => 0.95,
            'median_pay' => 148000,
            'ai_exposure' => 4.2,
            'reviewer_status' => ReviewerStatus::APPROVED,
            'quality_confidence' => 0.9,
            'editorial_patch_required' => false,
            'editorial_patch_status' => 'not_required',
            'index_state' => IndexStateValue::INDEXABLE,
            'index_eligible' => true,
            'fit_axes' => [
                'abstraction' => 0.9,
                'autonomy' => 0.82,
                'collaboration' => 0.46,
                'variability' => 0.72,
                'variant_trigger_load' => 0.08,
            ],
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public static function seedTrustLimitedCrossMarketChain(): array
    {
        return self::seedScenario([
            'slug' => 'backend-architect-cn-market',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'trust_inheritance',
            'structural_stability' => 0.82,
            'task_signature' => [
                'analysis' => 0.84,
                'build' => 0.72,
                'coordination' => 0.61,
            ],
            'crosswalk_confidence' => 0.78,
            'source_evidence_strength' => 0.74,
            'median_pay' => 133080,
            'ai_exposure' => 8.9,
            'reviewer_status' => 'pending',
            'quality_confidence' => 0.68,
            'editorial_patch_required' => true,
            'editorial_patch_status' => 'queued',
            'index_state' => IndexStateValue::TRUST_LIMITED,
            'index_eligible' => false,
            'fit_axes' => [
                'abstraction' => 0.84,
                'autonomy' => 0.76,
                'collaboration' => 0.38,
                'variability' => 0.66,
                'variant_trigger_load' => 0.22,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function seedMissingTruthChain(): array
    {
        return self::seedScenario([
            'slug' => 'backend-architect-missing-truth',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'trust_inheritance',
            'structural_stability' => 0.79,
            'task_signature' => [
                'analysis' => 0.86,
                'build' => 0.74,
                'coordination' => 0.58,
            ],
            'crosswalk_confidence' => 0.71,
            'source_evidence_strength' => 0.62,
            'median_pay' => null,
            'ai_exposure' => null,
            'reviewer_status' => 'in_review',
            'quality_confidence' => 0.58,
            'editorial_patch_required' => true,
            'editorial_patch_status' => 'queued',
            'index_state' => IndexStateValue::NOINDEX,
            'index_eligible' => false,
            'fit_axes' => [
                'abstraction' => 0.88,
                'autonomy' => 0.8,
                'collaboration' => 0.34,
                'variability' => 0.7,
                'variant_trigger_load' => 0.3,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @return array<string,mixed>
     */
    private static function seedScenario(array $scenario): array
    {
        $slug = (string) ($scenario['slug'] ?? 'backend-architect');
        $identityId = (string) ($scenario['identity_id'] ?? ('identity-'.$slug));
        $visitorId = (string) ($scenario['visitor_id'] ?? ('visitor-'.$slug));
        $triggerAssessmentId = (string) ($scenario['trigger_assessment_id'] ?? ('assessment-'.$slug));

        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'software-engineering-'.$slug,
            'title_en' => 'Software Engineering',
            'title_zh' => '软件工程',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => (string) ($scenario['truth_market'] ?? 'US'),
            'display_market' => (string) ($scenario['display_market'] ?? 'CN'),
            'crosswalk_mode' => (string) ($scenario['crosswalk_mode'] ?? 'trust_inheritance'),
            'canonical_title_en' => 'Backend Architect',
            'canonical_title_zh' => '后端架构师',
            'search_h1_zh' => '后端架构师职业诊断',
            'structural_stability' => (float) ($scenario['structural_stability'] ?? 0.84),
            'task_prototype_signature' => $scenario['task_signature'] ?? [
                'analysis' => 0.86,
                'build' => 0.74,
                'coordination' => 0.58,
            ],
            'market_semantics_gap' => (float) ($scenario['market_semantics_gap'] ?? 0.22),
            'regulatory_divergence' => (float) ($scenario['regulatory_divergence'] ?? 0.14),
            'toolchain_divergence' => (float) ($scenario['toolchain_divergence'] ?? 0.19),
            'skill_gap_threshold' => (float) ($scenario['skill_gap_threshold'] ?? 0.40),
            'trust_inheritance_scope' => [
                'allow_task_truth' => true,
                'allow_pay_direct_inheritance' => (bool) ($scenario['allow_pay_direct_inheritance'] ?? false),
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
            'mapping_type' => (string) ($scenario['crosswalk_mode'] ?? 'trust_inheritance'),
            'confidence_score' => (float) ($scenario['crosswalk_confidence'] ?? 0.84),
            'notes' => 'Career scoring fixture crosswalk.',
        ]);

        $sourceTrace = SourceTrace::query()->create([
            'source_id' => 'source_'.$slug,
            'source_type' => 'fixture_dataset',
            'title' => 'Career scoring fixture source',
            'url' => 'https://example.test/sources/'.$slug,
            'fields_used' => ['median_pay_usd_annual', 'outlook_pct_2024_2034', 'ai_exposure'],
            'retrieved_at' => now()->subDay(),
            'evidence_strength' => (float) ($scenario['source_evidence_strength'] ?? 0.92),
        ]);

        $truthMetric = OccupationTruthMetric::query()->create([
            'occupation_id' => $occupation->id,
            'source_trace_id' => $sourceTrace->id,
            'median_pay_usd_annual' => $scenario['median_pay'],
            'jobs_2024' => 1693800,
            'projected_jobs_2034' => 1961400,
            'employment_change' => 267700,
            'outlook_pct_2024_2034' => 16,
            'outlook_description' => 'Much faster than average',
            'entry_education' => "Bachelor's degree",
            'work_experience' => 'None',
            'on_the_job_training' => 'None',
            'ai_exposure' => $scenario['ai_exposure'],
            'ai_rationale' => 'Career scoring fixture rationale.',
            'truth_market' => (string) ($scenario['truth_market'] ?? 'US'),
            'effective_at' => now()->subDays(7),
            'reviewed_at' => now()->subDay(),
        ]);

        $skillGraph = OccupationSkillGraph::query()->create([
            'occupation_id' => $occupation->id,
            'stack_key' => 'core',
            'skill_overlap_graph' => ['distributed_systems' => 0.82, 'systems_design' => 0.86],
            'task_overlap_graph' => ['api_contracts' => 0.91, 'service_decomposition' => 0.74],
            'tool_overlap_graph' => ['kubernetes' => 0.71, 'postgresql' => 0.77],
        ]);

        $trustManifest = TrustManifest::query()->create([
            'occupation_id' => $occupation->id,
            'content_version' => 'v4.1',
            'data_version' => '2026.04',
            'logic_version' => 'scoring_v1.2',
            'locale_context' => [
                'truth_market' => (string) ($scenario['truth_market'] ?? 'US'),
                'display_market' => (string) ($scenario['display_market'] ?? 'CN'),
            ],
            'methodology' => [
                'source_program' => 'Fixture source',
                'scoring_formula_version' => 'score_v1.2',
                'crosswalk_policy' => (string) ($scenario['crosswalk_mode'] ?? 'trust_inheritance'),
            ],
            'reviewer_status' => (string) ($scenario['reviewer_status'] ?? ReviewerStatus::APPROVED),
            'reviewer_id' => 'reviewer-'.$slug,
            'reviewed_at' => now()->subHours(4),
            'ai_assistance' => ['used' => true, 'mode' => 'structuring'],
            'quality' => ['confidence_score' => (float) ($scenario['quality_confidence'] ?? 0.88)],
            'last_substantive_update_at' => now()->subDay(),
            'next_review_due_at' => now()->addMonths(3),
        ]);

        $editorialPatch = EditorialPatch::query()->create([
            'occupation_id' => $occupation->id,
            'required' => (bool) ($scenario['editorial_patch_required'] ?? false),
            'status' => (string) ($scenario['editorial_patch_status'] ?? 'not_required'),
            'patch_version' => null,
            'notes' => ['reason' => 'fixture scenario'],
        ]);

        $indexState = IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => (string) ($scenario['index_state'] ?? IndexStateValue::TRUST_LIMITED),
            'index_eligible' => (bool) ($scenario['index_eligible'] ?? false),
            'canonical_path' => '/career/jobs/'.$slug,
            'canonical_target' => null,
            'reason_codes' => (array) ($scenario['index_reason_codes'] ?? ['fixture']),
            'changed_at' => now()->subHour(),
        ]);

        $contextSnapshot = ContextSnapshot::query()->create([
            'identity_id' => $identityId,
            'visitor_id' => $visitorId,
            'captured_at' => now()->subMinutes(30),
            'current_occupation_id' => $occupation->id,
            'employment_status' => 'employed',
            'monthly_comp_band' => '25k_40k',
            'burnout_level' => (float) ($scenario['burnout_level'] ?? 0.48),
            'switch_urgency' => (float) ($scenario['switch_urgency'] ?? 0.54),
            'risk_tolerance' => (float) ($scenario['risk_tolerance'] ?? 0.45),
            'geo_region' => 'cn-east',
            'family_constraint_level' => (float) ($scenario['family_constraint_level'] ?? 0.40),
            'manager_track_preference' => (float) ($scenario['manager_track_preference'] ?? 0.32),
            'time_horizon_months' => (int) ($scenario['time_horizon_months'] ?? 12),
            'context_payload' => [
                'trigger' => 'career_refresh',
                'notes' => ['fixture scenario'],
            ],
        ]);

        $parentProjection = ProfileProjection::query()->create([
            'identity_id' => $identityId,
            'visitor_id' => $visitorId,
            'context_snapshot_id' => $contextSnapshot->id,
            'projection_version' => 'career_projection_v1',
            'psychometric_axis_coverage' => (float) ($scenario['parent_axis_coverage'] ?? 0.76),
            'projection_payload' => [
                'fit_axes' => [
                    'abstraction' => 0.82,
                    'autonomy' => 0.72,
                    'collaboration' => 0.44,
                    'variability' => 0.63,
                    'variant_trigger_load' => 0.1,
                ],
            ],
        ]);

        $childProjection = ProfileProjection::query()->create([
            'identity_id' => $identityId,
            'visitor_id' => $visitorId,
            'context_snapshot_id' => $contextSnapshot->id,
            'projection_version' => 'career_projection_v1',
            'psychometric_axis_coverage' => (float) ($scenario['child_axis_coverage'] ?? 0.81),
            'projection_payload' => [
                'fit_axes' => (array) ($scenario['fit_axes'] ?? [
                    'abstraction' => 0.88,
                    'autonomy' => 0.78,
                    'collaboration' => 0.42,
                    'variability' => 0.68,
                    'variant_trigger_load' => 0.12,
                ]),
            ],
        ]);

        $lineage = ProjectionLineage::query()->create([
            'parent_projection_id' => $parentProjection->id,
            'child_projection_id' => $childProjection->id,
            'trigger_context_snapshot_id' => $contextSnapshot->id,
            'trigger_assessment_id' => $triggerAssessmentId,
            'lineage_reason' => ProjectionLineageReason::CONTEXT_REFRESH,
            'diff_summary' => ['fit_axes' => ['scenario' => $slug]],
        ]);

        $recommendationSnapshot = RecommendationSnapshot::query()->create([
            'profile_projection_id' => $childProjection->id,
            'context_snapshot_id' => $contextSnapshot->id,
            'occupation_id' => $occupation->id,
            'snapshot_payload' => [
                'legacy_fixture' => true,
                'claim_permissions' => ['allow_strong_claim' => false],
            ],
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
