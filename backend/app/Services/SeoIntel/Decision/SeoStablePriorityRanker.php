<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

final class SeoStablePriorityRanker
{
    public const VERSION = 'seo.priority.ranking.v1';

    public const DEFAULT_PROFILE = 'balanced_v1';

    /** @var array<string, array<string, int>> */
    public const PROFILES = [
        'balanced_v1' => [
            'impact_scope' => 20,
            'evidence_strength' => 25,
            'business_value' => 15,
            'risk' => 15,
            'estimated_fix_cost' => 10,
            'evidence_freshness' => 10,
            'measurement_state' => 5,
        ],
        'impact_guarded_v1' => [
            'impact_scope' => 25,
            'evidence_strength' => 25,
            'business_value' => 15,
            'risk' => 15,
            'estimated_fix_cost' => 5,
            'evidence_freshness' => 10,
            'measurement_state' => 5,
        ],
        'evidence_guarded_v1' => [
            'impact_scope' => 15,
            'evidence_strength' => 35,
            'business_value' => 15,
            'risk' => 15,
            'estimated_fix_cost' => 5,
            'evidence_freshness' => 10,
            'measurement_state' => 5,
        ],
    ];

    private const EVIDENCE_BAND = [
        'verified' => 0,
        'observed' => 1,
        'inferred' => 2,
    ];

    /** @param list<array<string, mixed>> $evaluations @return list<array<string, mixed>> */
    public function rank(array $evaluations, string $profile = self::DEFAULT_PROFILE): array
    {
        $weights = self::PROFILES[$profile] ?? null;
        if ($weights === null || array_sum($weights) !== 100) {
            return [];
        }

        $ranked = array_map(fn (array $evaluation): array => $this->calibrate($evaluation, $profile, $weights), $evaluations);
        usort($ranked, fn (array $left, array $right): int => $this->compare($left, $right));

        return $ranked;
    }

    /** @param list<array<string, mixed>> $evaluations @return array<string, mixed> */
    public function sensitivity(array $evaluations): array
    {
        $orders = [];
        $stable = true;
        foreach (array_keys(self::PROFILES) as $profile) {
            $ranked = $this->rank($evaluations, $profile);
            $orders[$profile] = array_column($ranked, 'cluster_uid');
            $stable = $stable && $this->guardrailsHold($ranked);
        }

        return [
            'schema_version' => self::VERSION,
            'profiles' => $orders,
            'guardrails_passed' => $stable,
            'randomness_used' => false,
            'missing_values_filled' => false,
            'evidence_threshold_lowered' => false,
        ];
    }

    /** @param array<string, mixed> $evaluation @param array<string, int> $weights @return array<string, mixed> */
    private function calibrate(array $evaluation, string $profile, array $weights): array
    {
        $evaluation['ranking_contract'] = self::VERSION;
        $evaluation['ranking_profile'] = $profile;
        if (($evaluation['ranking_eligible'] ?? false) !== true
            || ! is_array($evaluation['components'] ?? null)
            || ! is_array($evaluation['ranking_dimensions'] ?? null)) {
            return $evaluation;
        }

        $dimensions = $evaluation['ranking_dimensions'];
        $severity = $dimensions['severity'] ?? null;
        $direct = ($dimensions['direct_evidence'] ?? false) === true;
        $evidence = $dimensions['evidence_strength'] ?? null;
        if (in_array($severity, ['P0', 'P1'], true) && (! $direct || $evidence !== 'verified')) {
            $evaluation['state'] = 'MEASUREMENT_HOLD';
            $evaluation['priority_score'] = null;
            $evaluation['ranking_eligible'] = false;
            $evaluation['components'] = null;
            $evaluation['hold_reasons'] = ['direct_verified_evidence_required_for_'.$severity];

            return $evaluation;
        }
        if (! is_string($evidence) || ! array_key_exists($evidence, self::EVIDENCE_BAND)) {
            $evaluation['state'] = 'MEASUREMENT_HOLD';
            $evaluation['priority_score'] = null;
            $evaluation['ranking_eligible'] = false;
            $evaluation['components'] = null;
            $evaluation['hold_reasons'] = ['ranking_evidence_threshold_not_met'];

            return $evaluation;
        }

        $weighted = 0.0;
        foreach ($weights as $component => $weight) {
            if (! array_key_exists($component, $evaluation['components'])
                || ! is_numeric($evaluation['components'][$component])) {
                $evaluation['state'] = 'MEASUREMENT_HOLD';
                $evaluation['priority_score'] = null;
                $evaluation['ranking_eligible'] = false;
                $evaluation['components'] = null;
                $evaluation['hold_reasons'] = ['missing_calibrated_component'];

                return $evaluation;
            }
            $weighted += ((float) $evaluation['components'][$component]) * ($weight / 100);
        }
        $evaluation['priority_score'] = round($weighted, 4);

        return $evaluation;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compare(array $left, array $right): int
    {
        foreach ([
            [$this->directSeverityBand($left), $this->directSeverityBand($right), false],
            [$this->eligibilityBand($left), $this->eligibilityBand($right), false],
            [$this->evidenceBand($left), $this->evidenceBand($right), false],
            [$this->number($left, 'priority_score', -1), $this->number($right, 'priority_score', -1), true],
            [$this->component($left, 'impact_scope'), $this->component($right, 'impact_scope'), true],
            [$this->component($left, 'evidence_strength'), $this->component($right, 'evidence_strength'), true],
            [$this->component($left, 'business_value'), $this->component($right, 'business_value'), true],
            [$this->component($left, 'risk'), $this->component($right, 'risk'), true],
            [$this->component($left, 'estimated_fix_cost'), $this->component($right, 'estimated_fix_cost'), true],
            [$this->component($left, 'evidence_freshness'), $this->component($right, 'evidence_freshness'), true],
        ] as [$leftValue, $rightValue, $descending]) {
            $comparison = $leftValue <=> $rightValue;
            if ($comparison !== 0) {
                return $descending ? -$comparison : $comparison;
            }
        }

        return strcmp((string) ($left['cluster_uid'] ?? ''), (string) ($right['cluster_uid'] ?? ''));
    }

    /** @param list<array<string, mixed>> $ranked */
    private function guardrailsHold(array $ranked): bool
    {
        $lastDirectBand = -1;
        $lastEvidenceBand = -1;
        foreach ($ranked as $item) {
            $directBand = $this->directSeverityBand($item);
            $evidenceBand = $this->evidenceBand($item);
            if ($directBand < $lastDirectBand || ($directBand === 2 && $evidenceBand < $lastEvidenceBand)) {
                return false;
            }
            $lastDirectBand = $directBand;
            if ($directBand === 2 && $this->eligibilityBand($item) === 0) {
                $lastEvidenceBand = $evidenceBand;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $item */
    private function directSeverityBand(array $item): int
    {
        if (($item['ranking_eligible'] ?? false) !== true) {
            return 2;
        }
        $dimensions = is_array($item['ranking_dimensions'] ?? null) ? $item['ranking_dimensions'] : [];
        if (($dimensions['direct_evidence'] ?? false) !== true || ($dimensions['evidence_strength'] ?? null) !== 'verified') {
            return 2;
        }

        return match ($dimensions['severity'] ?? null) {
            'P0' => 0,
            'P1' => 1,
            default => 2,
        };
    }

    /** @param array<string, mixed> $item */
    private function eligibilityBand(array $item): int
    {
        return ($item['ranking_eligible'] ?? false) === true ? 0 : 1;
    }

    /** @param array<string, mixed> $item */
    private function evidenceBand(array $item): int
    {
        $dimensions = is_array($item['ranking_dimensions'] ?? null) ? $item['ranking_dimensions'] : [];

        return self::EVIDENCE_BAND[$dimensions['evidence_strength'] ?? ''] ?? 3;
    }

    /** @param array<string, mixed> $item */
    private function component(array $item, string $component): float
    {
        return $this->number(is_array($item['components'] ?? null) ? $item['components'] : [], $component, -1);
    }

    /** @param array<string, mixed> $item */
    private function number(array $item, string $key, float $fallback): float
    {
        return is_numeric($item[$key] ?? null) ? (float) $item[$key] : $fallback;
    }
}
