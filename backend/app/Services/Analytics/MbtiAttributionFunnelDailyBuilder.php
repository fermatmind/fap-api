<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Order;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MbtiAttributionFunnelDailyBuilder
{
    /**
     * @var array<string, string>
     */
    private const ENTRY_EVENT_METRIC_MAP = [
        'landing_view' => 'entry_views',
        'start_click' => 'start_clicks',
    ];

    /**
     * @var array<string, string>
     */
    private const ATTEMPT_EVENT_METRIC_MAP = [
        'view_result' => 'result_views',
        'click_unlock' => 'unlock_clicks',
        'invite_create_success' => 'invite_creates',
        'invite_share_or_copy' => 'invite_shares',
        'invite_unlock_completion_qualified' => 'invite_completions',
    ];

    /**
     * @var list<string>
     */
    private const EVENT_CODES = [
        'landing_view',
        'start_click',
        'view_result',
        'click_unlock',
        'invite_create_success',
        'invite_share_or_copy',
        'invite_unlock_completion_qualified',
        'invite_unlock_partial_granted',
        'invite_unlock_full_granted',
    ];

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:list<array<string,mixed>>,attempted_rows:int,org_scope:list<int>,from:string,to:string}
     */
    public function build(\DateTimeInterface $from, \DateTimeInterface $to, array $orgIds = []): array
    {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);

        $startAttemptRows = $this->loadMbtiAttemptsStartedInRange($fromAt, $toAt, $normalizedOrgIds);
        $eventRows = $this->loadMbtiEventsInRange($fromAt, $toAt, $normalizedOrgIds);
        $orderRows = $this->loadOrderRowsInRange($fromAt, $toAt, $normalizedOrgIds);

        $candidateAttemptIds = [];
        foreach ($startAttemptRows as $row) {
            $attemptId = trim((string) ($row->id ?? ''));
            if ($attemptId !== '') {
                $candidateAttemptIds[$attemptId] = true;
            }
        }
        foreach ($eventRows as $row) {
            $meta = $this->decodeJson($row->meta_json ?? null);
            $attemptId = $this->resolveAttemptId($row->attempt_id ?? null, $meta);
            if ($attemptId !== null) {
                $candidateAttemptIds[$attemptId] = true;
            }
        }
        foreach ($orderRows as $row) {
            $attemptId = trim((string) ($row->target_attempt_id ?? ''));
            if ($attemptId !== '') {
                $candidateAttemptIds[$attemptId] = true;
            }
        }

        $attemptDimensions = $this->loadAttemptDimensions(array_keys($candidateAttemptIds), $normalizedOrgIds);
        $rows = [];

        foreach ($startAttemptRows as $row) {
            $attemptId = trim((string) ($row->id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $dimensions = $attemptDimensions[$attemptId] ?? null;
            if ($dimensions === null) {
                continue;
            }

            $day = $this->normalizeDay($row->created_at ?? null);
            if ($day === null) {
                continue;
            }

            $this->incrementMetric($rows, $day, $dimensions, 'start_attempts', 1);
        }

        foreach ($eventRows as $row) {
            $eventCode = strtolower(trim((string) ($row->event_code ?? '')));
            if ($eventCode === '') {
                continue;
            }

            $day = $this->normalizeDay($row->occurred_at ?? null);
            if ($day === null) {
                continue;
            }

            $meta = $this->decodeJson($row->meta_json ?? null);
            $orgId = max(0, (int) ($row->org_id ?? 0));
            $eventLocale = $this->normalizeLocale($row->locale ?? null);

            if (array_key_exists($eventCode, self::ENTRY_EVENT_METRIC_MAP)) {
                $dimensions = $this->resolveEntryDimensionsFromMeta($meta, $orgId, $eventLocale);
                if (! $this->isMbtiSurface($dimensions['entry_surface'])) {
                    continue;
                }

                $this->incrementMetric($rows, $day, $dimensions, self::ENTRY_EVENT_METRIC_MAP[$eventCode], 1);
                continue;
            }

            $attemptId = $this->resolveAttemptId($row->attempt_id ?? null, $meta);
            if ($attemptId === null) {
                continue;
            }

            $dimensions = $attemptDimensions[$attemptId] ?? null;
            if ($dimensions === null) {
                continue;
            }

            if (array_key_exists($eventCode, self::ATTEMPT_EVENT_METRIC_MAP)) {
                $this->incrementMetric($rows, $day, $dimensions, self::ATTEMPT_EVENT_METRIC_MAP[$eventCode], 1);
            }

            if (in_array($eventCode, ['invite_unlock_partial_granted', 'invite_unlock_full_granted'], true)) {
                $this->incrementMetric($rows, $day, $dimensions, 'invite_unlock_successes', 1);
                $this->incrementMetric($rows, $day, $dimensions, 'unlock_successes', 1);
            }
        }

        foreach ($orderRows as $row) {
            $attemptId = trim((string) ($row->target_attempt_id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $dimensions = $attemptDimensions[$attemptId] ?? null;
            if ($dimensions === null) {
                continue;
            }

            $createdDay = $this->normalizeDay($row->created_at ?? null);
            if ($createdDay !== null) {
                $this->incrementMetric($rows, $createdDay, $dimensions, 'orders_created', 1);
            }

            $paidDay = $this->normalizeDay($row->paid_at ?? null);
            if ($paidDay !== null && $this->isPaidOrder($row)) {
                $this->incrementMetric($rows, $paidDay, $dimensions, 'payments_confirmed', 1);
            }

            $unlockDay = $this->resolveOrderUnlockDay($row);
            if ($unlockDay !== null) {
                $this->incrementMetric($rows, $unlockDay, $dimensions, 'payment_unlock_successes', 1);
                $this->incrementMetric($rows, $unlockDay, $dimensions, 'unlock_successes', 1);
            }
        }

        $finalRows = array_values(array_map(function (array $row): array {
            $row['updated_at'] = now();
            $row['last_refreshed_at'] = now();

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

                DB::table('analytics_mbti_attribution_daily')->upsert(
                    $rows,
                    ['day', 'org_id', 'locale', 'entry_surface', 'source_page_type', 'test_slug', 'form_code'],
                    [
                        'entry_views',
                        'start_clicks',
                        'start_attempts',
                        'result_views',
                        'unlock_clicks',
                        'orders_created',
                        'payments_confirmed',
                        'unlock_successes',
                        'payment_unlock_successes',
                        'invite_creates',
                        'invite_shares',
                        'invite_completions',
                        'invite_unlock_successes',
                        'last_refreshed_at',
                        'updated_at',
                    ]
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
     * @param  list<int>  $orgIds
     * @return list<object>
     */
    private function loadMbtiAttemptsStartedInRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $orgIds,
    ): array {
        if (! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $query = DB::table('attempts')
            ->whereBetween('created_at', [$from, $to])
            ->select(['id', 'org_id', 'locale', 'answers_summary_json', 'created_at', 'scale_code']);

        if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
            $query->addSelect('scale_code_v2');
        }

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        $this->applyMbtiAttemptFilter($query);

        return $query->get()->all();
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<object>
     */
    private function loadMbtiEventsInRange(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        [$sql, $bindings] = $this->lowerInSql('event_code', self::EVENT_CODES);

        $query = DB::table('events')
            ->whereBetween('occurred_at', [$from, $to])
            ->whereRaw($sql, $bindings)
            ->select(['id', 'org_id', 'event_code', 'attempt_id', 'meta_json', 'occurred_at', 'locale']);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        return $query->get()->all();
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<object>
     */
    private function loadOrderRowsInRange(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('orders')) {
            return [];
        }

        $query = DB::table('orders')
            ->whereNotNull('target_attempt_id')
            ->where(function ($nested) use ($from, $to): void {
                $nested->whereBetween('created_at', [$from, $to])
                    ->orWhereBetween('paid_at', [$from, $to]);

                if (SchemaBaseline::hasColumn('orders', 'fulfilled_at')) {
                    $nested->orWhereBetween('fulfilled_at', [$from, $to]);
                }
            });

        $columns = [
            'target_attempt_id',
            'org_id',
            'status',
            'created_at',
            'paid_at',
        ];
        if (SchemaBaseline::hasColumn('orders', 'payment_state')) {
            $columns[] = 'payment_state';
        }
        if (SchemaBaseline::hasColumn('orders', 'grant_state')) {
            $columns[] = 'grant_state';
        }
        if (SchemaBaseline::hasColumn('orders', 'fulfilled_at')) {
            $columns[] = 'fulfilled_at';
        }

        $query->select($columns);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        return $query->get()->all();
    }

    /**
     * @param  list<string>  $attemptIds
     * @param  list<int>  $orgIds
     * @return array<string,array{org_id:int,locale:string,entry_surface:string,source_page_type:string,test_slug:string,form_code:string}>
     */
    private function loadAttemptDimensions(array $attemptIds, array $orgIds): array
    {
        if ($attemptIds === [] || ! SchemaBaseline::hasTable('attempts')) {
            return [];
        }

        $dimensions = [];

        foreach (array_chunk($attemptIds, 500) as $chunk) {
            $query = DB::table('attempts')
                ->whereIn('id', $chunk)
                ->select(['id', 'org_id', 'locale', 'answers_summary_json', 'scale_code']);

            if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
                $query->addSelect('scale_code_v2');
            }

            if ($orgIds !== []) {
                $query->whereIn('org_id', $orgIds);
            }

            $this->applyMbtiAttemptFilter($query);

            foreach ($query->get() as $row) {
                $attemptId = trim((string) ($row->id ?? ''));
                if ($attemptId === '') {
                    continue;
                }

                $summary = $this->decodeJson($row->answers_summary_json ?? null);
                $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];

                $entrySurface = $this->normalizeDimension($meta['entry_surface'] ?? $meta['entrypoint'] ?? null, 'unknown');
                $sourcePageType = $this->normalizeDimension($meta['source_page_type'] ?? null, $this->guessSourcePageType($entrySurface));
                $testSlug = $this->normalizeDimension($meta['test_slug'] ?? null, '');
                $formCode = $this->normalizeDimension($meta['form_code'] ?? null, '');

                if (! $this->isMbtiSurface($entrySurface)) {
                    $entrySurface = 'mbti_unknown';
                    if ($sourcePageType === 'unknown') {
                        $sourcePageType = 'unknown';
                    }
                }

                $dimensions[$attemptId] = [
                    'org_id' => max(0, (int) ($row->org_id ?? 0)),
                    'locale' => $this->normalizeLocale($row->locale ?? null),
                    'entry_surface' => $entrySurface,
                    'source_page_type' => $sourcePageType,
                    'test_slug' => $testSlug,
                    'form_code' => $formCode,
                ];
            }
        }

        return $dimensions;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array{org_id:int,locale:string,entry_surface:string,source_page_type:string,test_slug:string,form_code:string}
     */
    private function resolveEntryDimensionsFromMeta(array $meta, int $orgId, string $locale): array
    {
        $entrySurface = $this->normalizeDimension($meta['entry_surface'] ?? null, 'unknown');
        $sourcePageType = $this->normalizeDimension($meta['source_page_type'] ?? null, $this->guessSourcePageType($entrySurface));

        return [
            'org_id' => max(0, $orgId),
            'locale' => $locale,
            'entry_surface' => $entrySurface,
            'source_page_type' => $sourcePageType,
            'test_slug' => $this->normalizeDimension($meta['test_slug'] ?? null, ''),
            'form_code' => $this->normalizeDimension($meta['form_code'] ?? null, ''),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array{org_id:int,locale:string,entry_surface:string,source_page_type:string,test_slug:string,form_code:string}  $dimensions
     */
    private function incrementMetric(array &$rows, string $day, array $dimensions, string $metric, int $delta): void
    {
        $key = implode('|', [
            $day,
            (string) $dimensions['org_id'],
            $dimensions['locale'],
            $dimensions['entry_surface'],
            $dimensions['source_page_type'],
            $dimensions['test_slug'],
            $dimensions['form_code'],
        ]);

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'day' => $day,
                'org_id' => $dimensions['org_id'],
                'locale' => $dimensions['locale'],
                'entry_surface' => $dimensions['entry_surface'],
                'source_page_type' => $dimensions['source_page_type'],
                'test_slug' => $dimensions['test_slug'],
                'form_code' => $dimensions['form_code'],
                'entry_views' => 0,
                'start_clicks' => 0,
                'start_attempts' => 0,
                'result_views' => 0,
                'unlock_clicks' => 0,
                'orders_created' => 0,
                'payments_confirmed' => 0,
                'unlock_successes' => 0,
                'payment_unlock_successes' => 0,
                'invite_creates' => 0,
                'invite_shares' => 0,
                'invite_completions' => 0,
                'invite_unlock_successes' => 0,
                'created_at' => now(),
            ];
        }

        $rows[$key][$metric] = max(0, (int) ($rows[$key][$metric] ?? 0) + $delta);
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

    private function isPaidOrder(object $row): bool
    {
        $paymentState = strtolower(trim((string) ($row->payment_state ?? '')));
        $status = strtolower(trim((string) ($row->status ?? '')));

        return in_array($paymentState, [Order::PAYMENT_STATE_PAID], true)
            || in_array($status, [Order::STATUS_PAID, Order::STATUS_FULFILLED], true);
    }

    private function resolveOrderUnlockDay(object $row): ?string
    {
        $grantState = strtolower(trim((string) ($row->grant_state ?? '')));
        $status = strtolower(trim((string) ($row->status ?? '')));

        if ($grantState === Order::GRANT_STATE_GRANTED || $status === Order::STATUS_FULFILLED) {
            $fulfilledDay = $this->normalizeDay($row->fulfilled_at ?? null);
            if ($fulfilledDay !== null) {
                return $fulfilledDay;
            }

            return $this->normalizeDay($row->paid_at ?? null);
        }

        return null;
    }

    private function resolveAttemptId(mixed $rowAttemptId, array $meta): ?string
    {
        $direct = $this->normalizeOptionalString($rowAttemptId, 64);
        if ($direct !== null) {
            return $direct;
        }

        return $this->normalizeOptionalString(
            $meta['target_attempt_id'] ?? $meta['attempt_id'] ?? null,
            64
        );
    }

    private function normalizeLocale(mixed $value): string
    {
        $locale = strtolower(trim((string) ($value ?? '')));

        if ($locale === '') {
            return 'en';
        }

        return $locale;
    }

    private function normalizeDimension(mixed $value, string $fallback): string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return $fallback;
        }

        return mb_substr($normalized, 0, 128, 'UTF-8');
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
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
     * @param  list<int>  $orgIds
     */
    private function deleteScope(string $from, string $to, array $orgIds): int
    {
        $query = DB::table('analytics_mbti_attribution_daily')
            ->whereBetween('day', [$from, $to]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        return (int) $query->delete();
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            $orgIds
        ), static fn (int $value): bool => $value >= 0)));

        sort($normalized);

        return $normalized;
    }

    private function isMbtiSurface(string $entrySurface): bool
    {
        return str_starts_with($entrySurface, 'mbti_');
    }

    private function guessSourcePageType(string $entrySurface): string
    {
        if (str_starts_with($entrySurface, 'mbti_')) {
            $guess = str_replace('mbti_', '', $entrySurface);

            return $guess !== '' ? $guess : 'unknown';
        }

        return 'unknown';
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyMbtiAttemptFilter($query): void
    {
        $query->where(function ($nested): void {
            $nested->whereRaw("LOWER(COALESCE(scale_code, '')) = ?", ['mbti']);

            if (SchemaBaseline::hasColumn('attempts', 'scale_code_v2')) {
                $nested->orWhereRaw("LOWER(COALESCE(scale_code_v2, '')) = ?", ['mbti']);
            }
        });
    }

    /**
     * @param  list<string>  $values
     * @return array{0:string,1:list<string>}
     */
    private function lowerInSql(string $column, array $values): array
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [
            "LOWER(COALESCE({$column}, '')) IN ({$placeholders})",
            array_map(static fn (string $value): string => strtolower(trim($value)), $values),
        ];
    }
}
