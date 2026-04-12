<?php

declare(strict_types=1);

namespace App\Services\Career\Explainability;

final class StrainRadarBuilder
{
    /**
     * @var list<string>
     */
    private const PUBLIC_AXES = [
        'people_friction',
        'context_switch_load',
        'political_load',
        'uncertainty_load',
        'low_autonomy_trap',
        'repetition_mismatch',
    ];

    /**
     * @param  array<string, mixed>  $strainScore
     * @return array<string, mixed>|null
     */
    public function build(array $strainScore): ?array
    {
        $integrityState = is_scalar($strainScore['integrity_state'] ?? null)
            ? (string) $strainScore['integrity_state']
            : null;

        if ($integrityState === 'blocked') {
            return null;
        }

        $inputs = $strainScore['component_breakdown']['inputs'] ?? null;
        if (! is_array($inputs)) {
            return null;
        }

        $axes = [];

        foreach (self::PUBLIC_AXES as $axis) {
            if (! array_key_exists($axis, $inputs)) {
                continue;
            }

            $axes[$axis] = [
                'value' => round((float) $inputs[$axis], 4),
            ];
        }

        if ($axes === []) {
            return null;
        }

        return [
            'integrity_state' => $integrityState,
            'confidence_cap' => (int) ($strainScore['confidence_cap'] ?? 0),
            'degradation_factor' => (float) ($strainScore['degradation_factor'] ?? 0.0),
            'formula_version' => is_scalar($strainScore['formula_ref'] ?? null) ? (string) $strainScore['formula_ref'] : null,
            'axes' => $axes,
        ];
    }
}
