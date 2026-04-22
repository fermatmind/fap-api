<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Registry;

final class RegistryValidator
{
    public const TRAIT_CODES = ['O', 'C', 'E', 'A', 'N'];

    private const ATOMIC_BANDS = ['low', 'mid', 'high'];

    private const REQUIRED_ATOMIC_SLOTS = [
        'hero_summary.headline',
        'hero_summary.body_core',
        'domains_overview.snapshot_line',
        'domain_deep_dive.definition',
        'domain_deep_dive.strengths',
        'domain_deep_dive.costs',
        'domain_deep_dive.daily_life',
        'core_portrait.identity',
        'core_portrait.default_style',
        'norms_comparison.relative_meaning',
        'action_plan.priority_hint',
    ];

    private const REQUIRED_MODIFIER_INJECTIONS = [
        'hero_summary.headline_extension',
        'domain_deep_dive.intensity_sentence',
        'core_portrait.load_sentence',
        'norms_comparison.compare_sentence',
        'action_plan.urgency_sentence',
    ];

    private const REQUIRED_SHARED_ASSETS = [
        'section_headlines.voice_anchor.tone',
        'compare_phrases.voice_anchor',
        'methodology.title',
        'trait_labels.labels.O.report_anchor',
        'trait_labels.labels.C.report_anchor',
        'trait_labels.labels.E.report_anchor',
        'trait_labels.labels.A.report_anchor',
        'trait_labels.labels.N.report_anchor',
        'band_labels.bands.low.meaning',
        'band_labels.bands.mid.meaning',
        'band_labels.bands.high.meaning',
        'gradient_labels.gradients.g1.copy_rule',
        'gradient_labels.gradients.g2.copy_rule',
        'gradient_labels.gradients.g3.copy_rule',
        'gradient_labels.gradients.g4.copy_rule',
        'gradient_labels.gradients.g5.copy_rule',
    ];

    private const SYNERGY_IDS = [
        'n_high_x_e_low',
        'o_high_x_c_low',
        'o_high_x_n_high',
        'c_high_x_n_high',
        'e_high_x_a_low',
    ];

    private const REQUIRED_SYNERGY_COPY_FIELDS = [
        'headline',
        'body',
        'strength_sentence',
        'risk_sentence',
        'action_hook',
    ];

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

        $errors = array_merge($errors, $this->validateAtomicCoverage($registry));
        $errors = array_merge($errors, $this->validateModifierCoverage($registry));
        $errors = array_merge($errors, $this->validateSharedAssets($registry));
        $errors = array_merge($errors, $this->validateSynergyCoverage($registry));

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
     * @return list<string>
     */
    private function validateSynergyCoverage(array $registry): array
    {
        $errors = [];
        $synergies = is_array($registry['synergies'] ?? null) ? $registry['synergies'] : [];
        if (array_values(array_keys($synergies)) !== self::SYNERGY_IDS) {
            $errors[] = 'Synergy coverage must be exactly n_high_x_e_low/o_high_x_c_low/o_high_x_n_high/c_high_x_n_high/e_high_x_a_low in order';
        }

        $ids = [];
        foreach (self::SYNERGY_IDS as $synergyId) {
            $synergy = is_array($synergies[$synergyId] ?? null) ? $synergies[$synergyId] : [];
            $declaredId = (string) ($synergy['synergy_id'] ?? '');
            if ($declaredId !== $synergyId) {
                $errors[] = "Synergy id mismatch for {$synergyId}.json";
            }
            if (in_array($declaredId, $ids, true)) {
                $errors[] = "Duplicate synergy_id {$declaredId}";
            }
            $ids[] = $declaredId;

            foreach (['trigger', 'mutex_group', 'mutual_excludes', 'max_show', 'section_targets', 'copy'] as $key) {
                if (! array_key_exists($key, $synergy)) {
                    $errors[] = "Synergy {$synergyId} missing {$key}";
                }
            }
            if (! array_key_exists('priority_weight_formula', $synergy) && ! array_key_exists('priority_weight', $synergy)) {
                $errors[] = "Synergy {$synergyId} missing priority weight";
            }
            if (trim((string) ($synergy['mutex_group'] ?? '')) === '') {
                $errors[] = "Synergy {$synergyId} mutex_group must be non-empty";
            }
            $maxShow = (int) ($synergy['max_show'] ?? 0);
            if ($maxShow < 1 || $maxShow > 2) {
                $errors[] = "Synergy {$synergyId} max_show must be 1-2";
            }

            $targets = is_array($synergy['section_targets'] ?? null) ? $synergy['section_targets'] : [];
            foreach ($targets as $target) {
                $sectionKey = is_array($target) ? (string) ($target['section_key'] ?? '') : '';
                if (! in_array($sectionKey, ['core_portrait', 'action_plan'], true)) {
                    $errors[] = "Synergy {$synergyId} has invalid section target {$sectionKey}";
                }
            }

            $copy = is_array($synergy['copy'] ?? null) ? $synergy['copy'] : [];
            foreach (self::REQUIRED_SYNERGY_COPY_FIELDS as $field) {
                if (! array_key_exists($field, $copy) || trim((string) $copy[$field]) === '') {
                    $errors[] = "Synergy {$synergyId} copy missing {$field}";
                }
            }

            $mutualExcludes = is_array($synergy['mutual_excludes'] ?? null) ? $synergy['mutual_excludes'] : [];
            foreach ($mutualExcludes as $mutualExclude) {
                if (! in_array((string) $mutualExclude, self::SYNERGY_IDS, true)) {
                    $errors[] = "Synergy {$synergyId} mutual_excludes unknown rule {$mutualExclude}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return list<string>
     */
    private function validateSharedAssets(array $registry): array
    {
        $errors = [];
        $shared = is_array($registry['shared'] ?? null) ? $registry['shared'] : [];
        foreach (self::REQUIRED_SHARED_ASSETS as $assetPath) {
            if (! $this->hasPath($shared, $assetPath)) {
                $errors[] = "Missing shared asset {$assetPath}";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return list<string>
     */
    private function validateAtomicCoverage(array $registry): array
    {
        $errors = [];
        $atomic = is_array($registry['atomic'] ?? null) ? $registry['atomic'] : [];
        if (array_values(array_keys($atomic)) !== self::TRAIT_CODES) {
            $errors[] = 'Atomic trait coverage must be O/C/E/A/N in order';
        }

        foreach (self::TRAIT_CODES as $traitCode) {
            $pack = is_array($atomic[$traitCode] ?? null) ? $atomic[$traitCode] : [];
            if ((string) ($pack['trait_code'] ?? '') !== $traitCode) {
                $errors[] = "Atomic trait_code mismatch for {$traitCode}.json";
            }
            $bands = is_array($pack['bands'] ?? null) ? $pack['bands'] : [];
            foreach (self::ATOMIC_BANDS as $band) {
                $slots = is_array($bands[$band]['slots'] ?? null) ? $bands[$band]['slots'] : null;
                if ($slots === null) {
                    $errors[] = "Missing {$traitCode} atomic band slots: {$band}";

                    continue;
                }
                foreach (self::REQUIRED_ATOMIC_SLOTS as $slotPath) {
                    if (! $this->hasPath($slots, $slotPath)) {
                        $errors[] = "Missing {$traitCode}.{$band} atomic slot {$slotPath}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return list<string>
     */
    private function validateModifierCoverage(array $registry): array
    {
        $errors = [];
        $modifiers = is_array($registry['modifiers'] ?? null) ? $registry['modifiers'] : [];
        if (array_values(array_keys($modifiers)) !== self::TRAIT_CODES) {
            $errors[] = 'Modifier trait coverage must be O/C/E/A/N in order';
        }

        $ids = [];
        foreach (self::TRAIT_CODES as $traitCode) {
            $pack = is_array($modifiers[$traitCode] ?? null) ? $modifiers[$traitCode] : [];
            if ((string) ($pack['trait_code'] ?? '') !== $traitCode) {
                $errors[] = "Modifier trait_code mismatch for {$traitCode}.json";
            }
            $gradients = is_array($pack['gradients'] ?? null) ? $pack['gradients'] : [];
            $expectedIds = array_map(
                static fn (int $index): string => strtolower($traitCode).'_g'.$index,
                range(1, 5)
            );
            if (array_values(array_keys($gradients)) !== $expectedIds) {
                $errors[] = "Modifier gradients for {$traitCode} must be ".implode('/', $expectedIds);
            }
            foreach ($gradients as $gradientId => $gradient) {
                if (in_array($gradientId, $ids, true)) {
                    $errors[] = "Duplicate modifier gradient id {$gradientId}";
                }
                $ids[] = (string) $gradientId;
                if (! is_array($gradient)) {
                    $errors[] = "Invalid {$traitCode} modifier gradient: {$gradientId}";

                    continue;
                }
                if (array_key_exists('replace_map', $gradient)) {
                    $errors[] = "Modifier gradient {$gradientId} uses forbidden replace_map";
                }
                $injections = is_array($gradient['injections'] ?? null) ? $gradient['injections'] : [];
                foreach (self::REQUIRED_MODIFIER_INJECTIONS as $injectionKey) {
                    if (! array_key_exists($injectionKey, $injections)) {
                        $errors[] = "Modifier gradient {$gradientId} missing sentence-level injection {$injectionKey}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasPath(array $payload, string $path): bool
    {
        $cursor = $payload;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return is_array($cursor) ? $cursor !== [] : trim((string) $cursor) !== '';
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
