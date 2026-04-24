<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Models\Result;

final class EnneagramPublicProjectionService
{
    private const TYPE_ORDER = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9'];

    private const PROJECTION_V2_SCHEMA = 'enneagram.public_projection.v2';

    private const PROJECTION_V2_VERSION = 'enneagram_projection.v2';

    private const REPORT_SCHEMA_VERSION = 'enneagram.report.v1';

    private const CLOSE_CALL_RULE_VERSION = 'close_call_rule.v1';

    private const REPORT_ENGINE_VERSION = 'enneagram_report_engine.v1';

    private const TECHNICAL_NOTE_VERSION = 'unavailable';

    private const QUALITY_POLICY_VERSION = 'enneagram_quality_policy.v1';

    private const CONFIDENCE_POLICY_VERSION = 'enneagram_confidence_policy.v1';

    /**
     * @var array{
     *   close_call_gap_pct_max:float,
     *   close_call_normalized_gap_max:float,
     *   medium_confidence_gap_pct_min:float,
     *   high_confidence_gap_pct_min:float,
     *   high_confidence_normalized_gap_min:float,
     *   diffuse_entropy_min:float,
     *   diffuse_entropy_priority_min:float,
     *   diffuse_top3_spread_max:float,
     *   diffuse_gap_pct_max:float
     * }
     */
    private const POLICY_THRESHOLDS = [
        'close_call_gap_pct_max' => 8.0,
        'close_call_normalized_gap_max' => 0.35,
        'medium_confidence_gap_pct_min' => 8.0,
        'high_confidence_gap_pct_min' => 15.0,
        'high_confidence_normalized_gap_min' => 0.8,
        'diffuse_entropy_min' => 0.72,
        'diffuse_entropy_priority_min' => 0.82,
        'diffuse_top3_spread_max' => 12.0,
        'diffuse_gap_pct_max' => 15.0,
    ];

    /**
     * @var list<string>
     */
    private const INTERPRETATION_PRECEDENCE = ['low_quality', 'diffuse', 'close_call', 'clear'];

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const CENTER_LABELS = [
        'body' => ['en' => 'body', 'zh' => '身体中心'],
        'heart' => ['en' => 'heart', 'zh' => '情感中心'],
        'head' => ['en' => 'head', 'zh' => '思维中心'],
    ];

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const STANCE_LABELS = [
        'assertive' => ['en' => 'assertive', 'zh' => '主动型'],
        'compliant' => ['en' => 'compliant', 'zh' => '顺从型'],
        'withdrawn' => ['en' => 'withdrawn', 'zh' => '退缩型'],
    ];

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const HARMONIC_LABELS = [
        'positive_outlook' => ['en' => 'positive outlook', 'zh' => '积极展望组'],
        'competency' => ['en' => 'competency', 'zh' => '能力取向组'],
        'reactive' => ['en' => 'reactive', 'zh' => '反应组'],
    ];

    /**
     * @var array<string,array{center:string,stance:string,harmonic:string,left_wing:string,right_wing:string}>
     */
    private const TYPE_TRAITS = [
        'T1' => ['center' => 'body', 'stance' => 'compliant', 'harmonic' => 'competency', 'left_wing' => 'T9', 'right_wing' => 'T2'],
        'T2' => ['center' => 'heart', 'stance' => 'compliant', 'harmonic' => 'positive_outlook', 'left_wing' => 'T1', 'right_wing' => 'T3'],
        'T3' => ['center' => 'heart', 'stance' => 'assertive', 'harmonic' => 'competency', 'left_wing' => 'T2', 'right_wing' => 'T4'],
        'T4' => ['center' => 'heart', 'stance' => 'withdrawn', 'harmonic' => 'reactive', 'left_wing' => 'T3', 'right_wing' => 'T5'],
        'T5' => ['center' => 'head', 'stance' => 'withdrawn', 'harmonic' => 'competency', 'left_wing' => 'T4', 'right_wing' => 'T6'],
        'T6' => ['center' => 'head', 'stance' => 'compliant', 'harmonic' => 'reactive', 'left_wing' => 'T5', 'right_wing' => 'T7'],
        'T7' => ['center' => 'head', 'stance' => 'assertive', 'harmonic' => 'positive_outlook', 'left_wing' => 'T6', 'right_wing' => 'T8'],
        'T8' => ['center' => 'body', 'stance' => 'assertive', 'harmonic' => 'reactive', 'left_wing' => 'T7', 'right_wing' => 'T9'],
        'T9' => ['center' => 'body', 'stance' => 'withdrawn', 'harmonic' => 'positive_outlook', 'left_wing' => 'T8', 'right_wing' => 'T1'],
    ];

    /**
     * @var array<string,array{
     *   score_space_version:string,
     *   methodology_variant:string,
     *   precision_level:string,
     *   method_boundary_copy_key:string,
     *   form_interpretation_boundary:array{en:string,zh:string},
     *   score_source:string,
     *   score_display_key:string
     * }>
     */
    private const FORM_POLICY = [
        'enneagram_likert_105' => [
            'score_space_version' => 'e105_likert_space.v1',
            'methodology_variant' => 'e105_standard',
            'precision_level' => 'standard',
            'method_boundary_copy_key' => 'enneagram.method_boundary.e105_standard.v1',
            'form_interpretation_boundary' => [
                'zh' => 'E105 使用 Likert intensity / dominance score space；结果适合建立全谱结构，但不默认与 FC144 直接数值比较。',
                'en' => 'E105 uses a Likert intensity / dominance score space. It is suitable for establishing the full-profile structure, but it is not directly numerically comparable with FC144 by default.',
            ],
            'score_source' => 'likert_intensity_norm',
            'score_display_key' => 'profile100',
        ],
        'enneagram_forced_choice_144' => [
            'score_space_version' => 'fc144_forced_choice_space.v1',
            'methodology_variant' => 'fc144_forced_choice',
            'precision_level' => 'deep',
            'method_boundary_copy_key' => 'enneagram.method_boundary.fc144_forced_choice.v1',
            'form_interpretation_boundary' => [
                'zh' => 'FC144 使用 forced-choice wins / exposures score space；它提高辨析度，但不等于终极判型，也不默认与 E105 直接数值比较。',
                'en' => 'FC144 uses a forced-choice wins / exposures score space. It increases discrimination, but it is not an ultimate typing form and is not directly numerically comparable with E105 by default.',
            ],
            'score_source' => 'forced_choice_win_rate_norm',
            'score_display_key' => 'preference100',
        ],
    ];

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const TYPE_LABELS = [
        'T1' => ['en' => 'Type 1', 'zh' => '一号'],
        'T2' => ['en' => 'Type 2', 'zh' => '二号'],
        'T3' => ['en' => 'Type 3', 'zh' => '三号'],
        'T4' => ['en' => 'Type 4', 'zh' => '四号'],
        'T5' => ['en' => 'Type 5', 'zh' => '五号'],
        'T6' => ['en' => 'Type 6', 'zh' => '六号'],
        'T7' => ['en' => 'Type 7', 'zh' => '七号'],
        'T8' => ['en' => 'Type 8', 'zh' => '八号'],
        'T9' => ['en' => 'Type 9', 'zh' => '九号'],
    ];

    public function __construct(
        private readonly ?EnneagramFormCatalog $formCatalog = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildFromResult(Result $result, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        return $this->build($this->extractScoreResult($result), $locale, $variant, $locked);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildV2FromResult(Result $result, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        return $this->buildV2($this->extractScoreResult($result), $locale, $variant, $locked);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    public function build(array $scoreResult, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        $language = $this->normalizeLanguage($locale);
        $scoresPct = is_array($scoreResult['scores_0_100'] ?? null) ? $scoreResult['scores_0_100'] : [];
        $ranking = is_array($scoreResult['ranking'] ?? null) ? $scoreResult['ranking'] : [];
        $rankByType = [];
        foreach ($ranking as $row) {
            if (! is_array($row)) {
                continue;
            }
            $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
            if ($typeCode !== '') {
                $rankByType[$typeCode] = $row;
            }
        }

        $typeVector = [];
        foreach (self::TYPE_ORDER as $typeCode) {
            $row = is_array($rankByType[$typeCode] ?? null) ? $rankByType[$typeCode] : [];
            $score = (float) ($scoresPct[$typeCode] ?? ($row['score_pct'] ?? 0.0));
            $typeVector[] = [
                'type_code' => $typeCode,
                'label' => $this->typeLabel($typeCode, $language),
                'score_pct' => round($score, 2),
                'rank' => (int) ($row['rank'] ?? 0),
                'band' => $this->bandForScore($score),
            ];
        }

        $rankedTypes = $this->rankedTypes($ranking, $scoresPct, $language);
        $primaryType = $this->normalizeTypeCode($scoreResult['primary_type'] ?? ($rankedTypes[0]['type_code'] ?? ''));
        $topTypes = array_slice($rankedTypes, 0, 3);
        $confidence = is_array($scoreResult['confidence'] ?? null) ? $scoreResult['confidence'] : [];
        $scoring = $this->buildScoringBlock($scoreResult);
        $analysis = $this->buildAnalysisBlock($scoreResult, $primaryType, $topTypes, $confidence);
        $display = $this->buildDisplayBlock($scoreResult, $rankedTypes, $language);

        $sections = [
            [
                'key' => 'summary',
                'title' => $language === 'zh' ? '九型人格结果概览' : 'Enneagram result summary',
                'blocks' => [
                    [
                        'kind' => 'summary',
                        'primary_type' => $primaryType,
                        'primary_label' => $this->typeLabel($primaryType, $language),
                        'confidence_level' => strtolower(trim((string) ($confidence['level'] ?? 'unknown'))),
                        'interpretation_state' => (string) ($analysis['interpretation_state'] ?? ''),
                    ],
                ],
            ],
            [
                'key' => 'scores',
                'title' => $language === 'zh' ? '九型得分排序' : 'Nine type ranking',
                'blocks' => [
                    [
                        'kind' => 'ranking',
                        'items' => $rankedTypes,
                    ],
                ],
            ],
        ];

        return [
            'schema_version' => 'enneagram.public_projection.v1',
            'scale_code' => 'ENNEAGRAM',
            'form_code' => trim((string) ($scoreResult['form_code'] ?? '')),
            'score_method' => trim((string) ($scoreResult['score_method'] ?? '')),
            'primary_type' => $primaryType,
            'primary_label' => $this->typeLabel($primaryType, $language),
            'type_vector' => $typeVector,
            'ranked_types' => $rankedTypes,
            'top_types' => $topTypes,
            'scoring' => $scoring,
            'analysis' => $analysis,
            'display' => $display,
            'confidence' => [
                'level' => strtolower(trim((string) ($confidence['level'] ?? 'unknown'))),
                'top1_top2_gap' => $confidence['top1_top2_gap'] ?? null,
                'score_separation' => $confidence['score_separation'] ?? ($analysis['score_separation'] ?? null),
                'interpretation_state' => $confidence['interpretation_state'] ?? ($analysis['interpretation_state'] ?? null),
            ],
            'quality' => is_array($scoreResult['quality'] ?? null) ? $scoreResult['quality'] : [],
            'ordered_section_keys' => ['summary', 'scores'],
            'sections' => $sections,
            '_meta' => array_filter([
                'engine_version' => trim((string) ($scoreResult['engine_version'] ?? '')),
                'scoring_spec_version' => trim((string) ($scoreResult['scoring_spec_version'] ?? '')),
                'display_score_semantics' => $this->displayScoreSemantics($scoreResult),
                'variant' => $variant,
                'locked' => $locked,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    public function buildV2(array $scoreResult, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        $v1 = $this->build($scoreResult, $locale, $variant, $locked);
        $language = $this->normalizeLanguage($locale);
        $form = $this->resolveFormPolicy($scoreResult, $language);
        $topTypes = $this->buildTopTypesV2($scoreResult, $language, $form);
        $all9Profile = $this->buildAll9ProfileV2($scoreResult, $language, $form);
        $quality = is_array($scoreResult['quality'] ?? null) ? $scoreResult['quality'] : [];
        $policyEvaluation = $this->evaluateClassificationPolicy($scoreResult, $topTypes, $all9Profile, $quality, $language);
        $classification = $this->buildClassificationV2($policyEvaluation);
        $closeCallPair = $this->buildCloseCallPair($scoreResult, $topTypes, $policyEvaluation);
        $wingHints = $this->buildWingHints($topTypes, $all9Profile);
        $contentReleaseHash = $this->resolveContentReleaseHash($scoreResult);
        $interpretationContextId = $this->buildInterpretationContextId(
            $scoreResult,
            $form,
            $classification,
            $closeCallPair,
            $contentReleaseHash
        );
        $unavailable = $this->buildUnavailableFields($policyEvaluation);
        $formBoundary = is_array($form['form_interpretation_boundary'] ?? null)
            ? ($form['form_interpretation_boundary'][$language] ?? $form['form_interpretation_boundary']['zh'] ?? '')
            : '';
        $methodBoundaryCopyKey = (string) ($form['method_boundary_copy_key'] ?? '');
        $compareGroup = 'ENNEAGRAM:'.(string) ($form['form_code'] ?? 'unknown').':'.(string) ($form['score_space_version'] ?? 'unknown');
        $recommendedFirstAction = $this->recommendedFirstAction(
            (string) ($classification['confidence_level'] ?? 'medium_confidence'),
            (string) ($classification['interpretation_scope'] ?? 'clear'),
            (string) ($form['form_code'] ?? '')
        );

        return [
            'schema_version' => self::PROJECTION_V2_SCHEMA,
            'scale_code' => 'ENNEAGRAM',
            'form' => [
                'form_code' => $form['form_code'] ?? null,
                'form_kind' => $form['form_kind'] ?? null,
                'question_count' => $form['question_count'] ?? null,
                'estimated_minutes' => $form['estimated_minutes'] ?? null,
                'score_method' => trim((string) ($scoreResult['score_method'] ?? ($form['score_method'] ?? ''))),
                'scoring_spec_version' => trim((string) ($scoreResult['scoring_spec_version'] ?? ($form['scoring_spec_version'] ?? ''))),
                'score_space_version' => $form['score_space_version'] ?? null,
                'methodology_variant' => $form['methodology_variant'] ?? null,
            ],
            'scores' => [
                'primary_candidate' => $topTypes[0]['type'] ?? null,
                'second_candidate' => $topTypes[1]['type'] ?? null,
                'third_candidate' => $topTypes[2]['type'] ?? null,
                'top_types' => array_slice($topTypes, 0, 3),
                'all9_profile' => $all9Profile,
            ],
            'classification' => $classification,
            'dynamics' => [
                'center_scores' => [
                    'body' => null,
                    'heart' => null,
                    'head' => null,
                ],
                'stance_scores' => [
                    'assertive' => null,
                    'compliant' => null,
                    'withdrawn' => null,
                ],
                'harmonic_scores' => [
                    'positive_outlook' => null,
                    'competency' => null,
                    'reactive' => null,
                ],
                'blind_spot_type' => null,
                'close_call_pair' => $closeCallPair,
                'wing_hint_left' => $wingHints['left'],
                'wing_hint_right' => $wingHints['right'],
                'wing_hint_strength' => $wingHints['strength'],
            ],
            'methodology' => [
                'compare_compatibility_group' => $compareGroup,
                'cross_form_comparable' => false,
                'form_interpretation_boundary' => $formBoundary !== '' ? $formBoundary : null,
                'method_boundary_copy_key' => $methodBoundaryCopyKey !== '' ? $methodBoundaryCopyKey : null,
            ],
            'calibration_data' => [
                'user_confirmed_type' => null,
                'user_confirmation_source' => null,
                'resonance_score' => null,
                'observation_completion_rate' => null,
                'day3_observation_feedback' => null,
                'day7_resonance_feedback' => null,
                'user_disagreed_reason' => null,
                'suggested_next_action' => null,
            ],
            'algorithmic_meta' => [
                'projection_version' => self::PROJECTION_V2_VERSION,
                'report_schema_version' => self::REPORT_SCHEMA_VERSION,
                'close_call_rule_version' => self::CLOSE_CALL_RULE_VERSION,
                'report_engine_version' => self::REPORT_ENGINE_VERSION,
                'technical_note_version' => self::TECHNICAL_NOTE_VERSION,
                'quality_policy_version' => self::QUALITY_POLICY_VERSION,
                'confidence_policy_version' => self::CONFIDENCE_POLICY_VERSION,
            ],
            'content_binding' => [
                'content_snapshot_id' => null,
                'content_snapshot_hash' => null,
                'content_release_hash' => $contentReleaseHash !== '' ? $contentReleaseHash : null,
                'interpretation_context_id' => $interpretationContextId,
            ],
            'render_hints' => [
                'show_primary_type' => true,
                'show_close_call_card' => $closeCallPair !== null,
                'show_diffuse_warning' => (string) ($classification['interpretation_scope'] ?? '') === 'diffuse',
                'show_low_quality_boundary' => (string) ($classification['interpretation_scope'] ?? '') === 'low_quality',
                'show_wing_hint' => ($wingHints['left'] !== null || $wingHints['right'] !== null),
                'recommended_first_action' => $recommendedFirstAction,
            ],
            '_meta' => [
                'inherits_from_projection_v1' => (string) ($v1['schema_version'] ?? 'enneagram.public_projection.v1'),
                'display_score_semantics' => $v1['_meta']['display_score_semantics'] ?? $this->displayScoreSemantics($scoreResult),
                'variant' => $variant,
                'locked' => $locked,
                'source_policy' => [
                    'top_types' => [
                        'ranking_source' => 'score_result.ranking',
                        'display_score_source' => 'score_result.scores_0_100',
                        'raw_source_mode' => (string) ($form['score_source'] ?? 'unknown'),
                    ],
                    'all9_profile' => [
                        'coverage' => 'all nine Enneagram types',
                        'display_score_source' => 'score_result.scores_0_100',
                        'raw_source_mode' => (string) ($form['score_source'] ?? 'unknown'),
                    ],
                    'classification' => [
                        'dominance_gap_abs' => 'derived from score_norm top1-top2',
                        'dominance_gap_pct' => 'derived from score_norm top1-top2 and top1',
                        'normalized_gap' => 'derived from dominance_gap_abs divided by within-form all9 profile standard deviation',
                        'profile_entropy' => 'derived from within-form all9 score_norm Shannon entropy',
                        'confidence_level' => 'policy driven precedence low_quality > diffuse > close_call > clear',
                        'interpretation_scope' => 'policy driven precedence low_quality > diffuse > close_call > clear',
                    ],
                    'close_call_pair' => [
                        'source' => 'score_result.analysis with threshold fallback',
                        'rule_version' => self::CLOSE_CALL_RULE_VERSION,
                    ],
                    'wing_hint' => [
                        'source' => 'adjacent neighbors of primary candidate in all9 profile',
                        'note' => 'visual hint only; not a formal wing judgement',
                    ],
                    'compare_policy' => [
                        'same_model_not_same_score_space' => true,
                        'cross_form_comparable' => false,
                    ],
                ],
                'policy' => [
                    'versions' => [
                        'close_call_rule_version' => self::CLOSE_CALL_RULE_VERSION,
                        'quality_policy_version' => self::QUALITY_POLICY_VERSION,
                        'confidence_policy_version' => self::CONFIDENCE_POLICY_VERSION,
                    ],
                    'precedence' => self::INTERPRETATION_PRECEDENCE,
                    'thresholds' => self::POLICY_THRESHOLDS,
                    'applied' => [
                        'confidence_level' => $classification['confidence_level'] ?? null,
                        'interpretation_scope' => $classification['interpretation_scope'] ?? null,
                        'interpretation_reason' => $classification['interpretation_reason'] ?? null,
                        'low_quality_status' => $classification['low_quality_status'] ?? null,
                        'close_call_trigger_reason' => $policyEvaluation['close_call_trigger_reason'] ?? null,
                        'diffuse_trigger_reason' => $policyEvaluation['diffuse_trigger_reason'] ?? null,
                    ],
                    'signal_limitations' => [
                        'low_quality' => $policyEvaluation['quality_signal_limitation'] ?? 'no_signal',
                        'note' => 'low_quality is only triggered when operational QC flags are present in scorer/analyzer output.',
                    ],
                    'source' => '01_measurement_and_judgement_strategy.md',
                ],
                'unavailable' => $unavailable,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload,
            data_get($payload, 'breakdown_json.score_result'),
            data_get($payload, 'axis_scores_json.score_result'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && strtoupper(trim((string) ($candidate['scale_code'] ?? ''))) === 'ENNEAGRAM') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranking
     * @param  array<string,mixed>  $scoresPct
     * @return list<array<string,mixed>>
     */
    private function rankedTypes(array $ranking, array $scoresPct, string $language): array
    {
        $rows = [];
        if ($ranking !== []) {
            foreach ($ranking as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
                if ($typeCode === '') {
                    continue;
                }
                $score = (float) ($row['score_pct'] ?? ($scoresPct[$typeCode] ?? 0.0));
                $rows[] = [
                    'type_code' => $typeCode,
                    'label' => $this->typeLabel($typeCode, $language),
                    'score_pct' => round($score, 2),
                    'rank' => (int) ($row['rank'] ?? 0),
                    'band' => $this->bandForScore($score),
                ];
            }
        }

        if ($rows === []) {
            foreach (self::TYPE_ORDER as $typeCode) {
                $score = (float) ($scoresPct[$typeCode] ?? 0.0);
                $rows[] = [
                    'type_code' => $typeCode,
                    'label' => $this->typeLabel($typeCode, $language),
                    'score_pct' => round($score, 2),
                    'rank' => 0,
                    'band' => $this->bandForScore($score),
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $scoreCompare = ((float) $b['score_pct']) <=> ((float) $a['score_pct']);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp((string) $a['type_code'], (string) $b['type_code']);
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function buildScoringBlock(array $scoreResult): array
    {
        $scoring = is_array($scoreResult['scoring'] ?? null) ? $scoreResult['scoring'] : [];
        if ($scoring !== []) {
            return $scoring;
        }

        $rawScores = is_array($scoreResult['raw_scores'] ?? null) ? $scoreResult['raw_scores'] : [];
        if (is_array($rawScores['raw_intensity'] ?? null)) {
            return [
                'raw' => $rawScores['raw_intensity'],
                'dominance' => is_array($rawScores['dominance'] ?? null) ? $rawScores['dominance'] : [],
            ];
        }

        if (is_array($rawScores['type_counts'] ?? null)) {
            return [
                'wins' => $rawScores['type_counts'],
                'exposures' => is_array($rawScores['exposures'] ?? null) ? $rawScores['exposures'] : [],
            ];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  list<array<string,mixed>>  $topTypes
     * @param  array<string,mixed>  $confidence
     * @return array<string,mixed>
     */
    private function buildAnalysisBlock(array $scoreResult, string $primaryType, array $topTypes, array $confidence): array
    {
        $analysis = is_array($scoreResult['analysis'] ?? null) ? $scoreResult['analysis'] : [];

        return array_filter([
            'core_type' => (string) ($analysis['core_type'] ?? $primaryType),
            'top3' => is_array($analysis['top3'] ?? null)
                ? $analysis['top3']
                : array_values(array_map(static fn (array $row): string => (string) ($row['type_code'] ?? ''), $topTypes)),
            'wing_candidate' => $analysis['wing_candidate'] ?? null,
            'wing_neighbor_scores' => $analysis['wing_neighbor_scores'] ?? null,
            'runner_up' => $analysis['runner_up'] ?? null,
            'topology_relation_of_runner_up' => $analysis['topology_relation_of_runner_up'] ?? null,
            'score_separation' => $analysis['score_separation'] ?? ($confidence['score_separation'] ?? ($confidence['top1_top2_gap'] ?? null)),
            'tie_break_status' => $analysis['tie_break_status'] ?? null,
            'unresolved_tie' => $analysis['unresolved_tie'] ?? null,
            'close_call_candidates' => $analysis['close_call_candidates'] ?? null,
            'tie_break_scores' => $analysis['tie_break_scores'] ?? null,
            'interpretation_state' => $analysis['interpretation_state'] ?? ($confidence['interpretation_state'] ?? null),
            'confidence_band' => $analysis['confidence_band'] ?? ($confidence['level'] ?? null),
            'response_quality_summary' => $analysis['response_quality_summary'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  list<array<string,mixed>>  $rankedTypes
     * @return array<string,mixed>
     */
    private function buildDisplayBlock(array $scoreResult, array $rankedTypes, string $language): array
    {
        $display = is_array($scoreResult['display'] ?? null) ? $scoreResult['display'] : [];
        $profile100 = is_array($display['profile100'] ?? null) ? $display['profile100'] : [];
        $preference100 = is_array($display['preference100'] ?? null) ? $display['preference100'] : [];
        $scoreKey = $profile100 !== [] ? 'profile100' : 'preference100';
        $scores = $profile100 !== [] ? $profile100 : $preference100;

        $chartVector = [];
        foreach ($rankedTypes as $row) {
            $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
            if ($typeCode === '') {
                continue;
            }
            $chartVector[] = [
                'type_code' => $typeCode,
                'label' => $this->typeLabel($typeCode, $language),
                $scoreKey => $scores[$typeCode] ?? null,
                'rank' => (int) ($row['rank'] ?? 0),
            ];
        }

        return array_filter([
            'profile100' => $profile100 !== [] ? $profile100 : null,
            'preference100' => $preference100 !== [] ? $preference100 : null,
            'chart_vector' => $chartVector,
            'score_kind' => $display['score_kind'] ?? null,
            'score_note' => $display['score_note'] ?? null,
            'not_percentile' => true,
            'not_t_score' => true,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,string|bool>
     */
    private function displayScoreSemantics(array $scoreResult): array
    {
        $formCode = trim((string) ($scoreResult['form_code'] ?? ''));
        if ($formCode === 'enneagram_forced_choice_144') {
            return [
                'display_score' => 'preference100',
                'basis' => 'wins divided by exposures',
                'not_percentile' => true,
                'not_t_score' => true,
            ];
        }

        return [
            'display_score' => 'profile100',
            'basis' => 'raw intensity mapped from [-2,2] to [0,100]',
            'not_percentile' => true,
            'not_t_score' => true,
        ];
    }

    private function normalizeTypeCode(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (preg_match('/^T([1-9])$/', $value, $matches) !== 1) {
            return '';
        }

        return 'T'.$matches[1];
    }

    private function typeLabel(string $typeCode, string $language): string
    {
        $labels = self::TYPE_LABELS[$typeCode] ?? null;
        if (! is_array($labels)) {
            return $typeCode;
        }

        return (string) ($labels[$language] ?? $labels['zh']);
    }

    private function bandForScore(float $score): string
    {
        if ($score >= 67.0) {
            return 'high';
        }
        if ($score <= 33.0) {
            return 'low';
        }

        return 'mid';
    }

    private function normalizeLanguage(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'en') ? 'en' : 'zh';
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function resolveFormPolicy(array $scoreResult, string $language): array
    {
        $formCode = trim((string) ($scoreResult['form_code'] ?? ''));
        $resolved = [];
        if ($formCode !== '') {
            try {
                $catalog = $this->resolveFormCatalog();
                $resolved = $catalog?->resolve($formCode) ?? [];
            } catch (\Throwable) {
                $resolved = [];
            }
        }

        $policy = self::FORM_POLICY[$formCode] ?? [];
        $estimatedMinutes = null;
        $forms = config('content_packs.enneagram_forms.forms', []);
        if (is_array($forms) && is_array($forms[$formCode] ?? null)) {
            $public = is_array($forms[$formCode]['public'] ?? null) ? $forms[$formCode]['public'] : [];
            $estimatedMinutes = (int) ($public['estimated_minutes'] ?? 0);
            if ($estimatedMinutes <= 0) {
                $estimatedMinutes = null;
            }
        }

        return [
            'form_code' => $formCode !== '' ? $formCode : null,
            'form_kind' => $resolved['form_kind'] ?? null,
            'question_count' => isset($resolved['question_count']) ? (int) $resolved['question_count'] : null,
            'estimated_minutes' => $estimatedMinutes,
            'score_method' => trim((string) ($scoreResult['score_method'] ?? '')),
            'scoring_spec_version' => trim((string) ($scoreResult['scoring_spec_version'] ?? ($resolved['scoring_spec_version'] ?? ''))),
            'score_space_version' => $policy['score_space_version'] ?? 'unknown',
            'methodology_variant' => $policy['methodology_variant'] ?? 'unknown',
            'precision_level' => $policy['precision_level'] ?? 'unavailable',
            'precision_label' => $this->precisionLabel((string) ($policy['precision_level'] ?? 'unavailable'), $language),
            'method_boundary_copy_key' => $policy['method_boundary_copy_key'] ?? null,
            'form_interpretation_boundary' => $policy['form_interpretation_boundary'] ?? [
                'zh' => '当前 form 方法边界未配置。',
                'en' => 'The current form interpretation boundary is unavailable.',
            ],
            'score_source' => $policy['score_source'] ?? 'unknown',
            'score_display_key' => $policy['score_display_key'] ?? 'score_norm',
        ];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $form
     * @return list<array<string,mixed>>
     */
    private function buildTopTypesV2(array $scoreResult, string $language, array $form): array
    {
        $scoresPct = is_array($scoreResult['scores_0_100'] ?? null) ? $scoreResult['scores_0_100'] : [];
        $rankedTypes = $this->rankedTypes(
            is_array($scoreResult['ranking'] ?? null) ? $scoreResult['ranking'] : [],
            $scoresPct,
            $language
        );
        $rows = [];

        foreach ($rankedTypes as $row) {
            $typeCode = $this->normalizeTypeCode($row['type_code'] ?? '');
            if ($typeCode === '') {
                continue;
            }

            $rank = (int) ($row['rank'] ?? 0);
            $scoreNorm = $this->normalizeFloat($scoresPct[$typeCode] ?? ($row['score_pct'] ?? null));
            $scoreRaw = $this->rawScoreForType($scoreResult, $typeCode);

            $rows[] = [
                'type' => $this->publicTypeCode($typeCode),
                'rank' => $rank > 0 ? $rank : count($rows) + 1,
                'score_norm' => $scoreNorm,
                'score_raw' => $scoreRaw,
                'score_display' => $this->formatDisplayScore($scoreNorm),
                'score_source' => (string) ($form['score_source'] ?? 'unknown'),
                'display_score_key' => (string) ($form['score_display_key'] ?? 'score_norm'),
                'candidate_role' => $this->candidateRole($rank > 0 ? $rank : count($rows) + 1),
                'label' => $this->typeLabel($typeCode, $language),
                'center' => $this->typeTrait($typeCode, 'center'),
                'stance' => $this->typeTrait($typeCode, 'stance'),
                'harmonic' => $this->typeTrait($typeCode, 'harmonic'),
                'raw_source_summary' => $this->rawSourceSummary($scoreResult, $typeCode),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $form
     * @return list<array<string,mixed>>
     */
    private function buildAll9ProfileV2(array $scoreResult, string $language, array $form): array
    {
        $rankMap = [];
        foreach ($this->buildTopTypesV2($scoreResult, $language, $form) as $row) {
            $publicType = (string) ($row['type'] ?? '');
            if ($publicType !== '') {
                $rankMap[$publicType] = $row;
            }
        }

        $scoresPct = is_array($scoreResult['scores_0_100'] ?? null) ? $scoreResult['scores_0_100'] : [];
        $rows = [];
        foreach (self::TYPE_ORDER as $typeCode) {
            $publicType = $this->publicTypeCode($typeCode);
            $scoreNorm = $this->normalizeFloat($scoresPct[$typeCode] ?? null);
            $rankRow = is_array($rankMap[$publicType] ?? null) ? $rankMap[$publicType] : [];
            $rows[] = [
                'type' => $publicType,
                'rank' => isset($rankRow['rank']) ? (int) $rankRow['rank'] : null,
                'score_norm' => $scoreNorm,
                'score_display' => $this->formatDisplayScore($scoreNorm),
                'label' => $this->typeLabel($typeCode, $language),
                'center' => $this->typeTrait($typeCode, 'center'),
                'stance' => $this->typeTrait($typeCode, 'stance'),
                'harmonic' => $this->typeTrait($typeCode, 'harmonic'),
                'raw_source_summary' => $this->rawSourceSummary($scoreResult, $typeCode),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $topTypes
     * @param  list<array<string,mixed>>  $all9Profile
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    private function evaluateClassificationPolicy(
        array $scoreResult,
        array $topTypes,
        array $all9Profile,
        array $quality,
        string $language
    ): array {
        $top1Norm = $this->normalizeFloat($topTypes[0]['score_norm'] ?? null);
        $top2Norm = $this->normalizeFloat($topTypes[1]['score_norm'] ?? null);
        $top3Norm = $this->normalizeFloat($topTypes[2]['score_norm'] ?? null);
        $gapAbs = ($top1Norm !== null && $top2Norm !== null)
            ? round($top1Norm - $top2Norm, 4)
            : null;
        $gapPct = ($gapAbs !== null && $top1Norm !== null && $top1Norm > 0.0)
            ? round(($gapAbs / max($top1Norm, 1.0)) * 100.0, 4)
            : null;
        $top3Spread = ($top1Norm !== null && $top3Norm !== null)
            ? round($top1Norm - $top3Norm, 4)
            : null;
        $profileSd = $this->profileStandardDeviation($all9Profile);
        $normalizedGap = ($gapAbs !== null && $profileSd !== null && $profileSd > 0.0)
            ? round($gapAbs / $profileSd, 6)
            : null;
        $profileEntropy = $this->profileEntropy($all9Profile);
        $analysis = is_array($scoreResult['analysis'] ?? null) ? $scoreResult['analysis'] : [];
        $qualitySummary = $this->qualitySignalSummary($quality);
        $precisionLevel = (string) ($this->resolveFormPolicy($scoreResult, $language)['precision_level'] ?? 'unavailable');
        $closeCallTriggerReason = $this->closeCallTriggerReason($analysis, $gapPct, $normalizedGap);
        $diffuseTriggerReason = in_array($closeCallTriggerReason, ['analyzer_close_call', 'unresolved_tie'], true)
            ? null
            : $this->diffuseTriggerReason($profileEntropy, $top3Spread, $gapPct);

        $interpretationScope = 'clear';
        $confidenceLevel = 'medium_confidence';
        $interpretationReason = 'clear_within_policy';

        if ((bool) ($qualitySummary['trigger_low_quality'] ?? false)) {
            $interpretationScope = 'low_quality';
            $confidenceLevel = 'low_quality';
            $interpretationReason = 'operational_qc_flags_present';
        } elseif ($diffuseTriggerReason !== null) {
            $interpretationScope = 'diffuse';
            $confidenceLevel = 'diffuse';
            $interpretationReason = $diffuseTriggerReason;
        } elseif ($closeCallTriggerReason !== null) {
            $interpretationScope = 'close_call';
            $confidenceLevel = 'close_call';
            $interpretationReason = $closeCallTriggerReason;
        } elseif ($this->isHighConfidence($gapPct, $normalizedGap)) {
            $interpretationScope = 'clear';
            $confidenceLevel = 'high_confidence';
            $interpretationReason = 'gap_above_high_confidence_threshold';
        } else {
            $interpretationScope = 'clear';
            $confidenceLevel = 'medium_confidence';
            $interpretationReason = 'gap_within_medium_confidence_band';
        }

        return [
            'top1' => $topTypes[0]['type'] ?? null,
            'top2' => $topTypes[1]['type'] ?? null,
            'top3' => $topTypes[2]['type'] ?? null,
            'dominance_gap_abs' => $gapAbs,
            'dominance_gap_pct' => $gapPct,
            'normalized_gap' => $normalizedGap,
            'top3_spread' => $top3Spread,
            'profile_entropy' => $profileEntropy,
            'confidence_level' => $confidenceLevel,
            'confidence_label' => $this->confidenceLabel($confidenceLevel, $language),
            'precision_level' => $precisionLevel,
            'precision_label' => $this->precisionLabel($precisionLevel, $language),
            'interpretation_scope' => $interpretationScope,
            'interpretation_reason' => $interpretationReason,
            'quality_level' => $qualitySummary['quality_level'] ?? 'unavailable',
            'low_quality_status' => $qualitySummary['low_quality_status'] ?? 'not_triggered_no_operational_signal',
            'quality_signal_limitation' => $qualitySummary['signal_limitation'] ?? 'no_signal',
            'qc_flags' => $qualitySummary['flags'] ?? [],
            'close_call_trigger_reason' => $closeCallTriggerReason,
            'diffuse_trigger_reason' => $diffuseTriggerReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $policyEvaluation
     * @return array<string,mixed>
     */
    private function buildClassificationV2(array $policyEvaluation): array
    {
        return [
            'dominance' => [
                'top1' => $policyEvaluation['top1'] ?? null,
                'top2' => $policyEvaluation['top2'] ?? null,
                'top3' => $policyEvaluation['top3'] ?? null,
                'gap_abs' => $policyEvaluation['dominance_gap_abs'] ?? null,
                'gap_pct' => $policyEvaluation['dominance_gap_pct'] ?? null,
                'normalized_gap' => $policyEvaluation['normalized_gap'] ?? null,
                'top3_spread' => $policyEvaluation['top3_spread'] ?? null,
                'profile_entropy' => $policyEvaluation['profile_entropy'] ?? null,
            ],
            'dominance_gap_abs' => $policyEvaluation['dominance_gap_abs'] ?? null,
            'dominance_gap_pct' => $policyEvaluation['dominance_gap_pct'] ?? null,
            'confidence_level' => $policyEvaluation['confidence_level'] ?? null,
            'confidence_band' => $policyEvaluation['confidence_level'] ?? null,
            'confidence_label' => $policyEvaluation['confidence_label'] ?? null,
            'precision_level' => $policyEvaluation['precision_level'] ?? null,
            'precision_label' => $policyEvaluation['precision_label'] ?? null,
            'interpretation_scope' => $policyEvaluation['interpretation_scope'] ?? null,
            'interpretation_reason' => $policyEvaluation['interpretation_reason'] ?? null,
            'quality_level' => $policyEvaluation['quality_level'] ?? null,
            'low_quality_status' => $policyEvaluation['low_quality_status'] ?? null,
            'qc_flags' => $policyEvaluation['qc_flags'] ?? [],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $topTypes
     * @param  array<string,mixed>  $policyEvaluation
     * @return array<string,mixed>|null
     */
    private function buildCloseCallPair(array $scoreResult, array $topTypes, array $policyEvaluation): ?array
    {
        $analysis = is_array($scoreResult['analysis'] ?? null) ? $scoreResult['analysis'] : [];
        $pairTypes = [];
        $triggerReason = (string) ($policyEvaluation['close_call_trigger_reason'] ?? '');
        $isCloseCall = (string) ($policyEvaluation['interpretation_scope'] ?? '') === 'close_call';

        $closeCallCandidates = is_array($analysis['close_call_candidates'] ?? null)
            ? $analysis['close_call_candidates']
            : [];
        if (count($closeCallCandidates) >= 2) {
            $pairTypes = array_slice(array_values(array_map(fn (mixed $value): string => $this->publicTypeCode($this->normalizeTypeCode($value)), $closeCallCandidates)), 0, 2);
        } elseif ($isCloseCall) {
            $pairTypes = [
                (string) ($topTypes[0]['type'] ?? ''),
                (string) ($topTypes[1]['type'] ?? ''),
            ];
        }

        $pairTypes = array_values(array_filter($pairTypes, static fn (string $value): bool => $value !== ''));
        if (count($pairTypes) < 2) {
            return null;
        }

        sort($pairTypes, SORT_STRING);

        return [
            'type_a' => $pairTypes[0],
            'type_b' => $pairTypes[1],
            'pair_key' => implode('_', $pairTypes),
            'rule_version' => self::CLOSE_CALL_RULE_VERSION,
            'trigger_reason' => $triggerReason !== '' ? $triggerReason : 'analyzer_close_call',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $topTypes
     * @param  list<array<string,mixed>>  $all9Profile
     * @return array{left:?array<string,mixed>,right:?array<string,mixed>,strength:?float}
     */
    private function buildWingHints(array $topTypes, array $all9Profile): array
    {
        $primaryType = (string) ($topTypes[0]['type'] ?? '');
        $primaryTypeCode = $this->internalTypeCode($primaryType);
        if ($primaryTypeCode === '') {
            return ['left' => null, 'right' => null, 'strength' => null];
        }

        $traits = self::TYPE_TRAITS[$primaryTypeCode] ?? null;
        if (! is_array($traits)) {
            return ['left' => null, 'right' => null, 'strength' => null];
        }

        $profileByType = [];
        foreach ($all9Profile as $row) {
            $type = trim((string) ($row['type'] ?? ''));
            if ($type !== '') {
                $profileByType[$type] = $row;
            }
        }

        $leftType = $this->publicTypeCode($traits['left_wing']);
        $rightType = $this->publicTypeCode($traits['right_wing']);
        $leftNorm = $this->normalizeFloat($profileByType[$leftType]['score_norm'] ?? null);
        $rightNorm = $this->normalizeFloat($profileByType[$rightType]['score_norm'] ?? null);

        return [
            'left' => $this->wingHintNode($leftType, $leftNorm, $leftNorm, $rightNorm),
            'right' => $this->wingHintNode($rightType, $rightNorm, $rightNorm, $leftNorm),
            'strength' => ($leftNorm !== null && $rightNorm !== null) ? round(abs($leftNorm - $rightNorm), 4) : null,
        ];
    }

    private function wingHintNode(string $type, ?float $scoreNorm, ?float $current, ?float $other): ?array
    {
        if ($type === '') {
            return null;
        }

        return [
            'type' => $type,
            'score_norm' => $scoreNorm,
            'relative_strength' => $this->relativeStrength($current, $other),
        ];
    }

    private function relativeStrength(?float $current, ?float $other): string
    {
        if ($current === null) {
            return 'unavailable';
        }
        if ($other === null) {
            return 'medium';
        }

        $max = max($current, $other, 0.01);
        $ratio = $current / $max;
        if ($ratio >= 0.9) {
            return 'high';
        }
        if ($ratio >= 0.6) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,mixed>  $analysis
     */
    private function closeCallTriggerReason(array $analysis, ?float $gapPct, ?float $normalizedGap): ?string
    {
        if ((bool) ($analysis['unresolved_tie'] ?? false)) {
            return 'unresolved_tie';
        }

        $interpretationState = trim((string) ($analysis['interpretation_state'] ?? ''));
        if (in_array($interpretationState, ['mixed_close_call', 'forced_choice_close_call', 'wing_heavy', 'line_tension'], true)) {
            return 'analyzer_close_call';
        }

        if ($gapPct !== null && $gapPct < self::POLICY_THRESHOLDS['close_call_gap_pct_max']) {
            return 'gap_below_threshold';
        }

        if ($normalizedGap !== null && $normalizedGap < self::POLICY_THRESHOLDS['close_call_normalized_gap_max']) {
            return 'normalized_gap_below_threshold';
        }

        return null;
    }

    private function diffuseTriggerReason(?float $profileEntropy, ?float $top3Spread, ?float $gapPct): ?string
    {
        if ($profileEntropy !== null
            && $profileEntropy >= self::POLICY_THRESHOLDS['diffuse_entropy_priority_min']
            && ($gapPct === null || $gapPct < self::POLICY_THRESHOLDS['diffuse_gap_pct_max'])) {
            return 'high_profile_entropy';
        }

        if ($profileEntropy !== null
            && $profileEntropy >= self::POLICY_THRESHOLDS['diffuse_entropy_min']
            && $top3Spread !== null
            && $top3Spread <= self::POLICY_THRESHOLDS['diffuse_top3_spread_max']
            && ($gapPct === null || $gapPct < self::POLICY_THRESHOLDS['diffuse_gap_pct_max'])) {
            return 'top3_spread_low';
        }

        return null;
    }

    private function isHighConfidence(?float $gapPct, ?float $normalizedGap): bool
    {
        if ($gapPct !== null && $gapPct >= self::POLICY_THRESHOLDS['high_confidence_gap_pct_min']) {
            return true;
        }

        return $normalizedGap !== null && $normalizedGap >= self::POLICY_THRESHOLDS['high_confidence_normalized_gap_min'];
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array{
     *   flags:list<string>,
     *   quality_level:string,
     *   low_quality_status:string,
     *   trigger_low_quality:bool,
     *   signal_limitation:string
     * }
     */
    private function qualitySignalSummary(array $quality): array
    {
        $flags = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($quality['flags'] ?? null) ? $quality['flags'] : []
        )));
        $level = strtoupper(trim((string) ($quality['level'] ?? 'P0')));
        $hasOperationalSignal = $flags !== [];
        $hasHardSignal = $hasOperationalSignal && $level !== '' && $level !== 'P0' && $level !== 'CLEAN';

        if ($hasHardSignal) {
            return [
                'flags' => $flags,
                'quality_level' => 'retest',
                'low_quality_status' => 'triggered_operational_signal',
                'trigger_low_quality' => true,
                'signal_limitation' => 'operational_signal_present',
            ];
        }

        if ($hasOperationalSignal) {
            return [
                'flags' => $flags,
                'quality_level' => 'caution',
                'low_quality_status' => 'not_triggered_soft_signal_only',
                'trigger_low_quality' => false,
                'signal_limitation' => 'soft_signal_only',
            ];
        }

        return [
            'flags' => [],
            'quality_level' => 'unavailable',
            'low_quality_status' => 'not_triggered_no_operational_signal',
            'trigger_low_quality' => false,
            'signal_limitation' => 'no_signal',
        ];
    }

    private function confidenceLabel(string $confidenceLevel, string $language): string
    {
        return match ($confidenceLevel) {
            'high_confidence' => $language === 'zh' ? '高置信结果' : 'High-confidence result',
            'medium_confidence' => $language === 'zh' ? '中等置信结果' : 'Medium-confidence result',
            'close_call' => $language === 'zh' ? '近距离辨析结果' : 'Close-call result',
            'diffuse' => $language === 'zh' ? '分散型结果' : 'Diffuse result',
            'low_quality' => $language === 'zh' ? '低质量边界结果' : 'Low-quality boundary result',
            default => $language === 'zh' ? '结果待解释' : 'Result requires interpretation',
        };
    }

    private function precisionLabel(string $precisionLevel, string $language): string
    {
        return match ($precisionLevel) {
            'standard' => $language === 'zh' ? '标准辨析度' : 'Standard discrimination',
            'deep' => $language === 'zh' ? '深度辨析度' : 'Deep discrimination',
            default => $language === 'zh' ? '辨析度暂不可用' : 'Precision unavailable',
        };
    }

    private function publicTypeCode(string $typeCode): string
    {
        return str_starts_with($typeCode, 'T') ? substr($typeCode, 1) : $typeCode;
    }

    private function internalTypeCode(string $type): string
    {
        $normalized = trim($type);
        if ($normalized === '') {
            return '';
        }
        if (preg_match('/^[1-9]$/', $normalized) === 1) {
            return 'T'.$normalized;
        }

        return $this->normalizeTypeCode($normalized);
    }

    private function typeTrait(string $typeCode, string $field): ?string
    {
        $traits = self::TYPE_TRAITS[$typeCode] ?? null;

        return is_array($traits) ? ($traits[$field] ?? null) : null;
    }

    private function candidateRole(int $rank): string
    {
        return match ($rank) {
            1 => 'primary',
            2 => 'secondary',
            3 => 'tertiary',
            default => 'other',
        };
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    private function rawSourceSummary(array $scoreResult, string $typeCode): array
    {
        $rawScores = is_array($scoreResult['raw_scores'] ?? null) ? $scoreResult['raw_scores'] : [];
        if (is_array($rawScores['raw_intensity'] ?? null)) {
            return [
                'mode' => 'likert_intensity',
                'raw_intensity' => $this->normalizeFloat($rawScores['raw_intensity'][$typeCode] ?? null),
                'dominance' => $this->normalizeFloat($rawScores['dominance'][$typeCode] ?? null),
            ];
        }

        if (is_array($rawScores['type_counts'] ?? null)) {
            return [
                'mode' => 'forced_choice_wins_exposures',
                'wins' => isset($rawScores['type_counts'][$typeCode]) ? (int) $rawScores['type_counts'][$typeCode] : null,
                'exposures' => isset($rawScores['exposures'][$typeCode]) ? (int) $rawScores['exposures'][$typeCode] : null,
            ];
        }

        return [
            'mode' => 'unavailable',
        ];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     */
    private function rawScoreForType(array $scoreResult, string $typeCode): ?float
    {
        $summary = $this->rawSourceSummary($scoreResult, $typeCode);
        if (array_key_exists('raw_intensity', $summary)) {
            return $this->normalizeFloat($summary['raw_intensity'] ?? null);
        }
        if (array_key_exists('wins', $summary)) {
            return isset($summary['wins']) ? (float) $summary['wins'] : null;
        }

        return null;
    }

    private function formatDisplayScore(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $formatted = number_format($score, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     */
    private function resolveContentReleaseHash(array $scoreResult): string
    {
        $versionSnapshot = is_array($scoreResult['version_snapshot'] ?? null) ? $scoreResult['version_snapshot'] : [];

        return trim((string) (
            $versionSnapshot['content_manifest_hash']
            ?? $scoreResult['content_manifest_hash']
            ?? ''
        ));
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $form
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>|null  $closeCallPair
     */
    private function buildInterpretationContextId(
        array $scoreResult,
        array $form,
        array $classification,
        ?array $closeCallPair,
        string $contentReleaseHash
    ): string {
        $payload = [
            'scale_code' => 'ENNEAGRAM',
            'form_code' => $form['form_code'] ?? null,
            'score_method' => $scoreResult['score_method'] ?? null,
            'scoring_spec_version' => $scoreResult['scoring_spec_version'] ?? null,
            'score_space_version' => $form['score_space_version'] ?? null,
            'projection_version' => self::PROJECTION_V2_VERSION,
            'report_schema_version' => self::REPORT_SCHEMA_VERSION,
            'close_call_rule_version' => self::CLOSE_CALL_RULE_VERSION,
            'quality_policy_version' => self::QUALITY_POLICY_VERSION,
            'confidence_policy_version' => self::CONFIDENCE_POLICY_VERSION,
            'confidence_level' => $classification['confidence_level'] ?? null,
            'interpretation_scope' => $classification['interpretation_scope'] ?? null,
            'close_call_pair' => $closeCallPair !== null ? ($closeCallPair['pair_key'] ?? null) : null,
            'content_release_hash' => $contentReleaseHash !== '' ? $contentReleaseHash : null,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $policyEvaluation
     * @return array<string,mixed>
     */
    private function buildUnavailableFields(array $policyEvaluation): array
    {
        $out = [
            'dynamics' => [
                'center_scores' => [
                    'status' => 'unavailable',
                    'reason' => 'deferred_to_future_group_policy_not_shipped',
                ],
                'stance_scores' => [
                    'status' => 'unavailable',
                    'reason' => 'deferred_to_future_group_policy_not_shipped',
                ],
                'harmonic_scores' => [
                    'status' => 'unavailable',
                    'reason' => 'deferred_to_future_group_policy_not_shipped',
                ],
                'blind_spot_type' => [
                    'status' => 'unavailable',
                    'reason' => 'deferred_to_future_blind_spot_policy_not_shipped',
                ],
            ],
        ];

        if (($policyEvaluation['normalized_gap'] ?? null) === null) {
            $out['classification']['dominance']['normalized_gap'] = [
                'status' => 'unavailable',
                'reason' => 'profile_sd_zero_or_missing',
            ];
        }

        if (($policyEvaluation['profile_entropy'] ?? null) === null) {
            $out['classification']['dominance']['profile_entropy'] = [
                'status' => 'unavailable',
                'reason' => 'score_norm_sum_zero_or_missing',
            ];
        }

        if (($policyEvaluation['low_quality_status'] ?? '') === 'not_triggered_no_operational_signal') {
            $out['classification']['quality']['low_quality_status'] = [
                'status' => 'no_signal',
                'reason' => 'current_scorers_do_not_emit_operational_qc_flags',
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $all9Profile
     */
    private function profileStandardDeviation(array $all9Profile): ?float
    {
        $scores = [];
        foreach ($all9Profile as $row) {
            $score = $this->normalizeFloat($row['score_norm'] ?? null);
            if ($score !== null) {
                $scores[] = $score;
            }
        }

        $count = count($scores);
        if ($count === 0) {
            return null;
        }

        $mean = array_sum($scores) / $count;
        $variance = 0.0;
        foreach ($scores as $score) {
            $variance += ($score - $mean) ** 2;
        }
        $variance /= $count;

        $sd = sqrt($variance);

        return $sd > 0.0 ? round($sd, 6) : null;
    }

    /**
     * @param  list<array<string,mixed>>  $all9Profile
     */
    private function profileEntropy(array $all9Profile): ?float
    {
        $scores = [];
        foreach ($all9Profile as $row) {
            $score = $this->normalizeFloat($row['score_norm'] ?? null);
            if ($score !== null && $score > 0.0) {
                $scores[] = $score;
            }
        }

        $count = count($scores);
        $sum = array_sum($scores);
        if ($count <= 1 || $sum <= 0.0) {
            return null;
        }

        $entropy = 0.0;
        foreach ($scores as $score) {
            $p = $score / $sum;
            $entropy += -1.0 * $p * log($p);
        }

        return round($entropy / log(9.0), 6);
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 6);
        }

        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return round((float) $value, 6);
        }

        return null;
    }

    private function recommendedFirstAction(string $confidenceLevel, string $interpretationScope, string $formCode): string
    {
        if ($interpretationScope === 'low_quality') {
            return 'retake_same_form';
        }
        if ($interpretationScope === 'diffuse') {
            return 'observe_7_days';
        }
        if ($confidenceLevel === 'close_call' && $formCode === 'enneagram_likert_105') {
            return 'fc144';
        }
        if ($confidenceLevel === 'close_call') {
            return 'read_close_call';
        }

        return 'observe_7_days';
    }

    private function resolveFormCatalog(): ?EnneagramFormCatalog
    {
        if ($this->formCatalog instanceof EnneagramFormCatalog) {
            return $this->formCatalog;
        }

        try {
            $resolved = app(EnneagramFormCatalog::class);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof EnneagramFormCatalog ? $resolved : null;
    }
}
