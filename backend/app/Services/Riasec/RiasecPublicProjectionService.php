<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Result;
use App\Services\ReviewGovernance\PublicReviewContract;

/** @review-surface riasec_content_release_review */
final class RiasecPublicProjectionService
{
    private const LABELS = [
        'R' => 'Realistic',
        'I' => 'Investigative',
        'A' => 'Artistic',
        'S' => 'Social',
        'E' => 'Enterprising',
        'C' => 'Conventional',
    ];

    public function __construct(
        private readonly RiasecMeasurementContract $measurementContract,
        private readonly RiasecActivityExplorerService $activityExplorer,
        private readonly RiasecExplorationFeedbackOverlayService $feedbackOverlay,
        private readonly RiasecLifecycleCopyService $lifecycleCopy,
        private readonly RiasecInterpretationRuleContract $interpretationRuleContract,
        private readonly RiasecQualityRuleContract $qualityRuleContract,
        private readonly RiasecReportModuleSelector $moduleSelector,
        private readonly RiasecDeepCopySlotRegistry $deepCopySlots,
        private readonly PublicReviewContract $publicReviewContract,
        private readonly RiasecPrivateResultSourceRepository $privateResultSource = new RiasecPrivateResultSourceRepository,
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
                'cross_form_interpretation' => 'emphasis_difference_only',
            ],
            'dimension_score_band_contract_v1' => data_get($measurementContract, 'scoring.interpretation_band_contract', []),
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
    public function buildV2FromResult(
        Result $result,
        string $locale = 'zh-CN',
        bool $snapshotBound = false,
        bool $resultSummaryBound = false,
    ): array {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $rawDimensionScores = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        if ($rawDimensionScores === [] && is_array($payload['scores_0_100'] ?? null)) {
            $rawDimensionScores = $payload['scores_0_100'];
        }
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
            'answer_count' => (int) ($payload['answer_count'] ?? data_get($measurementContract, 'form.question_count', 0)),
        ]));
        $interpretationRulePayload = $payload;
        $interpretationRulePayload['scores_0_100'] = $this->hasCompleteValidDimensionScores($rawDimensionScores) ? $rawDimensionScores : [];
        $interpretationRule = $this->interpretationRuleContract->build($interpretationRulePayload, $qualityRule);
        if ((string) data_get($interpretationRule, 'tie_display_v1.ordered_code') !== (string) ($v1['top_code'] ?? '')) {
            data_set($interpretationRule, 'profile_shape', 'unavailable');
            data_set($interpretationRule, 'near_tie_state.state', 'none');
            data_set($interpretationRule, 'near_tie_state.dimensions', []);
            data_set($interpretationRule, 'alternate_code.show', false);
            data_set($interpretationRule, 'alternate_code.codes', []);
            data_set($interpretationRule, 'alternate_code.reason', null);
            data_set($interpretationRule, 'alternate_code_reason', null);
            data_set($interpretationRule, 'tie_display_v1.kind', 'none');
            data_set($interpretationRule, 'tie_display_v1.position', 'none');
            data_set($interpretationRule, 'tie_display_v1.dimensions', []);
            data_set($interpretationRule, 'tie_display_v1.groups', []);
            data_set($interpretationRule, 'tie_display_v1.alternate_codes', []);
            data_set($interpretationRule, 'tie_display_v1.ordered_code', (string) ($v1['top_code'] ?? ''));
            data_set($interpretationRule, 'tie_display_v1.unavailable_reason', 'score_code_mismatch');
            data_set($interpretationRule, 'validation_status', 'score_code_mismatch_unavailable');
        }

        $projection = [
            '_dimension_scores_complete' => $this->hasCompleteValidDimensionScores($rawDimensionScores),
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
                'cross_form_interpretation' => 'emphasis_difference_only',
            ],
            'dimension_score_band_contract_v1' => data_get($v1, 'dimension_score_band_contract_v1', []),
            'structural_difference' => $this->structuralDifferencePolicy($formCode, $qualityRule['quality_state'] ?? 'normal', $payload, $comparePolicy),
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
                'display_v1' => $this->qualityDisplay(
                    locale: $locale,
                    grade: (string) ($v1['quality_grade'] ?? ''),
                    qualityRule: $qualityRule,
                ),
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
            'interpretation_state' => $this->publicInterpretationState($interpretationRule, $locale),
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
        if ($snapshotBound || $resultSummaryBound) {
            $projection['result_summary_v1'] = $this->resultSummary($projection, $locale, true);
        }
        $projection['exploration_feedback_overlay_v0_1'] = $this->feedbackOverlay->build($result, $projection, $snapshotBound, $locale);
        $projection['lifecycle_copy_v1'] = $this->lifecycleCopy->runtimeLifecycleCopyContract($snapshotBound, $locale);
        unset($projection['_dimension_scores_complete']);

        return $projection;
    }

    /** @return array<string,mixed> */
    private function resultSummary(array $projection, string $locale, bool $snapshotBound): array
    {
        $isZh = str_starts_with(strtolower($locale), 'zh');
        $tie = (array) data_get($projection, 'interpretation_state.tie_display_v1', []);
        $quality = (array) data_get($projection, 'quality.display_v1', []);
        $highlights = [];
        $copy = $this->summaryDimensionCopy($isZh);
        $dimensionRows = collect((array) data_get($projection, 'scores.dimensions', []))
            ->keyBy(static fn (array $dimension): string => (string) ($dimension['code'] ?? ''));
        foreach (str_split((string) data_get($projection, 'holland_code.code', '')) as $code) {
            $dimension = (array) $dimensionRows->get($code, []);
            $score = $dimension['score'] ?? null;
            if (! is_numeric($score) || ! isset($copy[$code])) {
                continue;
            }
            $band = (float) $score < 34 ? 'low' : ((float) $score < 67 ? 'medium' : 'high');
            $highlights[] = [
                'dimension_code' => $code,
                'label' => (string) ($dimension['label'] ?? $code),
                'text' => $copy[$code][$band],
            ];
        }

        $qualityParts = array_values(array_filter([
            trim((string) ($quality['headline'] ?? '')),
            trim((string) data_get($quality, 'reasons.0', '')),
            trim((string) data_get($quality, 'improvements.0', '')),
        ]));

        return [
            'schema_version' => 'riasec.result_summary.v1',
            'locale' => $isZh ? 'zh-CN' : 'en',
            'estimated_read_seconds' => 180,
            'snapshot_bound' => $snapshotBound,
            'snapshot_scope' => (bool) data_get($projection, 'measurement_evidence.snapshot_bound', false)
                ? 'report_snapshot'
                : 'persisted_result',
            'headline' => $isZh ? (string) $this->privateResultSource->get('result_summary.headline') : 'Your interest result summary',
            'ranking_display' => (string) data_get($tie, 'display_copy.headline', data_get($projection, 'holland_code.code', '')),
            'tie_note' => (string) data_get($tie, 'display_copy.note', ''),
            'quality_summary' => implode($isZh ? '；' : '; ', $qualityParts),
            'highlights' => array_slice($highlights, 0, 3),
            'next_step' => $isZh
                ? (string) $this->privateResultSource->get('result_summary.next_step')
                : 'Choose one of the first three interest dimensions for a 15–30 minute low-risk activity, then note whether you want to continue.',
            'boundary' => $isZh
                ? (string) $this->privateResultSource->get('result_summary.boundary')
                : 'This result describes career-interest signals from this response only; it is not an ability, identity, career-match, or outcome conclusion.',
        ];
    }

    /** @return array<string,string> */
    private function summaryDimensionCopy(bool $isZh): array
    {
        if ($isZh) {
            return (array) $this->privateResultSource->get('result_summary.dimension_bands', []);
        }

        return [
            'R' => ['high' => 'Hands-on work stands out here; test it with one practical task.', 'medium' => 'Your interest in practical work may depend on the tools, goal, and setting.', 'low' => 'Practical work ranks lower here; a short task can still clarify the experience.'],
            'I' => ['high' => 'Questioning and evidence analysis stand out here; test them on a real problem.', 'medium' => 'Your interest in analysis may depend on the problem and room to explore.', 'low' => 'Analysis ranks lower here; notice whether a concrete question still draws you into investigation.'],
            'A' => ['high' => 'Creative expression stands out here; test it through a small original piece.', 'medium' => 'Your interest in expression may depend on autonomy and the medium.', 'low' => 'Creative expression ranks lower here; a lightweight piece can clarify your response.'],
            'S' => ['high' => 'Understanding and supporting people stand out here; test this in real collaboration.', 'medium' => 'Your interest in helping may depend on the relationship and interaction style.', 'low' => 'Helping activity ranks lower here; distinguish sustained interaction from support in specific settings.'],
            'E' => ['high' => 'Advancing goals and organizing action stand out here; test them in a small initiative.', 'medium' => 'Your interest in driving action may depend on purpose and autonomy.', 'low' => 'Influence activity ranks lower here; notice whether contributing ideas feels better than leading.'],
            'C' => ['high' => 'Organizing information and processes stands out here; test it in a structured task.', 'medium' => 'Your interest in structure may depend on clear rules and room to improve them.', 'low' => 'Routine structure ranks lower here; separate repetition from a genuine need for clarity.'],
        ];
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
        foreach ($this->selectedDimensionSlots($projection) as $slot) {
            $this->appendRenderableSlot(
                $slots,
                $slot,
                'six_dimension_map',
                $modulePolicy,
                $locale,
                (bool) data_get($slot, 'selection_v1.is_top_three', false) ? 'visible' : 'collapsed'
            );
        }

        $top3Key = $this->selectedTop3Key($topCode);
        if ($top3Key !== null) {
            $this->appendRenderableSlot(
                $slots,
                $this->deepCopySlots->resolveTop3ChainSlot($topCode),
                'hero_activity_chain',
                $modulePolicy,
                $locale
            );
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

        $structuralDifferencePolicy = (array) ($projection['structural_difference'] ?? []);

        foreach ($this->selected140qDimensionLayerSlots($topCode, $formCode, $qualityState, $structuralDifferencePolicy) as $slot) {
            $this->appendRenderableSlot(
                $slots,
                $slot,
                '140q_context_cards',
                $modulePolicy,
                $locale
            );
        }

        foreach ($this->selected140qSlots($formCode, $qualityState, $structuralDifferencePolicy) as $slotName) {
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

        foreach ($this->selectedInterpretationStateCopySlots((array) ($projection['interpretation_state'] ?? []), $qualityState) as $slot) {
            $this->appendRenderableSlot($slots, $slot, 'result_reading_boundary', $modulePolicy, $locale, 'collapsed');
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
        $slot = $this->deepCopySlots->resolveForLocale($slot, $locale);
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
     * @param  array<string,mixed>  $projection
     * @return list<array<string,mixed>>
     */
    private function selectedDimensionSlots(array $projection): array
    {
        $qualityState = (string) data_get($projection, 'quality.quality_state', 'normal');
        if (in_array($qualityState, ['low_quality', 'retake_recommended'], true)) {
            return [];
        }
        if (($projection['_dimension_scores_complete'] ?? false) !== true) {
            return [];
        }

        $scoreRows = array_values(array_filter(
            (array) data_get($projection, 'scores.dimensions', []),
            static fn (mixed $row): bool => is_array($row) && in_array((string) ($row['code'] ?? ''), RiasecDeepCopySlotRegistry::DIMENSIONS, true)
        ));
        usort($scoreRows, static function (array $left, array $right): int {
            $scoreOrder = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            return array_search((string) ($left['code'] ?? ''), RiasecDeepCopySlotRegistry::DIMENSIONS, true)
                <=> array_search((string) ($right['code'] ?? ''), RiasecDeepCopySlotRegistry::DIMENSIONS, true);
        });
        $ranks = [];
        $previousScore = null;
        $previousRank = 0;
        foreach ($scoreRows as $index => $row) {
            $score = (float) ($row['score'] ?? 0);
            $rank = $previousScore !== null && abs($previousScore - $score) < 0.000001 ? $previousRank : $index + 1;
            $ranks[(string) $row['code']] = $rank;
            $previousScore = $score;
            $previousRank = $rank;
        }

        $slots = [];
        foreach ($scoreRows as $row) {
            $dimensionCode = (string) $row['code'];
            $score = max(0.0, min(100.0, (float) ($row['score'] ?? 0)));
            [$scoreBand, $selectedDetailKey] = $score >= 67
                ? ['high', 'high_score_reading']
                : ($score >= 34 ? ['medium', 'medium_score_reading'] : ['low', 'low_score_safe_reading']);
            $slot = $this->deepCopySlots->resolveDimensionSlot($dimensionCode);
            if (($slot[$selectedDetailKey] ?? null) === null || trim((string) $slot[$selectedDetailKey]) === '') {
                continue;
            }
            $slot['selection_v1'] = [
                'schema_version' => 'riasec.dimension_interpretation_selection.v1',
                'dimension_code' => $dimensionCode,
                'rank' => $ranks[$dimensionCode],
                'is_top_three' => $ranks[$dimensionCode] <= 3,
                'score_band' => $scoreBand,
                'selected_detail_key' => $selectedDetailKey,
            ];
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * @param  array<string,mixed>  $scores
     */
    private function hasCompleteValidDimensionScores(array $scores): bool
    {
        foreach (RiasecDeepCopySlotRegistry::DIMENSIONS as $dimension) {
            if (! array_key_exists($dimension, $scores) || ! is_numeric($scores[$dimension])) {
                return false;
            }
            $score = (float) $scores[$dimension];
            if (! is_finite($score) || $score < 0 || $score > 100) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function selected140qDimensionLayerSlots(string $topCode, string $formCode, string $qualityState, array $structuralDifferencePolicy): array
    {
        if ($formCode !== 'riasec_140' || in_array($qualityState, ['low_quality', 'retake_recommended'], true)) {
            return [];
        }

        $letters = array_values(array_unique(array_filter(str_split(strtoupper($topCode)), static fn (string $letter): bool => in_array($letter, RiasecDeepCopySlotRegistry::DIMENSIONS, true))));
        $dimensionCode = $letters[0] ?? null;
        if ($dimensionCode === null) {
            return [];
        }

        $layerStates = is_array($structuralDifferencePolicy['layer_states'] ?? null)
            ? $structuralDifferencePolicy['layer_states']
            : [];

        $selected = [];
        foreach (['task', 'environment', 'role'] as $layer) {
            $state = $this->normalize140qSelectionLayerState((string) ($layerStates[$layer] ?? 'unavailable'));
            if (! in_array($state, ['agreement', 'tension'], true)) {
                continue;
            }

            $selected[] = $this->deepCopySlots->resolve140qDimensionLayerSlot($dimensionCode, $layer, $state);
        }

        return $selected;
    }

    private function selectedTop3Key(string $topCode): ?string
    {
        $letters = array_values(array_unique(array_filter(str_split(strtoupper($topCode)), static fn (string $letter): bool => in_array($letter, RiasecDeepCopySlotRegistry::DIMENSIONS, true))));
        if (count($letters) < 3) {
            return null;
        }

        $order = array_flip(RiasecDeepCopySlotRegistry::DIMENSIONS);
        $top3 = array_slice($letters, 0, 3);
        usort($top3, fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode('_', $top3);
    }

    /**
     * @return list<string>
     */
    private function selected140qSlots(string $formCode, string $qualityState, array $structuralDifferencePolicy): array
    {
        if (in_array($qualityState, ['low_quality', 'retake_recommended'], true)) {
            return [];
        }
        if ($formCode === 'riasec_140') {
            $layerStates = is_array($structuralDifferencePolicy['layer_states'] ?? null)
                ? $structuralDifferencePolicy['layer_states']
                : [];
            $normalizedLayerStates = array_map(
                fn (string $layer): string => $this->normalize140qSelectionLayerState((string) ($layerStates[$layer] ?? 'unavailable')),
                ['task', 'environment', 'role']
            );
            $hasCompleteExplicitStates = count(array_filter(
                $normalizedLayerStates,
                static fn (string $state): bool => in_array($state, ['agreement', 'tension'], true)
            )) === 3;

            $selected = ['task_activity_card', 'environment_card', 'role_responsibility_card'];
            if ($hasCompleteExplicitStates) {
                $selected[] = in_array('tension', $normalizedLayerStates, true) ? 'layer_tension' : 'layer_agreement';
            }

            return $selected;
        }

        return ['layer_unavailable', '140q_cta'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $comparePolicy
     * @return array<string,mixed>
     */
    private function structuralDifferencePolicy(string $formCode, string $qualityState, array $payload, array $comparePolicy): array
    {
        $enabled = $formCode === 'riasec_140' && ! in_array($qualityState, ['low_quality', 'retake_recommended'], true);
        $layerStates = is_array(data_get($payload, 'riasec_140q_layer_states'))
            ? (array) data_get($payload, 'riasec_140q_layer_states')
            : (array) data_get($payload, 'enhanced_breakdown.layer_states', []);

        return [
            'schema_version' => 'riasec.structural_difference_policy.v1',
            'enabled' => $enabled,
            'state' => $enabled
                ? $this->normalizeStructuralDifferenceState((string) data_get($payload, 'structural_difference_state', data_get($comparePolicy, 'structural_difference_state', 'different_emphasis')))
                : 'cross_form_not_comparable',
            'basis' => 'task_environment_role_emphasis_only',
            'selection_rule' => 'explicit_layer_state_or_unavailable_without_score_delta',
            'score_comparison_allowed' => false,
            'raw_score_delta_allowed' => false,
            'raw_scores_used_for_selection' => false,
            'different_form_scores_comparable' => false,
            'emphasis_difference_only' => true,
            'correctness_ranking_allowed' => false,
            'raw_score_comparison_allowed' => false,
            'result_override_allowed' => false,
            'code_conversion_allowed' => false,
            'layer_states' => [
                'task' => $this->normalize140qSelectionLayerState((string) ($layerStates['task'] ?? data_get($payload, 'task_layer_state', 'unavailable'))),
                'environment' => $this->normalize140qSelectionLayerState((string) ($layerStates['environment'] ?? data_get($payload, 'environment_layer_state', 'unavailable'))),
                'role' => $this->normalize140qSelectionLayerState((string) ($layerStates['role'] ?? data_get($payload, 'role_layer_state', 'unavailable'))),
            ],
            'public_copy_boundary' => (string) $this->privateResultSource->get('generic_slots.structural_public_boundary'),
        ];
    }

    private function normalize140qSelectionLayerState(string $layerState): string
    {
        $normalized = strtolower(trim($layerState));

        return in_array($normalized, ['agreement', 'tension', 'unavailable', 'insufficient_quality', 'not_applicable_60q_only'], true)
            ? $normalized
            : 'unavailable';
    }

    private function normalizeStructuralDifferenceState(string $state): string
    {
        $normalized = strtolower(trim($state));

        return in_array($normalized, RiasecDeepCopySlotRegistry::STRUCTURAL_DIFFERENCE_STATES, true)
            ? $normalized
            : 'different_emphasis';
    }

    /**
     * @param  array<string,mixed>  $qualityRule
     * @return array<string,mixed>
     */
    private function qualityDisplay(string $locale, string $grade, array $qualityRule): array
    {
        $isZh = str_starts_with(strtolower($locale), 'zh');
        $rawGrade = in_array(strtoupper(trim($grade)), ['A', 'B', 'C'], true) ? strtoupper(trim($grade)) : 'C';
        $normalizedGrade = match ((string) ($qualityRule['quality_state'] ?? '')) {
            'low_quality', 'retake_recommended' => 'C',
            'caution' => $rawGrade === 'C' ? 'C' : 'B',
            default => $rawGrade,
        };
        $flags = array_values(array_unique(array_filter(array_map('strval', (array) ($qualityRule['quality_flags'] ?? [])))));
        if ((bool) ($qualityRule['too_fast'] ?? false)) {
            $flags[] = 'too_fast';
        }
        if ((bool) ($qualityRule['neutral_overuse'] ?? false)) {
            $flags[] = 'neutral_overuse';
        }
        if ((bool) ($qualityRule['missing_items'] ?? false)) {
            $flags[] = 'missing_items';
        }
        $flags = array_values(array_unique($flags));

        $copy = $isZh ? (array) $this->privateResultSource->get('quality_display', []) : [
            'headlines' => [
                'A' => 'Your responses are stable enough for a standard reading',
                'B' => 'A mild response-quality note applies; read the result alongside your real experience',
                'C' => 'Response-quality concerns limit this result to an initial signal; consider retaking when ready',
            ],
            'reasons' => [
                'attention_133_failed' => 'One attention check was missed, so some answers may not fully reflect your usual preferences.',
                'attention_137_failed' => 'One attention check was missed, so some answers may not fully reflect your usual preferences.',
                'low_consistency' => 'Some similar questions received notably different answers, which may reduce result stability.',
                'idealization' => 'Some answers may reflect an ideal self rather than your usual choices.',
                'strong_idealization' => 'Several answer patterns may reflect an ideal self rather than your usual choices.',
                'broad_agreement' => 'You agreed with or liked many items, which may make the interest dimensions less distinct.',
                'too_fast' => 'The assessment was completed unusually quickly, leaving limited time to consider each item.',
                'neutral_overuse' => 'Frequent neutral responses limit how clearly this result can distinguish your interests.',
                'missing_items' => 'Some required items were not completed, so a full reading is not supported.',
            ],
            'improvements' => [
                'attention_133_failed' => 'Retake in a distraction-free setting and read each item fully.',
                'attention_137_failed' => 'Retake in a distraction-free setting and read each item fully.',
                'low_consistency' => 'Answer from recurring real-life behavior rather than trying to make every answer look consistent.',
                'idealization' => 'Choose what is closest to your everyday behavior, not what seems ideal or expected.',
                'strong_idealization' => 'Choose what is closest to your everyday behavior, not what seems ideal or expected.',
                'broad_agreement' => 'When many options appeal to you, ask which activities you would willingly repeat over time.',
                'too_fast' => 'Retake when you have enough time, linking each answer to a concrete experience.',
                'neutral_overuse' => 'Use concrete experiences to decide whether each activity is closer to appealing or unappealing.',
                'missing_items' => 'Complete every required item before generating a new result.',
            ],
            'boundary' => 'This note concerns the readability of this response set; it does not judge ability, personality, or personal worth.',
        ];

        $reasons = [];
        $improvements = [];
        $attentionFlags = array_values(array_intersect($flags, ['attention_133_failed', 'attention_137_failed']));
        if (count($attentionFlags) === 2) {
            $reasons[] = $isZh
                ? (string) $this->privateResultSource->get('quality_display.reasons.both_attention_failed')
                : 'Both attention checks were missed, so some answers may not fully reflect your usual preferences.';
            $improvements[] = $copy['improvements']['attention_133_failed'];
        }
        foreach ($flags as $flag) {
            if (count($attentionFlags) === 2 && in_array($flag, $attentionFlags, true)) {
                continue;
            }
            if (isset($copy['reasons'][$flag])) {
                $reasons[] = $copy['reasons'][$flag];
                $improvements[] = $copy['improvements'][$flag];
            }
        }
        if ($normalizedGrade !== 'A' && $reasons === []) {
            $reasons[] = $isZh
                ? (string) $this->privateResultSource->get('quality_display.reasons.generic')
                : 'This response set received a quality note, so the result may be less distinct or stable.';
            $improvements[] = $isZh
                ? (string) $this->privateResultSource->get('quality_display.improvements.generic')
                : 'Consider retaking when settled and free from distractions, answering from everyday experience.';
        }

        return [
            'schema_version' => 'riasec.quality_display.v1',
            'locale' => $isZh ? 'zh-CN' : 'en',
            'headline' => $copy['headlines'][$normalizedGrade],
            'reasons' => array_values(array_unique($reasons)),
            'improvements' => array_values(array_unique($improvements)),
            'reading_boundary' => $copy['boundary'],
        ];
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
     * @param  array<string,mixed>  $interpretationState
     * @return list<array<string,mixed>>
     */
    private function selectedInterpretationStateCopySlots(array $interpretationState, string $qualityState): array
    {
        $slots = [];
        $profileShape = (string) ($interpretationState['profile_shape'] ?? '');
        if ($profileShape !== '' && $profileShape !== 'unavailable') {
            $slots[] = $this->deepCopySlots->resolveInterpretationStateCopySlot('profile_shape_copy', $profileShape);
        }

        $confidenceState = $profileShape === 'unavailable' ? null : $this->confidenceCopyState($interpretationState, $qualityState);
        if ($confidenceState !== null) {
            $slots[] = $this->deepCopySlots->resolveInterpretationStateCopySlot('top_code_confidence_copy', $confidenceState);
        }

        $nearTieState = (string) data_get($interpretationState, 'near_tie_state.state', 'none');
        if ($qualityState !== 'low_quality' && $nearTieState !== '' && $nearTieState !== 'none') {
            $slots[] = $this->deepCopySlots->resolveInterpretationStateCopySlot('near_tie_alternate_code_copy', $nearTieState);
            if ((bool) data_get($interpretationState, 'alternate_code.show', false)) {
                $slots[] = $this->deepCopySlots->resolveInterpretationStateCopySlot('near_tie_alternate_code_copy', 'alternate_code_available');
            }
        }

        return array_values(array_filter(
            $slots,
            static fn (array $slot): bool => ($slot['content_status'] ?? null) === 'authored'
        ));
    }

    /**
     * @param  array<string,mixed>  $interpretationState
     */
    private function confidenceCopyState(array $interpretationState, string $qualityState): ?string
    {
        $profileShape = (string) ($interpretationState['profile_shape'] ?? '');
        if ($qualityState === 'low_quality' || in_array($profileShape, ['low_quality', 'low_clarity'], true)) {
            return 'low_clarity';
        }
        if ($profileShape === 'near_tie') {
            return 'near_tie';
        }
        if ($profileShape === 'broad_profile') {
            return 'broad_profile';
        }

        $level = (string) data_get($interpretationState, 'top_code_confidence.level', '');
        if (in_array($level, ['high', 'medium_high'], true)) {
            return 'high_confidence';
        }
        if (in_array($level, ['medium', 'low'], true)) {
            return 'moderate_confidence';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $slot
     * @return array<string,mixed>
     */
    private function publicDeepContentSlot(array $slot, string $moduleKey, string $visibility, string $locale): array
    {
        $contentKeys = [
            'title',
            'core_drive',
            'positive_value',
            'real_world_cost',
            'high_score_reading',
            'medium_score_reading',
            'low_score_safe_reading',
            'work_activity_examples',
            'common_misread',
            'action_advice',
            'pair_label',
            'short_label',
            'chemistry',
            'activities_to_validate',
            'strategy_label',
            'activity_chain',
            'core_reading',
            'first_experiment',
            'ordered_code_handling',
            'low_risk_validation',
            'primary_activity_chain',
            'secondary_support_line',
            'tertiary_stabilizer',
            'likely_tension',
            'activity_sequence',
            'when_to_use_140q',
            'when_not_to_overread',
            'free_page_teaser',
            'deep_report_extension',
            'label',
            'copy',
            'module_policy',
            'example_question',
            'task_activity_card',
            'environment_card',
            'role_responsibility_card',
            'question',
            'what_user_sees',
            'button_label',
            'selection_basis',
            'summary',
            'body',
        ];
        $content = [];
        $selection = is_array($slot['selection_v1'] ?? null) ? $slot['selection_v1'] : null;
        $layerContentKey = match ((string) ($slot['layer'] ?? '')) {
            'task' => 'task_activity_card',
            'environment' => 'environment_card',
            'role' => 'role_responsibility_card',
            default => null,
        };
        foreach ($contentKeys as $key) {
            if ($layerContentKey !== null && in_array($key, ['task_activity_card', 'environment_card', 'role_responsibility_card'], true) && $key !== $layerContentKey) {
                continue;
            }
            if (
                $selection !== null
                && in_array($key, ['high_score_reading', 'medium_score_reading', 'low_score_safe_reading'], true)
                && $key !== ($selection['selected_detail_key'] ?? null)
            ) {
                continue;
            }
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
            ...$this->publicReviewContract->project($slot['review_status'] ?? null),
            'source_status' => (string) ($slot['source_status'] ?? ''),
            'evidence_level' => (string) ($slot['evidence_level'] ?? ''),
            'locale' => (string) ($slot['locale'] ?? (str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en')),
            'frontend_fallback_allowed' => false,
            'fallback_behavior' => (string) ($slot['fallback_behavior'] ?? 'omit_module'),
            'selection_v1' => $selection,
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
                'top3_key' => $slot['top3_key'] ?? null,
                'layer_dimension' => $slot['layer_dimension'] ?? null,
                'layer' => $slot['layer'] ?? null,
                'slot_name' => $slot['slot_name'] ?? null,
                'layer_state' => $slot['layer_state'] ?? null,
                'quality_state' => $slot['quality_state'] ?? null,
                'profile_shape' => $slot['profile_shape'] ?? null,
                'confidence_state' => $slot['confidence_state'] ?? null,
                'near_tie_copy_state' => $slot['near_tie_copy_state'] ?? null,
                'structural_difference_state' => $slot['structural_difference_state'] ?? null,
                'aspirations_state' => $slot['aspirations_state'] ?? null,
                'disagree_state' => $slot['disagree_state'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'content' => $content,
            'boundaries' => [
                'user_visible_boundary' => (string) ($slot['user_visible_boundary'] ?? ''),
                'required_boundaries' => array_values((array) ($slot['required_boundaries'] ?? [])),
                'forbidden_claims' => array_values((array) ($slot['forbidden_claims'] ?? [])),
                'emphasis_difference_only' => (bool) ($slot['emphasis_difference_only'] ?? false),
                'correctness_ranking_allowed' => (bool) ($slot['correctness_ranking_allowed'] ?? false),
                'raw_score_comparison_allowed' => (bool) ($slot['raw_score_comparison_allowed'] ?? false),
                'result_override_allowed' => (bool) ($slot['result_override_allowed'] ?? false),
                'code_conversion_allowed' => (bool) ($slot['code_conversion_allowed'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $slot
     */
    private function slotId(array $slot): string
    {
        foreach (['dimension_code', 'pair_key', 'top3_key', 'slot_name'] as $field) {
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
        return str_starts_with(strtolower($locale), 'zh')
            ? (array) $this->privateResultSource->get('dimension_labels', [])
            : self::LABELS;
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
    private function publicInterpretationState(array $interpretationRule, string $locale): array
    {
        $tieDisplay = is_array($interpretationRule['tie_display_v1'] ?? null) ? $interpretationRule['tie_display_v1'] : [];
        $tieDisplay['locale'] = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $tieDisplay['display_copy'] = $this->tieDisplayCopy($tieDisplay, $locale);

        return [
            'interpretation_rule_version' => (string) ($interpretationRule['interpretation_rule_version'] ?? ''),
            'profile_shape' => (string) ($interpretationRule['profile_shape'] ?? ''),
            'profile_shape_version' => (string) ($interpretationRule['profile_shape_version'] ?? ''),
            'clarity_label' => (string) ($interpretationRule['clarity_label'] ?? ''),
            'near_tie_state' => is_array($interpretationRule['near_tie_state'] ?? null) ? $interpretationRule['near_tie_state'] : [],
            'alternate_code' => is_array($interpretationRule['alternate_code'] ?? null) ? $interpretationRule['alternate_code'] : [],
            'alternate_code_reason' => $interpretationRule['alternate_code_reason'] ?? null,
            'tie_display_v1' => $tieDisplay,
            'top_code_confidence' => is_array($interpretationRule['top_code_confidence'] ?? null) ? $interpretationRule['top_code_confidence'] : [],
            'reading_strength' => (string) ($interpretationRule['reading_strength'] ?? ''),
            'result_page_strategy' => is_array($interpretationRule['result_page_strategy'] ?? null) ? $interpretationRule['result_page_strategy'] : [],
            'module_visibility_policy_id' => (string) ($interpretationRule['module_visibility_policy_id'] ?? ''),
            'validation_status' => (string) ($interpretationRule['validation_status'] ?? ''),
            'field_authority' => is_array($interpretationRule['field_authority'] ?? null) ? $interpretationRule['field_authority'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $tieDisplay
     * @return array<string,string>
     */
    private function tieDisplayCopy(array $tieDisplay, string $locale): array
    {
        $isZh = str_starts_with(strtolower($locale), 'zh');
        $kind = (string) ($tieDisplay['kind'] ?? 'none');
        $dimensions = array_values(array_filter(array_map('strval', (array) ($tieDisplay['dimensions'] ?? []))));
        $groups = array_values(array_filter(array_map(
            static fn ($group): array => array_values(array_filter(array_map('strval', is_array($group) ? $group : []))),
            (array) ($tieDisplay['groups'] ?? [])
        )));
        $code = (string) ($tieDisplay['ordered_code'] ?? '');
        $joined = $isZh ? implode('、', $dimensions) : $this->englishJoin($dimensions);
        $position = (string) ($tieDisplay['position'] ?? 'none');
        if (($tieDisplay['unavailable_reason'] ?? null) === 'score_code_mismatch') {
            $zhCopy = (array) $this->privateResultSource->get('tie_display.score_code_mismatch', []);

            return [
                'headline' => $isZh ? (string) ($zhCopy['headline'] ?? '') : 'This result cannot be interpreted yet',
                'note' => $isZh ? (string) ($zhCopy['note'] ?? '') : 'The score order and result code do not agree, so interpretive content is hidden; the six dimension scores remain available for review.',
                'boundary' => $isZh ? (string) ($zhCopy['boundary'] ?? '') : 'Please check the result again; do not infer an interest order, ability, or career conclusion from this page.',
            ];
        }

        return match ($kind) {
            'exact_tie' => [
                'headline' => $this->exactTieHeadline($code, $dimensions, $groups, $position, $isZh),
                'note' => $isZh ? (string) $this->privateResultSource->get('tie_display.exact_tie_note') : 'These dimensions have the same score; their letter order does not indicate a difference.',
                'boundary' => $isZh ? (string) $this->privateResultSource->get('tie_display.exact_tie_boundary') : 'The tie describes this interest score only; it is not an ability, identity, or career conclusion.',
            ],
            'near_tie' => [
                'headline' => $isZh ? strtr((string) $this->privateResultSource->get('tie_display.near_tie_headline'), ['{code}' => $code, '{dimensions}' => $joined]) : $code.' ('.$joined.' are close)',
                'note' => $isZh ? (string) $this->privateResultSource->get('tie_display.near_tie_note') : 'These scores are close, so the order should not be emphasized.',
                'boundary' => $isZh ? (string) $this->privateResultSource->get('tie_display.near_tie_boundary') : 'This is a provisional reading threshold, not a statistical-significance or measurement-error conclusion, and it does not create a second measured result.',
            ],
            default => [
                'headline' => $code,
                'note' => '',
                'boundary' => '',
            ],
        };
    }

    private function englishJoin(array $items): string
    {
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        if (count($items) === 2) {
            return $items[0].' and '.$items[1];
        }

        return implode(', ', array_slice($items, 0, -1)).', and '.$items[array_key_last($items)];
    }

    private function exactTieHeadline(string $code, array $dimensions, array $groups, string $position, bool $isZh): string
    {
        $groupLabels = array_map(fn (array $group): string => $isZh ? implode('/', $group) : $this->englishJoin($group), $groups);

        return $isZh
            ? strtr((string) $this->privateResultSource->get('tie_display.exact_tie_headline'), ['{code}' => $code, '{groups}' => implode('；', $groupLabels)])
            : $code.' (tied groups: '.implode('; ', $groupLabels).')';
    }
}
