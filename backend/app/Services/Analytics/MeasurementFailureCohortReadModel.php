<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MeasurementFailureCohortReadModel
{
    public const SCHEMA_VERSION = 'fermatmind.measurement-failure-cohorts.v2';

    public const TASK = 'FERMATMIND-SEO-MEASUREMENT-FAILURE-COHORTS-01';

    /** @var list<string> */
    private const REQUIRED_TABLES = ['attempts', 'results', 'events'];

    public function __construct(
        private readonly MeasurementAttributionDimensions $dimensions,
        private readonly AnalyticsTrafficExclusionPolicy $trafficExclusionPolicy,
    ) {}

    /**
     * @param  array<string, list<string>>  $requestedFilters
     * @return array<string, mixed>
     */
    public function report(int $orgId, string $from, string $to, array $requestedFilters = []): array
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

        $filters = $this->filters($requestedFilters);
        if ($filters === false) {
            $issues[] = 'filter_invalid';
        }

        if ($issues !== [] || $fromDate === null || $toDate === null || $filters === false) {
            return $this->blocked($orgId, $from, $to, $issues);
        }

        $fromAt = $fromDate->startOfDay();
        $toAt = $toDate->endOfDay();
        $attempts = $this->eligibleAttempts($orgId, $fromAt, $toAt, $filters);
        $resultReadyAt = $this->validResultReadyAt($orgId, $attempts->keys()->all(), $toAt);
        $events = $this->failureEvents($orgId, $fromAt, $toAt, $filters);
        $eventDimensionFiltersActive = $this->eventDimensionFiltersActive($filters);

        if ($eventDimensionFiltersActive) {
            $issues[] = 'eligible_denominator_unavailable_for_event_dimension_filters';
        }

        $cohorts = [];
        $rowGroups = [];
        foreach (MeasurementFailureEventContract::EVENT_NAMES as $eventName) {
            $eventRows = $events->where('event_name', $eventName)->values();
            $cohort = $this->cohort(
                $eventName,
                $attempts,
                $resultReadyAt,
                $eventRows,
                $toAt,
                $eventDimensionFiltersActive,
                $rowGroups,
            );
            $cohorts[$eventName] = $cohort;

            if (in_array($cohort['coverage_status'], ['partial', 'insufficient_correlation'], true)) {
                $issues[] = $eventName.'_coverage_'.$cohort['coverage_status'];
            }
            if ((int) $cohort['duplicate_failure_event_count'] > 0) {
                $issues[] = $eventName.'_duplicates_detected';
            }
        }

        $rows = $this->finalizeRows($rowGroups);
        $hasData = $attempts->isNotEmpty() || $events->isNotEmpty();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_contract_version' => MeasurementEventContract::VERSION,
            'failure_contract_version' => MeasurementFailureEventContract::VERSION,
            'task' => self::TASK,
            'ok' => true,
            'status' => ! $hasData ? 'empty' : ($issues === [] ? 'pass' : 'warning'),
            'issues' => array_values(array_unique($issues)),
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'org_id' => $orgId,
            'filters' => $filters,
            'source_tables' => self::REQUIRED_TABLES,
            'minimum_cohort_size' => MeasurementFailureEventContract::MINIMUM_COHORT_SIZE,
            'row_count' => count($rows),
            'rows' => $rows,
            'cohorts' => $cohorts,
            'read_only' => true,
        ];
    }

    /**
     * @param  array<string, list<string>>  $filters
     * @return Collection<string, object>
     */
    private function eligibleAttempts(int $orgId, CarbonImmutable $fromAt, CarbonImmutable $toAt, array $filters): Collection
    {
        $rows = DB::table('attempts')->select([
            'id',
            'anon_id',
            'scale_code',
            'locale',
            'client_platform',
            'channel',
            'answers_summary_json',
            'started_at',
            'submitted_at',
        ])->where('org_id', $orgId)
            ->whereBetween('started_at', [$fromAt, $toAt])
            ->orderBy('id')
            ->get();

        return $rows->filter(function (object $attempt) use ($filters): bool {
            if ($this->trafficExclusionPolicy->isExcludedAttemptRow($attempt)) {
                return false;
            }
            $safe = $this->attemptDimensions($attempt);

            return $this->matches($safe, $filters, ['scale_code', 'form_code', 'locale', 'device_class']);
        })->keyBy(fn (object $attempt): string => (string) $attempt->id);
    }

    /**
     * @param  list<string>  $attemptIds
     * @return array<string, CarbonImmutable>
     */
    private function validResultReadyAt(int $orgId, array $attemptIds, CarbonImmutable $toAt): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $ready = [];
        foreach (array_chunk($attemptIds, 500) as $chunk) {
            $rows = DB::table('results')
                ->select(['attempt_id', 'computed_at'])
                ->where('org_id', $orgId)
                ->whereIn('attempt_id', $chunk)
                ->where('is_valid', true)
                ->whereNotNull('computed_at')
                ->where('computed_at', '<=', $toAt)
                ->orderBy('computed_at')
                ->get();

            foreach ($rows as $row) {
                $attemptId = (string) $row->attempt_id;
                $computedAt = $this->dateTime($row->computed_at ?? null);
                if ($attemptId !== '' && $computedAt !== null && ! isset($ready[$attemptId])) {
                    $ready[$attemptId] = $computedAt;
                }
            }
        }

        return $ready;
    }

    /**
     * @param  array<string, list<string>>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function failureEvents(int $orgId, CarbonImmutable $fromAt, CarbonImmutable $toAt, array $filters): Collection
    {
        return DB::table('events')
            ->select(['id', 'event_code', 'attempt_id', 'anon_id', 'session_id', 'request_id', 'meta_json', 'occurred_at', 'scale_code', 'locale', 'client_platform'])
            ->where('org_id', $orgId)
            ->whereIn('event_code', MeasurementFailureEventContract::EVENT_NAMES)
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(function (object $event): array {
                $meta = $this->decodeArray($event->meta_json ?? null);
                if ($this->trafficExclusionPolicy->isExcludedSeoConversionEvent($event, $meta)) {
                    return [];
                }
                $safe = MeasurementFailureEventContract::sanitizeProperties(array_merge($meta, [
                    'scale_code' => $meta['scale_code'] ?? $event->scale_code ?? null,
                    'locale' => $meta['locale'] ?? $event->locale ?? null,
                    'device_class' => $meta['device_class'] ?? $this->eventDeviceClass($event->client_platform ?? null),
                ]));

                return [
                    'event_id' => (string) $event->id,
                    'event_name' => strtolower(trim((string) $event->event_code)),
                    'attempt_id' => trim((string) ($event->attempt_id ?? '')),
                    'occurred_at' => $this->dateTime($event->occurred_at ?? null),
                    'dimensions' => $safe,
                ];
            })
            ->filter(static fn (array $event): bool => $event !== [])
            ->filter(fn (array $event): bool => $event['occurred_at'] instanceof CarbonImmutable)
            ->filter(fn (array $event): bool => $this->matches((array) $event['dimensions'], $filters))
            ->values();
    }

    /**
     * @param  Collection<string, object>  $attempts
     * @param  array<string, CarbonImmutable>  $resultReadyAt
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  array<string, array<string, mixed>>  $rowGroups
     * @return array<string, mixed>
     */
    private function cohort(
        string $eventName,
        Collection $attempts,
        array $resultReadyAt,
        Collection $events,
        CarbonImmutable $toAt,
        bool $eventDimensionFiltersActive,
        array &$rowGroups,
    ): array {
        $unattributed = 0;
        $eventsByAttempt = [];

        foreach ($events as $event) {
            $attemptId = (string) $event['attempt_id'];
            if ($attemptId === '' || ! $attempts->has($attemptId)) {
                $unattributed++;

                continue;
            }

            $attempt = $attempts->get($attemptId);
            $event['dimensions'] = $this->mergeEventDimensions(
                (array) $event['dimensions'],
                $this->attemptDimensions($attempt),
            );
            $eventsByAttempt[$attemptId][] = $event;
        }

        $failedAttemptCount = 0;
        $retryingAttemptCount = 0;
        $eventualSuccessCount = 0;
        $terminalFailureCount = 0;
        $uniqueEventCount = 0;
        $duplicateCount = 0;
        $retryCount = 0;
        $recoverySeconds = [];

        foreach ($eventsByAttempt as $attemptId => $attemptEvents) {
            usort($attemptEvents, fn (array $left, array $right): int => $left['occurred_at']->getTimestampMs() <=> $right['occurred_at']->getTimestampMs());
            $seen = [];
            $unique = [];
            foreach ($attemptEvents as $event) {
                $dedupDimensions = (array) $event['dimensions'];
                unset($dedupDimensions['retry_bucket']);
                $signature = $eventName.'|'.$attemptId.'|'.$event['occurred_at']->format('Y-m-d H:i:s.u').'|'.json_encode($dedupDimensions);
                if (isset($seen[$signature])) {
                    $duplicateCount++;

                    continue;
                }
                $seen[$signature] = true;
                $unique[] = $event;
            }

            if ($unique === []) {
                continue;
            }

            $failedAttemptCount++;
            $uniqueEventCount += count($unique);
            $attemptRetryCount = max(0, count($unique) - 1);
            $retryCount += $attemptRetryCount;
            if ($attemptRetryCount > 0) {
                $retryingAttemptCount++;
            }

            $first = $unique[0];
            $successAt = $this->successAt($eventName, $attempts->get($attemptId), $resultReadyAt[$attemptId] ?? null, $first['occurred_at'], $toAt);
            if ($successAt !== null) {
                $eventualSuccessCount++;
                $recoverySeconds[] = (int) max(0, $first['occurred_at']->diffInSeconds($successAt));
            } else {
                $terminalFailureCount++;
            }

            $date = $first['occurred_at']->toDateString();
            $dimensions = (array) $first['dimensions'];
            $dimensions['retry_bucket'] = MeasurementFailureEventContract::retryBucket($attemptRetryCount);
            $key = $eventName.'|'.$date.'|'.json_encode($dimensions);
            $rowGroups[$key] ??= [
                'event_name' => $eventName,
                'report_date' => $date,
                'dimensions' => $dimensions,
                'attempt_ids' => [],
                'retrying_attempt_ids' => [],
                'eventual_success_ids' => [],
                'terminal_failure_ids' => [],
                'failure_event_count' => 0,
            ];
            $rowGroups[$key]['attempt_ids'][$attemptId] = true;
            if ($attemptRetryCount > 0) {
                $rowGroups[$key]['retrying_attempt_ids'][$attemptId] = true;
            }
            if ($successAt !== null) {
                $rowGroups[$key]['eventual_success_ids'][$attemptId] = true;
            } else {
                $rowGroups[$key]['terminal_failure_ids'][$attemptId] = true;
            }
            $rowGroups[$key]['failure_event_count'] += count($unique);
        }

        $rawEventCount = $events->count();
        $coverage = match (true) {
            $rawEventCount === 0 && $attempts->isEmpty() => 'no_data',
            $rawEventCount === 0 => 'complete',
            $failedAttemptCount === 0 => 'insufficient_correlation',
            $unattributed > 0 => 'partial',
            default => 'complete',
        };

        $eligibleCount = $eventDimensionFiltersActive ? null : $attempts->count();
        $failureRate = $coverage === 'complete' && $eligibleCount !== null && $eligibleCount > 0
            ? round($failedAttemptCount / $eligibleCount, 6)
            : null;

        sort($recoverySeconds);

        return [
            'eligible_attempt_count' => $eligibleCount,
            'failed_attempt_count' => $failedAttemptCount,
            'first_failure_attempt_count' => $failedAttemptCount,
            'retrying_attempt_count' => $retryingAttemptCount,
            'eventual_success_attempt_count' => $eventualSuccessCount,
            'terminal_failure_attempt_count' => $terminalFailureCount,
            'failure_event_count' => $rawEventCount,
            'attributed_failure_event_count' => $uniqueEventCount,
            'duplicate_failure_event_count' => $duplicateCount,
            'unattributed_failure_event_count' => $unattributed,
            'retry_count' => $retryCount,
            'eventual_success_rate' => $failureRate === null || $failedAttemptCount === 0
                ? null
                : round($eventualSuccessCount / $failedAttemptCount, 6),
            'eligible_failure_rate' => $failureRate,
            'recovery_time_p50_seconds' => $this->percentile($recoverySeconds, 0.50),
            'recovery_time_p95_seconds' => $this->percentile($recoverySeconds, 0.95),
            'coverage_status' => $coverage,
        ];
    }

    private function successAt(
        string $eventName,
        object $attempt,
        ?CarbonImmutable $resultReadyAt,
        CarbonImmutable $failureAt,
        CarbonImmutable $toAt,
    ): ?CarbonImmutable {
        $candidates = [];
        if ($eventName === 'questions_load_failure') {
            $startedAt = $this->dateTime($attempt->started_at ?? null);
            if ($startedAt !== null) {
                $candidates[] = $startedAt;
            }
        } elseif ($resultReadyAt !== null) {
            $candidates[] = $resultReadyAt;
        }

        usort($candidates, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestampMs() <=> $right->getTimestampMs());
        foreach ($candidates as $candidate) {
            if ($candidate->greaterThan($failureAt) && $candidate->lessThanOrEqualTo($toAt)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowGroups
     * @return list<array<string, mixed>>
     */
    private function finalizeRows(array $rowGroups): array
    {
        ksort($rowGroups);
        $rows = [];
        $groupsByEventDate = [];

        foreach ($rowGroups as $group) {
            $groupsByEventDate[$group['event_name'].'|'.$group['report_date']][] = $group;
        }

        foreach ($groupsByEventDate as $groups) {
            $requiresComplementarySuppression = collect($groups)->contains(
                fn (array $group): bool => count((array) $group['attempt_ids']) < MeasurementFailureEventContract::MINIMUM_COHORT_SIZE,
            );
            if ($requiresComplementarySuppression) {
                $first = $groups[0];
                $rows[] = [
                    'event_name' => $first['event_name'],
                    'report_date' => $first['report_date'],
                    'dimensions' => ['suppressed' => 'suppressed'],
                    'metrics' => null,
                    'suppressed' => true,
                    'minimum_cohort_size' => MeasurementFailureEventContract::MINIMUM_COHORT_SIZE,
                    'suppression_reason' => 'minimum_cohort_or_complementary_suppression',
                ];

                continue;
            }

            foreach ($groups as $group) {
                $attemptCount = count((array) $group['attempt_ids']);
                $rows[] = [
                    'event_name' => $group['event_name'],
                    'report_date' => $group['report_date'],
                    'dimensions' => $group['dimensions'],
                    'metrics' => [
                        'failed_attempt_count' => $attemptCount,
                        'first_failure_attempt_count' => $attemptCount,
                        'retrying_attempt_count' => count((array) $group['retrying_attempt_ids']),
                        'eventual_success_attempt_count' => count((array) $group['eventual_success_ids']),
                        'terminal_failure_attempt_count' => count((array) $group['terminal_failure_ids']),
                        'failure_event_count' => (int) $group['failure_event_count'],
                    ],
                    'suppressed' => false,
                    'minimum_cohort_size' => MeasurementFailureEventContract::MINIMUM_COHORT_SIZE,
                ];
            }
        }
        usort($rows, fn (array $left, array $right): int => [
            $left['event_name'],
            $left['report_date'],
            json_encode($left['dimensions']),
        ] <=> [
            $right['event_name'],
            $right['report_date'],
            json_encode($right['dimensions']),
        ]);

        return $rows;
    }

    /** @return array<string, string> */
    private function attemptDimensions(object $attempt): array
    {
        $safe = $this->dimensions->fromAttempt($attempt);

        return [
            'scale_code' => $safe['scale_code'] ?? 'unknown',
            'form_code' => $safe['form_code'] ?? 'unknown',
            'locale' => $safe['locale'] ?? 'unknown',
            'device_class' => match ($safe['device_class'] ?? 'unknown') {
                'web' => 'desktop',
                'mobile_app', 'mini_program' => 'mobile',
                'other' => 'other',
                default => 'unknown',
            },
        ];
    }

    private function eventDeviceClass(mixed $value): string
    {
        $value = strtolower(trim(is_scalar($value) ? (string) $value : ''));

        return match (true) {
            str_contains($value, 'tablet'), str_contains($value, 'ipad') => 'tablet',
            str_contains($value, 'mobile'), str_contains($value, 'ios'), str_contains($value, 'android'), str_contains($value, 'mini') => 'mobile',
            str_contains($value, 'desktop'), str_contains($value, 'web') => 'desktop',
            $value !== '' => 'other',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, string>  $event
     * @param  array<string, string>  $attempt
     * @return array<string, string>
     */
    private function mergeEventDimensions(array $event, array $attempt): array
    {
        foreach (['scale_code', 'form_code', 'locale', 'device_class'] as $field) {
            if (($event[$field] ?? 'unknown') === 'unknown' && ($attempt[$field] ?? 'unknown') !== 'unknown') {
                $event[$field] = $attempt[$field];
            }
        }

        return $event;
    }

    /**
     * @param  array<string, string>  $dimensions
     * @param  array<string, list<string>>  $filters
     * @param  list<string>|null  $fields
     */
    private function matches(array $dimensions, array $filters, ?array $fields = null): bool
    {
        foreach ($filters as $field => $values) {
            if ($fields !== null && ! in_array($field, $fields, true)) {
                continue;
            }
            if ($values !== [] && ! in_array($dimensions[$field] ?? 'unknown', $values, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $requested
     * @return array<string, list<string>>|false
     */
    private function filters(array $requested): array|false
    {
        $allowed = [
            'device_class' => MeasurementFailureEventContract::DEVICE_CLASSES,
            'browser_class' => MeasurementFailureEventContract::BROWSER_CLASSES,
            'endpoint_class' => MeasurementFailureEventContract::ENDPOINT_CLASSES,
            'status_group' => MeasurementFailureEventContract::STATUS_GROUPS,
            'error_class' => MeasurementFailureEventContract::ERROR_CLASSES,
        ];
        $result = [];

        foreach (['scale_code', 'form_code', 'locale', ...array_keys($allowed)] as $field) {
            $values = [];
            foreach ($requested[$field] ?? [] as $raw) {
                foreach (explode(',', $raw) as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate === '') {
                        continue;
                    }
                    if ($field === 'locale') {
                        $lower = strtolower(str_replace('_', '-', $candidate));
                        $candidate = str_starts_with($lower, 'zh') ? 'zh-CN' : (str_starts_with($lower, 'en') ? 'en' : '');
                    } elseif ($field === 'scale_code') {
                        $candidate = preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $candidate) === 1 ? strtoupper($candidate) : '';
                    } elseif ($field === 'form_code') {
                        $candidate = preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $candidate) === 1 ? strtolower($candidate) : '';
                    } else {
                        $candidate = strtolower($candidate);
                        if (! in_array($candidate, $allowed[$field], true)) {
                            $candidate = '';
                        }
                    }
                    if ($candidate === '') {
                        return false;
                    }
                    $values[] = $candidate;
                }
            }
            $result[$field] = array_values(array_unique($values));
        }

        return $result;
    }

    /** @param array<string, list<string>> $filters */
    private function eventDimensionFiltersActive(array $filters): bool
    {
        foreach (['browser_class', 'endpoint_class', 'status_group', 'error_class'] as $field) {
            if (($filters[$field] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        $index = (int) ceil(count($values) * $percentile) - 1;

        return (int) $values[max(0, min($index, count($values) - 1))];
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

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
            'failure_contract_version' => MeasurementFailureEventContract::VERSION,
            'task' => self::TASK,
            'ok' => false,
            'status' => 'blocked',
            'issues' => array_values(array_unique($issues)),
            'from' => $from,
            'to' => $to,
            'org_id' => $orgId >= 0 ? $orgId : null,
            'rows' => [],
            'cohorts' => [],
            'read_only' => true,
        ];
    }
}
