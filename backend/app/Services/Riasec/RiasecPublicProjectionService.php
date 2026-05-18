<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Result;

final class RiasecPublicProjectionService
{
    private const LABELS = [
        'R' => ['en' => 'Realistic', 'zh-CN' => '现实型'],
        'I' => ['en' => 'Investigative', 'zh-CN' => '研究型'],
        'A' => ['en' => 'Artistic', 'zh-CN' => '艺术型'],
        'S' => ['en' => 'Social', 'zh-CN' => '社会型'],
        'E' => ['en' => 'Enterprising', 'zh-CN' => '企业型'],
        'C' => ['en' => 'Conventional', 'zh-CN' => '常规型'],
    ];

    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
        private readonly RiasecActivityExplorerService $activityExplorer,
        private readonly RiasecExplorationFeedbackOverlayService $feedbackOverlay,
        private readonly RiasecInterpretationRuleContract $interpretationRuleContract,
        private readonly RiasecQualityRuleContract $qualityRuleContract,
        private readonly RiasecReportModuleSelector $moduleSelector,
        private readonly RiasecDeepCopySlotRegistry $deepCopySlots,
    ) {}

    public function buildFromResult(Result $result, string $locale = 'zh-CN'): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $scores = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        if ($scores === [] && is_array($payload['scores_0_100'] ?? null)) {
            $scores = $payload['scores_0_100'];
        }

        $topCode = trim((string) ($payload['top_code'] ?? ($result->type_code ?? '')));
        $primary = trim((string) ($payload['primary_type'] ?? substr($topCode, 0, 1)));
        $secondary = trim((string) ($payload['secondary_type'] ?? substr($topCode, 1, 1)));
        $tertiary = trim((string) ($payload['tertiary_type'] ?? substr($topCode, 2, 1)));
        $formCode = $this->measurementContract->canonicalFormCode(
            (string) ($payload['form_code'] ?? data_get($payload, 'measurement_contract_v1.form.form_code', '')),
            (int) ($payload['answer_count'] ?? 0)
        );
        $measurementContract = is_array($payload['measurement_contract_v1'] ?? null)
            ? $payload['measurement_contract_v1']
            : $this->measurementContract->forFormCode($formCode, (int) ($payload['answer_count'] ?? 0));
        $comparePolicy = is_array($payload['compare_policy_v1'] ?? null)
            ? $payload['compare_policy_v1']
            : (is_array($measurementContract['compare_policy'] ?? null)
                ? $measurementContract['compare_policy']
                : $this->measurementContract->comparePolicyForFormCode($formCode, (int) ($payload['answer_count'] ?? 0)));

        return [
            'schema' => 'fap.riasec.public_projection.v1',
            'top_code' => $topCode,
            'primary_type' => $primary,
            'secondary_type' => $secondary,
            'tertiary_type' => $tertiary,
            'scores_0_100' => $this->normalizeScores($scores),
            'clarity_index' => (float) ($payload['clarity_index'] ?? 0),
            'breadth_index' => (float) ($payload['breadth_index'] ?? 0),
            'quality_grade' => (string) ($payload['quality_grade'] ?? data_get($payload, 'quality.grade', 'A')),
            'quality_flags' => array_values(array_filter(array_map('strval', (array) ($payload['quality_flags'] ?? data_get($payload, 'quality.flags', []))))),
            'dimension_labels' => $this->dimensionLabels($locale),
            'form' => [
                'form_code' => $formCode,
                'score_space_version' => (string) data_get($measurementContract, 'form.score_space_version', ''),
                'compare_compatibility_group' => (string) ($comparePolicy['compare_compatibility_group'] ?? ''),
                'cross_form_comparable' => false,
                'raw_score_delta_allowed' => false,
            ],
            'measurement_contract_v1' => $measurementContract,
            'compare_policy_v1' => $comparePolicy,
            'enhanced_breakdown' => [
                'activity' => $this->prefixedScores($payload, 'activity_'),
                'environment' => $this->prefixedScores($payload, 'env_'),
                'role' => $this->prefixedScores($payload, 'role_'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildV2FromResult(Result $result, string $locale = 'zh-CN', bool $snapshotBound = false): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $v1 = $this->buildFromResult($result, $locale);
        $measurementContract = is_array($v1['measurement_contract_v1'] ?? null)
            ? $v1['measurement_contract_v1']
            : $this->measurementContract->forFormCode((string) data_get($v1, 'form.form_code', ''));
        $comparePolicy = is_array($v1['compare_policy_v1'] ?? null)
            ? $v1['compare_policy_v1']
            : (is_array($measurementContract['compare_policy'] ?? null)
                ? $measurementContract['compare_policy']
                : $this->measurementContract->comparePolicyForFormCode((string) data_get($v1, 'form.form_code', '')));
        $scoreSpaceVersion = (string) data_get($measurementContract, 'form.score_space_version', data_get($v1, 'form.score_space_version', ''));
        $formCode = (string) data_get($measurementContract, 'form.form_code', data_get($v1, 'form.form_code', ''));
        $qualityRule = $this->qualityRuleContract->build(array_merge($payload, [
            'form_code' => $formCode,
            'answer_count' => (int) data_get($measurementContract, 'form.question_count', (int) ($payload['answer_count'] ?? 0)),
        ]));
        $interpretationRule = $this->interpretationRuleContract->build($payload, $qualityRule);

        $projection = [
            'schema_version' => 'riasec.public_projection.v2',
            'scale_code' => 'RIASEC',
            'locale' => str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en',
            'holland_code' => [
                'code' => (string) ($v1['top_code'] ?? ''),
                'primary_type' => (string) ($v1['primary_type'] ?? ''),
                'secondary_type' => (string) ($v1['secondary_type'] ?? ''),
                'tertiary_type' => (string) ($v1['tertiary_type'] ?? ''),
            ],
            'scores' => [
                'score_kind' => 'dimension_scores_0_100',
                'dimensions' => $this->dimensionScoreRows(is_array($v1['scores_0_100'] ?? null) ? $v1['scores_0_100'] : [], $locale),
            ],
            'form' => [
                'form_code' => $formCode,
                'question_count' => (int) data_get($measurementContract, 'form.question_count', 0),
                'form_kind' => (string) data_get($measurementContract, 'form.form_kind', ''),
                'score_space_version' => $scoreSpaceVersion,
                'compare_compatibility_group' => (string) ($comparePolicy['compare_compatibility_group'] ?? ''),
                'cross_form_comparable' => false,
                'raw_score_delta_allowed' => false,
            ],
            'measurement_evidence' => [
                'measurement_contract_version' => (string) ($measurementContract['schema_version'] ?? RiasecMeasurementContract::SCHEMA_VERSION),
                'scoring_spec_version' => $this->firstString([
                    $result->scoring_spec_version ?? null,
                    data_get($payload, 'version_snapshot.scoring_spec_version'),
                    data_get($payload, 'scoring_spec_version'),
                ]),
                'form_version' => $this->firstString([
                    $result->dir_version ?? null,
                    data_get($payload, 'version_snapshot.pack_version'),
                ]),
                'content_package_version' => $this->firstString([
                    $result->content_package_version ?? null,
                    data_get($payload, 'version_snapshot.pack_version'),
                ]),
                'score_space_version' => $scoreSpaceVersion,
                'normalization_method' => (string) data_get($measurementContract, 'scoring.normalization_method', ''),
                'quality_rule_version' => $this->firstString([
                    data_get($payload, 'version_snapshot.quality_rule_version'),
                    data_get($payload, 'quality_rule_version'),
                    $qualityRule['quality_rule_version'] ?? null,
                ]),
                'quality_rule_status' => (string) data_get($measurementContract, 'quality.quality_rule_status', ''),
                'interpretation_rule_version' => (string) ($interpretationRule['interpretation_rule_version'] ?? ''),
                'validation_status' => 'runtime_contract_defined_validation_pending',
                'snapshot_bound' => $snapshotBound,
            ],
            'quality' => [
                'grade' => (string) ($v1['quality_grade'] ?? ''),
                'flags' => is_array($v1['quality_flags'] ?? null) ? array_values($v1['quality_flags']) : [],
                'low_quality_strength' => (string) data_get($measurementContract, 'quality.low_quality_strength', ''),
                'quality_rule_version' => (string) ($qualityRule['quality_rule_version'] ?? ''),
                'quality_state' => (string) ($qualityRule['quality_state'] ?? ''),
                'response_quality' => (string) ($qualityRule['response_quality'] ?? ''),
                'reading_strength' => (string) ($qualityRule['reading_strength'] ?? ''),
                'result_page_behavior' => (string) ($qualityRule['result_page_behavior'] ?? ''),
                'module_policy' => is_array($qualityRule['module_policy'] ?? null) ? $qualityRule['module_policy'] : [],
                'score_mutation_allowed' => false,
                'measured_holland_code_mutation_allowed' => false,
            ],
            'interpretation_state' => $this->publicInterpretationState($interpretationRule),
            'indices' => [
                'clarity_index' => (float) ($v1['clarity_index'] ?? 0),
                'breadth_index' => (float) ($v1['breadth_index'] ?? 0),
            ],
            'claim_boundary' => is_array($measurementContract['claim_boundary'] ?? null) ? $measurementContract['claim_boundary'] : [],
            'compare_policy_v1' => $comparePolicy,
            'content_boundary' => [
                'occupation_examples_policy' => (string) data_get(
                    $measurementContract,
                    'claim_boundary.occupation_examples_policy',
                    'content_example_not_registry_match_without_reviewed_registry_source'
                ),
            ],
            'activity_explorer_v0_1' => $this->activityExplorer->build((string) ($v1['top_code'] ?? ''), $locale),
        ];
        $projection['module_visibility_policy'] = $this->moduleSelector->build($projection);
        $projection['deep_content_slots_v1'] = $this->deepContentSlotsEnvelope($projection, $locale);
        $projection['exploration_feedback_overlay_v0_1'] = $this->feedbackOverlay->build($result, $projection, $snapshotBound);

        return $projection;
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function deepContentSlotsEnvelope(array $projection, string $locale): array
    {
        $qualityState = (string) data_get($projection, 'quality.quality_state', 'normal');
        $formCode = (string) data_get($projection, 'form.form_code', 'riasec_60');
        $topCode = (string) data_get($projection, 'holland_code.code', '');
        $modulePolicy = is_array($projection['module_visibility_policy'] ?? null) ? $projection['module_visibility_policy'] : [];

        $slots = [];
        foreach ($this->deepCopySlots->dimensionSlots() as $slot) {
            $this->appendRenderableSlot($slots, $slot, 'six_dimension_map', $modulePolicy, $locale);
        }

        foreach ($this->selectedPairKeys($topCode) as $pairKey) {
            $this->appendRenderableSlot(
                $slots,
                $this->deepCopySlots->resolvePairBlendSlot($pairKey),
                'pair_blend',
                $modulePolicy,
                $locale
            );
        }

        foreach ($this->selected140qSlots($formCode, $qualityState) as $slotName) {
            $moduleKey = str_starts_with($slotName, '140q_') ? '140q_cta' : '140q_context_cards';
            $this->appendRenderableSlot(
                $slots,
                $this->deepCopySlots->resolve140qLayerSlot($slotName),
                $moduleKey,
                $modulePolicy,
                $locale
            );
        }

        foreach ($this->selectedQualitySlots($qualityState, $formCode) as $slotName) {
            $slot = $this->deepCopySlots->lowQualitySlots()[$slotName] ?? null;
            if (is_array($slot)) {
                $this->appendRenderableSlot($slots, $slot, 'quality_copy', $modulePolicy, $locale, 'visible');
            }
        }

        if ($formCode === 'riasec_140' && ! in_array($qualityState, ['low_quality', 'retake_recommended'], true)) {
            foreach ($this->deepCopySlots->structuralDifferenceSlots() as $slot) {
                $this->appendRenderableSlot($slots, $slot, 'structural_difference', $modulePolicy, $locale, 'collapsed');
            }
        }

        if (! in_array($qualityState, ['low_quality', 'retake_recommended'], true)) {
            foreach (['intro', 'input_boundary', 'no_score_mutation_boundary'] as $slotName) {
                $this->appendRenderableSlot(
                    $slots,
                    $this->deepCopySlots->resolveAspirationsSlot($slotName),
                    'aspirations_calibration',
                    $modulePolicy,
                    $locale,
                    'collapsed'
                );
            }
            foreach (['user_not_wrong_message', 'feedback_no_mutation_boundary', 'next_step'] as $slotName) {
                $this->appendRenderableSlot(
                    $slots,
                    $this->deepCopySlots->resolveDisagreePathSlot($slotName),
                    'disagree_path',
                    $modulePolicy,
                    $locale,
                    'collapsed'
                );
            }
        }

        return [
            'schema_version' => 'riasec.deep_content_slots.v1',
            'scale_code' => 'RIASEC',
            'locale' => str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en',
            'content_authority' => 'backend_riasec_deep_copy_slot_registry',
            'snapshot_bound' => (bool) data_get($projection, 'measurement_evidence.snapshot_bound', false),
            'source_policy' => [
                'frontend_fallback_allowed' => false,
                'missing_content_behavior' => 'omit_module_fail_closed',
                'pending_content_behavior' => 'omit_module_fail_closed',
                'unknown_slot_behavior' => 'hidden',
                'formal_report_generation' => 'deterministic_backend_snapshot',
            ],
            'slot_visibility_policy' => [
                'module_visibility_policy_id' => (string) data_get($projection, 'module_visibility_policy.policy_id', RiasecReportModuleSelector::POLICY_ID),
                'hidden_slots_omitted' => true,
                'pending_or_unavailable_slots_omitted' => true,
                'frontend_inference_allowed' => false,
            ],
            'slots' => array_values($slots),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $slots
     * @param  array<string,mixed>  $slot
     * @param  array<string,mixed>  $modulePolicy
     */
    private function appendRenderableSlot(
        array &$slots,
        array $slot,
        string $moduleKey,
        array $modulePolicy,
        string $locale,
        ?string $forcedVisibility = null
    ): void {
        if (($slot['content_status'] ?? null) !== 'authored') {
            return;
        }
        if (($slot['frontend_fallback_allowed'] ?? true) !== false) {
            return;
        }
        if ($this->deepCopySlots->validateSlot($slot) !== []) {
            return;
        }

        $visibility = $forcedVisibility ?? $this->moduleVisibility($modulePolicy, $moduleKey);
        if ($visibility === 'hidden') {
            return;
        }

        $slots[] = $this->publicDeepContentSlot($slot, $moduleKey, $visibility, $locale);
    }

    /**
     * @param  array<string,mixed>  $modulePolicy
     */
    private function moduleVisibility(array $modulePolicy, string $moduleKey): string
    {
        foreach ((array) ($modulePolicy['modules'] ?? []) as $module) {
            if (is_array($module) && ($module['key'] ?? null) === $moduleKey) {
                $visibility = (string) ($module['visibility'] ?? 'hidden');

                return in_array($visibility, ['visible', 'collapsed'], true) ? $visibility : 'hidden';
            }
        }

        return 'hidden';
    }

    /**
     * @return list<string>
     */
    private function selectedPairKeys(string $topCode): array
    {
        $letters = array_values(array_filter(str_split(strtoupper($topCode)), static fn (string $letter): bool => in_array($letter, RiasecDeepCopySlotRegistry::DIMENSIONS, true)));
        $pairs = [];
        for ($i = 0; $i < count($letters); $i++) {
            for ($j = $i + 1; $j < count($letters); $j++) {
                $pairs[] = $letters[$i].'_'.$letters[$j];
            }
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    private function selected140qSlots(string $formCode, string $qualityState): array
    {
        if (in_array($qualityState, ['low_quality', 'retake_recommended'], true)) {
            return [];
        }
        if ($formCode === 'riasec_140') {
            return ['task_activity_card', 'environment_card', 'role_responsibility_card', 'layer_agreement'];
        }

        return ['layer_unavailable', '140q_cta'];
    }

    /**
     * @return list<string>
     */
    private function selectedQualitySlots(string $qualityState, string $formCode): array
    {
        return match ($qualityState) {
            'low_quality' => ['top_notice', 'user_not_blamed_message', 'what_happened_explanation', 'hidden_modules_explanation', 'retake_guidance', 'share_pdf_boundary', 'next_step'],
            'retake_recommended' => ['top_notice', 'retake_guidance', 'share_pdf_boundary', 'next_step'],
            'caution' => ['cautious_reading_notice'],
            default => $formCode === 'riasec_60' ? ['minimal_quality_boundary_60q'] : [],
        };
    }

    /**
     * @param  array<string,mixed>  $slot
     * @return array<string,mixed>
     */
    private function publicDeepContentSlot(array $slot, string $moduleKey, string $visibility, string $locale): array
    {
        $contentKeys = [
            'title',
            'summary',
            'body',
            'core_drive',
            'positive_value',
            'real_world_cost',
            'high_score_reading',
            'medium_score_reading',
            'low_score_safe_reading',
            'work_activity_examples',
            'possible_drains',
            'common_misread',
            'action_advice',
            'pair_label',
            'short_label',
            'chemistry',
            'activities_to_validate',
            'question',
            'what_user_sees',
            'button_label',
        ];
        $content = [];
        foreach ($contentKeys as $key) {
            if (array_key_exists($key, $slot) && $slot[$key] !== null && $slot[$key] !== '' && $slot[$key] !== []) {
                $content[$key] = $slot[$key];
            }
        }

        return [
            'slot_key' => (string) ($slot['slot_key'] ?? ''),
            'slot_group' => (string) ($slot['slot_group'] ?? ''),
            'slot_id' => $this->slotId($slot),
            'module_key' => $moduleKey,
            'slot_visibility' => $visibility,
            'status' => (string) ($slot['content_status'] ?? 'unavailable'),
            'content_status' => (string) ($slot['content_status'] ?? 'unavailable'),
            'content_version' => (string) ($slot['content_version'] ?? ''),
            'review_status' => (string) ($slot['review_status'] ?? ''),
            'source_status' => (string) ($slot['source_status'] ?? ''),
            'evidence_level' => (string) ($slot['evidence_level'] ?? ''),
            'locale' => (string) ($slot['locale'] ?? (str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en')),
            'frontend_fallback_allowed' => false,
            'fallback_behavior' => (string) ($slot['fallback_behavior'] ?? 'omit_module'),
            'applicability' => [
                'form_codes' => array_values((array) ($slot['applicable_form_codes'] ?? [])),
                'profile_shapes' => array_values((array) ($slot['applicable_profile_shapes'] ?? [])),
                'quality_states' => array_values((array) ($slot['applicable_quality_states'] ?? [])),
                'codes' => array_values((array) ($slot['applicable_codes'] ?? [])),
                'dimensions' => array_values((array) ($slot['applicable_dimensions'] ?? [])),
            ],
            'state' => array_filter([
                'dimension_code' => $slot['dimension_code'] ?? null,
                'pair_key' => $slot['pair_key'] ?? null,
                'slot_name' => $slot['slot_name'] ?? null,
                'layer_state' => $slot['layer_state'] ?? null,
                'quality_state' => $slot['quality_state'] ?? null,
                'structural_difference_state' => $slot['structural_difference_state'] ?? null,
                'aspirations_state' => $slot['aspirations_state'] ?? null,
                'disagree_state' => $slot['disagree_state'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'content' => $content,
            'boundaries' => [
                'user_visible_boundary' => (string) ($slot['user_visible_boundary'] ?? ''),
                'required_boundaries' => array_values((array) ($slot['required_boundaries'] ?? [])),
                'forbidden_claims' => array_values((array) ($slot['forbidden_claims'] ?? [])),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $slot
     */
    private function slotId(array $slot): string
    {
        foreach (['dimension_code', 'pair_key', 'slot_name'] as $field) {
            if (trim((string) ($slot[$field] ?? '')) !== '') {
                return (string) ($slot['slot_key'] ?? '').':'.(string) $slot[$field];
            }
        }

        return (string) ($slot['slot_key'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $scores
     * @return array<string,float>
     */
    private function normalizeScores(array $scores): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $dimension) {
            $out[$dimension] = round((float) ($scores[$dimension] ?? 0), 2);
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function dimensionLabels(string $locale): array
    {
        $key = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $out = [];
        foreach (self::LABELS as $dimension => $labels) {
            $out[$dimension] = $labels[$key] ?? $labels['en'];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,float>
     */
    private function prefixedScores(array $payload, string $prefix): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $dimension) {
            $key = $prefix.$dimension;
            if (array_key_exists($key, $payload)) {
                $out[$dimension] = round((float) $payload[$key], 2);
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $scores
     * @return list<array{code:string,label:string,score:float}>
     */
    private function dimensionScoreRows(array $scores, string $locale): array
    {
        $labels = $this->dimensionLabels($locale);
        $out = [];
        foreach ($this->normalizeScores($scores) as $code => $score) {
            $out[] = [
                'code' => $code,
                'label' => (string) ($labels[$code] ?? $code),
                'score' => $score,
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $interpretationRule
     * @return array<string,mixed>
     */
    private function publicInterpretationState(array $interpretationRule): array
    {
        return [
            'interpretation_rule_version' => (string) ($interpretationRule['interpretation_rule_version'] ?? ''),
            'profile_shape' => (string) ($interpretationRule['profile_shape'] ?? ''),
            'profile_shape_version' => (string) ($interpretationRule['profile_shape_version'] ?? ''),
            'clarity_label' => (string) ($interpretationRule['clarity_label'] ?? ''),
            'near_tie_state' => is_array($interpretationRule['near_tie_state'] ?? null) ? $interpretationRule['near_tie_state'] : [],
            'alternate_code' => is_array($interpretationRule['alternate_code'] ?? null) ? $interpretationRule['alternate_code'] : [],
            'alternate_code_reason' => $interpretationRule['alternate_code_reason'] ?? null,
            'top_code_confidence' => is_array($interpretationRule['top_code_confidence'] ?? null) ? $interpretationRule['top_code_confidence'] : [],
            'reading_strength' => (string) ($interpretationRule['reading_strength'] ?? ''),
            'result_page_strategy' => is_array($interpretationRule['result_page_strategy'] ?? null) ? $interpretationRule['result_page_strategy'] : [],
            'module_visibility_policy_id' => (string) ($interpretationRule['module_visibility_policy_id'] ?? ''),
            'validation_status' => (string) ($interpretationRule['validation_status'] ?? ''),
            'field_authority' => is_array($interpretationRule['field_authority'] ?? null) ? $interpretationRule['field_authority'] : [],
        ];
    }
}
