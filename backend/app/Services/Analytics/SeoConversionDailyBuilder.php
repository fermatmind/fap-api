<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SeoConversionDailyBuilder
{
    private const TABLE = 'analytics_seo_conversion_daily';

    private const RUN_TABLE = 'analytics_seo_conversion_refresh_runs';

    /**
     * @var array<string, string>
     */
    private const EVENT_METRIC_MAP = [
        'landing_pv' => 'landing_pv_count',
        'article_to_test_click' => 'article_to_test_click_count',
        'start_test' => 'start_test_count',
        'complete_test' => 'complete_test_count',
        'result_ready' => 'result_ready_count',
        'view_result' => 'view_result_count',
        'return_public_content' => 'return_public_content_count',
        'test_start' => 'start_test_count',
        'test_submit' => 'complete_test_count',
        'test_complete' => 'complete_test_count',
        'result_view' => 'view_result_count',
    ];

    /** @var array<string, string> */
    private const AUTHORITATIVE_ATTEMPT_EVENT_METRIC_MAP = [
        'test_start' => 'start_test_count',
        'test_submit' => 'complete_test_count',
        'test_complete' => 'complete_test_count',
        'result_view' => 'view_result_count',
    ];

    /** @var array<string, string> */
    private const BROWSER_MIRROR_EVENT_METRIC_MAP = [
        'start_test' => 'start_test_count',
        'complete_test' => 'complete_test_count',
        'view_result' => 'view_result_count',
    ];

    /** @var list<string> */
    private const METRICS = [
        'landing_pv_count',
        'article_to_test_click_count',
        'start_test_count',
        'complete_test_count',
        'result_ready_count',
        'view_result_count',
        'return_public_content_count',
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

    private readonly PublicArticleAttributionResolver $articleAttribution;

    private readonly AnalyticsFunnelDailyBuilder $reportingWindow;

    public function __construct(
        ?AnalyticsTrafficExclusionPolicy $trafficExclusionPolicy = null,
        ?PublicArticleAttributionResolver $articleAttribution = null,
        ?AnalyticsFunnelDailyBuilder $reportingWindow = null,
    ) {
        $this->trafficExclusionPolicy = $trafficExclusionPolicy ?? new AnalyticsTrafficExclusionPolicy;
        $this->articleAttribution = $articleAttribution ?? new PublicArticleAttributionResolver;
        $this->reportingWindow = $reportingWindow ?? new AnalyticsFunnelDailyBuilder;
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{rows:list<array<string,mixed>>,attempted_rows:int,org_scope:list<int>,from:string,to:string,skipped_rows:int}
     */
    public function build(\DateTimeInterface $from, \DateTimeInterface $to, array $orgIds = []): array
    {
        $window = $this->reportingWindow->reportingWindow($from, $to);
        $fromAt = CarbonImmutable::parse($window['storage_start'], $window['storage_timezone']);
        $toAt = CarbonImmutable::parse($window['storage_end_exclusive'], $window['storage_timezone']);
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $rows = [];
        $skippedRows = 0;

        $events = $this->loadSeoEventsInRange($fromAt, $toAt, $normalizedOrgIds);
        $authoritativeCoverage = [];

        foreach ($events as $event) {
            $eventCode = strtolower(trim((string) ($event->event_code ?? '')));
            $metric = self::AUTHORITATIVE_ATTEMPT_EVENT_METRIC_MAP[$eventCode] ?? null;
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

            $dimensions = $this->resolveAttemptBoundDimensions($meta, $event);
            if ($dimensions === null) {
                $skippedRows++;

                continue;
            }

            $this->incrementMetric($rows, $day, $dimensions, $metric);
            $authoritativeCoverage[$this->metricCoverageKey($day, $dimensions, $metric)] = true;
        }

        foreach ($events as $event) {
            $eventCode = strtolower(trim((string) ($event->event_code ?? '')));
            $metric = self::EVENT_METRIC_MAP[$eventCode] ?? null;
            if ($metric === null || array_key_exists($eventCode, self::AUTHORITATIVE_ATTEMPT_EVENT_METRIC_MAP)) {
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
            $dimensions = $eventCode === 'result_ready'
                ? $this->resolveResultReadyDimensions($meta, $event)
                : $this->resolveDimensions($seoConversion, $event);
            if ($dimensions === null) {
                $skippedRows++;

                continue;
            }
            if ($eventCode === 'return_public_content' && ! $this->isPublicReturnUrl((string) $dimensions['url'])) {
                $skippedRows++;

                continue;
            }
            if (array_key_exists($eventCode, self::BROWSER_MIRROR_EVENT_METRIC_MAP)
                && isset($authoritativeCoverage[$this->metricCoverageKey($day, $dimensions, $metric)])) {
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
            'from' => $window['from'],
            'to' => $window['to'],
            'reporting_timezone' => $window['reporting_timezone'],
            'storage_timezone' => $window['storage_timezone'],
            'window_utc_start' => $window['window_utc_start'],
            'window_utc_end_exclusive' => $window['window_utc_end_exclusive'],
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
        string $triggerMode = 'manual',
    ): array {
        $triggerMode = strtolower(trim($triggerMode));
        if (! in_array($triggerMode, ['manual', 'scheduled', 'rerun'], true)) {
            throw new \InvalidArgumentException('Unsupported SEO conversion refresh trigger mode.');
        }

        $runUid = (string) Str::uuid();
        $startedAt = CarbonImmutable::now('UTC');
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
                        'source_article_id',
                        'target_test',
                        'referrer_host',
                        'landing_pv_count',
                        'article_to_test_click_count',
                        'start_test_count',
                        'complete_test_count',
                        'result_ready_count',
                        'view_result_count',
                        'return_public_content_count',
                        'last_refreshed_at',
                        'updated_at',
                    ]
                );

                $upsertedRows = count($rows);
            });
        }

        $readbackReceipt = $dryRun
            ? [
                'status' => 'not_executed',
                'reason' => 'dry_run',
                'expected_metrics' => $this->metricTotals($rows),
            ]
            : $this->readbackReceipt($payload, $rows);

        $result = $payload + [
            'run_uid' => $runUid,
            'trigger_mode' => $triggerMode,
            'deleted_rows' => $deletedRows,
            'upserted_rows' => $upsertedRows,
            'dry_run' => $dryRun,
            'readback_receipt' => $readbackReceipt,
        ];

        $result['refresh_receipt'] = $dryRun
            ? null
            : $this->persistRefreshReceipt($result, $startedAt);

        return $result;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function persistRefreshReceipt(array $result, CarbonImmutable $startedAt): array
    {
        if (! SchemaBaseline::hasTable(self::RUN_TABLE)) {
            throw new \RuntimeException('SEO conversion refresh receipt table is missing.');
        }

        $completedAt = CarbonImmutable::now('UTC');
        $status = data_get($result, 'readback_receipt.status') === 'pass' ? 'success' : 'blocked';
        $receipt = [
            'schema_version' => 'analytics-seo-conversion-refresh-receipt.v2',
            'environment' => app()->environment(),
            'run_uid' => $result['run_uid'],
            'trigger_mode' => $result['trigger_mode'],
            'status' => $status,
            'application_sha' => $this->releaseSha(),
            'workflow_sha' => $this->releaseSha(),
            'active_production_sha' => $this->releaseSha(),
            'from' => $result['from'],
            'to' => $result['to'],
            'reporting_timezone' => $result['reporting_timezone'],
            'storage_timezone' => $result['storage_timezone'],
            'org_scope_mode' => ($result['org_scope'] ?? []) === [] ? 'all' : 'bounded',
            'org_scope_count' => count((array) ($result['org_scope'] ?? [])),
            'public_org_zero_only' => ($result['org_scope'] ?? []) === [0],
            'attempted_rows' => (int) ($result['attempted_rows'] ?? 0),
            'skipped_rows' => (int) ($result['skipped_rows'] ?? 0),
            'deleted_rows' => (int) ($result['deleted_rows'] ?? 0),
            'upserted_rows' => (int) ($result['upserted_rows'] ?? 0),
            'readback_receipt' => $result['readback_receipt'],
            'raw_query_exposed' => false,
            'raw_session_or_business_identifiers_exposed' => false,
            'private_paths_allowed' => false,
            'search_submission_allowed' => false,
        ];
        $coverageTo = CarbonImmutable::parse((string) $receipt['to'], 'UTC')->startOfDay();
        $coverageFrom = ($receipt['public_org_zero_only'] ?? false)
            ? $coverageTo->subDays(89)
            : CarbonImmutable::parse((string) $receipt['from'], 'UTC')->startOfDay();
        $receipt['readmodel_snapshot'] = [
            'environment' => $receipt['environment'],
            'from' => $coverageFrom->toDateString(),
            'to' => $coverageTo->toDateString(),
            'org_scope_mode' => $receipt['org_scope_mode'],
            'org_scope_count' => $receipt['org_scope_count'],
            'public_org_zero_only' => $receipt['public_org_zero_only'],
            'persisted_metrics' => $this->persistedMetrics(
                $coverageFrom,
                $coverageTo,
                ($receipt['public_org_zero_only'] ?? false) ? [0] : [],
            ),
        ];
        $receipt['readmodel_snapshot_hash'] = $this->canonicalHash($receipt['readmodel_snapshot']);
        $receipt['receipt_hash'] = $this->canonicalHash($receipt);

        DB::table(self::RUN_TABLE)->insert([
            'run_uid' => $result['run_uid'],
            'trigger_mode' => $result['trigger_mode'],
            'status' => $status,
            'from_date' => $result['from'],
            'to_date' => $result['to'],
            'org_scope_count' => count((array) ($result['org_scope'] ?? [])),
            'attempted_rows' => (int) ($result['attempted_rows'] ?? 0),
            'skipped_rows' => (int) ($result['skipped_rows'] ?? 0),
            'deleted_rows' => (int) ($result['deleted_rows'] ?? 0),
            'upserted_rows' => (int) ($result['upserted_rows'] ?? 0),
            'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'started_at' => $startedAt->toDateTimeString(),
            'completed_at' => $completedAt->toDateTimeString(),
            'created_at' => $completedAt->toDateTimeString(),
            'updated_at' => $completedAt->toDateTimeString(),
        ]);

        return $receipt;
    }

    private function releaseSha(): ?string
    {
        $candidates = [
            trim((string) config('app.git_sha', '')),
            is_file(dirname(base_path()).'/REVISION') ? trim((string) file_get_contents(dirname(base_path()).'/REVISION')) : '',
        ];
        foreach ($candidates as $candidate) {
            if (preg_match('/^[a-f0-9]{40}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $value */
    private function canonicalHash(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<int> $orgIds @return array<string, int> */
    private function persistedMetrics(CarbonImmutable $from, CarbonImmutable $to, array $orgIds): array
    {
        $query = DB::table(self::TABLE)->whereBetween('day', [$from->toDateString(), $to->toDateString()]);
        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }
        foreach (self::METRICS as $metric) {
            $query->selectRaw(sprintf('COALESCE(SUM(%s), 0) AS %s', $metric, $metric));
        }
        $row = $query->first();

        return array_combine(self::METRICS, array_map(
            static fn (string $metric): int => max(0, (int) ($row->{$metric} ?? 0)),
            self::METRICS,
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function readbackReceipt(array $payload, array $rows): array
    {
        if (! SchemaBaseline::hasTable(self::TABLE)) {
            return ['status' => 'blocked', 'reason' => 'read_model_missing'];
        }

        $query = DB::table(self::TABLE)
            ->whereBetween('day', [(string) $payload['from'], (string) $payload['to']]);
        if (($payload['org_scope'] ?? []) !== []) {
            $query->whereIn('org_id', (array) $payload['org_scope']);
        }
        foreach (self::METRICS as $metric) {
            $query->selectRaw(sprintf('COALESCE(SUM(%s), 0) AS %s', $metric, $metric));
        }
        $persisted = $query->first();
        $expected = $this->metricTotals($rows);
        $actual = [];
        foreach (self::METRICS as $metric) {
            $actual[$metric] = max(0, (int) ($persisted->{$metric} ?? 0));
        }

        return [
            'status' => $expected === $actual ? 'pass' : 'blocked',
            'authority' => 'events_to_analytics_seo_conversion_daily',
            'from' => $payload['from'],
            'to' => $payload['to'],
            'expected_metrics' => $expected,
            'persisted_metrics' => $actual,
            'raw_session_or_business_identifiers_exposed' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function metricTotals(array $rows): array
    {
        $totals = array_fill_keys(self::METRICS, 0);
        foreach ($rows as $row) {
            foreach (self::METRICS as $metric) {
                $totals[$metric] += max(0, (int) ($row[$metric] ?? 0));
            }
        }

        return $totals;
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
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->whereRaw('lower(event_code) in ('.$placeholders.')', $eventCodes)
            ->select(['id', 'org_id', 'event_code', 'anon_id', 'session_id', 'request_id', 'attempt_id', 'meta_json', 'occurred_at', 'locale', 'scale_code']);

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

        $pageType = strtolower($this->normalizeDimension($seoConversion['page_type'] ?? null, 64));
        if (! $this->isRegisteredPublicPageType($pageType)) {
            return null;
        }

        $sourceUrl = $this->normalizePublicUrl($seoConversion['source_url'] ?? null, true);
        $targetTest = $this->normalizePublicUrl($seoConversion['target_test'] ?? null, true);
        $referrerHost = $this->normalizeReferrerHost($seoConversion['referrer'] ?? null);

        return [
            'org_id' => max(0, (int) ($event->org_id ?? 0)),
            'url' => $url,
            'url_hash' => sha1($url),
            'lang' => $this->normalizeLang($seoConversion['lang'] ?? $event->locale ?? null),
            'page_type' => $pageType,
            'source_url' => $sourceUrl,
            'source_url_hash' => $sourceUrl === null ? '' : sha1($sourceUrl),
            'source_article' => $this->normalizeDimension($seoConversion['source_article'] ?? null, 160),
            'source_article_id' => null,
            'source_article_hash' => sha1($this->normalizeDimension($seoConversion['source_article'] ?? null, 160)),
            'target_test' => $targetTest,
            'target_test_hash' => $targetTest === null ? '' : sha1($targetTest),
            'scale_id' => $this->normalizeDimension($seoConversion['scale_id'] ?? null, 64),
            'form_id' => $this->normalizeDimension($seoConversion['form_id'] ?? null, 64),
            'session_id_hash' => '',
            'referrer_host' => $referrerHost,
            'referrer_host_hash' => $referrerHost === '' ? '' : sha1($referrerHost),
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>|null
     */
    private function resolveResultReadyDimensions(array $meta, object $event): ?array
    {
        $articleId = $this->positiveInteger($meta['source_article_id'] ?? null);
        if ($articleId === null) {
            return $this->resolveAttemptBoundDimensions($meta, $event);
        }

        $article = $this->articleAttribution->byPublicArticleId($articleId);
        if ($article === null) {
            return null;
        }

        return $this->articleDimensions($article, $meta, $event);
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>|null
     */
    private function resolveAttemptBoundDimensions(array $meta, object $event): ?array
    {
        $article = $this->articleAttributionFromAttempt($event);
        if ($article === null) {
            return null;
        }

        return $this->articleDimensions($article, $meta, $event);
    }

    /**
     * @param  array{article_id:int,slug:string,locale:string,canonical_path:string}  $article
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function articleDimensions(array $article, array $meta, object $event): array
    {
        $canonicalPath = $article['canonical_path'];
        $sourceArticle = $article['slug'];

        return [
            'org_id' => max(0, (int) ($event->org_id ?? 0)),
            'url' => $canonicalPath,
            'url_hash' => sha1($canonicalPath),
            'lang' => $this->normalizeLang($article['locale']),
            'page_type' => 'article',
            'source_url' => $canonicalPath,
            'source_url_hash' => sha1($canonicalPath),
            'source_article' => $sourceArticle,
            'source_article_id' => $article['article_id'],
            'source_article_hash' => sha1($sourceArticle),
            'target_test' => null,
            'target_test_hash' => '',
            'scale_id' => $this->normalizeDimension($event->scale_code ?? null, 64),
            'form_id' => $this->normalizeDimension($meta['form_code'] ?? null, 64),
            'session_id_hash' => '',
            'referrer_host' => '',
            'referrer_host_hash' => '',
        ];
    }

    /** @param array<string,mixed> $dimensions */
    private function metricCoverageKey(string $day, array $dimensions, string $metric): string
    {
        return implode('|', [
            $day,
            (string) ($dimensions['org_id'] ?? 0),
            $metric,
            (string) ($dimensions['scale_id'] ?? ''),
            (string) ($dimensions['form_id'] ?? ''),
        ]);
    }

    /**
     * @return array{article_id:int,slug:string,locale:string,canonical_path:string}|null
     */
    private function articleAttributionFromAttempt(object $event): ?array
    {
        $attemptId = trim((string) ($event->attempt_id ?? ''));
        if ($attemptId === '') {
            return null;
        }

        $attempt = DB::table('attempts')
            ->where('org_id', max(0, (int) ($event->org_id ?? 0)))
            ->where('id', $attemptId)
            ->first(['locale', 'answers_summary_json']);

        return $attempt === null ? null : $this->articleAttribution->fromAttempt($attempt);
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
                'source_article_id' => $dimensions['source_article_id'],
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
                'result_ready_count' => 0,
                'view_result_count' => 0,
                'return_public_content_count' => 0,
            ];
        }

        $rows[$key][$metric] = max(0, (int) ($rows[$key][$metric] ?? 0) + 1);
    }

    private function positiveInteger(mixed $value): ?int
    {
        $candidate = trim((string) $value);
        if (preg_match('/\A[1-9][0-9]*\z/', $candidate) !== 1) {
            return null;
        }

        $integer = filter_var($candidate, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
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
            if (! in_array($host, ['fermatmind.com', 'www.fermatmind.com'], true)) {
                return null;
            }
            $prefix = in_array($scheme, ['http', 'https'], true) ? $scheme.'://'.$host : 'https://'.$host;

            return $prefix.$path;
        }

        return $path;
    }

    private function isRegisteredPublicPageType(string $pageType): bool
    {
        return in_array($pageType, [
            'tests', 'test', 'test_detail', 'test_hub',
            'articles_topics', 'article', 'article_hub', 'topic', 'topic_hub',
            'career', 'career_job', 'career_guide', 'career_hub',
            'personality', 'personality_hub', 'personality_profile',
            'trust_method_help', 'methodology', 'support_article', 'support_hub',
            'other_public', 'home', 'landing_page',
        ], true);
    }

    private function isPublicReturnUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return preg_match('#(^|/)(take|attempt|attempts|result|results|report|reports|order|orders|share|shares|pay|payment|payments|history|account|recovery)(/|$)#i', $path) !== 1;
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

        for ($decodePass = 0; $decodePass < 5; $decodePass++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }

            $path = $decoded;
        }

        if (preg_match('/%[0-9a-f]{2}/i', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '\\')) {
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
            return CarbonImmutable::parse($value, $this->reportingWindow->storageTimezone())
                ->setTimezone($this->reportingWindow->reportingTimezone())
                ->toDateString();
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
