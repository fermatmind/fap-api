<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

final class BigFiveResultPageV2SourceAuthorityMap
{
    public const VERSION = 'big5_result_page_v2.source_authority.v1';

    public const CONTENT_SOURCES = [
        'fixture',
        'registry_asset',
        'transformed_old_v2_registry',
        'composer_projection',
        'compatibility_wrapper',
    ];

    public const FALLBACK_POLICIES = [
        'backend_required',
        'omit_block',
        'degrade_to_boundary',
        'share_safe_summary_only',
    ];

    public const OLD_V2_DIRECT_PREFIXES = [
        'old_v2_atomic',
        'old_v2_modifiers',
        'old_v2_synergies',
        'old_v2_facet_glossary',
        'old_v2_facet_precision',
        'old_v2_action_rules',
        'old_v2_shared',
    ];

    public const OLD_V2_ALLOWED_REUSE_STATUSES = [
        'transform_required',
        'internal_only',
        'not_v2_ready',
    ];

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function registries(): array
    {
        return [
            'domain_registry' => [
                'registry_key' => 'domain_registry',
                'purpose' => 'Own O/C/E/A/N banded domain interpretation blocks for trait bars, quick reading, and deep dives.',
                'owner' => 'backend_content_registry',
                'input_fields' => ['domains', 'domain_bands', 'norm_status', 'quality_status'],
                'output_block_kinds' => ['hero_summary', 'trait_bars', 'quick_cards', 'trait_deep_dive'],
                'allowed_modules' => ['module_01_hero', 'module_02_quick_understanding', 'module_03_trait_deep_dive'],
                'current_code_basis' => ['BIG5_OCEAN/v2/registry/atomic', 'BIG5_OCEAN/v2/registry/modifiers'],
                'missing_pieces' => ['5-band assets', 'V2.0 module-native block records', 'source manifest'],
                'public_allowed_fields' => ['summary_zh', 'body_zh', 'trait', 'score_band', 'projection_refs'],
                'internal_only_fields' => ['editor_note', 'selection_guidance', 'import_policy', 'raw_score_vector'],
                'safety_constraints' => ['non_type_system', 'no_clinical_or_hiring_claims', 'norm_sensitive'],
                'evidence_level_requirement' => 'registry_backed',
                'shareable_policy' => 'not_shareable_unless_rewritten_by_share_safety_registry',
                'fallback_policy' => 'omit_block',
                'versioning_policy' => 'versioned_registry_asset_with_manifest',
            ],
            'facet_registry' => [
                'registry_key' => 'facet_registry',
                'purpose' => 'Own 30-facet glossary and facet reframe blocks when item_count and confidence support public interpretation.',
                'owner' => 'backend_content_registry',
                'input_fields' => ['facets', 'facet_highlights', 'quality_flags', 'confidence_flags'],
                'output_block_kinds' => ['facet_reframe'],
                'allowed_modules' => ['module_05_facet_reframe'],
                'current_code_basis' => ['BIG5_OCEAN/v2/registry/facet_glossary', 'BIG5_OCEAN/v2/registry/facet_precision'],
                'missing_pieces' => ['facet confidence gates', 'degraded facet copy', 'rendered preview QA'],
                'public_allowed_fields' => ['summary_zh', 'facets', 'item_count', 'confidence', 'claim_strength'],
                'internal_only_fields' => ['selection_guidance', 'facet_weighting_formula', 'qa_note'],
                'safety_constraints' => ['no_independent_measurement_claim_without_support', 'degrade_when_confidence_missing'],
                'evidence_level_requirement' => 'computed',
                'shareable_policy' => 'not_shareable_by_default',
                'fallback_policy' => 'degrade_to_boundary',
                'versioning_policy' => 'facet_asset_version_plus_scoring_form_version',
            ],
            'coupling_registry' => [
                'registry_key' => 'coupling_registry',
                'purpose' => 'Own cross-domain coupling and tension interpretation selected from the trait vector.',
                'owner' => 'backend_content_registry',
                'input_fields' => ['domains', 'domain_bands', 'dominant_couplings', 'interpretation_scope'],
                'output_block_kinds' => ['quick_cards', 'coupling_cards'],
                'allowed_modules' => ['module_02_quick_understanding', 'module_04_coupling'],
                'current_code_basis' => ['BIG5_OCEAN/v2/registry/synergies'],
                'missing_pieces' => ['canonical V2 coupling coverage', 'mutex policy', 'max_show policy', 'QA matrix'],
                'public_allowed_fields' => ['summary_zh', 'coupling_key', 'traits', 'strength', 'action_zh'],
                'internal_only_fields' => ['priority_weight_formula', 'selection_context', 'mutex_debug'],
                'safety_constraints' => ['trait_vector_not_type', 'no_cross_scale_comparison'],
                'evidence_level_requirement' => 'computed',
                'shareable_policy' => 'not_shareable_unless_rewritten_by_share_safety_registry',
                'fallback_policy' => 'omit_block',
                'versioning_policy' => 'coupling_registry_version_with_trigger_contract',
            ],
            'scenario_registry' => [
                'registry_key' => 'scenario_registry',
                'purpose' => 'Own work, relationship, stress, growth, action, and collaboration application blocks.',
                'owner' => 'backend_content_registry',
                'input_fields' => ['domain_bands', 'dominant_couplings', 'interpretation_scope'],
                'output_block_kinds' => ['application_matrix', 'collaboration_manual'],
                'allowed_modules' => ['module_06_application_matrix', 'module_07_collaboration_manual'],
                'current_code_basis' => ['BIG5_OCEAN/v2/registry/action_rules'],
                'missing_pieces' => ['collaboration manual assets', 'scenario-specific boundary copy', 'module feedback mapping'],
                'public_allowed_fields' => ['summary_zh', 'scenario', 'action_zh', 'difficulty_level', 'time_horizon'],
                'internal_only_fields' => ['priority_weight', 'ranking_debug', 'selection_guidance'],
                'safety_constraints' => ['no_hiring_decision_claims', 'no_diagnostic_stress_claims'],
                'evidence_level_requirement' => 'registry_backed',
                'shareable_policy' => 'module_07_requires_share_safety_registry',
                'fallback_policy' => 'omit_block',
                'versioning_policy' => 'scenario_registry_version_with_locale',
            ],
            'profile_signature_registry' => [
                'registry_key' => 'profile_signature_registry',
                'purpose' => 'Own auxiliary profile/signature labels without presenting Big Five as a fixed type system.',
                'owner' => 'backend_content_registry',
                'input_fields' => ['profile_signature', 'dominant_couplings', 'interpretation_scope', 'safety_flags'],
                'output_block_kinds' => ['hero_summary'],
                'allowed_modules' => ['module_01_hero'],
                'current_code_basis' => ['none_live_for_v2_0'],
                'missing_pieces' => ['signature naming policy', 'non-type wording QA', 'source authority manifest'],
                'public_allowed_fields' => ['signature_key', 'label_key', 'summary_zh', 'is_fixed_type'],
                'internal_only_fields' => ['naming_rationale', 'editor_note', 'selection_context'],
                'safety_constraints' => ['must_not_claim_fixed_type', 'must_not_use_user_confirmed_type'],
                'evidence_level_requirement' => 'computed',
                'shareable_policy' => 'label_only_after_share_safety_rewrite',
                'fallback_policy' => 'omit_block',
                'versioning_policy' => 'signature_registry_version_with_safety_review',
            ],
            'state_scope_registry' => [
                'registry_key' => 'state_scope_registry',
                'purpose' => 'Own interpretation strategy for clear, mixed, balanced, high tension, low quality, missing norms, and retest scopes.',
                'owner' => 'backend_projection_contract',
                'input_fields' => ['interpretation_scope', 'quality_status', 'quality_flags', 'norm_status', 'confidence_flags'],
                'output_block_kinds' => ['quick_cards', 'feedback_block', 'method_boundary'],
                'allowed_modules' => ['module_02_quick_understanding', 'module_09_feedback_data_flywheel', 'module_10_method_privacy'],
                'current_code_basis' => ['BigFiveResultPageV2Contract::INTERPRETATION_SCOPES'],
                'missing_pieces' => ['scope selection service', 'result-state copy registry', 'low-quality rendered QA'],
                'public_allowed_fields' => ['scope', 'summary_zh', 'quality_status', 'norm_status'],
                'internal_only_fields' => ['scope_scoring_formula', 'qa_note', 'confidence_debug'],
                'safety_constraints' => ['degrade_when_low_quality', 'no_percentile_when_norm_unavailable'],
                'evidence_level_requirement' => 'computed',
                'shareable_policy' => 'share_safe_summary_only_when_scope_requires',
                'fallback_policy' => 'degrade_to_boundary',
                'versioning_policy' => 'scope_contract_version',
            ],
            'observation_feedback_registry' => [
                'registry_key' => 'observation_feedback_registry',
                'purpose' => 'Own module-level feedback, resonance, action choice, retest, and follow-up prompt block contracts.',
                'owner' => 'backend_observation_contract',
                'input_fields' => ['attempt_id', 'module_key', 'block_key', 'interpretation_scope'],
                'output_block_kinds' => ['feedback_block'],
                'allowed_modules' => ['module_09_feedback_data_flywheel'],
                'current_code_basis' => ['none_live_for_big5_v2_0', 'enneagram_observation_pattern_reference_only'],
                'missing_pieces' => ['Big Five feedback schema', 'event names', 'state machine', 'privacy review'],
                'public_allowed_fields' => ['summary_zh', 'feedback_event', 'module_key', 'block_key'],
                'internal_only_fields' => ['user_confirmed_type', 'resonance_model_debug', 'selection_guidance'],
                'safety_constraints' => ['no_user_confirmed_type', 'no_type_override', 'privacy_minimal'],
                'evidence_level_requirement' => 'descriptive',
                'shareable_policy' => 'not_shareable',
                'fallback_policy' => 'backend_required',
                'versioning_policy' => 'feedback_contract_version',
            ],
            'share_safety_registry' => [
                'registry_key' => 'share_safety_registry',
                'purpose' => 'Own public sharing redaction rules and share-safe summaries for collaboration and save/share modules.',
                'owner' => 'backend_safety_contract',
                'input_fields' => ['safety_flags', 'shareable', 'profile_signature', 'interpretation_scope'],
                'output_block_kinds' => ['collaboration_manual', 'share_save'],
                'allowed_modules' => ['module_07_collaboration_manual', 'module_08_share_save'],
                'current_code_basis' => ['none_live_for_v2_0'],
                'missing_pieces' => ['share card schema', 'sensitive score redaction tests', 'social preview QA'],
                'public_allowed_fields' => ['summary_zh', 'share_label', 'shareable', 'safety_level'],
                'internal_only_fields' => ['raw_scores', 'facet_vector', 'risk_flags', 'editor_note'],
                'safety_constraints' => ['no_raw_scores', 'no_high_risk_psychological_judgment', 'no_sensitive_percentiles'],
                'evidence_level_requirement' => 'descriptive',
                'shareable_policy' => 'required_for_every_shareable_true_block',
                'fallback_policy' => 'share_safe_summary_only',
                'versioning_policy' => 'share_safety_registry_version',
            ],
            'boundary_registry' => [
                'registry_key' => 'boundary_registry',
                'purpose' => 'Own non-diagnostic, non-type, non-hiring, norm availability, privacy, and low-quality boundary blocks.',
                'owner' => 'backend_safety_contract',
                'input_fields' => ['safety_flags', 'norm_status', 'quality_status', 'interpretation_scope'],
                'output_block_kinds' => ['trust_bar', 'hero_summary', 'share_save', 'method_boundary'],
                'allowed_modules' => ['module_00_trust_bar', 'module_01_hero', 'module_08_share_save', 'module_10_method_privacy'],
                'current_code_basis' => ['BIG5_OCEAN/v2/registry/shared/methodology'],
                'missing_pieces' => ['V2.0 boundary pack', 'privacy copy owner', 'rendered preview gate'],
                'public_allowed_fields' => ['summary_zh', 'boundary_key', 'norm_status', 'quality_status'],
                'internal_only_fields' => ['legal_review_note', 'qa_note', 'import_policy'],
                'safety_constraints' => ['backend_required', 'no_frontend_fallback'],
                'evidence_level_requirement' => 'descriptive',
                'shareable_policy' => 'only_share_safe_boundary_snippets',
                'fallback_policy' => 'backend_required',
                'versioning_policy' => 'boundary_registry_version_with_legal_review',
            ],
            'method_registry' => [
                'registry_key' => 'method_registry',
                'purpose' => 'Own method, scoring provenance, norms availability, privacy, and access explanation blocks.',
                'owner' => 'backend_psychometrics_contract',
                'input_fields' => ['scale_code', 'form_code', 'norm_status', 'norm_group_id', 'norm_version', 'quality_status'],
                'output_block_kinds' => ['trust_bar', 'method_boundary'],
                'allowed_modules' => ['module_00_trust_bar', 'module_10_method_privacy'],
                'current_code_basis' => ['BIG5_OCEAN/v2/registry/shared/methodology'],
                'missing_pieces' => ['V2.0 method pack', 'norm status display policy', 'privacy display policy'],
                'public_allowed_fields' => ['summary_zh', 'method_key', 'norm_status', 'form_code'],
                'internal_only_fields' => ['raw_norm_build_metadata', 'qa_note', 'selection_guidance'],
                'safety_constraints' => ['no_norm_curve_without_norms', 'backend_required', 'no_frontend_fallback'],
                'evidence_level_requirement' => 'descriptive',
                'shareable_policy' => 'not_shareable_by_default',
                'fallback_policy' => 'backend_required',
                'versioning_policy' => 'method_registry_version_with_norm_version',
            ],
        ];
    }

    /**
     * @return array<string,list<string>>
     */
    public static function moduleRegistryMap(): array
    {
        return [
            'module_00_trust_bar' => ['boundary_registry', 'method_registry'],
            'module_01_hero' => ['domain_registry', 'profile_signature_registry', 'boundary_registry'],
            'module_02_quick_understanding' => ['state_scope_registry', 'coupling_registry', 'domain_registry'],
            'module_03_trait_deep_dive' => ['domain_registry'],
            'module_04_coupling' => ['coupling_registry'],
            'module_05_facet_reframe' => ['facet_registry'],
            'module_06_application_matrix' => ['scenario_registry'],
            'module_07_collaboration_manual' => ['scenario_registry', 'share_safety_registry'],
            'module_08_share_save' => ['share_safety_registry', 'boundary_registry'],
            'module_09_feedback_data_flywheel' => ['observation_feedback_registry', 'state_scope_registry'],
            'module_10_method_privacy' => ['boundary_registry', 'method_registry', 'state_scope_registry'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function oldV2RegistryMap(): array
    {
        return [
            'atomic' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/atomic',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'domain_registry',
                'reuse_status' => 'transform_required',
                'reason' => 'Old assets are section-slot prose for the old 8-section report, not module-native V2.0 blocks.',
            ],
            'modifiers' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/modifiers',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'domain_registry',
                'reuse_status' => 'transform_required',
                'reason' => 'Sentence injections can inform 5-band modifiers but cannot directly own V2.0 blocks.',
            ],
            'synergies' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/synergies',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'coupling_registry',
                'reuse_status' => 'transform_required',
                'reason' => 'Rules are useful coupling candidates but require V2.0 module keys, safety metadata, and source refs.',
            ],
            'facet_glossary' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/facet_glossary',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'facet_registry',
                'reuse_status' => 'transform_required',
                'reason' => 'Glossary copy is reusable as source material but needs confidence/item-count gates.',
            ],
            'facet_precision' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/facet_precision',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'facet_registry',
                'reuse_status' => 'transform_required',
                'reason' => 'Precision rules can seed facet reframe logic but must not claim independent measurement.',
            ],
            'action_rules' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/action_rules',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'scenario_registry',
                'reuse_status' => 'transform_required',
                'reason' => 'Scenario action rules can seed Module 6 but do not cover collaboration manual or share safety.',
            ],
            'shared_methodology' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/shared/methodology.json',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'method_registry',
                'reuse_status' => 'internal_only',
                'reason' => 'Current methodology text names old v2 engine rollout state and should not surface as V2.0 copy.',
            ],
            'shared_labels' => [
                'current_path' => 'backend/content_packs/BIG5_OCEAN/v2/registry/shared',
                'current_runtime_contract' => 'fap.big5.report.v1',
                'v2_0_target_registry' => 'boundary_registry',
                'reuse_status' => 'not_v2_ready',
                'reason' => 'Labels/headlines are old shell metadata and cannot be treated as V2.0 content authority.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function registryKeys(): array
    {
        return array_keys(self::registries());
    }

    public static function isKnownRegistryKey(string $registryKey): bool
    {
        return array_key_exists($registryKey, self::registries());
    }

    public static function isKnownOldV2Group(string $oldV2Group): bool
    {
        return array_key_exists($oldV2Group, self::oldV2RegistryMap());
    }

    public static function oldV2GroupTargetsRegistry(string $oldV2Group, string $registryKey): bool
    {
        return (self::oldV2RegistryMap()[$oldV2Group]['v2_0_target_registry'] ?? null) === $registryKey;
    }

    public static function registryAllowsModule(string $registryKey, string $moduleKey): bool
    {
        $registry = self::registries()[$registryKey] ?? null;
        if (! is_array($registry)) {
            return false;
        }

        return in_array($moduleKey, (array) ($registry['allowed_modules'] ?? []), true);
    }

    public static function moduleAllowsRegistry(string $moduleKey, string $registryKey): bool
    {
        return in_array($registryKey, self::moduleRegistryMap()[$moduleKey] ?? [], true);
    }
}
