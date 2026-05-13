<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecContentRegistrySlotContract
{
    public const SCHEMA_VERSION = 'riasec.deep_copy_slot_schema.v1';

    /** @var list<string> */
    private const SLOT_GROUPS = [
        'method_assets',
        'dimension_deep_copy',
        'pair_blend_copy',
        'quality_copy',
        'module_visibility_copy',
        '140q_layer_copy',
        'structural_difference_copy',
        'aspirations_copy',
        'feedback_response_copy',
    ];

    /** @var array<string,array<string,mixed>> */
    private const SLOT_DEFINITIONS = [
        'interpretation_rule_spec_v2' => [
            'slot_group' => 'method_assets',
            'required_versions' => ['interpretation_rule_version'],
            'content_fields' => ['title', 'summary', 'body'],
            'fallback_behavior' => 'reject_payload',
        ],
        'quality_rule_spec_v2' => [
            'slot_group' => 'method_assets',
            'required_versions' => ['quality_rule_version'],
            'content_fields' => ['title', 'summary', 'body'],
            'fallback_behavior' => 'reject_payload',
        ],
        'module_visibility_policy' => [
            'slot_group' => 'module_visibility_copy',
            'required_versions' => ['module_visibility_policy_id'],
            'content_fields' => ['title', 'summary', 'body'],
            'fallback_behavior' => 'omit_module',
        ],
        'dimension_deep_copy' => [
            'slot_group' => 'dimension_deep_copy',
            'required_versions' => ['interpretation_rule_version'],
            'content_fields' => ['title', 'summary', 'body', 'core_drive', 'cost', 'shadow'],
            'fallback_behavior' => 'omit_module',
        ],
        'core_drive_cost_shadow_copy' => [
            'slot_group' => 'dimension_deep_copy',
            'required_versions' => ['interpretation_rule_version'],
            'content_fields' => ['title', 'summary', 'body', 'core_drive', 'cost', 'shadow'],
            'fallback_behavior' => 'omit_module',
        ],
        'pair_blend_copy' => [
            'slot_group' => 'pair_blend_copy',
            'required_versions' => ['interpretation_rule_version'],
            'content_fields' => ['title', 'summary', 'body'],
            'fallback_behavior' => 'omit_module',
        ],
        'triad_blend_copy' => [
            'slot_group' => 'pair_blend_copy',
            'required_versions' => ['interpretation_rule_version'],
            'content_fields' => ['title', 'summary', 'body'],
            'fallback_behavior' => 'omit_module',
        ],
        '140q_task_card_copy' => [
            'slot_group' => '140q_layer_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        '140q_environment_card_copy' => [
            'slot_group' => '140q_layer_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        '140q_role_card_copy' => [
            'slot_group' => '140q_layer_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        '140q_tension_copy' => [
            'slot_group' => '140q_layer_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        'low_quality_copy' => [
            'slot_group' => 'quality_copy',
            'required_versions' => ['quality_rule_version'],
        ],
        'cautious_reading_copy' => [
            'slot_group' => 'quality_copy',
            'required_versions' => ['quality_rule_version'],
        ],
        'structural_difference_copy' => [
            'slot_group' => 'structural_difference_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        'aspirations_calibration_copy' => [
            'slot_group' => 'aspirations_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        'disagree_path_copy' => [
            'slot_group' => 'feedback_response_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
        'feedback_response_copy' => [
            'slot_group' => 'feedback_response_copy',
            'required_versions' => ['interpretation_rule_version'],
        ],
    ];

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'slot_key',
        'slot_group',
        'scale_code',
        'locale',
        'content_version',
        'applicable_form_codes',
        'applicable_profile_shapes',
        'applicable_quality_states',
        'forbidden_claims',
        'required_boundaries',
        'evidence_level',
        'source_status',
        'review_status',
        'fallback_behavior',
    ];

    /** @var list<string> */
    private const REQUIRED_BOUNDARIES = [
        'interest_evidence_only',
        'not_career_recommendation',
        'not_job_fit',
        'not_success_prediction',
        'not_ability_or_skill_measure',
        'no_60q_140q_raw_delta',
        '140q_contextual_not_more_accurate',
        'feedback_does_not_mutate_measured_result',
        'missing_content_fails_closed',
        'frontend_fallback_forbidden',
    ];

    /** @var list<string> */
    private const FORBIDDEN_FIELDS = [
        'career_match',
        'occupation_match',
        'job_fit',
        'fit_score',
        'success_prediction',
        'ranking',
        'recommended_career',
        'source_url',
        'soc_code',
        'onet_code',
        'raw_delta',
        'percentile',
        'percentiles',
        'norm',
        'z_score',
        't_score',
        'ability_inference',
        'skill_inference',
        'frontend_fallback_copy',
        'ai_generated_report',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PHRASES = [
        'Matches',
        'career recommendation',
        'recommended career',
        'best career',
        'career match',
        'occupation match',
        'job fit',
        'fit score',
        'occupation ranking',
        'success probability',
        'career success',
        'hiring suitability',
        '140Q more accurate',
        'more accurate',
        '更准确',
        '60Q wrong',
        'raw delta',
        'score increased',
        'score decreased',
        'percentile',
        'normative percentile',
        'z-score',
        't-score',
        'ability inference',
        'skill inference',
        '职业推荐',
        '岗位匹配',
        '匹配度',
        '适合度',
        '职业成功',
    ];

    /**
     * @return array<string,mixed>
     */
    public function schema(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scale_code' => 'RIASEC',
            'slot_status' => 'schema_contract_only',
            'runtime_public_copy_included' => false,
            'missing_content_policy' => 'omit_module_fail_closed',
            'unknown_slot_policy' => 'reject',
            'frontend_fallback_allowed' => false,
            'allowed_slot_groups' => self::SLOT_GROUPS,
            'required_fields' => self::REQUIRED_FIELDS,
            'required_boundaries' => self::REQUIRED_BOUNDARIES,
            'allowed_review_statuses' => $this->allowedReviewStatuses(),
            'allowed_evidence_levels' => $this->allowedEvidenceLevels(),
            'allowed_source_statuses' => $this->allowedSourceStatuses(),
            'allowed_fallback_behaviors' => $this->allowedFallbackBehaviors(),
            'slots' => array_values(array_map(
                fn (string $slotKey): array => $this->slotDefinition($slotKey),
                $this->slotKeys()
            )),
            'forbidden_fields' => self::FORBIDDEN_FIELDS,
            'forbidden_phrases' => self::FORBIDDEN_PHRASES,
        ];
    }

    /**
     * @param  array<string,mixed>  $slot
     * @return array{ok:bool,errors:list<string>}
     */
    public function validate(array $slot): array
    {
        $errors = [];
        $slotKey = trim((string) ($slot['slot_key'] ?? ''));
        $slotDefinition = $this->slotDefinition($slotKey);

        foreach (self::REQUIRED_FIELDS as $required) {
            if (! array_key_exists($required, $slot) || $this->isBlank($slot[$required])) {
                $errors[] = 'missing_'.$required;
            }
        }

        if ($slotDefinition === null) {
            $errors[] = 'unsupported_slot_key';
        } elseif ((string) ($slot['slot_group'] ?? '') !== $slotDefinition['slot_group']) {
            $errors[] = 'slot_group_mismatch';
        }

        if ((string) ($slot['scale_code'] ?? '') !== 'RIASEC') {
            $errors[] = 'unsupported_scale_code';
        }
        if (! in_array((string) ($slot['slot_group'] ?? ''), self::SLOT_GROUPS, true)) {
            $errors[] = 'unsupported_slot_group';
        }
        if (! in_array((string) ($slot['review_status'] ?? ''), $this->allowedReviewStatuses(), true)) {
            $errors[] = 'unsupported_review_status';
        }
        if (! in_array((string) ($slot['evidence_level'] ?? ''), $this->allowedEvidenceLevels(), true)) {
            $errors[] = 'unsupported_evidence_level';
        }
        if (! in_array((string) ($slot['source_status'] ?? ''), $this->allowedSourceStatuses(), true)) {
            $errors[] = 'unsupported_source_status';
        }
        if (! in_array((string) ($slot['fallback_behavior'] ?? ''), $this->allowedFallbackBehaviors(), true)) {
            $errors[] = 'unsupported_fallback_behavior';
        }
        if (in_array((string) ($slot['fallback_behavior'] ?? ''), ['frontend_fallback', 'frontend_generated_copy'], true)) {
            $errors[] = 'frontend_fallback_forbidden';
        }

        foreach (['applicable_form_codes', 'applicable_profile_shapes', 'applicable_quality_states', 'forbidden_claims', 'required_boundaries'] as $field) {
            if (! is_array($slot[$field] ?? null) || $slot[$field] === []) {
                $errors[] = $field.'_must_be_non_empty_array';
            }
        }
        if (! is_array($slot['applicable_codes'] ?? null) && ! is_array($slot['applicable_dimensions'] ?? null)) {
            $errors[] = 'missing_applicable_codes_or_dimensions';
        }
        if ($slotDefinition !== null) {
            foreach ((array) ($slotDefinition['required_versions'] ?? []) as $requiredVersion) {
                if (! array_key_exists($requiredVersion, $slot) || $this->isBlank($slot[$requiredVersion])) {
                    $errors[] = 'missing_'.$requiredVersion;
                }
            }
            if ((string) ($slot['fallback_behavior'] ?? '') !== (string) ($slotDefinition['fallback_behavior'] ?? 'omit_module')) {
                $errors[] = 'fallback_behavior_mismatch';
            }
        }

        $boundaries = array_values(array_map('strval', (array) ($slot['required_boundaries'] ?? [])));
        foreach (self::REQUIRED_BOUNDARIES as $boundary) {
            if (! in_array($boundary, $boundaries, true)) {
                $errors[] = 'missing_boundary_'.$boundary;
            }
        }

        foreach (self::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $slot)) {
                $errors[] = 'forbidden_field_'.$field;
            }
        }
        $errors = array_merge($errors, $this->forbiddenPhraseErrors($slot));

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array{slot_key:string,exists:bool,behavior:string,module_state:string,frontend_fallback_allowed:bool,reason:string}
     */
    public function missingContentBehavior(string $slotKey): array
    {
        $definition = $this->slotDefinition($slotKey);

        return [
            'slot_key' => $slotKey,
            'exists' => $definition !== null,
            'behavior' => $definition === null ? 'reject_payload' : (string) $definition['fallback_behavior'],
            'module_state' => $definition === null ? 'hidden' : 'omitted',
            'frontend_fallback_allowed' => false,
            'reason' => $definition === null ? 'unknown_slot_rejected' : 'missing_backend_content_fails_closed',
        ];
    }

    /**
     * @return list<string>
     */
    private function slotKeys(): array
    {
        return array_keys($this->slotDefinitionMap());
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function slotDefinitionMap(): array
    {
        $default = [
            'required_versions' => ['interpretation_rule_version'],
            'content_fields' => ['title', 'summary', 'body'],
            'fallback_behavior' => 'omit_module',
        ];

        $definitions = [];
        foreach (self::SLOT_DEFINITIONS as $slotKey => $definition) {
            $definitions[$slotKey] = array_merge($default, $definition, ['slot_key' => $slotKey]);
        }

        return $definitions;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function slotDefinition(string $slotKey): ?array
    {
        if ($slotKey === '') {
            return null;
        }

        $definitions = $this->slotDefinitionMap();
        if (! array_key_exists($slotKey, $definitions)) {
            return null;
        }

        $definition = $definitions[$slotKey];

        return [
            'slot_key' => $slotKey,
            'slot_group' => $definition['slot_group'],
            'scale_code' => 'RIASEC',
            'required_versions' => $definition['required_versions'],
            'content_fields' => $definition['content_fields'],
            'required_fields' => self::REQUIRED_FIELDS,
            'fallback_behavior' => $definition['fallback_behavior'],
            'missing_behavior' => 'omit_module',
            'public_runtime_authority' => 'backend_or_cms_registry_only',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedReviewStatuses(): array
    {
        return ['fixture_only', 'draft', 'content_review', 'psychometric_review', 'approved_for_staging', 'deprecated'];
    }

    /**
     * @return list<string>
     */
    private function allowedEvidenceLevels(): array
    {
        return ['contract_only', 'content_example', 'theory_based', 'expert_review_required', 'expert_reviewed', 'validated'];
    }

    /**
     * @return list<string>
     */
    private function allowedSourceStatuses(): array
    {
        return ['schema_only', 'docs_only_candidate', 'placeholder_fixture', 'reviewed_content_copy', 'content_example_not_registry_match'];
    }

    /**
     * @return list<string>
     */
    private function allowedFallbackBehaviors(): array
    {
        return ['omit_module', 'minimal_backend_empty_state', 'reject_payload'];
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function forbiddenPhraseErrors(array $payload): array
    {
        $errors = [];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (self::FORBIDDEN_PHRASES as $phrase) {
            if (str_contains($json, $phrase)) {
                $errors[] = 'forbidden_claim_phrase_'.$this->errorToken($phrase);
            }
        }

        return $errors;
    }

    private function errorToken(string $phrase): string
    {
        $token = strtolower($phrase);
        $token = preg_replace('/[^a-z0-9]+/', '_', $token) ?: 'non_ascii';
        $token = trim($token, '_');

        return $token !== '' ? $token : 'non_ascii';
    }
}
