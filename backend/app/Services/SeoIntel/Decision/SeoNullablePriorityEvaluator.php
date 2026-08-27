<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Decision;

use Carbon\CarbonImmutable;
use Throwable;

final class SeoNullablePriorityEvaluator
{
    public const VERSION = 'seo.priority.candidate.v1';

    public const PROFILE = 'candidate_equal_components.v1';

    public const REQUIRED_INPUTS = [
        'impact_scope',
        'evidence_strength',
        'business_value',
        'risk',
        'estimated_fix_cost',
        'evidence_freshness',
        'measurement_state',
    ];

    private const EVIDENCE_POINTS = [
        'verified' => 100.0,
        'observed' => 70.0,
        'inferred' => 35.0,
    ];

    private const BUSINESS_POINTS = [
        'L1' => 100.0,
        'trust' => 90.0,
        'L2' => 70.0,
        'L3' => 45.0,
        'conditional' => 20.0,
    ];

    private const SEVERITY_POINTS = [
        'P0' => 100.0,
        'P1' => 80.0,
        'P2' => 50.0,
        'P3' => 20.0,
    ];

    private const BLAST_RADIUS_POINTS = [
        'high' => 100.0,
        'medium' => 60.0,
        'low' => 25.0,
    ];

    private const COST_POINTS = [
        'bounded' => 100.0,
        'template' => 80.0,
        'manual' => 60.0,
        'engineering' => 40.0,
        'external' => 20.0,
    ];

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function evaluate(array $input): array
    {
        $clusterUid = $input['cluster_uid'] ?? null;
        $missing = [];
        if (! is_string($clusterUid) || ! SeoDecisionCardContract::isClusterUid($clusterUid)) {
            $missing[] = 'cluster_uid';
        }
        foreach (self::REQUIRED_INPUTS as $key) {
            if (! array_key_exists($key, $input) || $input[$key] === null) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            return $this->hold($clusterUid, array_map(fn (string $key): string => 'missing_or_invalid_'.$key, $missing));
        }

        $components = $this->components($input);
        if (is_string($components)) {
            return $this->hold($clusterUid, [$components]);
        }

        return [
            'schema_version' => self::VERSION,
            'weight_profile' => self::PROFILE,
            'cluster_uid' => $clusterUid,
            'state' => 'eligible_candidate',
            'priority_score' => round(array_sum($components) / count($components), 4),
            'ranking_eligible' => true,
            'components' => $components,
            'hold_reasons' => [],
        ];
    }

    /** @param list<array<string, mixed>> $evaluations @return list<array<string, mixed>> */
    public function sort(array $evaluations): array
    {
        usort($evaluations, function (array $left, array $right): int {
            $leftEligible = ($left['ranking_eligible'] ?? false) === true;
            $rightEligible = ($right['ranking_eligible'] ?? false) === true;
            if ($leftEligible !== $rightEligible) {
                return $leftEligible ? -1 : 1;
            }

            $scoreComparison = ((float) ($right['priority_score'] ?? -1)) <=> ((float) ($left['priority_score'] ?? -1));

            return $scoreComparison !== 0
                ? $scoreComparison
                : strcmp((string) ($left['cluster_uid'] ?? ''), (string) ($right['cluster_uid'] ?? ''));
        });

        return $evaluations;
    }

    /** @param array<string, mixed> $input @return array<string, float>|string */
    private function components(array $input): array|string
    {
        $impact = $input['impact_scope'];
        if (! is_array($impact)
            || ! array_key_exists('affected_unique_public_urls', $impact)
            || ! is_int($impact['affected_unique_public_urls'])
            || $impact['affected_unique_public_urls'] < 0
            || ! is_string($impact['family_scope'] ?? null)
            || trim($impact['family_scope']) === '') {
            return 'invalid_impact_scope';
        }

        $evidenceKey = $input['evidence_strength'];
        $evidence = is_string($evidenceKey) ? (self::EVIDENCE_POINTS[$evidenceKey] ?? null) : null;
        if ($evidence === null) {
            return 'invalid_evidence_strength';
        }
        $businessKey = $input['business_value'];
        $business = is_string($businessKey) ? (self::BUSINESS_POINTS[$businessKey] ?? null) : null;
        if ($business === null) {
            return 'invalid_business_value';
        }

        $risk = $input['risk'];
        $severityKey = is_array($risk) ? ($risk['severity'] ?? null) : null;
        $blastRadiusKey = is_array($risk) ? ($risk['blast_radius'] ?? null) : null;
        $severity = is_string($severityKey) ? (self::SEVERITY_POINTS[$severityKey] ?? null) : null;
        $blastRadius = is_string($blastRadiusKey) ? (self::BLAST_RADIUS_POINTS[$blastRadiusKey] ?? null) : null;
        if ($severity === null || $blastRadius === null) {
            return 'invalid_risk';
        }
        $costKey = $input['estimated_fix_cost'];
        $cost = is_string($costKey) ? (self::COST_POINTS[$costKey] ?? null) : null;
        if ($cost === null) {
            return 'invalid_estimated_fix_cost';
        }

        $freshness = $this->freshnessPoints($input['evidence_freshness']);
        if (is_string($freshness)) {
            return $freshness;
        }
        if (! $this->measurementPassed($input['measurement_state'])) {
            return 'measurement_gate_not_passed';
        }

        return [
            'impact_scope' => min(100.0, (float) $impact['affected_unique_public_urls']),
            'evidence_strength' => $evidence,
            'business_value' => $business,
            'risk' => ($severity + $blastRadius) / 2,
            'estimated_fix_cost' => $cost,
            'evidence_freshness' => $freshness,
            'measurement_state' => 100.0,
        ];
    }

    private function freshnessPoints(mixed $value): float|string
    {
        if (! is_array($value)
            || ! is_string($value['observed_at'] ?? null)
            || ! is_string($value['evaluated_at'] ?? null)
            || ! is_int($value['max_age_seconds'] ?? null)
            || $value['max_age_seconds'] <= 0) {
            return 'invalid_evidence_freshness';
        }

        try {
            $observed = CarbonImmutable::parse($value['observed_at']);
            $evaluated = CarbonImmutable::parse($value['evaluated_at']);
        } catch (Throwable) {
            return 'invalid_evidence_freshness';
        }
        $age = $observed->diffInSeconds($evaluated, false);
        if ($age < 0 || $age > $value['max_age_seconds']) {
            return 'stale_evidence';
        }

        return round((1 - ($age / $value['max_age_seconds'])) * 100, 4);
    }

    private function measurementPassed(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach (['complete', 'quality_passed', 'comparable'] as $key) {
            if (! array_key_exists($key, $value) || $value[$key] !== true) {
                return false;
            }
        }

        return array_key_exists('lag_seconds', $value)
            && array_key_exists('max_lag_seconds', $value)
            && is_int($value['lag_seconds'])
            && is_int($value['max_lag_seconds'])
            && $value['lag_seconds'] >= 0
            && $value['max_lag_seconds'] >= 0
            && $value['lag_seconds'] <= $value['max_lag_seconds'];
    }

    /** @param list<string> $reasons @return array<string, mixed> */
    private function hold(mixed $clusterUid, array $reasons): array
    {
        return [
            'schema_version' => self::VERSION,
            'weight_profile' => self::PROFILE,
            'cluster_uid' => is_string($clusterUid) ? $clusterUid : null,
            'state' => 'MEASUREMENT_HOLD',
            'priority_score' => null,
            'ranking_eligible' => false,
            'components' => null,
            'hold_reasons' => $reasons,
        ];
    }
}
