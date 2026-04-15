<?php

declare(strict_types=1);

namespace App\Services\Career\Scoring;

final class CareerWhiteBoxScorePayloadBuilder
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_SCORE_FAMILIES = [
        'fit_score',
        'strain_score',
        'ai_survival_score',
        'mobility_score',
        'confidence_score',
    ];

    /**
     * @var list<string>
     */
    private const STRAIN_RADAR_DIMENSIONS = [
        'people_friction',
        'context_switch_load',
        'political_load',
        'uncertainty_load',
        'low_autonomy_trap',
        'repetition_mismatch',
    ];

    /**
     * @param  array<string, mixed>  $scoreBundle
     * @param  array<string, mixed>  $warnings
     * @return array<string, array<string, mixed>>
     */
    public function build(array $scoreBundle, array $warnings): array
    {
        $payload = [];

        foreach (self::SUPPORTED_SCORE_FAMILIES as $scoreFamily) {
            $score = $scoreBundle[$scoreFamily] ?? null;
            if (! is_array($score)) {
                continue;
            }

            $componentBreakdown = is_array($score['component_breakdown'] ?? null)
                ? $score['component_breakdown']
                : [];

            $entry = [
                'score' => (int) ($score['value'] ?? 0),
                'integrity_state' => is_scalar($score['integrity_state'] ?? null)
                    ? (string) $score['integrity_state']
                    : null,
                'degradation_factor' => round((float) ($score['degradation_factor'] ?? 0.0), 4),
                'formula_breakdown' => $this->buildFormulaBreakdown($componentBreakdown),
                'component_weights' => $this->buildComponentWeights($componentBreakdown),
                'penalties' => $this->buildPenalties($score['penalties'] ?? null),
                'warnings' => $this->buildWarnings($warnings, $scoreFamily, $score['penalties'] ?? null),
            ];

            $radarDimensions = $this->buildStrainRadarDimensions($scoreFamily, $componentBreakdown);
            if ($radarDimensions !== []) {
                $entry['radar_dimensions'] = $radarDimensions;
            }

            $payload[$scoreFamily] = $entry;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $componentBreakdown
     * @return list<array<string, mixed>>
     */
    private function buildFormulaBreakdown(array $componentBreakdown): array
    {
        $breakdown = [];
        $inputs = $componentBreakdown['inputs'] ?? null;
        if (is_array($inputs)) {
            foreach ($inputs as $component => $value) {
                if (! is_string($component)) {
                    continue;
                }

                $breakdown[] = [
                    'component' => $component,
                    'value' => round((float) $value, 4),
                ];
            }
        }

        if (is_numeric($componentBreakdown['base_score'] ?? null)) {
            $breakdown[] = [
                'component' => 'base_score',
                'value' => round((float) $componentBreakdown['base_score'], 4),
            ];
        }

        if (is_numeric($componentBreakdown['penalty_factor'] ?? null)) {
            $breakdown[] = [
                'component' => 'penalty_factor',
                'value' => round((float) $componentBreakdown['penalty_factor'], 4),
            ];
        }

        return $breakdown;
    }

    /**
     * @param  array<string, mixed>  $componentBreakdown
     * @return array<string, float>
     */
    private function buildComponentWeights(array $componentBreakdown): array
    {
        $weights = $componentBreakdown['weights'] ?? null;
        if (! is_array($weights)) {
            return [];
        }

        $normalized = [];
        foreach ($weights as $component => $weight) {
            if (! is_string($component) || ! is_numeric($weight)) {
                continue;
            }

            $normalized[$component] = round((float) $weight, 4);
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPenalties(mixed $penalties): array
    {
        if (! is_array($penalties)) {
            return [];
        }

        $normalized = [];

        foreach ($penalties as $penalty) {
            if (! is_array($penalty)) {
                continue;
            }

            $entry = [];

            if (is_scalar($penalty['code'] ?? null)) {
                $entry['code'] = (string) $penalty['code'];
            }
            if (is_numeric($penalty['weight'] ?? null)) {
                $entry['weight'] = round((float) $penalty['weight'], 4);
            }
            if (is_numeric($penalty['value'] ?? null)) {
                $entry['value'] = round((float) $penalty['value'], 4);
            }
            if (is_array($penalty['fields'] ?? null)) {
                $entry['fields'] = array_values(array_filter(
                    $penalty['fields'],
                    static fn (mixed $field): bool => is_string($field) && trim($field) !== ''
                ));
            }

            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $warnings
     * @return list<string>
     */
    private function buildWarnings(array $warnings, string $scoreFamily, mixed $penalties): array
    {
        $result = [];

        foreach (['red_flags', 'amber_flags'] as $bucket) {
            $flags = $warnings[$bucket] ?? null;
            if (! is_array($flags)) {
                continue;
            }

            foreach ($flags as $flag) {
                if (! is_string($flag)) {
                    continue;
                }

                if (str_starts_with($flag, $scoreFamily.'.')) {
                    $result[] = $flag;
                }
            }
        }

        if (is_array($penalties)) {
            foreach ($penalties as $penalty) {
                if (! is_array($penalty) || ! is_scalar($penalty['code'] ?? null)) {
                    continue;
                }

                $result[] = 'penalty:'.(string) $penalty['code'];
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param  array<string, mixed>  $componentBreakdown
     * @return list<array<string, mixed>>
     */
    private function buildStrainRadarDimensions(string $scoreFamily, array $componentBreakdown): array
    {
        if ($scoreFamily !== 'strain_score') {
            return [];
        }

        $inputs = $componentBreakdown['inputs'] ?? null;
        if (! is_array($inputs)) {
            return [];
        }

        $dimensions = [];

        foreach (self::STRAIN_RADAR_DIMENSIONS as $dimension) {
            if (! array_key_exists($dimension, $inputs)) {
                continue;
            }

            $dimensions[] = [
                'dimension' => $dimension,
                'value' => round((float) $inputs[$dimension], 4),
            ];
        }

        return $dimensions;
    }
}
