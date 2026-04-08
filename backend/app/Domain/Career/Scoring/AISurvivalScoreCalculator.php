<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class AISurvivalScoreCalculator
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array{integrity_state:string,critical_missing_fields:list<string>,confidence_cap:int}  $integrity
     */
    public function calculate(array $context, array $integrity, DegradationPolicy $degradationPolicy): CareerScoreResult
    {
        $inputs = [
            'ai_exposure_n' => ScoreMath::normalizeNullable($context['ai_exposure'] ?? null, 0.0),
            'human_moat_strength' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['task_analysis'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['skill_overlap'] ?? null, 0.5),
            ]),
            'relationship_moat' => ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.4),
            'judgment_complexity' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['task_analysis'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['task_build'] ?? null, 0.5),
                1.0 - ScoreMath::normalizeNullable($context['toolchain_divergence'] ?? null, 0.2),
            ]),
            'regulation_moat' => ScoreMath::normalizeNullable($context['regulatory_divergence'] ?? null, 0.1),
            'physical_world_moat' => ScoreMath::normalizeNullable($context['physical_world_moat'] ?? null, 0.05),
            'career_ladder_resilience' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['psychometric_axis_coverage'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['source_trace_evidence'] ?? null, 0.7),
            ]),
            'entry_fragility' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['skill_gap_threshold'] ?? null, 0.4),
                1.0 - ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5),
            ]),
        ];

        $positive = [
            'exposure_inverse' => 1.0 - $inputs['ai_exposure_n'],
            'human_moat_strength' => $inputs['human_moat_strength'],
            'relationship_moat' => $inputs['relationship_moat'],
            'judgment_complexity' => $inputs['judgment_complexity'],
            'regulation_moat' => max(0.1, $inputs['regulation_moat']),
            'physical_world_moat' => max(0.05, $inputs['physical_world_moat']),
            'career_ladder_resilience' => $inputs['career_ladder_resilience'],
            'entry_fragility_inverse' => 1.0 - $inputs['entry_fragility'],
        ];

        $weights = [
            'exposure_inverse' => 0.28,
            'human_moat_strength' => 0.18,
            'relationship_moat' => 0.08,
            'judgment_complexity' => 0.16,
            'regulation_moat' => 0.08,
            'physical_world_moat' => 0.04,
            'career_ladder_resilience' => 0.1,
            'entry_fragility_inverse' => 0.08,
        ];

        $base = ScoreMath::weightedGeometricMean($positive, $weights);
        $rawValue = ScoreMath::clamp100($base * 100);
        $degraded = $degradationPolicy->apply('ai_survival_score', (float) $rawValue, $integrity, $context);

        return new CareerScoreResult(
            $degraded['value'],
            (string) $integrity['integrity_state'],
            (array) $integrity['critical_missing_fields'],
            (int) $integrity['confidence_cap'],
            'career.ai_survival_v1.2',
            [
                'inputs' => $inputs,
                'weights' => $weights,
                'base_score' => ScoreMath::clamp100($base * 100),
            ],
            $degraded['penalties'],
            $degraded['degradation_factor'],
        );
    }
}
