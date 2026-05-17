<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\AttributionDailyBuilder;
use App\Services\SeoIntel\RevenueDailyBuilder;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class AttributionRevenueFoundationCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly AttributionDailyBuilder $attributionBuilder,
        private readonly RevenueDailyBuilder $revenueBuilder,
    ) {}

    public function name(): string
    {
        return 'attribution_revenue_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $writesAllowed = (bool) ($options['writes_allowed'] ?? false);
        $attribution = $this->attributionBuilder->build($this->fixtureEvents());
        $revenue = $this->revenueBuilder->build($this->fixtureRevenueRecords());

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: count($this->fixtureEvents()) + count($this->fixtureRevenueRecords()),
            issues: [
                'fixture_only_no_live_data_read',
                'writes_blocked_by_default',
            ],
            metadata: [
                'writes_allowed' => $writesAllowed,
                'external_api_calls_allowed' => false,
                'scheduler_enabled' => false,
                'queue_worker_enabled' => false,
                'event_funnel_daily_rows' => count($attribution['event_funnel_daily']),
                'landing_attribution_daily_rows' => count($attribution['landing_attribution_daily']),
                'revenue_daily_rows' => count($revenue['revenue_daily']),
                'cluster_daily_rows' => count($attribution['cluster_daily']) + count($revenue['cluster_daily']),
                'consent_daily_rows' => count($attribution['consent_daily']),
                'excluded_internal_qa_bot_count' => $attribution['excluded_internal_qa_bot_count'] + $revenue['excluded_internal_qa_bot_count'],
                'ignored_non_backend_purchase_truth_count' => $revenue['ignored_non_backend_purchase_truth_count'],
                'purchase_truth_source' => $revenue['purchase_truth_source'],
                'ga4_purchase_truth' => false,
                'baidu_purchase_truth' => false,
                'keyword_purchase_attribution_allowed' => false,
                'pii_forbidden' => true,
                'node2_local_laravel_data_source' => false,
                'api_track_role' => 'transport_only',
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureEvents(): array
    {
        return [
            [
                'event_name' => 'start_attempt',
                'occurred_at' => '2026-05-17T00:00:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'entity_id_or_slug' => 'mbti-personality-test-16-personality-types',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'consent_state' => 'granted',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'source_route_family' => 'test',
                'source_slug' => 'mbti-personality-test-16-personality-types',
                'test_slug' => 'mbti-personality-test-16-personality-types',
                'cta_id' => 'start',
                'entrypoint' => 'test_detail',
                'touch_type' => 'first',
                'is_landing_event' => true,
            ],
            [
                'event_name' => 'purchase_success',
                'occurred_at' => '2026-05-17T00:05:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'entity_id_or_slug' => 'mbti-personality-test-16-personality-types',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'consent_state' => 'granted',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'source_route_family' => 'test',
                'source_slug' => 'mbti-personality-test-16-personality-types',
                'test_slug' => 'mbti-personality-test-16-personality-types',
                'cta_id' => 'unlock',
                'entrypoint' => 'result_preview',
                'touch_type' => 'last',
            ],
            [
                'event_name' => 'start_attempt',
                'occurred_at' => '2026-05-17T00:10:00Z',
                'source_engine' => 'paid_google',
                'consent_state' => 'granted',
                'traffic_quality' => 'qa',
                'environment' => 'production',
                'is_qa' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureRevenueRecords(): array
    {
        return [
            [
                'truth_source' => 'backend_orders_payment_benefits',
                'status' => 'paid',
                'occurred_at' => '2026-05-17T00:06:00Z',
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types'),
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'cluster' => 'mbti',
                'source_engine' => 'google',
                'traffic_quality' => 'production_user',
                'environment' => 'production',
                'revenue_cents' => 19900,
                'currency' => 'CNY',
                'sessions_proxy_count' => 10,
            ],
            [
                'truth_source' => 'ga4',
                'status' => 'paid',
                'occurred_at' => '2026-05-17T00:06:00Z',
                'source_engine' => 'google',
                'revenue_cents' => 19900,
            ],
        ];
    }
}
