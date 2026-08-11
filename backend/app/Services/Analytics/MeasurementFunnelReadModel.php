<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MeasurementFunnelReadModel
{
    public const SCHEMA_VERSION = 'fermatmind.measurement-funnel.v2';

    public const TASK = 'MEASUREMENT-INSTRUMENTATION-01';

    private const REQUIRED_TABLES = ['attempts', 'results', 'events'];

    public function __construct(
        private readonly MeasurementAttributionDimensions $dimensions,
    ) {}

    /**
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    public function report(int $orgId, string $from, string $to, array $scaleCodes = [], array $locales = []): array
    {
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        $issues = [];

        if ($orgId < 0) {
            $issues[] = 'org_id_invalid';
        }

        if ($fromDate === null) {
            $issues[] = 'from_date_invalid';
        }
        if ($toDate === null) {
            $issues[] = 'to_date_invalid';
        }
        if ($fromDate !== null && $toDate !== null && $fromDate->greaterThan($toDate)) {
            $issues[] = 'date_window_invalid';
        }

        foreach (self::REQUIRED_TABLES as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    $issues[] = $table.'_missing';
                }
            } catch (Throwable) {
                $issues[] = $table.'_schema_check_failed';
            }
        }

        $normalizedScales = $this->filters($scaleCodes, true);
        $normalizedLocales = $this->filters($locales, false, true);
        if ($normalizedScales === false) {
            $issues[] = 'scale_filter_invalid';
        }
        if ($normalizedLocales === false) {
            $issues[] = 'locale_filter_invalid';
        }

        if ($issues !== [] || $fromDate === null || $toDate === null) {
            return $this->blocked($orgId, $from, $to, $issues);
        }

        $fromAt = $fromDate->startOfDay();
        $toAt = $toDate->endOfDay();
        $rows = [];

        $attemptQuery = DB::table('attempts')->select([
            'id',
            'scale_code',
            'locale',
            'client_platform',
            'channel',
            'answers_summary_json',
            'started_at',
            'submitted_at',
        ])->where('org_id', $orgId)
            ->where(function (Builder $query) use ($fromAt, $toAt): void {
                $query->whereBetween('started_at', [$fromAt, $toAt])
                    ->orWhereBetween('submitted_at', [$fromAt, $toAt]);
            });
        $this->applyFilters($attemptQuery, $normalizedScales, $normalizedLocales, 'attempts');

        foreach ($attemptQuery->orderBy('id')->get() as $attempt) {
            $aggregateDimensions = $this->dimensions->aggregateDimensions($this->dimensions->fromAttempt($attempt));
            $this->markStage($rows, $attempt->started_at ?? null, $aggregateDimensions, 'attempt_started_ids', (string) $attempt->id, $fromAt, $toAt);
            $this->markStage($rows, $attempt->submitted_at ?? null, $aggregateDimensions, 'test_completed_ids', (string) $attempt->id, $fromAt, $toAt);
        }

        $resultQuery = DB::table('results')
            ->join('attempts', 'attempts.id', '=', 'results.attempt_id')
            ->select([
                'results.attempt_id',
                'results.computed_at',
                'attempts.submitted_at',
                'attempts.scale_code',
                'attempts.locale',
                'attempts.client_platform',
                'attempts.channel',
                'attempts.answers_summary_json',
            ])
            ->where('results.org_id', $orgId)
            ->where('attempts.org_id', $orgId)
            ->where('results.is_valid', true)
            ->whereBetween('results.computed_at', [$fromAt, $toAt]);
        $this->applyFilters($resultQuery, $normalizedScales, $normalizedLocales, 'attempts');

        foreach ($resultQuery->orderBy('results.attempt_id')->get() as $result) {
            $aggregateDimensions = $this->dimensions->aggregateDimensions($this->dimensions->fromAttempt($result, 'ready'));
            if (($result->submitted_at ?? null) === null) {
                $this->markStage($rows, $result->computed_at ?? null, $aggregateDimensions, 'test_completed_ids', (string) $result->attempt_id, $fromAt, $toAt);
            }
            $this->markStage($rows, $result->computed_at ?? null, $aggregateDimensions, 'result_ready_ids', (string) $result->attempt_id, $fromAt, $toAt);
        }

        $eventQuery = DB::table('events')
            ->join('attempts', 'attempts.id', '=', 'events.attempt_id')
            ->select([
                'events.id',
                'events.attempt_id',
                'events.occurred_at',
                'attempts.scale_code',
                'attempts.locale',
                'attempts.client_platform',
                'attempts.channel',
                'attempts.answers_summary_json',
            ])
            ->where('events.org_id', $orgId)
            ->where('attempts.org_id', $orgId)
            ->where('events.event_code', 'result_ready')
            ->whereBetween('events.occurred_at', [$fromAt, $toAt]);
        $this->applyFilters($eventQuery, $normalizedScales, $normalizedLocales, 'attempts');

        foreach ($eventQuery->orderBy('events.id')->get() as $event) {
            $aggregateDimensions = $this->dimensions->aggregateDimensions($this->dimensions->fromAttempt($event, 'ready'));
            $this->markStage($rows, $event->occurred_at ?? null, $aggregateDimensions, 'result_ready_event_ids', (string) $event->attempt_id, $fromAt, $toAt);
            $this->incrementStage($rows, $event->occurred_at ?? null, $aggregateDimensions, 'result_ready_event_rows', $fromAt, $toAt);
        }

        $finalRows = $this->finalizeRows($rows);
        $totals = $this->totals($finalRows);
        $coverage = $this->overallCoverageStatus($finalRows, $totals['result_ready_duplicate_event_count']);

        if ($coverage === 'partial') {
            $issues[] = 'result_ready_event_coverage_partial';
        } elseif ($coverage === 'duplicate') {
            $issues[] = 'result_ready_event_duplicates_detected';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_contract_version' => MeasurementEventContract::VERSION,
            'task' => self::TASK,
            'ok' => true,
            'status' => $issues === [] ? 'pass' : 'warning',
            'issues' => array_values(array_unique($issues)),
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'org_id' => $orgId,
            'filters' => [
                'scale_codes' => $normalizedScales,
                'locales' => $normalizedLocales,
            ],
            'source_tables' => self::REQUIRED_TABLES,
            'row_count' => count($finalRows),
            'rows' => $finalRows,
            'totals' => array_merge($totals, [
                'result_ready_event_coverage_status' => $coverage,
            ]),
            'read_only' => true,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, string>  $dimensions
     */
    private function markStage(
        array &$rows,
        mixed $occurredAt,
        array $dimensions,
        string $field,
        string $attemptId,
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
    ): void {
        $date = $this->dateTimeWithin($occurredAt, $fromAt, $toAt);
        if ($date === null || $attemptId === '') {
            return;
        }
        $key = $this->rowKey($date, $dimensions);
        $rows[$key] ??= $this->emptyRow($date, $dimensions);
        $rows[$key][$field][$attemptId] = true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, string>  $dimensions
     */
    private function incrementStage(
        array &$rows,
        mixed $occurredAt,
        array $dimensions,
        string $field,
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
    ): void {
        $date = $this->dateTimeWithin($occurredAt, $fromAt, $toAt);
        if ($date === null) {
            return;
        }
        $key = $this->rowKey($date, $dimensions);
        $rows[$key] ??= $this->emptyRow($date, $dimensions);
        $rows[$key][$field] = (int) ($rows[$key][$field] ?? 0) + 1;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @return array<string, mixed>
     */
    private function emptyRow(string $date, array $dimensions): array
    {
        return [
            'report_date' => $date,
            'dimensions' => $dimensions,
            'attempt_started_ids' => [],
            'test_completed_ids' => [],
            'result_ready_ids' => [],
            'result_ready_event_ids' => [],
            'result_ready_event_rows' => 0,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function finalizeRows(array $rows): array
    {
        ksort($rows);
        $final = [];
        foreach ($rows as $row) {
            $truthIds = (array) ($row['result_ready_ids'] ?? []);
            $eventIds = (array) ($row['result_ready_event_ids'] ?? []);
            $truth = count($truthIds);
            $events = count($eventIds);
            $duplicates = max(0, (int) ($row['result_ready_event_rows'] ?? 0) - $events);
            $final[] = [
                'report_date' => (string) $row['report_date'],
                'dimensions' => (array) $row['dimensions'],
                'metrics' => [
                    'attempt_started_count' => count((array) ($row['attempt_started_ids'] ?? [])),
                    'test_completed_count' => count((array) ($row['test_completed_ids'] ?? [])),
                    'result_ready_count' => $truth,
                    'result_ready_event_count' => $events,
                    'result_ready_duplicate_event_count' => $duplicates,
                    'result_ready_event_coverage_status' => $this->coverageStatus($truthIds, $eventIds, $duplicates),
                ],
            ];
        }

        return $final;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function totals(array $rows): array
    {
        $totals = [
            'attempt_started_count' => 0,
            'test_completed_count' => 0,
            'result_ready_count' => 0,
            'result_ready_event_count' => 0,
            'result_ready_duplicate_event_count' => 0,
        ];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $metric) {
                $totals[$metric] += (int) data_get($row, 'metrics.'.$metric, 0);
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, bool>  $truthIds
     * @param  array<string, bool>  $eventIds
     */
    private function coverageStatus(array $truthIds, array $eventIds, int $duplicates): string
    {
        if (MeasurementEventContract::RESULT_READY_IMPLEMENTATION !== 'active') {
            return 'not_instrumented';
        }
        if ($duplicates > 0) {
            return 'duplicate';
        }

        return array_diff_key($truthIds, $eventIds) === [] && array_diff_key($eventIds, $truthIds) === []
            ? 'complete'
            : 'partial';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function overallCoverageStatus(array $rows, int $duplicates): string
    {
        if (MeasurementEventContract::RESULT_READY_IMPLEMENTATION !== 'active') {
            return 'not_instrumented';
        }
        if ($duplicates > 0) {
            return 'duplicate';
        }
        foreach ($rows as $row) {
            if (data_get($row, 'metrics.result_ready_event_coverage_status') === 'partial') {
                return 'partial';
            }
        }

        return 'complete';
    }

    /**
     * @param  list<string>|false  $scaleCodes
     * @param  list<string>|false  $locales
     */
    private function applyFilters(Builder $query, array|false $scaleCodes, array|false $locales, string $prefix): void
    {
        if (is_array($scaleCodes) && $scaleCodes !== []) {
            $query->whereIn($prefix.'.scale_code', $scaleCodes);
        }
        if (is_array($locales) && $locales !== []) {
            $query->whereIn($prefix.'.locale', $locales);
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>|false
     */
    private function filters(array $values, bool $uppercase, bool $locale = false): array|false
    {
        $normalized = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                if ($locale) {
                    $lower = strtolower(str_replace('_', '-', $candidate));
                    $candidate = str_starts_with($lower, 'zh') ? 'zh-CN' : (str_starts_with($lower, 'en') ? 'en' : '');
                } elseif (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $candidate) !== 1) {
                    return false;
                }
                if ($candidate === '') {
                    return false;
                }
                $normalized[] = $uppercase ? strtoupper($candidate) : $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTimeWithin(mixed $value, CarbonImmutable $fromAt, CarbonImmutable $toAt): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $date->betweenIncluded($fromAt, $toAt) ? $date->toDateString() : null;
    }

    /**
     * @param  array<string, string>  $dimensions
     */
    private function rowKey(string $date, array $dimensions): string
    {
        return $date.'|'.implode('|', array_values($dimensions));
    }

    /**
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blocked(int $orgId, string $from, string $to, array $issues): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_contract_version' => MeasurementEventContract::VERSION,
            'task' => self::TASK,
            'ok' => false,
            'status' => 'blocked',
            'issues' => array_values(array_unique($issues)),
            'from' => $from,
            'to' => $to,
            'org_id' => $orgId >= 0 ? $orgId : null,
            'rows' => [],
            'read_only' => true,
        ];
    }
}
