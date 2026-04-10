<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CareerAttributionDailyBuilder
{
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
                    ['day', 'org_id', 'locale', 'surface', 'route_family', 'event_name', 'subject_kind', 'subject_key', 'readiness_class', 'query_mode'],
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
