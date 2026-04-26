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
        'editorial_selector_asset_batch',
        'registry_asset',
    ];

    public const REVIEW_STATUSES = [
        'fixture_only',
        'draft',
        'editorial_review',
        'safety_review',
        'approved_for_staging',
    ];

    public const READING_MODES = [
        'quick',
        'standard',
        'deep',
    ];

    public const FALLBACK_POLICIES = [
        'backend_required',
        'omit_block',
        'degrade_to_boundary',
        'share_safe_summary_only',
    ];

    public const MODULE_SLOT_PREFIXES = [
        'module_00_trust_bar' => ['module_00_trust_bar.', 'trust_bar.'],
        'module_01_hero' => ['module_01_hero.', 'hero_summary.'],
        'module_02_quick_understanding' => ['module_02_quick_understanding.', 'quick_cards.'],
        'module_03_trait_deep_dive' => ['module_03_trait_deep_dive.', 'trait_deep_dive.'],
        'module_04_coupling' => ['module_04_coupling.', 'coupling_cards.'],
        'module_05_facet_reframe' => ['module_05_facet_reframe.', 'facet_reframe.'],
        'module_06_application_matrix' => ['module_06_application_matrix.', 'application_matrix.'],
        'module_07_collaboration_manual' => ['module_07_collaboration_manual.', 'collaboration_manual.'],
        'module_08_share_save' => ['module_08_share_save.', 'share_save.'],
        'module_09_feedback_data_flywheel' => ['module_09_feedback_data_flywheel.', 'feedback_block.'],
        'module_10_method_privacy' => ['module_10_method_privacy.', 'method_boundary.'],
    ];

    public const REGISTRY_MODULES = [
        'profile_signature_registry' => ['module_01_hero'],
        'state_scope_registry' => ['module_02_quick_understanding'],
        'domain_registry' => ['module_01_hero', 'module_02_quick_understanding', 'module_03_trait_deep_dive'],
        'facet_pattern_registry' => ['module_05_facet_reframe'],
        'coupling_registry' => ['module_02_quick_understanding', 'module_04_coupling'],
        'triple_pattern_registry' => ['module_04_coupling'],
        'scenario_registry' => ['module_06_application_matrix', 'module_07_collaboration_manual'],
        'action_plan_registry' => ['module_06_application_matrix'],
        'observation_feedback_registry' => ['module_09_feedback_data_flywheel'],
        'share_safety_registry' => ['module_08_share_save'],
        'boundary_registry' => ['module_00_trust_bar', 'module_01_hero', 'module_08_share_save', 'module_10_method_privacy'],
        'method_registry' => ['module_00_trust_bar', 'module_10_method_privacy'],
    ];

    public const REGISTRY_BLOCK_KINDS = [
        'profile_signature_registry' => ['hero_summary'],
        'state_scope_registry' => ['quick_cards'],
        'domain_registry' => ['hero_summary', 'quick_cards', 'trait_deep_dive', 'trait_bars'],
        'facet_pattern_registry' => ['facet_reframe'],
        'coupling_registry' => ['quick_cards', 'coupling_cards'],
        'triple_pattern_registry' => ['coupling_cards'],
        'scenario_registry' => ['application_matrix', 'collaboration_manual'],
        'action_plan_registry' => ['application_matrix'],
        'observation_feedback_registry' => ['feedback_block'],
        'share_safety_registry' => ['share_save'],
        'boundary_registry' => ['trust_bar', 'hero_summary', 'share_save', 'method_boundary'],
        'method_registry' => ['trust_bar', 'method_boundary'],
    ];

    public const REQUIRED_TRIGGER_KEYS = [
        'trait_bands',
        'facet_patterns',
        'coupling_keys',
        'triple_patterns',
        'interpretation_scopes',
        'norm_status',
        'quality_status',
        'scenario',
        'feedback_state',
        'reading_mode',
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
