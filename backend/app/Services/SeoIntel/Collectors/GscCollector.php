<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\GscReadonlyLiveAdapter;
use App\Services\SeoIntel\GscSearchAnalyticsRowNormalizer;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class GscCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly GscSearchAnalyticsRowNormalizer $normalizer,
        private readonly GscDataQualityGate $dataQualityGate,
        private readonly GscReadonlyLiveAdapter $liveAdapter,
    ) {}

    public function name(): string
    {
        return 'gsc_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        if ((bool) ($options['gsc_live_preflight'] ?? false)) {
            $preflight = $this->liveAdapter->preflight($options);
            $blocked = ($preflight['status'] ?? 'blocked') !== 'ready';

            return new SeoIntelCollectorResult(
                collector: $this->name(),
                status: $blocked ? 'blocked' : 'success',
                dryRun: $dryRun,
                writesAttempted: false,
                writesCommitted: false,
                externalCallsAttempted: false,
                itemsSeen: 0,
                issues: array_values(array_map('strval', $preflight['issues'] ?? [])),
                metadata: [
                    'mode' => 'gsc_live_readonly_credential_preflight',
                    'data_origin_if_executed' => 'live_gsc_api',
                    'live_readiness' => $preflight,
                    'opportunity_queue_eligible' => false,
                    'cms_write_allowed' => false,
                    'search_channel_enqueue_allowed' => false,
                    'search_provider_submission_allowed' => false,
                    'writes_attempted' => false,
                    'external_calls_attempted' => false,
                ],
            );
        }

        if ((bool) ($options['gsc_live_read'] ?? false)) {
            return $this->collectLiveRead($options, $dryRun);
        }

        $lagDays = (int) config('seo_intel.gsc_backfill_lag_days', 3);
        $windowDays = (int) config('seo_intel.gsc_default_window_days', 28);
        $rows = $this->fixtureRows();
        $normalized = array_map(fn (array $row): array => $this->normalizer->normalize($row), $rows);
        $qualityGate = $this->dataQualityGate->evaluate($normalized);
        $brandRows = count(array_filter($normalized, static fn (array $row): bool => (bool) ($row['is_brand_query'] ?? false)));
        $nonBrandRows = count(array_filter($normalized, static fn (array $row): bool => ($row['query_type'] ?? 'unknown') === 'non_brand'));

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: count($rows),
            issues: [
                'fixture_only_no_live_gsc_api_call',
                'writes_blocked_by_default',
            ],
            metadata: [
                'rows_seen' => count($rows),
                'rows_normalized' => count($normalized),
                'brand_rows' => $brandRows,
                'non_brand_rows' => $nonBrandRows,
                'data_origin' => 'fixture',
                'data_quality_gate' => $qualityGate,
                'opportunity_queue_eligible' => false,
                'date_window' => [
                    'lag_days' => $lagDays,
                    'window_days' => $windowDays,
                    'end_date' => now()->subDays($lagDays)->toDateString(),
                    'start_date' => now()->subDays($lagDays + $windowDays - 1)->toDateString(),
                ],
                'data_lag_days' => $lagDays,
                'source_engine' => 'google',
                'gsc_enabled' => (bool) config('seo_intel.gsc_enabled', false),
                'gsc_live_api_enabled' => (bool) config('seo_intel.gsc_live_api_enabled', false),
                'credentials_required' => false,
                'external_api_calls_allowed' => false,
                'external_calls_attempted' => false,
                'scheduler_enabled' => false,
                'queue_worker_enabled' => false,
                'query_purchase_attribution_allowed' => false,
                'purchase_truth_source' => 'backend_orders_payment_benefits',
                'node2_local_laravel_data_source' => false,
                'baidu_connected' => false,
                'indexnow_connected' => false,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureRows(): array
    {
        $date = now()->subDays((int) config('seo_intel.gsc_backfill_lag_days', 3))->toDateString();

        return [
            [
                'date' => $date,
                'page' => 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types',
                'query' => 'fermatmind mbti',
                'locale' => 'zh-CN',
                'device' => 'DESKTOP',
                'country' => 'chn',
                'search_type' => 'web',
                'clicks' => 3,
                'impressions' => 100,
                'ctr' => 0.03,
                'position' => 4.2,
            ],
            [
                'date' => $date,
                'page' => 'https://fermatmind.com/zh/articles/personality-test',
                'query' => '人格测试',
                'locale' => 'zh-CN',
                'device' => 'MOBILE',
                'country' => 'chn',
                'search_type' => 'web',
                'clicks' => 2,
                'impressions' => 80,
                'position' => 7.5,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function collectLiveRead(array $options, bool $dryRun): SeoIntelCollectorResult
    {
        $request = $this->liveReadRequest($options);
        $result = $this->liveAdapter->fetchSearchAnalyticsRows($request, [
            ...$options,
            'execute_live_read' => true,
        ]);
        $rows = is_array($result['rows'] ?? null) ? array_values($result['rows']) : [];
        $datedRows = array_map(
            fn (array $row): array => $row + ['date' => $request['endDate']],
            $rows
        );
        $normalized = array_map(fn (array $row): array => $this->normalizer->normalize($row), $datedRows);
        $qualityGate = $rows === [] ? null : $this->dataQualityGate->evaluate($normalized);
        $status = (string) ($result['status'] ?? 'blocked');

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: $status,
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: (bool) ($result['external_calls_attempted'] ?? false),
            itemsSeen: (int) ($result['rows_seen'] ?? count($rows)),
            issues: array_values(array_map('strval', $result['issues'] ?? [])),
            metadata: [
                'mode' => 'gsc_live_readonly_sidecar_read',
                'data_origin' => 'live_gsc_api',
                'date_window' => [
                    'start_date' => $request['startDate'],
                    'end_date' => $request['endDate'],
                ],
                'dimensions' => $request['dimensions'],
                'row_limit' => $request['rowLimit'],
                'rows_seen' => count($rows),
                'safe_row_preview' => $this->safeRowPreview($normalized),
                'data_quality_gate' => $qualityGate,
                'opportunity_queue_eligible' => false,
                'cms_write_allowed' => false,
                'search_channel_enqueue_allowed' => false,
                'search_provider_submission_allowed' => false,
                'indexing_request_allowed' => false,
                'writes_attempted' => false,
                'writes_committed' => false,
                'scheduler_enabled' => false,
                'queue_worker_enabled' => false,
                'preflight' => $result['preflight'] ?? null,
                'http_status' => $result['http_status'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{startDate:string,endDate:string,dimensions:list<string>,rowLimit:int}
     */
    private function liveReadRequest(array $options): array
    {
        $lagDays = (int) config('seo_intel.gsc_backfill_lag_days', 3);
        $windowDays = (int) config('seo_intel.gsc_default_window_days', 28);
        $endDate = $this->dateOption($options['end_date'] ?? null, now()->subDays($lagDays)->toDateString());
        $startDate = $this->dateOption(
            $options['start_date'] ?? null,
            now()->subDays($lagDays + $windowDays - 1)->toDateString()
        );
        $dimensions = $this->dimensionsOption($options['dimensions'] ?? null);
        $limit = (int) ($options['limit'] ?? config('seo_intel.gsc_readonly_adapter.default_limit', 250));
        $maxLimit = (int) config('seo_intel.gsc_readonly_adapter.max_limit', 250);

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => $dimensions,
            'rowLimit' => max(1, min($limit, $maxLimit)),
        ];
    }

    private function dateOption(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }

    /**
     * @return list<string>
     */
    private function dimensionsOption(mixed $value): array
    {
        $allowed = ['query', 'page', 'country', 'device', 'searchAppearance'];
        $raw = trim((string) $value);
        $dimensions = $raw === ''
            ? ['query', 'page']
            : array_values(array_filter(array_map('trim', explode(',', $raw))));
        $dimensions = array_values(array_intersect($dimensions, $allowed));

        return $dimensions === [] ? ['query', 'page'] : $dimensions;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function safeRowPreview(array $rows): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'report_date' => $row['report_date'] ?? null,
            'canonical_url_hash' => $row['canonical_url_hash'] ?? null,
            'query_hash' => $row['query_hash'] ?? null,
            'query_display_masked' => $row['query_display_masked'] ?? null,
            'locale' => $row['locale'] ?? null,
            'source_engine' => $row['source_engine'] ?? null,
            'device' => $row['device'] ?? null,
            'country' => $row['country'] ?? null,
            'search_type' => $row['search_type'] ?? null,
            'clicks' => $row['clicks'] ?? null,
            'impressions' => $row['impressions'] ?? null,
            'ctr_ppm' => $row['ctr_ppm'] ?? null,
            'average_position_milli' => $row['average_position_milli'] ?? null,
            'is_brand_query' => $row['is_brand_query'] ?? null,
            'query_type' => $row['query_type'] ?? null,
            'data_state' => $row['data_state'] ?? null,
        ], array_slice($rows, 0, 3)));
    }
}
