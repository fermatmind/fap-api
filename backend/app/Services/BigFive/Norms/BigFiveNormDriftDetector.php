<?php

declare(strict_types=1);

namespace App\Services\BigFive\Norms;

final class BigFiveNormDriftDetector
{
    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $options
     */
    public function analyze(array $baseline, array $current, array $options = []): BigFiveNormDriftResult
    {
        $thresholds = [
            'mean_delta' => (float) ($options['mean_delta'] ?? 5.0),
            'sd_delta' => (float) ($options['sd_delta'] ?? 2.0),
            'percentile_delta' => (int) ($options['percentile_delta'] ?? 15),
            'sample_n_delta_ratio' => (float) ($options['sample_n_delta_ratio'] ?? 0.25),
        ];

        $alerts = [
            ...$this->metricDriftAlerts($baseline, $current, $thresholds),
            ...$this->percentileAnomalyAlerts($baseline, $current, $thresholds),
            ...$this->aggregationAnomalyAlerts($baseline, $current, $thresholds),
        ];
        $status = $alerts === [] ? 'clear' : 'alert';

        return new BigFiveNormDriftResult([
            'mode' => 'big5_norm_drift_detection',
            'status' => $status,
            'alert_count' => count($alerts),
            'thresholds' => $thresholds,
            'baseline_snapshot_version' => (string) data_get($baseline, 'summary.snapshot_version', ''),
            'current_snapshot_version' => (string) data_get($current, 'summary.snapshot_version', ''),
            'baseline_output_hash' => (string) data_get($baseline, 'summary.output_hash', ''),
            'current_output_hash' => (string) data_get($current, 'summary.output_hash', ''),
            'recomputation_drift_alerts' => count(array_filter($alerts, static fn (array $alert): bool => ($alert['alert_family'] ?? null) === 'recomputation_drift')),
            'percentile_anomaly_alerts' => count(array_filter($alerts, static fn (array $alert): bool => ($alert['alert_family'] ?? null) === 'percentile_anomaly')),
            'aggregation_anomaly_alerts' => count(array_filter($alerts, static fn (array $alert): bool => ($alert['alert_family'] ?? null) === 'aggregation_anomaly')),
            'public_percentile_display' => 'disabled',
            'runtime_attachment' => 'disabled',
            'public_exposure_allowed' => false,
        ], $alerts, [
            'mode' => 'big5_norm_rebuild_evidence',
            'baseline_snapshot_version' => (string) data_get($baseline, 'summary.snapshot_version', ''),
            'current_snapshot_version' => (string) data_get($current, 'summary.snapshot_version', ''),
            'baseline_output_hash' => (string) data_get($baseline, 'summary.output_hash', ''),
            'current_output_hash' => (string) data_get($current, 'summary.output_hash', ''),
            'drift_status' => $status,
            'rebuild_review_required' => $status === 'alert',
            'alerts' => array_map(static fn (array $alert): string => (string) $alert['alert_key'], $alerts),
            'public_percentile_display' => 'disabled',
            'runtime_attachment' => 'disabled',
        ]);
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $thresholds
     * @return list<array<string,mixed>>
     */
    private function metricDriftAlerts(array $baseline, array $current, array $thresholds): array
    {
        $alerts = [];
        $baselineMetrics = (array) ($baseline['metrics'] ?? []);
        $currentMetrics = (array) ($current['metrics'] ?? []);
        $domainKeys = array_values(array_intersect(array_keys($baselineMetrics), array_keys($currentMetrics)));
        sort($domainKeys);

        foreach ($domainKeys as $domainKey) {
            $baselineMean = $this->floatValue(data_get($baselineMetrics, $domainKey.'.mean'));
            $currentMean = $this->floatValue(data_get($currentMetrics, $domainKey.'.mean'));
            $baselineSd = $this->floatValue(data_get($baselineMetrics, $domainKey.'.sd'));
            $currentSd = $this->floatValue(data_get($currentMetrics, $domainKey.'.sd'));

            if ($baselineMean !== null && $currentMean !== null && abs($currentMean - $baselineMean) > $thresholds['mean_delta']) {
                $alerts[] = $this->alert('recomputation_drift', 'mean_drift', (string) $domainKey, [
                    'baseline' => $baselineMean,
                    'current' => $currentMean,
                    'delta' => round($currentMean - $baselineMean, 6),
                    'threshold' => $thresholds['mean_delta'],
                ]);
            }

            if ($baselineSd !== null && $currentSd !== null && abs($currentSd - $baselineSd) > $thresholds['sd_delta']) {
                $alerts[] = $this->alert('recomputation_drift', 'sd_drift', (string) $domainKey, [
                    'baseline' => $baselineSd,
                    'current' => $currentSd,
                    'delta' => round($currentSd - $baselineSd, 6),
                    'threshold' => $thresholds['sd_delta'],
                ]);
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $thresholds
     * @return list<array<string,mixed>>
     */
    private function percentileAnomalyAlerts(array $baseline, array $current, array $thresholds): array
    {
        $alerts = [];
        $baselinePercentiles = (array) ($baseline['internal_percentiles'] ?? []);
        $currentPercentiles = (array) ($current['internal_percentiles'] ?? []);
        $observationIds = array_values(array_intersect(array_keys($baselinePercentiles), array_keys($currentPercentiles)));
        sort($observationIds);

        foreach ($observationIds as $observationId) {
            $domainKeys = array_values(array_intersect(
                array_keys((array) $baselinePercentiles[$observationId]),
                array_keys((array) $currentPercentiles[$observationId]),
            ));
            sort($domainKeys);

            foreach ($domainKeys as $domainKey) {
                $baselinePercentile = $this->intValue(data_get($baselinePercentiles, $observationId.'.'.$domainKey));
                $currentPercentile = $this->intValue(data_get($currentPercentiles, $observationId.'.'.$domainKey));
                if ($baselinePercentile === null || $currentPercentile === null) {
                    continue;
                }

                $delta = abs($currentPercentile - $baselinePercentile);
                if ($delta > $thresholds['percentile_delta']) {
                    $alerts[] = $this->alert('percentile_anomaly', 'percentile_drift', (string) $domainKey, [
                        'observation_id_hash' => hash('sha256', (string) $observationId),
                        'baseline' => $baselinePercentile,
                        'current' => $currentPercentile,
                        'delta' => $delta,
                        'threshold' => $thresholds['percentile_delta'],
                    ]);
                }
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>  $thresholds
     * @return list<array<string,mixed>>
     */
    private function aggregationAnomalyAlerts(array $baseline, array $current, array $thresholds): array
    {
        $baselineCount = $this->intValue(data_get($baseline, 'summary.observation_count'));
        $currentCount = $this->intValue(data_get($current, 'summary.observation_count'));
        if ($baselineCount === null || $currentCount === null || $baselineCount === 0) {
            return [];
        }

        $ratio = abs($currentCount - $baselineCount) / $baselineCount;
        if ($ratio <= $thresholds['sample_n_delta_ratio']) {
            return [];
        }

        return [
            $this->alert('aggregation_anomaly', 'sample_n_shift', 'observation_count', [
                'baseline' => $baselineCount,
                'current' => $currentCount,
                'delta_ratio' => round($ratio, 6),
                'threshold' => $thresholds['sample_n_delta_ratio'],
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $details
     * @return array<string,mixed>
     */
    private function alert(string $family, string $type, string $target, array $details): array
    {
        return [
            'alert_key' => implode('.', [$family, $type, $target]),
            'alert_family' => $family,
            'alert_type' => $type,
            'target' => $target,
            'severity' => $family === 'percentile_anomaly' ? 'critical' : 'warning',
            'details' => $details,
            'public_exposure_allowed' => false,
        ];
    }

    private function floatValue(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private function intValue(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
