<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class FitScoreCalculator
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array{integrity_state:string,critical_missing_fields:list<string>,confidence_cap:int}  $integrity
     */
    public function calculate(array $context, array $integrity, DegradationPolicy $degradationPolicy): CareerScoreResult
    {
        $components = [
            'cognitive_fit' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['pref_abstraction'] ?? null, 0.6),
                ScoreMath::normalizeNullable($context['task_analysis'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['task_build'] ?? null, 0.5),
            ]),
            'interest_fit' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['skill_overlap'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['task_overlap'] ?? null, 0.5),
            ]),
            'environment_fit' => ScoreMath::average([
                1.0 - abs(
                    ScoreMath::normalizeNullable($context['pref_autonomy'] ?? null, 0.6)
                    - ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5)
                ),
                1.0 - abs(
                    ScoreMath::normalizeNullable($context['risk_tolerance'] ?? null, 0.5)
                    - (1.0 - ScoreMath::normalizeNullable($context['market_semantics_gap'] ?? null, 0.2))
                ),
            ]),
            'social_fit' => 1.0 - abs(
                ScoreMath::normalizeNullable($context['pref_collaboration'] ?? null, 0.5)
                - ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.5)
            ),
            'task_fit' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['skill_overlap'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['task_overlap'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['tool_overlap'] ?? null, 0.5),
            ]),
        ];

        $penaltyInputs = [
            'hard_conflict' => abs(
                ScoreMath::normalizeNullable($context['family_constraint_level'] ?? null, 0.4)
                - (1.0 - ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.5))
            ),
            'entry_barrier_mismatch' => abs(
                ScoreMath::normalizeNullable($context['time_horizon_fit'] ?? null, 0.5)
                - (1.0 - ScoreMath::normalizeNullable($context['skill_gap_threshold'] ?? null, 0.4))
            ),
            'variant_trigger_load' => ScoreMath::normalizeNullable($context['variant_trigger_load'] ?? null, 0.0),
        ];

        $weights = [
            'cognitive_fit' => 0.25,
            'interest_fit' => 0.2,
            'environment_fit' => 0.2,
            'social_fit' => 0.15,
            'task_fit' => 0.2,
        ];

        $base = ScoreMath::weightedGeometricMean($components, $weights);
        $penaltyFactor = ScoreMath::penaltyFactor([
            ['weight' => 0.28, 'value' => $penaltyInputs['hard_conflict']],
            ['weight' => 0.18, 'value' => $penaltyInputs['entry_barrier_mismatch']],
            ['weight' => 0.12, 'value' => $penaltyInputs['variant_trigger_load']],
        ], 0.45);

        $rawValue = ScoreMath::clamp100($base * $penaltyFactor * 100);
        $degraded = $degradationPolicy->apply('fit_score', (float) $rawValue, $integrity, $context);

        return new CareerScoreResult(
            $degraded['value'],
            (string) $integrity['integrity_state'],
            (array) $integrity['critical_missing_fields'],
            (int) $integrity['confidence_cap'],
            'career.fit_v1.2',
            [
                'inputs' => $components,
                'weights' => $weights,
                'base_score' => ScoreMath::clamp100($base * 100),
                'penalty_inputs' => $penaltyInputs,
                'penalty_factor' => round($penaltyFactor, 4),
            ],
            $degraded['penalties'],
            $degraded['degradation_factor'],
        );
    }
}
