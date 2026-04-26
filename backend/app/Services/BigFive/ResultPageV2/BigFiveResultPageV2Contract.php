<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

final class BigFiveResultPageV2Contract
{
    public const PAYLOAD_KEY = 'big5_result_page_v2';

    public const SCHEMA_VERSION = 'fap.big5.result_page.v2';

    public const PROJECTION_SCHEMA_VERSION = 'fap.big5.projection.v2';

    public const SCALE_CODE = 'BIG5_OCEAN';

    public const MODULE_KEYS = [
        'module_00_trust_bar',
        'module_01_hero',
        'module_02_quick_understanding',
        'module_03_trait_deep_dive',
        'module_04_coupling',
        'module_05_facet_reframe',
        'module_06_application_matrix',
        'module_07_collaboration_manual',
        'module_08_share_save',
        'module_09_feedback_data_flywheel',
        'module_10_method_privacy',
    ];

    public const BLOCK_KINDS = [
        'trust_bar',
        'hero_summary',
        'trait_bars',
        'quick_cards',
        'trait_deep_dive',
        'coupling_cards',
        'facet_reframe',
        'application_matrix',
        'collaboration_manual',
        'share_save',
        'feedback_block',
        'method_boundary',
    ];

    public const INTERPRETATION_SCOPES = [
        'clear_signature',
        'mixed_signature',
        'balanced_profile',
        'high_tension_profile',
        'facet_inconsistent',
        'norm_unavailable',
        'low_quality',
        'retest_recommended',
        'share_safe_summary_only',
    ];

    public const SAFETY_LEVELS = [
        'standard',
        'boundary',
        'degraded',
        'share_safe',
    ];

    public const EVIDENCE_LEVELS = [
        'descriptive',
        'computed',
        'normed',
        'registry_backed',
        'data_supported',
    ];

    public const FORBIDDEN_PUBLIC_FIELDS = [
        'editor_note',
        'qa_note',
        'selection_guidance',
        'import_policy',
        'governance_metadata',
        'internal_metadata',
        'internal_notes',
        'private_metadata',
        'review_status',
        'codex_policy',
        'replacement_policy',
        'selection_context',
        'type_code',
        'canonical_type',
        'fixed_type',
        'type_name',
        'user_confirmed_type',
    ];

    public const SHARE_FORBIDDEN_SCORE_FIELDS = [
        'raw_score',
        'raw_scores',
        'raw_mean',
        'z',
        't',
        'standardized_scores',
        'score_vector',
        'percentile',
        'percentiles',
        'domains',
        'facets',
        'facet_vector',
        'domain_vector',
    ];

    public const LOW_QUALITY_ALLOWED_MODULE_KEYS = [
        'module_00_trust_bar',
        'module_09_feedback_data_flywheel',
        'module_10_method_privacy',
    ];

    public const REQUIRED_PROJECTION_FIELDS = [
        'attempt_id',
        'result_version',
        'scale_code',
        'form_code',
        'domains',
        'domain_bands',
        'facets',
        'facet_highlights',
        'norm_status',
        'norm_group_id',
        'norm_version',
        'quality_status',
        'quality_flags',
        'profile_signature',
        'dominant_couplings',
        'interpretation_scope',
        'confidence_flags',
        'safety_flags',
        'public_fields',
        'internal_only_fields',
    ];
}
