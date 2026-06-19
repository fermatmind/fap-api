<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\GscSearchAnalyticsRowNormalizer;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class GscCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly GscSearchAnalyticsRowNormalizer $normalizer,
        private readonly GscDataQualityGate $dataQualityGate,
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
}
