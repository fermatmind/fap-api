<?php

declare(strict_types=1);

namespace App\Services\Report;

final class EqIntegratedReportComposer
{
    private const STRATEGY_LABELS = [
        'CUE' => 'emotion_cue_reading',
        'PAUSE' => 'pressure_pause',
        'EMP' => 'empathic_response',
        'BND' => 'boundary_setting',
        'REPAIR' => 'relationship_repair',
        'INFL' => 'constructive_influence',
    ];

    /**
     * @param  array<string,mixed>  $eq60Report
     * @param  array<string,mixed>  $sjtScore
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public function compose(array $eq60Report, array $sjtScore, array $ctx = []): array
    {
        $locale = $this->string(data_get($ctx, 'locale')) ?: $this->string($eq60Report['locale'] ?? null) ?: 'zh-CN';
        $gapMap = $this->buildGapMap($eq60Report, $sjtScore);
        $pressurePattern = $this->buildPressurePattern($sjtScore, $gapMap);
        $scriptIds = $this->scriptIds($gapMap, $pressurePattern);
        $integratedActionPath = $this->integratedActionPath($sjtScore, $gapMap, $pressurePattern);

        return [
            'schema_version' => 'eq.integrated_report.v1',
            'scale_code' => 'EQ_INTEGRATED',
            'eq_report_mode' => 'integrated',
            'measurement_type' => 'integrated_self_report_and_scenario_judgment',
            'locale' => $locale,
            'access' => [
                'all_results_free' => true,
                'locked' => false,
                'blur' => false,
                'paywall' => false,
            ],
            'component_reports' => [
                'self_report' => [
                    'scale_code' => 'EQ_60',
                    'measurement_type' => 'self_report_trait_mixed_ei',
                    'eq_report_mode' => 'self_report',
                    'core_formulation_id' => $this->string(data_get($eq60Report, 'interpretation.core_formulation_id')),
                    'quality' => data_get($eq60Report, 'quality', []),
                ],
                'scenario_judgment' => [
                    'scale_code' => 'EQ_SJT_16',
                    'measurement_type' => 'scenario_based_emotional_judgment',
                    'answer_mode' => $this->string($sjtScore['answer_mode'] ?? null) ?: 'likely_response',
                    'score_method' => $this->string($sjtScore['score_method'] ?? null),
                    'quality' => data_get($sjtScore, 'quality', []),
                ],
            ],
            'scores' => [
                'self_report' => [
                    'global' => data_get($eq60Report, 'scores.global', []),
                    'dimensions' => data_get($eq60Report, 'scores.dimensions', []),
                ],
                'scenario_judgment' => [
                    'overall' => [
                        'raw_score' => $this->number($sjtScore['raw_score'] ?? null),
                        'max_score' => $this->number($sjtScore['max_score'] ?? null),
                        'score_pct' => $this->number($sjtScore['score_pct'] ?? null),
                        'band' => $this->string($sjtScore['band'] ?? null),
                    ],
                    'strategy_scores' => data_get($sjtScore, 'strategy_scores', []),
                    'domain_scores' => data_get($sjtScore, 'domain_scores', []),
                ],
            ],
            'interpretation' => [
                'gap_map' => $gapMap,
                'pressure_pattern' => $pressurePattern,
                'scenario_script_ids' => $scriptIds,
                'integrated_action_path' => $integratedActionPath,
            ],
            'methodology' => [
                'report_version' => 'eq_integrated_report_v1_draft',
                'self_report_content_version' => $this->string(data_get($eq60Report, 'methodology.content_version')) ?: 'EQ_60/v1',
                'scenario_content_version' => $this->string(data_get($sjtScore, 'version_snapshot.content_version')) ?: 'EQ_SJT_16/v1',
                'scenario_rubric_version' => $this->string(data_get($sjtScore, 'version_snapshot.rubric_version')) ?: 'eq_sjt_16.rubric.v1_draft',
                'validation_status' => 'draft_not_yet_validated',
            ],
            'claim_boundary' => [
                'not_clinical' => true,
                'not_hiring' => true,
                'not_certified_capability_evaluation' => true,
                'not_msceit_equivalent' => true,
                'not_true_emotional_ability_score' => true,
                'does_not_predict_job_performance' => true,
            ],
            'visibility' => [
                'public_runtime_enabled' => false,
                'requires_sjt_runtime_available' => true,
                'frontend_integrated_report_visible' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $eq60Report
     * @param  array<string,mixed>  $sjtScore
     * @return list<array<string,mixed>>
     */
    private function buildGapMap(array $eq60Report, array $sjtScore): array
    {
        $pairs = [
            ['id' => 'self_awareness_cue_alignment', 'dimension' => 'SA', 'strategy' => 'CUE'],
            ['id' => 'regulation_pause_alignment', 'dimension' => 'ER', 'strategy' => 'PAUSE'],
            ['id' => 'empathy_response_alignment', 'dimension' => 'EM', 'strategy' => 'EMP'],
            ['id' => 'empathy_boundary_alignment', 'dimension' => 'EM', 'strategy' => 'BND'],
            ['id' => 'relationship_repair_alignment', 'dimension' => 'RM', 'strategy' => 'REPAIR'],
            ['id' => 'constructive_influence_alignment', 'dimension' => 'RM', 'strategy' => 'INFL'],
        ];

        $out = [];
        foreach ($pairs as $pair) {
            $dimension = $pair['dimension'];
            $strategy = $pair['strategy'];
            $selfLevel = $this->levelFromDimension(data_get($eq60Report, "scores.dimensions.{$dimension}", []));
            $scenarioLevel = $this->levelFromStrategy(data_get($sjtScore, "strategy_scores.{$strategy}", []));
            $gapType = $this->gapType($dimension, $strategy, $selfLevel, $scenarioLevel);

            $out[] = [
                'id' => $pair['id'],
                'self_report_dimension' => $dimension,
                'scenario_strategy' => $strategy,
                'strategy_label' => self::STRATEGY_LABELS[$strategy],
                'self_report_level' => $selfLevel,
                'scenario_level' => $scenarioLevel,
                'gap_type' => $gapType,
                'asset_id' => "eq.integrated.gap.{$gapType}.{$dimension}_{$strategy}",
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $gapMap
     * @return array<string,mixed>
     */
    private function buildPressurePattern(array $sjtScore, array $gapMap): array
    {
        $lowestStrategy = $this->string($sjtScore['lowest_strategy'] ?? null) ?: 'PAUSE';
        $gapTypes = array_values(array_unique(array_map(
            fn (array $gap): string => $this->string($gap['gap_type'] ?? null),
            $gapMap
        )));
        $patternId = match ($lowestStrategy) {
            'BND' => 'boundary_under_pressure',
            'REPAIR' => 'repair_after_conflict_gap',
            'INFL' => 'constructive_influence_gap',
            'EMP' => 'empathic_response_gap',
            'CUE' => 'cue_reading_gap',
            default => 'pressure_pause_gap',
        };

        return [
            'pattern_id' => $patternId,
            'lowest_strategy' => $lowestStrategy,
            'top_strategy' => $this->string($sjtScore['top_strategy'] ?? null),
            'dominant_gap_types' => array_values(array_filter($gapTypes)),
            'asset_id' => "eq.integrated.pressure.{$patternId}",
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $gapMap
     * @param  array<string,mixed>  $pressurePattern
     * @return list<string>
     */
    private function scriptIds(array $gapMap, array $pressurePattern): array
    {
        $ids = [];
        foreach ($gapMap as $gap) {
            $gapType = $this->string($gap['gap_type'] ?? null);
            $strategy = $this->string($gap['scenario_strategy'] ?? null);
            if (in_array($gapType, ['boundary_gap', 'knowledge_execution_gap', 'development_priority'], true)) {
                $ids[] = "eq.integrated.script.{$strategy}.{$gapType}";
            }
        }

        $patternId = $this->string($pressurePattern['pattern_id'] ?? null);
        if ($patternId !== '') {
            $ids[] = "eq.integrated.script.pressure.{$patternId}";
        }

        return array_values(array_slice(array_unique($ids), 0, 4));
    }

    /**
     * @param  list<array<string,mixed>>  $gapMap
     * @param  array<string,mixed>  $pressurePattern
     * @return array<string,mixed>
     */
    private function integratedActionPath(array $sjtScore, array $gapMap, array $pressurePattern): array
    {
        $lowestStrategy = $this->string($sjtScore['lowest_strategy'] ?? null) ?: $this->string($pressurePattern['lowest_strategy'] ?? null) ?: 'PAUSE';
        $priorityGap = $this->firstPriorityGap($gapMap);

        return [
            'duration_days' => 14,
            'focus_strategy' => $lowestStrategy,
            'priority_gap_type' => $this->string($priorityGap['gap_type'] ?? null) ?: 'mixed_signal',
            'steps' => [
                ['day_range' => '1-3', 'asset_id' => "eq.integrated.action.observe.{$lowestStrategy}"],
                ['day_range' => '4-7', 'asset_id' => "eq.integrated.action.practice.{$lowestStrategy}"],
                ['day_range' => '8-14', 'asset_id' => "eq.integrated.action.review.{$lowestStrategy}"],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $gapMap
     * @return array<string,mixed>
     */
    private function firstPriorityGap(array $gapMap): array
    {
        foreach (['boundary_gap', 'knowledge_execution_gap', 'development_priority', 'overestimated_capacity'] as $type) {
            foreach ($gapMap as $gap) {
                if (($gap['gap_type'] ?? null) === $type) {
                    return $gap;
                }
            }
        }

        return $gapMap[0] ?? [];
    }

    /**
     * @param  array<string,mixed>  $dimension
     */
    private function levelFromDimension(array $dimension): string
    {
        $percentile = $this->number($dimension['percentile'] ?? null);
        if ($percentile !== null) {
            return $this->levelFromPercent($percentile);
        }

        $standard = $this->number($dimension['standard_score'] ?? null);
        if ($standard !== null) {
            if ($standard >= 110.0) {
                return 'high';
            }
            if ($standard <= 90.0) {
                return 'low';
            }

            return 'medium';
        }

        $band = strtolower($this->string($dimension['display_band'] ?? $dimension['band'] ?? null));
        if (in_array($band, ['integrated', 'exceptional', 'proficient'], true)) {
            return 'high';
        }
        if (in_array($band, ['foundational', 'baseline', 'developing'], true)) {
            return 'low';
        }

        return 'medium';
    }

    /**
     * @param  array<string,mixed>  $strategy
     */
    private function levelFromStrategy(array $strategy): string
    {
        return $this->levelFromPercent($this->number($strategy['score_pct'] ?? null) ?? 50.0);
    }

    private function levelFromPercent(float $value): string
    {
        if ($value >= 67.0) {
            return 'high';
        }
        if ($value <= 33.0) {
            return 'low';
        }

        return 'medium';
    }

    private function gapType(string $dimension, string $strategy, string $selfLevel, string $scenarioLevel): string
    {
        if ($dimension === 'EM' && $strategy === 'BND' && $selfLevel === 'high' && $scenarioLevel === 'low') {
            return 'boundary_gap';
        }
        if ($selfLevel === 'high' && $scenarioLevel === 'high') {
            return 'aligned_strength';
        }
        if ($selfLevel === 'high' && $scenarioLevel === 'low') {
            return 'overestimated_capacity';
        }
        if ($selfLevel === 'low' && $scenarioLevel === 'high') {
            return 'underestimated_capacity';
        }
        if ($selfLevel === 'low' && $scenarioLevel === 'low') {
            return 'development_priority';
        }
        if ($scenarioLevel === 'low') {
            return 'knowledge_execution_gap';
        }

        return 'mixed_signal';
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
