<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

final class BigFiveResultPageV2SelectorAssetContract
{
    public const SCHEMA_VERSION = 'fap.big5.result_page_v2.selector_asset.v0.1';

    public const REQUIRED_FIELDS = [
        'version',
        'asset_key',
        'registry_key',
        'module_key',
        'block_key',
        'block_kind',
        'slot_key',
        'trigger',
        'priority',
        'mutual_exclusion_group',
        'can_stack_with',
        'reading_modes',
        'scenario',
        'scope',
        'required_evidence_level',
        'evidence_level',
        'safety_level',
        'shareable',
        'shareable_policy',
        'fallback_policy',
        'content_source',
        'provenance',
        'replacement_policy',
        'forbidden_public_fields',
        'review_status',
        'public_payload',
        'internal_metadata',
    ];

    public const REGISTRY_KEYS = [
        'profile_signature_registry',
        'state_scope_registry',
        'domain_registry',
        'facet_pattern_registry',
        'coupling_registry',
        'triple_pattern_registry',
        'scenario_registry',
        'action_plan_registry',
        'observation_feedback_registry',
        'share_safety_registry',
        'boundary_registry',
        'method_registry',
    ];

    public const CONTENT_SOURCES = [
        'fixture',
        'gpt_selector_asset_batch',
        'gpt_generated_selector_ready_p0_full_v0_3',
        'editorial_selector_asset_batch',
        'registry_asset',
    ];

    public const REVIEW_STATUSES = [
        'fixture_only',
        'draft',
        'draft_for_psychometric_review',
        'editorial_review',
        'safety_review',
        'approved_for_staging',
    ];

    public const READING_MODES = [
        'quick',
        'standard',
        'deep',
    ];

    public const SCENARIOS = [
        'global',
        'work',
        'relationship',
        'stress',
        'growth',
        'action',
        'collaboration',
        'share',
        'feedback',
        'follow_up',
        'multi_scenario',
        'advanced',
        'unspecified',
    ];

    public const SCOPES = [
        'all',
        'all_scopes',
        'quality_acceptable',
        'facet_supported',
        'clear_signature',
        'mixed_signature',
        'balanced_profile',
        'high_tension_profile',
        'facet_inconsistent',
        'norm_unavailable',
        'low_quality',
        'retest_recommended',
        'share_safe_summary_only',
        'standard',
    ];

    public const SHAREABLE_POLICIES = [
        'internal_policy_only',
        'not_shareable',
        'not_shareable_by_default',
        'not_shareable_unless_rewritten_by_share_safety_registry',
        'share_safe_summary_only_when_scope_requires',
        'label_only_after_share_safety_rewrite',
        'required_for_every_shareable_true_block',
        'module_07_requires_share_safety_registry',
        'requires_share_safety_registry',
        'share_safe_behavioral_only',
    ];

    public const FALLBACK_POLICIES = [
        'backend_required',
        'omit_block',
        'omit',
        'degrade_to_boundary',
        'boundary_only',
        'neutral_unavailable',
        'share_safe_summary_only',
    ];

    public const MODULE_SLOT_PREFIXES = [
        'module_00_trust_bar' => ['module_00_trust_bar.', 'trust_bar.'],
        'module_01_hero' => ['module_01_hero.', 'hero_summary.'],
        'module_02_quick_understanding' => ['module_02_quick_understanding.', 'module_02_quick.', 'quick_cards.'],
        'module_03_trait_deep_dive' => ['module_03_trait_deep_dive.', 'trait_deep_dive.'],
        'module_04_coupling' => ['module_04_coupling.', 'coupling_cards.'],
        'module_05_facet_reframe' => ['module_05_facet_reframe.', 'facet_reframe.'],
        'module_06_application_matrix' => ['module_06_application_matrix.', 'application_matrix.'],
        'module_07_collaboration_manual' => ['module_07_collaboration_manual.', 'collaboration_manual.'],
        'module_08_share_save' => ['module_08_share_save.', 'share_save.'],
        'module_09_feedback_data_flywheel' => ['module_09_feedback_data_flywheel.', 'module_09_feedback.', 'feedback_block.'],
        'module_10_method_privacy' => ['module_10_method_privacy.', 'method_boundary.'],
    ];

    public const REGISTRY_MODULES = [
        'profile_signature_registry' => ['module_01_hero'],
        'state_scope_registry' => [
            'module_02_quick_understanding',
            'module_03_trait_deep_dive',
            'module_04_coupling',
            'module_05_facet_reframe',
            'module_08_share_save',
            'module_09_feedback_data_flywheel',
            'module_10_method_privacy',
        ],
        'domain_registry' => ['module_01_hero', 'module_02_quick_understanding', 'module_03_trait_deep_dive'],
        'facet_pattern_registry' => ['module_05_facet_reframe'],
        'coupling_registry' => ['module_02_quick_understanding', 'module_04_coupling'],
        'triple_pattern_registry' => ['module_02_quick_understanding', 'module_04_coupling'],
        'scenario_registry' => ['module_06_application_matrix', 'module_07_collaboration_manual'],
        'action_plan_registry' => ['module_06_application_matrix'],
        'observation_feedback_registry' => ['module_09_feedback_data_flywheel'],
        'share_safety_registry' => ['module_08_share_save'],
        'boundary_registry' => ['module_00_trust_bar', 'module_01_hero', 'module_08_share_save', 'module_10_method_privacy'],
        'method_registry' => ['module_00_trust_bar', 'module_10_method_privacy'],
    ];

    public const REGISTRY_BLOCK_KINDS = [
        'profile_signature_registry' => ['hero_summary'],
        'state_scope_registry' => [
            'quick_cards',
            'trait_deep_dive',
            'coupling_cards',
            'facet_reframe',
            'share_save',
            'feedback_block',
            'method_boundary',
        ],
        'domain_registry' => ['hero_summary', 'quick_cards', 'trait_deep_dive', 'trait_bars'],
        'facet_pattern_registry' => ['facet_reframe'],
        'coupling_registry' => ['quick_cards', 'coupling_cards'],
        'triple_pattern_registry' => ['quick_cards', 'coupling_cards'],
        'scenario_registry' => ['application_matrix', 'collaboration_manual'],
        'action_plan_registry' => ['application_matrix'],
        'observation_feedback_registry' => ['feedback_block'],
        'share_safety_registry' => ['share_save'],
        'boundary_registry' => ['trust_bar', 'hero_summary', 'share_save', 'method_boundary'],
        'method_registry' => ['trust_bar', 'method_boundary'],
    ];

    public const REQUIRED_TRIGGER_KEYS = [
        'reading_mode',
    ];

    public const SELECTOR_EVIDENCE_LEVELS = [
        'cross_trait_interpretation',
        'facet_inference',
        'feedback_prompt',
        'method_boundary',
        'scenario_interpretation',
        'share_policy',
        'trait_band_interpretation',
    ];

    public const SELECTOR_SAFETY_LEVELS = [
        'normal',
        'required_boundary',
        'sensitive_non_clinical',
        'share_safe_behavioral',
        'share_safety_required',
    ];

    public const FORBIDDEN_PUBLIC_FIELDS = [
        'editor_notes',
        'qa_notes',
        'selection_guidance',
        'import_policy',
        'internal_metadata',
        'raw_score',
        'raw_scores',
        'raw_mean',
        'score_vector',
        'domain_vector',
        'facet_vector',
        'percentile',
        'percentiles',
        'normal_curve',
        'user_confirmed_type',
        'fixed_type',
        'type_code',
        'type_name',
        'diagnosis',
    ];
}
