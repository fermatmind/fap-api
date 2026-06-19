<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Carbon\CarbonImmutable;
use Throwable;

final class GscDataQualityGate
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function evaluate(array $rows, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $lagDays = max(0, (int) config('seo_intel.gsc_backfill_lag_days', 3));
        $maxAgeDays = max($lagDays, (int) config('seo_intel.gsc_data_quality.max_report_age_days', 10));
        $minRows = max(1, (int) config('seo_intel.gsc_data_quality.min_rows', 1));
        $forbiddenOrigins = $this->stringList(config('seo_intel.gsc_data_quality.forbidden_data_origins', [
            'fixture',
            'mock',
            'static_artifact',
            'unknown',
        ]));
        $allowedOrigins = $this->stringList(config('seo_intel.gsc_data_quality.allowed_data_origins', [
            'live_gsc_api',
        ]));

        $reasons = [];
        $origins = [];
        $reportDates = [];
        $missingRequiredMetricRows = 0;
        $nonGoogleRows = 0;

        if (count($rows) < $minRows) {
            $reasons[] = 'insufficient_rows';
        }

        foreach ($rows as $row) {
            $metadata = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
            $origin = $this->normalizeOrigin(
                $metadata['data_origin']
                    ?? $metadata['row_source']
                    ?? $row['data_origin']
                    ?? $row['row_source']
                    ?? null
            );
            $origins[$origin] = true;

            if (in_array($origin, $forbiddenOrigins, true)) {
                $reasons[] = 'fixture_or_mock_source';
            }

            if (! in_array($origin, $allowedOrigins, true)) {
                $reasons[] = 'untrusted_data_origin';
            }

            if (($row['source_engine'] ?? null) !== 'google') {
                $nonGoogleRows++;
            }

            $date = $this->parseReportDate($row['report_date'] ?? null);
            if ($date === null) {
                $reasons[] = 'missing_report_date';
            } else {
                $reportDates[] = $date;
            }

            if (
                empty($row['canonical_url_hash'])
                || empty($row['query_hash'])
                || ! array_key_exists('clicks', $row)
                || ! array_key_exists('impressions', $row)
            ) {
                $missingRequiredMetricRows++;
            }
        }

        if ($nonGoogleRows > 0) {
            $reasons[] = 'non_google_source_engine';
        }

        if ($missingRequiredMetricRows > 0) {
            $reasons[] = 'missing_required_metric_fields';
        }

        if ($reportDates !== []) {
            usort($reportDates, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

            $minDate = $reportDates[0];
            $maxDate = $reportDates[count($reportDates) - 1];
            $latestFinalDate = $now->subDays($lagDays)->startOfDay();
            $oldestAllowedDate = $now->subDays($maxAgeDays)->startOfDay();

            if ($maxDate->greaterThan($latestFinalDate)) {
                $reasons[] = 'gsc_finalization_lag_not_met';
            }

            if ($maxDate->lessThan($oldestAllowedDate)) {
                $reasons[] = 'stale_gsc_report_date';
            }
        } else {
            $minDate = null;
            $maxDate = null;
        }

        $reasons = array_values(array_unique($reasons));
        $passed = $reasons === [];

        return [
            'status' => $passed ? 'pass' : 'blocked',
            'opportunity_queue_eligible' => $passed,
            'reasons' => $reasons,
            'rows_checked' => count($rows),
            'data_origins' => array_keys($origins),
            'freshness' => [
                'lag_days_required' => $lagDays,
                'max_report_age_days' => $maxAgeDays,
                'min_report_date' => $minDate?->toDateString(),
                'max_report_date' => $maxDate?->toDateString(),
            ],
            'forbidden_sources' => [
                'fixture',
                'mock',
                'static_artifact',
            ],
            'allowed_sources' => $allowedOrigins,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => $this->normalizeOrigin($item), $value),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function normalizeOrigin(mixed $origin): string
    {
        $origin = trim(mb_strtolower((string) $origin, 'UTF-8'));

        return $origin === '' ? 'unknown' : $origin;
    }

    private function parseReportDate(mixed $date): ?CarbonImmutable
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr($date, 0, 10))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
