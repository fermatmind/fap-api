<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CareerAttributionDailyBuilder
{
    /** Live action detail complements the historical daily table without rewriting it. */
    public function actions(int $orgId, string $from, string $to, bool $includeQa = false): array
    {
        $fromAt = CarbonImmutable::parse($from, 'Asia/Shanghai')->startOfDay()->utc();
        $toAt = CarbonImmutable::parse($to, 'Asia/Shanghai')->addDay()->startOfDay()->utc();
        $policy = app(AnalyticsTrafficExclusionPolicy::class);
        $rows = [];
        $excluded = 0;
        $toolRuns = [];
        $firstV2 = null;
        foreach (DB::table('events')->where('org_id', $orgId)->where('scale_code', 'CAREER')
            ->whereIn('event_name', CareerAttributionEventMapper::ALLOWED_EVENT_NAMES)
            ->where('occurred_at', '>=', $fromAt)->where('occurred_at', '<', $toAt)
            ->orderBy('id')->cursor() as $event) {
            $meta = json_decode((string) $event->meta_json, true) ?: [];
            $isQa = $policy->isExcludedSeoConversionEvent($event, $meta)
                || in_array($meta['traffic_quality'] ?? null, ['qa', 'internal', 'bot'], true)
                || ($meta['utm_source'] ?? null) === 'fermatmind_qa';
            if ($isQa && ! $includeQa) {
                $excluded++;

                continue;
            }
            $action = (string) ($meta['target_action'] ?? '');
            $bucket = in_array($action, CareerAttributionEventMapper::DETAIL_ACTIONS, true) ? $action
                : (in_array($action, ['open_next_step_link', 'open_transition_cta', 'open_primary_cta', 'open_support_link'], true)
                    ? 'historical_unspecified' : 'other_or_unknown');
            if (($meta['action_contract_version'] ?? null) === CareerAttributionEventMapper::ACTION_CONTRACT_VERSION) {
                $firstV2 = $firstV2 === null ? $event->created_at : min($firstV2, $event->created_at);
            }
            $dimensions = [
                'day' => CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone('Asia/Shanghai')->toDateString(),
                'locale' => $event->locale,
                'event_name' => $event->event_name,
                'target_action' => preg_match('/\A[a-z0-9_]{1,128}\z/', $action) ? $action : 'unknown',
                'action_bucket' => $bucket,
                'output_format' => in_array($meta['output_format'] ?? null, ['text', 'markdown', 'print'], true) ? $meta['output_format'] : 'none',
                'resource_key' => in_array($meta['resource_key'] ?? null, ['onet_interest_profiler', 'mynextmove', 'onet_online', 'england_profiles', 'england_courses', 'england_skills', 'england_support'], true) ? $meta['resource_key'] : 'unknown',
                'traffic_scope' => $isQa ? 'qa_or_excluded' : 'eligible_or_unknown',
            ];
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $key) {
                $value = (string) ($meta[$key] ?? '');
                $dimensions[$key] = preg_match('/\A[A-Za-z0-9._-]{1,96}\z/', $value) ? $value : 'unknown';
            }
            $key = json_encode($dimensions);
            $rows[$key] ??= $dimensions + ['event_count' => 0, 'identified_run_count' => 0, 'unknown_run_count' => 0, '__runs' => []];
            $rows[$key]['event_count']++;
            $run = (string) ($meta['tool_run_id'] ?? '');
            if (\Illuminate\Support\Str::isUuid($run)) {
                $rows[$key]['__runs'][$run] = true;
                $toolRuns[$event->event_name][$run] = true;
            } else {
                $rows[$key]['unknown_run_count']++;
            }
        }
        $final = array_values(array_map(static function (array $row): array {
            $row['identified_run_count'] = count($row['__runs']);
            unset($row['__runs']);

            return $row;
        }, $rows));
        $tools = [];
        foreach (CareerAttributionEventMapper::TOOL_EVENTS as $event) {
            $entry = str_starts_with($event, 'career_compare_') ? 'career_compare_entered' : 'career_worksheet_entered';
            $runs = $toolRuns[$event] ?? [];
            $entries = $toolRuns[$entry] ?? [];
            $tools[$event] = [
                'distinct_observed_runs' => count($runs),
                'matched_entered_runs' => count(array_intersect_key($runs, $entries)),
                'unmatched_runs' => count(array_diff_key($runs, $entries)),
                'entered_denominator' => count($entries),
            ];
        }

        return [
            'ok' => true, 'schema_version' => 'career.action-read-model.v2',
            'action_contract_version' => CareerAttributionEventMapper::ACTION_CONTRACT_VERSION,
            'from' => $from, 'to' => $to, 'org_id' => $orgId,
            'as_of' => now()->toIso8601String(), 'reporting_timezone' => 'Asia/Shanghai',
            'definition_first_received_at_in_scope' => $firstV2,
            'historical_policy' => 'retain_original_D0_and_unspecified_actions_no_backfill',
            'qa_included' => $includeQa, 'excluded_event_count' => $excluded,
            'rows' => $final, 'tools' => $tools,
            'tool_window' => 'observations_in_selected_calendar_interval_not_test_attempts',
            'read_only' => true,
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:list<array<string,mixed>>,attempted_rows:int,org_scope:list<int>,from:string,to:string}
     */
    public function build(\DateTimeInterface $from, \DateTimeInterface $to, array $orgIds = []): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [
                'rows' => [],
                'attempted_rows' => 0,
                'org_scope' => [],
                'from' => CarbonImmutable::parse($from)->toDateString(),
                'to' => CarbonImmutable::parse($to)->toDateString(),
            ];
        }

        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $readinessBySlug = $this->loadReadinessBySlug();

        $query = DB::table('events')
            ->select(['event_name', 'org_id', 'locale', 'anon_id', 'session_id', 'occurred_at', 'meta_json'])
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->where('scale_code', 'CAREER')
            ->whereIn('event_name', CareerAttributionEventMapper::ALLOWED_EVENT_NAMES)
            ->orderBy('occurred_at');

        if ($normalizedOrgIds !== []) {
            $query->whereIn('org_id', $normalizedOrgIds);
        }

        $rows = [];

        foreach ($query->get() as $event) {
            $day = $this->normalizeDay($event->occurred_at ?? null);
            if ($day === null) {
                continue;
            }

            $meta = is_array($event->meta_json ?? null)
                ? $event->meta_json
                : (json_decode((string) ($event->meta_json ?? '{}'), true) ?: []);

            $surface = $this->normalizeDimension($meta['entry_surface'] ?? null, 128, 'unknown');
            $sourcePageType = $this->normalizeSourcePageType($meta['source_page_type'] ?? null);
            $routeFamily = $this->normalizeDimension($meta['route_family'] ?? null, 64, 'unknown');
            $subjectKind = $this->normalizeDimension($meta['subject_kind'] ?? null, 32, 'none');
            $subjectKey = $subjectKind === 'none'
                ? ''
                : $this->normalizeDimension($meta['subject_key'] ?? null, 128, '');
            $queryMode = $this->normalizeDimension($meta['query_mode'] ?? null, 16, 'non_query');
            $readinessClass = $this->deriveReadinessClass($subjectKind, $subjectKey, $readinessBySlug);
            $locale = $this->normalizeDimension($event->locale ?? null, 16, 'en');
            $eventName = $this->normalizeDimension($event->event_name ?? null, 64, '');

            if ($eventName === '') {
                continue;
            }

            $key = implode('|', [
                $day,
                max(0, (int) ($event->org_id ?? 0)),
                $locale,
                $surface,
                $sourcePageType,
                $routeFamily,
                $eventName,
                $subjectKind,
                $subjectKey,
                $readinessClass,
                $queryMode,
            ]);

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'day' => $day,
                    'org_id' => max(0, (int) ($event->org_id ?? 0)),
                    'locale' => $locale,
                    'surface' => $surface,
                    'source_page_type' => $sourcePageType,
                    'route_family' => $routeFamily,
                    'event_name' => $eventName,
                    'subject_kind' => $subjectKind,
                    'subject_key' => $subjectKey,
                    'readiness_class' => $readinessClass,
                    'query_mode' => $queryMode,
                    'event_count' => 0,
                    'unique_anon_count' => 0,
                    'unique_session_count' => 0,
                    '__anon_ids' => [],
                    '__session_ids' => [],
                ];
            }

            $rows[$key]['event_count']++;

            $anonId = trim((string) ($event->anon_id ?? ''));
            if ($anonId !== '') {
                $rows[$key]['__anon_ids'][$anonId] = true;
            }

            $sessionId = trim((string) ($event->session_id ?? ''));
            if ($sessionId !== '') {
                $rows[$key]['__session_ids'][$sessionId] = true;
            }
        }

        $now = now();
        $finalRows = array_values(array_map(function (array $row) use ($now): array {
            $row['unique_anon_count'] = count($row['__anon_ids']);
            $row['unique_session_count'] = count($row['__session_ids']);
            unset($row['__anon_ids'], $row['__session_ids']);
            $row['last_refreshed_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows));

        return [
            'rows' => $finalRows,
            'attempted_rows' => count($finalRows),
            'org_scope' => $normalizedOrgIds,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:list<array<string,mixed>>,attempted_rows:int,deleted_rows:int,upserted_rows:int,org_scope:list<int>,from:string,to:string,dry_run:bool}
     */
    public function refresh(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        bool $dryRun = false,
    ): array {
        $payload = $this->build($from, $to, $orgIds);
        $rows = $payload['rows'];
        $deletedRows = 0;
        $upsertedRows = 0;

        if (! $dryRun) {
            DB::transaction(function () use ($payload, $rows, &$deletedRows, &$upsertedRows): void {
                $deletedRows = $this->deleteScope($payload['from'], $payload['to'], $payload['org_scope']);

                if ($rows === []) {
                    return;
                }

                DB::table('analytics_career_attribution_daily')->upsert(
                    $rows,
                    ['day', 'org_id', 'locale', 'surface', 'source_page_type', 'route_family', 'event_name', 'subject_kind', 'subject_key', 'readiness_class', 'query_mode'],
                    ['event_count', 'unique_anon_count', 'unique_session_count', 'last_refreshed_at', 'updated_at']
                );

                $upsertedRows = count($rows);
            });
        }

        return $payload + [
            'deleted_rows' => $deletedRows,
            'upserted_rows' => $upsertedRows,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loadReadinessBySlug(): array
    {
        try {
            $summary = app(FirstWaveReadinessSummaryService::class)->build()->toArray();
        } catch (\Throwable) {
            return [];
        }

        $map = [];

        foreach ((array) ($summary['occupations'] ?? []) as $occupation) {
            if (! is_array($occupation)) {
                continue;
            }

            $slug = trim((string) ($occupation['canonical_slug'] ?? ''));
            $status = trim((string) ($occupation['status'] ?? ''));
            if ($slug === '' || $status === '') {
                continue;
            }

            $map[$slug] = $status;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $readinessBySlug
     */
    private function deriveReadinessClass(string $subjectKind, string $subjectKey, array $readinessBySlug): string
    {
        if ($subjectKind === 'family_slug') {
            return 'unknown';
        }

        if ($subjectKind !== 'job_slug' || $subjectKey === '') {
            return 'unknown';
        }

        return $readinessBySlug[$subjectKey] ?? 'unknown';
    }

    private function normalizeDay(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDimension(mixed $value, int $maxLength, string $fallback): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return $fallback;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }

    private function normalizeSourcePageType(mixed $value): string
    {
        $normalized = strtolower($this->normalizeDimension($value, 64, 'unknown'));

        return match ($normalized) {
            'career_job_index' => 'job_index',
            'career_job_detail' => 'job_detail',
            'career_recommendation_index' => 'recommendation_index',
            'career_recommendation_detail' => 'recommendation_detail',
            'career_family_hub' => 'family_hub',
            'career_alias_disambiguation' => 'alias_disambiguation',
            default => $normalized,
        };
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            $orgIds
        ), static fn (int $value): bool => $value >= 0));

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function deleteScope(string $from, string $to, array $orgIds): int
    {
        if (! SchemaBaseline::hasTable('analytics_career_attribution_daily')) {
            return 0;
        }

        $query = DB::table('analytics_career_attribution_daily')
            ->whereBetween('day', [$from, $to]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        return $query->delete();
    }
}
