<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SeoConversionDailyBuilder
{
    private const TABLE = 'analytics_seo_conversion_daily';

    /**
     * @var array<string, string>
     */
    private const EVENT_METRIC_MAP = [
        'landing_pv' => 'landing_pv_count',
        'article_to_test_click' => 'article_to_test_click_count',
        'start_test' => 'start_test_count',
        'complete_test' => 'complete_test_count',
        'view_result' => 'view_result_count',
    ];

    /**
     * @var list<string>
     */
    private const PRIVATE_PATH_SEGMENTS = [
        'result',
        'results',
        'order',
        'orders',
        'share',
        'shares',
        'pay',
        'payment',
        'payments',
        'history',
    ];

    private readonly AnalyticsTrafficExclusionPolicy $trafficExclusionPolicy;

    public function __construct(?AnalyticsTrafficExclusionPolicy $trafficExclusionPolicy = null)
    {
        $this->trafficExclusionPolicy = $trafficExclusionPolicy ?? new AnalyticsTrafficExclusionPolicy;
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:list<array<string,mixed>>,attempted_rows:int,org_scope:list<int>,from:string,to:string,skipped_rows:int}
     */
    public function build(\DateTimeInterface $from, \DateTimeInterface $to, array $orgIds = []): array
    {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $rows = [];
        $skippedRows = 0;

        foreach ($this->loadSeoEventsInRange($fromAt, $toAt, $normalizedOrgIds) as $event) {
            $eventCode = strtolower(trim((string) ($event->event_code ?? '')));
            $metric = self::EVENT_METRIC_MAP[$eventCode] ?? null;
            if ($metric === null) {
                continue;
            }

            $day = $this->normalizeDay($event->occurred_at ?? null);
            if ($day === null) {
                $skippedRows++;

                continue;
            }

            $meta = $this->decodeJson($event->meta_json ?? null);
            if ($this->trafficExclusionPolicy->isExcludedSeoConversionEvent($event, $meta)) {
                continue;
            }

            $seoConversion = is_array($meta['seo_conversion'] ?? null) ? $meta['seo_conversion'] : [];
            $dimensions = $this->resolveDimensions($seoConversion, $event);
            if ($dimensions === null) {
                $skippedRows++;

                continue;
            }

            $this->incrementMetric($rows, $day, $dimensions, $metric);
        }

        $now = now();
        $finalRows = array_values(array_map(static function (array $row) use ($now): array {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $row['last_refreshed_at'] = $now;

            return $row;
        }, $rows));

        return [
            'rows' => $finalRows,
            'attempted_rows' => count($finalRows),
            'org_scope' => $normalizedOrgIds,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:list<array<string,mixed>>,attempted_rows:int,deleted_rows:int,upserted_rows:int,org_scope:list<int>,from:string,to:string,dry_run:bool,skipped_rows:int}
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

        if (! $dryRun && SchemaBaseline::hasTable(self::TABLE)) {
            DB::transaction(function () use ($payload, $rows, &$deletedRows, &$upsertedRows): void {
                $deletedRows = $this->deleteScope($payload['from'], $payload['to'], $payload['org_scope']);

                if ($rows === []) {
                    return;
                }

                DB::table(self::TABLE)->upsert(
                    $rows,
                    [
                        'day',
                        'org_id',
                        'url_hash',
                        'lang',
                        'page_type',
                        'source_url_hash',
                        'source_article_hash',
                        'target_test_hash',
                        'scale_id',
                        'form_id',
                        'session_id_hash',
                        'referrer_host_hash',
                    ],
                    [
                        'url',
                        'source_url',
                        'source_article',
                        'target_test',
                        'referrer_host',
                        'landing_pv_count',
                        'article_to_test_click_count',
                        'start_test_count',
                        'complete_test_count',
                        'view_result_count',
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
    private function loadSeoEventsInRange(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $eventCodes = array_keys(self::EVENT_METRIC_MAP);
        $placeholders = implode(',', array_fill(0, count($eventCodes), '?'));

        $query = DB::table('events')
            ->whereBetween('occurred_at', [$from, $to])
            ->whereRaw('lower(event_code) in ('.$placeholders.')', $eventCodes)
            ->select(['id', 'org_id', 'event_code', 'anon_id', 'session_id', 'request_id', 'attempt_id', 'meta_json', 'occurred_at', 'locale']);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        return $query->get()->all();
    }

    /**
     * @param  array<string,mixed>  $seoConversion
     * @return array<string,mixed>|null
     */
    private function resolveDimensions(array $seoConversion, object $event): ?array
    {
        $url = $this->normalizePublicUrl($seoConversion['url'] ?? null);
        if ($url === null) {
            return null;
        }

        $sourceUrl = $this->normalizePublicUrl($seoConversion['source_url'] ?? null, true);
        $targetTest = $this->normalizePublicUrl($seoConversion['target_test'] ?? null, true);
        $referrerHost = $this->normalizeReferrerHost($seoConversion['referrer'] ?? null);
        $sessionId = $this->normalizeDimension($seoConversion['session_id'] ?? $event->session_id ?? null, 160);

        return [
            'org_id' => max(0, (int) ($event->org_id ?? 0)),
            'url' => $url,
            'url_hash' => sha1($url),
            'lang' => $this->normalizeLang($seoConversion['lang'] ?? $event->locale ?? null),
            'page_type' => $this->normalizeDimension($seoConversion['page_type'] ?? null, 64),
            'source_url' => $sourceUrl,
            'source_url_hash' => $sourceUrl === null ? '' : sha1($sourceUrl),
            'source_article' => $this->normalizeDimension($seoConversion['source_article'] ?? null, 160),
            'source_article_hash' => sha1($this->normalizeDimension($seoConversion['source_article'] ?? null, 160)),
            'target_test' => $targetTest,
            'target_test_hash' => $targetTest === null ? '' : sha1($targetTest),
            'scale_id' => $this->normalizeDimension($seoConversion['scale_id'] ?? null, 64),
            'form_id' => $this->normalizeDimension($seoConversion['form_id'] ?? null, 64),
            'session_id_hash' => $sessionId === '' ? '' : hash('sha256', $sessionId),
            'referrer_host' => $referrerHost,
            'referrer_host_hash' => $referrerHost === '' ? '' : sha1($referrerHost),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string,mixed>  $dimensions
     */
    private function incrementMetric(array &$rows, string $day, array $dimensions, string $metric): void
    {
        $key = implode('|', [
            $day,
            (string) $dimensions['org_id'],
            $dimensions['url_hash'],
            $dimensions['lang'],
            $dimensions['page_type'],
            $dimensions['source_url_hash'],
            $dimensions['source_article_hash'],
            $dimensions['target_test_hash'],
            $dimensions['scale_id'],
            $dimensions['form_id'],
            $dimensions['session_id_hash'],
            $dimensions['referrer_host_hash'],
        ]);

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'day' => $day,
                'org_id' => $dimensions['org_id'],
                'url' => $dimensions['url'],
                'url_hash' => $dimensions['url_hash'],
                'lang' => $dimensions['lang'],
                'page_type' => $dimensions['page_type'],
                'source_url' => $dimensions['source_url'],
                'source_url_hash' => $dimensions['source_url_hash'],
                'source_article' => $dimensions['source_article'],
                'source_article_hash' => $dimensions['source_article_hash'],
                'target_test' => $dimensions['target_test'],
                'target_test_hash' => $dimensions['target_test_hash'],
                'scale_id' => $dimensions['scale_id'],
                'form_id' => $dimensions['form_id'],
                'session_id_hash' => $dimensions['session_id_hash'],
                'referrer_host' => $dimensions['referrer_host'],
                'referrer_host_hash' => $dimensions['referrer_host_hash'],
                'landing_pv_count' => 0,
                'article_to_test_click_count' => 0,
                'start_test_count' => 0,
                'complete_test_count' => 0,
                'view_result_count' => 0,
            ];
        }

        $rows[$key][$metric] = max(0, (int) ($rows[$key][$metric] ?? 0) + 1);
    }

    private function normalizePublicUrl(mixed $value, bool $allowEmpty = false): ?string
    {
        $candidate = $this->normalizeDimension($value, 2048);
        if ($candidate === '') {
            return $allowEmpty ? null : null;
        }

        $parts = @parse_url($candidate);
        if (! is_array($parts)) {
            return null;
        }

        $path = $this->normalizePath($parts['path'] ?? '');
        if ($path === null || $this->isPrivatePath($path)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host !== '') {
            $prefix = in_array($scheme, ['http', 'https'], true) ? $scheme.'://'.$host : 'https://'.$host;

            return $prefix.$path;
        }

        return $path;
    }

    private function normalizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        if (! str_starts_with($path, '/')) {
            return null;
        }

        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function isPrivatePath(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', strtolower($path)), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return false;
        }

        $firstContentSegment = in_array($segments[0], ['en', 'zh', 'zh-cn', 'zh-tw'], true)
            ? ($segments[1] ?? '')
            : $segments[0];

        return in_array($firstContentSegment, self::PRIVATE_PATH_SEGMENTS, true);
    }

    private function normalizeReferrerHost(mixed $value): string
    {
        $candidate = $this->normalizeDimension($value, 2048);
        if ($candidate === '') {
            return '';
        }

        $parts = @parse_url($candidate);
        if (! is_array($parts)) {
            return '';
        }

        return $this->normalizeDimension(strtolower((string) ($parts['host'] ?? '')), 160);
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

    private function normalizeLang(mixed $value): string
    {
        $lang = strtolower($this->normalizeDimension($value, 16));

        return preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $lang) === 1 ? $lang : '';
    }

    private function normalizeDimension(mixed $value, int $maxLength): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[\x00-\x1F\x7F]+/', '', $normalized) ?? '';

        return mb_substr($normalized, 0, $maxLength);
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
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        $normalized = [];
        foreach ($orgIds as $orgId) {
            $value = max(0, (int) $orgId);
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function deleteScope(string $from, string $to, array $orgIds): int
    {
        if (! SchemaBaseline::hasTable(self::TABLE)) {
            return 0;
        }

        $query = DB::table(self::TABLE)->whereBetween('day', [$from, $to]);
        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        return $query->delete();
    }
}
