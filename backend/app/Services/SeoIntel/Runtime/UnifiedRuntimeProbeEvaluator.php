<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Runtime;

final class UnifiedRuntimeProbeEvaluator
{
    public const SCHEMA_VERSION = 'seo-platform-07-runtime-probe.v1';

    public const MEASUREMENT_HOLD = 'MEASUREMENT_HOLD';

    public const DETECTORS = [
        'http_404',
        'http_410',
        'http_5xx',
        'false_noindex',
        'empty_shell',
        'canonical_drift',
        'hreflang_drift',
        'schema_drift',
        'latency_breach',
        'timeout',
    ];

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function evaluate(array $evidence): array
    {
        $accumulator = $this->emptyAccumulator();
        $sources = [
            'core_entry' => $this->adaptCoreEntry((array) ($evidence['core_entry'] ?? []), $accumulator),
            'public_content' => $this->adaptPublicContent((array) ($evidence['public_content'] ?? []), $accumulator),
            'career_runtime' => $this->adaptCareer((array) ($evidence['career_runtime'] ?? []), $accumulator),
            'crawler' => $this->adaptCrawler((array) ($evidence['crawler'] ?? []), $accumulator),
        ];

        $incidents = $accumulator['incidents'];
        usort($incidents, static fn (array $left, array $right): int => strcmp($left['incident_key'], $right['incident_key']));
        $sourceStates = array_column($sources, 'state');
        $state = $incidents !== []
            ? 'incident'
            : (in_array('no_data', $sourceStates, true) ? self::MEASUREMENT_HOLD : 'success');
        $severity = $this->highestSeverity($incidents);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'state' => $state,
            'severity' => $severity,
            'sources' => $sources,
            'slis' => $this->presentSlis($accumulator),
            'incidents' => $incidents,
            'measurement_hold_reasons' => array_values(array_map(
                static fn (string $source): string => $source.'_no_data',
                array_keys(array_filter($sources, static fn (array $source): bool => $source['state'] === 'no_data')),
            )),
            'boundaries' => [
                'read_only' => true,
                'p0_p1_direct_evidence_only' => true,
                'inferred_p0_p1_allowed' => false,
                'raw_url_emitted' => false,
                'query_emitted' => false,
                'user_agent_emitted' => false,
                'response_body_emitted' => false,
                'raw_topology_emitted' => false,
                'write_authorization_granted' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $accumulator */
    private function adaptCoreEntry(array $source, array &$accumulator): array
    {
        $results = array_values(array_filter((array) ($source['results'] ?? []), 'is_array'));
        if ($results === []) {
            return $this->sourceState('no_data', 0, 0);
        }

        $incidentsBefore = count($accumulator['incidents']);
        foreach ($results as $result) {
            $statusCode = data_get($result, 'http.status_code');
            $httpFailures = [
                'http_404' => $statusCode === 404,
                'http_410' => $statusCode === 410,
                'http_5xx' => is_int($statusCode) && $statusCode >= 500,
            ];
            foreach ($httpFailures as $detector => $failed) {
                $this->observe($accumulator, $detector, $failed ? 1 : 0, 1);
            }
            if ($httpFailures['http_404']) {
                $this->incident($accumulator, 'http_404', $result, 'core_entry', 1, 'identity');
            } elseif ($httpFailures['http_410']) {
                $this->incident($accumulator, 'http_410', $result, 'core_entry', 1, 'identity');
            } elseif ($httpFailures['http_5xx']) {
                $this->incident($accumulator, 'http_5xx', $result, 'core_entry', 1, 'identity');
            }

            $categories = array_map('strval', (array) ($result['incident_categories'] ?? []));
            $detectors = [
                'empty_shell' => in_array('thin_shell', $categories, true)
                    || data_get($result, 'ssr_visible_content.status') === 'missing_or_thin',
                'canonical_drift' => in_array('canonical_drift', $categories, true),
                'hreflang_drift' => in_array('hreflang_drift', $categories, true),
                'schema_drift' => in_array('schema_drift', $categories, true),
                'latency_breach' => in_array('ttfb_breach', $categories, true),
                'timeout' => in_array('transport_error', $categories, true)
                    || in_array('timeout', $categories, true),
                'false_noindex' => data_get($result, 'robots.status') === 'drift'
                    && ($result['authority_indexable'] ?? false) === true,
            ];
            foreach ($detectors as $detector => $failed) {
                $this->observe($accumulator, $detector, $failed ? 1 : 0, 1);
                if ($failed) {
                    $this->incident($accumulator, $detector, $result, 'core_entry', 1, 'identity');
                }
            }
        }

        return $this->sourceState(
            count($accumulator['incidents']) > $incidentsBefore ? 'incident' : 'success',
            count($results),
            count($accumulator['incidents']) - $incidentsBefore,
        );
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $accumulator */
    private function adaptPublicContent(array $source, array &$accumulator): array
    {
        $items = array_values(array_filter((array) ($source['items'] ?? []), 'is_array'));
        $observed = array_sum(array_map(static fn (array $item): int => max(0, (int) ($item['request_count'] ?? 0)), $items));
        if ($items === [] || $observed === 0) {
            return $this->sourceState('no_data', 0, 0);
        }

        $incidentsBefore = count($accumulator['incidents']);
        foreach ($items as $item) {
            $requests = max(0, (int) ($item['request_count'] ?? 0));
            $family = (string) ($item['route_family'] ?? 'unknown');
            $locale = (string) ($item['locale'] ?? 'unknown');
            $base = ['page_family' => $family, 'locale' => $locale];
            $counts = [
                'http_404' => $this->rateCount($item['not_found_rate'] ?? null, $requests),
                'http_5xx' => $this->rateCount($item['server_error_rate'] ?? null, $requests),
                'timeout' => $this->rateCount($item['timeout_rate'] ?? null, $requests),
            ];
            foreach ($counts as $detector => $count) {
                $this->observe($accumulator, $detector, $count, $requests);
                if ($count > 0) {
                    $this->incident($accumulator, $detector, $base, 'public_content', $count, 'api');
                }
            }
            $this->observe($accumulator, 'http_410', 0, $requests);

            $latencyThreshold = $item['approved_p95_budget_ms'] ?? null;
            if (is_numeric($latencyThreshold) && is_numeric($item['p95_ms'] ?? null)) {
                $breached = (float) $item['p95_ms'] > (float) $latencyThreshold;
                $this->observe($accumulator, 'latency_breach', $breached ? 1 : 0, 1);
                if ($breached) {
                    $this->incident($accumulator, 'latency_breach', $base, 'public_content', $requests, 'api');
                }
            }
        }

        return $this->sourceState(
            count($accumulator['incidents']) > $incidentsBefore ? 'incident' : 'success',
            $observed,
            count($accumulator['incidents']) - $incidentsBefore,
        );
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $accumulator */
    private function adaptCareer(array $source, array &$accumulator): array
    {
        $sampleCount = max(0, (int) ($source['sample_count'] ?? 0));
        $alerts = array_values(array_filter((array) ($source['alerts'] ?? []), 'is_string'));
        if ($sampleCount === 0 && $alerts === []) {
            return $this->sourceState('no_data', 0, 0);
        }

        $mapping = [
            'career_api_5xx_rate_above_1_percent' => 'http_5xx',
            'career_api_warm_p95_above_800ms' => 'latency_breach',
            'career_page_false_empty' => 'empty_shell',
            'career_release_smoke_failed' => 'http_5xx',
        ];
        $incidentsBefore = count($accumulator['incidents']);
        foreach ($alerts as $alert) {
            $detector = $mapping[$alert] ?? null;
            if ($detector === null) {
                continue;
            }
            $this->observe($accumulator, $detector, 1, max(1, $sampleCount));
            $this->incident(
                $accumulator,
                $detector,
                ['page_family' => 'career', 'locale' => 'unknown'],
                'career_runtime',
                max(1, $sampleCount),
                'api',
            );
        }

        return $this->sourceState(
            count($accumulator['incidents']) > $incidentsBefore ? 'incident' : 'success',
            max(1, $sampleCount),
            count($accumulator['incidents']) - $incidentsBefore,
        );
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $accumulator */
    private function adaptCrawler(array $source, array &$accumulator): array
    {
        $rows = array_values(array_filter((array) ($source['recent_rows'] ?? []), 'is_array'));
        $totalHits = max(0, (int) ($source['total_hits'] ?? 0));
        if ($rows === [] || $totalHits === 0) {
            return $this->sourceState('no_data', 0, 0);
        }

        $incidentsBefore = count($accumulator['incidents']);
        foreach ($rows as $row) {
            $status = $row['http_status'] ?? null;
            $hits = max(1, (int) ($row['hit_count'] ?? 1));
            $base = [
                'page_family' => (string) ($row['route_family'] ?? 'unknown'),
                'locale' => (string) ($row['locale'] ?? 'unknown'),
            ];
            foreach (['http_404', 'http_410', 'http_5xx'] as $detector) {
                $failed = match ($detector) {
                    'http_404' => $status === 404,
                    'http_410' => $status === 410,
                    'http_5xx' => is_int($status) && $status >= 500,
                };
                $this->observe($accumulator, $detector, $failed ? $hits : 0, $hits);
                if ($failed) {
                    $this->incident($accumulator, $detector, $base, 'crawler', 1, 'template');
                }
            }
        }

        return $this->sourceState(
            count($accumulator['incidents']) > $incidentsBefore ? 'incident' : 'success',
            $totalHits,
            count($accumulator['incidents']) - $incidentsBefore,
        );
    }

    /** @return array<string,mixed> */
    private function emptyAccumulator(): array
    {
        return [
            'slis' => array_fill_keys(self::DETECTORS, ['numerator' => 0, 'denominator' => 0]),
            'incidents' => [],
        ];
    }

    /** @param array<string,mixed> $accumulator */
    private function observe(array &$accumulator, string $detector, int $numerator, int $denominator): void
    {
        $accumulator['slis'][$detector]['numerator'] += max(0, $numerator);
        $accumulator['slis'][$detector]['denominator'] += max(0, $denominator);
    }

    /**
     * @param  array<string,mixed>  $accumulator
     * @param  array<string,mixed>  $evidence
     */
    private function incident(
        array &$accumulator,
        string $detector,
        array $evidence,
        string $source,
        int $affectedCount,
        string $scope,
    ): void {
        $family = $this->safeDimension((string) ($evidence['page_family'] ?? 'unknown'));
        $locale = $this->safeDimension((string) ($evidence['locale'] ?? 'unknown'));
        $affectedCount = max(1, $affectedCount);
        $severity = in_array($scope, ['template', 'api'], true) && $affectedCount >= 2 ? 'P1' : 'P2';
        $key = hash('sha256', implode('|', [self::SCHEMA_VERSION, $source, $detector, $family, $locale, $scope]));

        foreach ($accumulator['incidents'] as &$existing) {
            if ($existing['incident_key'] !== $key) {
                continue;
            }
            $existing['affected_count'] += $affectedCount;
            if ($existing['affected_count'] >= 2 && in_array($scope, ['template', 'api'], true)) {
                $existing['severity'] = 'P1';
            }

            return;
        }

        $accumulator['incidents'][] = [
            'incident_key' => $key,
            'source' => $source,
            'detector' => $detector,
            'page_family' => $family,
            'locale' => $locale,
            'scope' => $scope,
            'affected_count' => $affectedCount,
            'evidence_state' => 'direct_observation',
            'severity' => $severity,
        ];
    }

    /** @param array<string,mixed> $accumulator @return array<string,mixed> */
    private function presentSlis(array $accumulator): array
    {
        $slis = [];
        foreach (self::DETECTORS as $detector) {
            $numerator = (int) $accumulator['slis'][$detector]['numerator'];
            $denominator = (int) $accumulator['slis'][$detector]['denominator'];
            $slis[$detector] = [
                'state' => $denominator === 0
                    ? self::MEASUREMENT_HOLD
                    : ($numerator === 0 ? 'success' : 'incident'),
                'numerator' => $denominator === 0 ? null : $numerator,
                'denominator' => $denominator === 0 ? null : $denominator,
                'rate' => $denominator === 0 ? null : round($numerator / $denominator, 6),
            ];
        }

        return $slis;
    }

    /** @return array{state:string,observed_count:int,incident_count:int} */
    private function sourceState(string $state, int $observedCount, int $incidentCount): array
    {
        return [
            'state' => $state,
            'observed_count' => max(0, $observedCount),
            'incident_count' => max(0, $incidentCount),
        ];
    }

    private function rateCount(mixed $rate, int $total): int
    {
        return ! is_numeric($rate) || $total <= 0 ? 0 : (int) round(max(0.0, (float) $rate) * $total);
    }

    /** @param list<array<string,mixed>> $incidents */
    private function highestSeverity(array $incidents): ?string
    {
        foreach (['P0', 'P1', 'P2'] as $severity) {
            if (array_any($incidents, static fn (array $incident): bool => $incident['severity'] === $severity)) {
                return $severity;
            }
        }

        return null;
    }

    private function safeDimension(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9_-]{1,64}$/', $value) === 1 ? $value : 'unknown';
    }
}
