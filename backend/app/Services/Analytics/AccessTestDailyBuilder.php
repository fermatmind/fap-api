<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AccessTestDailyBuilder
{
    private const PAGE_VIEW_EVENTS = ['landing_pv', 'landing_view'];

    public function __construct(
        private readonly AnalyticsTrafficExclusionPolicy $trafficExclusionPolicy,
    ) {}

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:int,from:string,to:string,org_scope:list<int>}
     */
    public function refresh(\DateTimeInterface $from, \DateTimeInterface $to, array $orgIds = []): array
    {
        $timezone = (string) config('analytics.access_test_statistics.timezone', AccessTestIdentity::TIMEZONE);
        $fromDay = CarbonImmutable::parse($from, $timezone)->setTimezone($timezone)->startOfDay();
        $toDay = CarbonImmutable::parse($to, $timezone)->setTimezone($timezone)->startOfDay();
        $scope = $this->resolveOrgScope($fromDay, $toDay, $orgIds);
        $rows = [];

        for ($day = $fromDay; $day->lte($toDay); $day = $day->addDay()) {
            foreach ($scope as $orgId) {
                array_push($rows, ...$this->buildDay($day, $orgId));
            }
        }

        DB::transaction(function () use ($fromDay, $toDay, $scope, $rows): void {
            DB::table('analytics_access_test_daily')
                ->whereBetween('day', [$fromDay->toDateString(), $toDay->toDateString()])
                ->whereIn('org_id', $scope)
                ->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('analytics_access_test_daily')->insert($chunk);
            }
        });

        return [
            'rows' => count($rows),
            'from' => $fromDay->toDateString(),
            'to' => $toDay->toDateString(),
            'org_scope' => $scope,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function buildDay(CarbonImmutable $day, int $orgId): array
    {
        $startUtc = $day->startOfDay()->utc();
        $endUtc = $day->addDay()->startOfDay()->utc();
        $buckets = [];
        $latestAt = null;
        $hasLegacyTestRows = false;

        if (SchemaBaseline::hasTable('events')) {
            $events = DB::table('events')
                ->where('org_id', $orgId)
                ->whereIn('event_name', self::PAGE_VIEW_EVENTS)
                ->where('occurred_at', '>=', $startUtc)
                ->where('occurred_at', '<', $endUtc)
                ->get();

            foreach ($events as $event) {
                $meta = $this->jsonArray($event->meta_json ?? null);
                $raw = is_array($meta['raw_payload'] ?? null) ? $meta['raw_payload'] : [];
                $dimensions = [
                    'scale_code' => $this->dimension($event->scale_code ?? $raw['scale_code'] ?? $raw['scale_id'] ?? null),
                    'form_code' => $this->dimension($raw['form_code'] ?? $raw['form_id'] ?? null),
                    'locale' => $this->locale($event->locale ?? $raw['locale'] ?? $raw['lang'] ?? null),
                ];
                $eligible = ($event->analytics_eligible ?? null) === null
                    ? ! $this->trafficExclusionPolicy->isExcludedSeoConversionEvent($event, $meta)
                    : (bool) $event->analytics_eligible;
                $reason = trim((string) ($event->analytics_exclusion_reason ?? ''));
                $suspected = $reason === 'suspected_traffic';
                $eventKey = trim((string) ($event->request_id ?? '')) ?: (string) $event->id;
                $ipHash = $this->validHash($event->analytics_ip_hash ?? null);

                foreach ($this->dimensionKeys($dimensions) as $key) {
                    $bucket = &$this->bucket($buckets, $key);
                    if ($eligible) {
                        $bucket['page_view_keys'][$eventKey] = true;
                        if ($ipHash !== null) {
                            $bucket['visit_ips'][$ipHash] = true;
                        } else {
                            $bucket['missing_visit_ip_events']++;
                        }
                    } elseif ($suspected) {
                        $bucket['suspected_page_views']++;
                    } else {
                        $bucket['excluded_page_views']++;
                    }
                    unset($bucket);
                }

                $latestAt = $this->latest($latestAt, $event->occurred_at ?? null);
            }
        }

        if (SchemaBaseline::hasTable('attempts')) {
            $completed = $this->completedAttemptTimes($startUtc, $endUtc, $orgId);
            $attempts = DB::table('attempts')
                ->where('org_id', $orgId)
                ->where(function ($query) use ($startUtc, $endUtc, $completed): void {
                    $query->where(function ($started) use ($startUtc, $endUtc): void {
                        $started->where('started_at', '>=', $startUtc)->where('started_at', '<', $endUtc);
                    })->orWhere(function ($submitted) use ($startUtc, $endUtc): void {
                        $submitted->where('submitted_at', '>=', $startUtc)->where('submitted_at', '<', $endUtc);
                    });
                    if ($completed !== []) {
                        $query->orWhereIn('id', array_keys($completed));
                    }
                })
                ->get()
                ->keyBy('id');

            foreach ($attempts as $attempt) {
                $meta = $this->jsonArray($attempt->answers_summary_json ?? null);
                $dimensions = [
                    'scale_code' => $this->dimension($attempt->scale_code ?? null),
                    'form_code' => $this->dimension($attempt->form_code ?? data_get($meta, 'meta.form_code')),
                    'locale' => $this->locale($attempt->locale ?? null),
                ];
                $excludedLegacy = $this->trafficExclusionPolicy->isExcludedAttemptRow($attempt);

                if ($this->within($attempt->started_at ?? null, $startUtc, $endUtc)) {
                    $hasLegacyTestRows = $hasLegacyTestRows || ($attempt->analytics_start_eligible ?? null) === null;
                    $eligible = ($attempt->analytics_start_eligible ?? null) === null
                        ? ! $excludedLegacy
                        : (bool) $attempt->analytics_start_eligible;
                    $reason = trim((string) ($attempt->analytics_start_exclusion_reason ?? ''));
                    $ipHash = $this->validHash($attempt->analytics_start_ip_hash ?? null);
                    foreach ($this->dimensionKeys($dimensions) as $key) {
                        $bucket = &$this->bucket($buckets, $key);
                        if ($eligible && $ipHash !== null) {
                            $bucket['started_ips'][$ipHash] = true;
                        } elseif ($eligible) {
                            $bucket['missing_started_ip_attempts']++;
                        } elseif ($reason === 'suspected_traffic') {
                            $bucket['suspected_attempts']++;
                        } else {
                            $bucket['excluded_started_attempts']++;
                        }
                        unset($bucket);
                    }
                    $latestAt = $this->latest($latestAt, $attempt->started_at);
                }

                $attemptId = (string) $attempt->id;
                if (! isset($completed[$attemptId])) {
                    continue;
                }

                $hasLegacyTestRows = $hasLegacyTestRows || ($attempt->analytics_submit_eligible ?? null) === null;
                $eligible = ($attempt->analytics_submit_eligible ?? null) === null
                    ? ! $excludedLegacy
                    : (bool) $attempt->analytics_submit_eligible;
                $reason = trim((string) ($attempt->analytics_submit_exclusion_reason ?? ''));
                $ipHash = $this->validHash($attempt->analytics_submit_ip_hash ?? null);
                foreach ($this->dimensionKeys($dimensions) as $key) {
                    $bucket = &$this->bucket($buckets, $key);
                    if ($eligible) {
                        $bucket['successful_attempt_ids'][$attemptId] = true;
                        if ($ipHash !== null) {
                            $bucket['completed_ips'][$ipHash] = true;
                        } else {
                            $bucket['missing_completed_ip_attempts']++;
                        }
                    } elseif ($reason === 'suspected_traffic') {
                        $bucket['suspected_attempts']++;
                    } else {
                        $bucket['excluded_completed_attempts']++;
                    }
                    unset($bucket);
                }
                $latestAt = $this->latest($latestAt, $completed[$attemptId]);
            }
        }

        $globalKey = $this->key('*', '*', '*');
        $this->bucket($buckets, $globalKey);
        $now = now();
        $collectionStart = CarbonImmutable::parse(
            (string) config('analytics.access_test_statistics.collection_started_on', '2026-09-18'),
            AccessTestIdentity::TIMEZONE
        )->startOfDay();
        $coverage = $day->lt($collectionStart)
            ? ($hasLegacyTestRows ? 'backfilled_partial' : 'not_collected')
            : ($day->isSameDay(CarbonImmutable::now(AccessTestIdentity::TIMEZONE)) ? 'partial_today' : 'complete');

        $rows = [];
        foreach ($buckets as $key => $bucket) {
            [$scaleCode, $formCode, $locale] = explode('|', $key, 3);
            $rows[] = [
                'day' => $day->toDateString(),
                'org_id' => $orgId,
                'scale_code' => $scaleCode,
                'form_code' => $formCode,
                'locale' => $locale,
                'valid_visit_ips' => count($bucket['visit_ips']),
                'started_test_ips' => count($bucket['started_ips']),
                'completed_test_ips' => count($bucket['completed_ips']),
                'successful_attempts' => count($bucket['successful_attempt_ids']),
                'valid_page_views' => count($bucket['page_view_keys']),
                'missing_visit_ip_events' => $bucket['missing_visit_ip_events'],
                'missing_started_ip_attempts' => $bucket['missing_started_ip_attempts'],
                'missing_completed_ip_attempts' => $bucket['missing_completed_ip_attempts'],
                'excluded_page_views' => $bucket['excluded_page_views'],
                'excluded_started_attempts' => $bucket['excluded_started_attempts'],
                'excluded_completed_attempts' => $bucket['excluded_completed_attempts'],
                'suspected_page_views' => $bucket['suspected_page_views'],
                'suspected_attempts' => $bucket['suspected_attempts'],
                'coverage_status' => $coverage,
                'source_version' => AccessTestIdentity::RULE_VERSION,
                'data_through_at' => $latestAt,
                'last_successful_refresh_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function &bucket(array &$buckets, string $key): array
    {
        if (! isset($buckets[$key])) {
            $buckets[$key] = [
                'visit_ips' => [], 'started_ips' => [], 'completed_ips' => [],
                'successful_attempt_ids' => [], 'page_view_keys' => [],
                'missing_visit_ip_events' => 0, 'missing_started_ip_attempts' => 0,
                'missing_completed_ip_attempts' => 0, 'excluded_page_views' => 0,
                'excluded_started_attempts' => 0, 'excluded_completed_attempts' => 0,
                'suspected_page_views' => 0, 'suspected_attempts' => 0,
            ];
        }

        return $buckets[$key];
    }

    /** @param array{scale_code:string,form_code:string,locale:string} $dimensions
     * @return list<string>
     */
    private function dimensionKeys(array $dimensions): array
    {
        $keys = [];
        $scales = $dimensions['scale_code'] === '' ? ['*'] : ['*', $dimensions['scale_code']];
        $forms = $dimensions['form_code'] === '' ? ['*'] : ['*', $dimensions['form_code']];
        $locales = $dimensions['locale'] === '' ? ['*'] : ['*', $dimensions['locale']];
        foreach ($scales as $scale) {
            foreach ($forms as $form) {
                foreach ($locales as $locale) {
                    $keys[$this->key($scale, $form, $locale)] = true;
                }
            }
        }

        return array_keys($keys);
    }

    private function key(string $scale, string $form, string $locale): string
    {
        return implode('|', [$scale, $form, $locale]);
    }

    /** @return array<string,mixed> */
    private function completedAttemptTimes(CarbonImmutable $startUtc, CarbonImmutable $endUtc, int $orgId): array
    {
        if (! SchemaBaseline::hasTable('results')) {
            return [];
        }

        $timeColumn = SchemaBaseline::hasColumn('results', 'computed_at') ? 'computed_at' : 'created_at';
        $rows = DB::table('results')
            ->where('org_id', $orgId)
            ->when(
                SchemaBaseline::hasColumn('results', 'is_valid'),
                static fn ($query) => $query->where('is_valid', true)
            )
            ->where($timeColumn, '>=', $startUtc)
            ->where($timeColumn, '<', $endUtc)
            ->orderBy($timeColumn)
            ->get(['attempt_id', $timeColumn]);

        $completed = [];
        foreach ($rows as $row) {
            $completed[(string) $row->attempt_id] ??= $row->{$timeColumn};
        }

        return $completed;
    }

    /** @param list<int> $requested
     * @return list<int>
     */
    private function resolveOrgScope(CarbonImmutable $from, CarbonImmutable $to, array $requested): array
    {
        $scope = array_values(array_unique(array_map('intval', $requested)));
        if ($scope !== []) {
            sort($scope);

            return $scope;
        }

        $ids = [0];
        foreach (['attempts', 'events'] as $table) {
            if (! SchemaBaseline::hasTable($table) || ! SchemaBaseline::hasColumn($table, 'org_id')) {
                continue;
            }
            $column = $table === 'attempts' ? 'created_at' : 'occurred_at';
            $values = DB::table($table)
                ->where($column, '>=', $from->utc())
                ->where($column, '<', $to->addDay()->utc())
                ->distinct()
                ->pluck('org_id')
                ->map(static fn (mixed $value): int => max(0, (int) $value))
                ->all();
            array_push($ids, ...$values);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function within(mixed $value, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($value === null) {
            return false;
        }
        $time = CarbonImmutable::parse($value, 'UTC');

        return $time->gte($start) && $time->lt($end);
    }

    private function latest(mixed $current, mixed $candidate): mixed
    {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null || CarbonImmutable::parse($candidate)->gt(CarbonImmutable::parse($current))) {
            return $candidate;
        }

        return $current;
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function dimension(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function locale(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : ($normalized === 'en' ? 'en' : 'unknown');
    }

    private function validHash(mixed $value): ?string
    {
        $hash = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }
}
