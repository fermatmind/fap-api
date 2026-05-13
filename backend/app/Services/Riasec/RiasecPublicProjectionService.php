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
        $projection['exploration_feedback_overlay_v0_1'] = $this->feedbackOverlay->build($result, $projection, $snapshotBound);

        return $projection;
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
