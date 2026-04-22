<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Registry;

final class RegistryValidator
{
    /**
     * @param  array<string,mixed>  $registry
     * @return list<string>
     */
    public function validate(array $registry): array
    {
        $errors = [];

        foreach (['manifest', 'atomic', 'modifiers', 'synergies', 'facet_precision', 'action_rules', 'shared'] as $key) {
            if (! is_array($registry[$key] ?? null)) {
                $errors[] = "Missing registry group: {$key}";
            }
        }

        $atomicN = is_array($registry['atomic']['N'] ?? null) ? $registry['atomic']['N'] : [];
        foreach (['low', 'mid', 'high'] as $band) {
            if (! is_array($atomicN['bands'][$band]['slots'] ?? null)) {
                $errors[] = "Missing N atomic band slots: {$band}";
            }
        }

        $modifiersN = is_array($registry['modifiers']['N'] ?? null) ? $registry['modifiers']['N'] : [];
        foreach (($modifiersN['gradients'] ?? []) as $gradientId => $gradient) {
            if (! is_array($gradient)) {
                $errors[] = "Invalid N modifier gradient: {$gradientId}";

                continue;
            }
            if (array_key_exists('replace_map', $gradient)) {
                $errors[] = "Modifier gradient {$gradientId} uses forbidden replace_map";
            }
            if (! is_array($gradient['injections'] ?? null)) {
                $errors[] = "Modifier gradient {$gradientId} missing sentence-level injections";
            }
        }

        $synergy = is_array($registry['synergies']['n_high_x_e_low'] ?? null) ? $registry['synergies']['n_high_x_e_low'] : [];
        foreach (['trigger', 'priority_weight_formula', 'mutex_group', 'mutual_excludes', 'max_show', 'copy'] as $key) {
            if (! array_key_exists($key, $synergy)) {
                $errors[] = "Synergy n_high_x_e_low missing {$key}";
            }
        }

        $facetRules = $registry['facet_precision']['N']['rules'] ?? null;
        if (! is_array($facetRules) || count($facetRules) < 5) {
            $errors[] = 'N facet precision must define at least five rules';
        }

        $actionCount = 0;
        foreach (['workplace', 'stress_recovery', 'personal_growth'] as $scenario) {
            $rules = $registry['action_rules'][$scenario]['rules'] ?? null;
            if (! is_array($rules)) {
                $errors[] = "Action scenario missing rules: {$scenario}";

                continue;
            }
            $actionCount += count($rules);
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                foreach (['scenario_tags', 'difficulty_level', 'time_horizon', 'bucket', 'title', 'body'] as $requiredKey) {
                    if (! array_key_exists($requiredKey, $rule)) {
                        $errors[] = "Action rule missing {$requiredKey}: ".(string) ($rule['rule_id'] ?? $scenario);
                    }
                }
            }
        }
        if ($actionCount < 8 || $actionCount > 12) {
            $errors[] = "Action rule count must be 8-12, got {$actionCount}";
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $registry
     */
    public function assertValid(array $registry): void
    {
        $errors = $this->validate($registry);
        if ($errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }
    }
}
