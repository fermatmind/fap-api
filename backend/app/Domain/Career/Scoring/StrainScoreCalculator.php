<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class StrainScoreCalculator
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array{integrity_state:string,critical_missing_fields:list<string>,confidence_cap:int}  $integrity
     */
    public function calculate(array $context, array $integrity, DegradationPolicy $degradationPolicy): CareerScoreResult
    {
        $environmentFit = ScoreMath::average([
            1.0 - abs(
                ScoreMath::normalizeNullable($context['pref_autonomy'] ?? null, 0.6)
                - ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5)
            ),
            1.0 - abs(
                ScoreMath::normalizeNullable($context['pref_collaboration'] ?? null, 0.5)
                - ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.5)
            ),
        ]);

        $loads = [
            'environment_fit' => $environmentFit,
            'people_friction' => ScoreMath::clamp01(
                ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.5)
                * (1.0 - ScoreMath::normalizeNullable($context['pref_collaboration'] ?? null, 0.5))
            ),
            'context_switch_load' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['switch_urgency'] ?? null, 0.5),
                ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.5),
                1.0 - ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5),
            ]),
            'political_load' => ScoreMath::clamp01(
                ScoreMath::normalizeNullable($context['task_coordination'] ?? null, 0.5)
                * (1.0 - ScoreMath::normalizeNullable($context['manager_track_preference'] ?? null, 0.5))
            ),
            'uncertainty_load' => ScoreMath::average([
                ScoreMath::normalizeNullable($context['market_semantics_gap'] ?? null, 0.2),
                ScoreMath::normalizeNullable($context['toolchain_divergence'] ?? null, 0.2),
                ScoreMath::normalizeNullable($context['regulatory_divergence'] ?? null, 0.2),
            ]),
            'repetition_mismatch' => ScoreMath::clamp01(
                abs(
                    ScoreMath::normalizeNullable($context['pref_variability'] ?? null, 0.5)
                    - ScoreMath::normalizeNullable($context['task_build'] ?? null, 0.5)
                )
            ),
            'low_autonomy_trap' => ScoreMath::clamp01(
                ScoreMath::normalizeNullable($context['pref_autonomy'] ?? null, 0.6)
                * (1.0 - ScoreMath::normalizeNullable($context['structural_stability'] ?? null, 0.5))
            ),
        ];

        $strainComponents = [
            'environment_mismatch' => 1.0 - $loads['environment_fit'],
            'people_friction' => $loads['people_friction'],
            'context_switch_load' => $loads['context_switch_load'],
            'political_load' => $loads['political_load'],
            'uncertainty_load' => $loads['uncertainty_load'],
            'repetition_mismatch' => $loads['repetition_mismatch'],
            'low_autonomy_trap' => $loads['low_autonomy_trap'],
        ];

        $weights = [
            'environment_mismatch' => 0.18,
            'people_friction' => 0.16,
            'context_switch_load' => 0.2,
            'political_load' => 0.14,
            'uncertainty_load' => 0.14,
            'repetition_mismatch' => 0.08,
            'low_autonomy_trap' => 0.1,
        ];

        $base = ScoreMath::weightedGeometricMean($strainComponents, $weights);
        $rawValue = ScoreMath::clamp100($base * 100);
        $degraded = $degradationPolicy->apply('strain_score', (float) $rawValue, $integrity, $context);

        return new CareerScoreResult(
            $degraded['value'],
            (string) $integrity['integrity_state'],
            (array) $integrity['critical_missing_fields'],
            (int) $integrity['confidence_cap'],
            'career.strain_v1.2',
            [
                'inputs' => $loads,
                'weights' => $weights,
                'base_score' => ScoreMath::clamp100($base * 100),
            ],
            $degraded['penalties'],
            $degraded['degradation_factor'],
        );
    }
}
