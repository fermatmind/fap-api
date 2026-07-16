<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\ProviderFreshness\ProviderFreshnessService;
use App\Services\Analytics\ProviderFreshness\ProviderReconciler;
use App\Services\Analytics\ProviderFreshness\ProviderSnapshotStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnalyticsProviderFreshnessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-16 12:00:00', 'Asia/Shanghai'));
        config()->set('analytics.provider_freshness.enabled', true);
        config()->set('analytics.provider_freshness.cache_store', 'array');
        config()->set('analytics.provider_freshness.max_attempts', 1);
        config()->set('analytics.provider_freshness.retry_base_delay_ms', 0);
        config()->set('analytics.provider_freshness.retry_jitter_ms', 0);
        config()->set('analytics.provider_freshness.minimum_backend_activity', 5);
        config()->set('analytics.provider_freshness.allowed_provider_lag_days', 2);
        $this->configureProviders();
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_successful_refresh_persists_versioned_aggregate_snapshot_and_uses_only_global_org_zero(): void
    {
        $this->insertFunnelRow(0, 12);
        $this->insertFunnelRow(99, 999);
        $this->fakeProviderSuccess(30, 20);

        $snapshot = app(ProviderFreshnessService::class)->refresh();

        $this->assertSame(ProviderSnapshotStore::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertSame(12, $snapshot['backend_global']['activity']);
        $this->assertSame('global_org0', $snapshot['backend_global']['scope']);
        $this->assertSame('healthy', $snapshot['providers']['ga4']['status']);
        $this->assertSame('healthy', $snapshot['providers']['baidu']['status']);
        $this->assertSame('healthy', $snapshot['reconciliation']['status']);
        $this->assertSame($snapshot, app(ProviderSnapshotStore::class)->read());
    }

    public function test_failed_refresh_preserves_last_known_good_metrics_and_success_time(): void
    {
        $this->insertFunnelRow(0, 12);
        $this->fakeProviderSuccess(30, 20);
        $first = app(ProviderFreshnessService::class)->refresh();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-16 13:00:00', 'Asia/Shanghai'));
        Http::fakeSequence()
            ->push(['access_token' => 'ga-token'])
            ->push([], 503)
            ->push([], 503);
        $failed = app(ProviderFreshnessService::class)->refresh();

        foreach (['ga4', 'baidu'] as $provider) {
            $this->assertTrue($failed['providers'][$provider]['using_lkg']);
            $this->assertSame('degraded', $failed['providers'][$provider]['status']);
            $this->assertSame($first['providers'][$provider]['metrics'], $failed['providers'][$provider]['metrics']);
            $this->assertSame($first['providers'][$provider]['last_success_at'], $failed['providers'][$provider]['last_success_at']);
            $this->assertNotSame($first['providers'][$provider]['last_attempt_at'], $failed['providers'][$provider]['last_attempt_at']);
        }
    }

    public function test_cached_success_becomes_stale_from_data_through_and_last_success(): void
    {
        $this->insertFunnelRow(0, 12);
        $this->fakeProviderSuccess(30, 20);
        app(ProviderFreshnessService::class)->refresh();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-21 12:00:00', 'Asia/Shanghai'));
        $this->insertFunnelRow(0, 12, '2026-07-20');
        $snapshot = app(ProviderFreshnessService::class)->snapshot();

        $this->assertSame('stale', $snapshot['providers']['ga4']['status']);
        $this->assertSame('stale', $snapshot['providers']['baidu']['status']);
        $this->assertSame('stale', $snapshot['reconciliation']['status']);
    }

    public function test_missing_global_row_is_unknown_not_zero_and_low_traffic_is_no_activity(): void
    {
        config()->set('analytics.provider_freshness.ga4.enabled', false);
        config()->set('analytics.provider_freshness.baidu.enabled', false);

        $missing = app(ProviderFreshnessService::class)->snapshot();
        $this->assertFalse($missing['backend_global']['row_exists']);
        $this->assertNull($missing['backend_global']['activity']);
        $this->assertSame('unknown', $missing['reconciliation']['status']);
        $this->assertSame('backend_global_missing', $missing['reconciliation']['reason_code']);

        $this->insertFunnelRow(0, 2);
        config()->set('analytics.provider_freshness.ga4.enabled', true);
        config()->set('analytics.provider_freshness.baidu.enabled', true);
        $this->fakeProviderSuccess(0, 0);
        $lowTraffic = app(ProviderFreshnessService::class)->refresh(false);
        $this->assertSame('unknown', $lowTraffic['reconciliation']['status']);
        $this->assertSame('no_activity', $lowTraffic['reconciliation']['reason_code']);
    }

    public function test_reconciliation_distinguishes_unconfigured_unknown_degraded_and_investigate(): void
    {
        $reconciler = app(ProviderReconciler::class);
        $backend = ['row_exists' => true, 'status' => 'healthy', 'activity' => 10];

        $this->assertSame('unconfigured', $reconciler->reconcile($backend, $this->providers('unconfigured', 0, 'healthy', 1))['status']);
        $this->assertSame('unknown', $reconciler->reconcile(['row_exists' => true, 'status' => 'healthy', 'activity' => 0], $this->providers('healthy', 0, 'healthy', 0))['status']);
        $this->assertSame('investigate', $reconciler->reconcile($backend, $this->providers('healthy', 0, 'healthy', 0))['status']);
        $this->assertSame('degraded', $reconciler->reconcile($backend, $this->providers('healthy', 0, 'healthy', 8))['status']);
        $this->assertSame('stale', $reconciler->reconcile($backend, $this->providers('stale', 8, 'healthy', 8))['status']);
    }

    /** @return array<string,array<string,mixed>> */
    private function providers(string $gaStatus, int $gaCount, string $baiduStatus, int $baiduCount): array
    {
        return [
            'ga4' => ['status' => $gaStatus, 'metrics' => ['event_count' => $gaCount]],
            'baidu' => ['status' => $baiduStatus, 'metrics' => ['page_views' => $baiduCount]],
        ];
    }

    private function configureProviders(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        config()->set('analytics.provider_freshness.ga4.enabled', true);
        config()->set('analytics.provider_freshness.ga4.property_id', '123456789');
        config()->set('analytics.provider_freshness.ga4.service_account_json', json_encode([
            'client_email' => 'reader@example.test',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));
        config()->set('analytics.provider_freshness.baidu.enabled', true);
        config()->set('analytics.provider_freshness.baidu.site_id', '987654321');
        config()->set('analytics.provider_freshness.baidu.access_token', 'baidu-token');
    }

    private function fakeProviderSuccess(int $gaCount, int $baiduViews): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'ga-token'])
            ->push([
                'rows' => [
                    ['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => (string) $gaCount], ['value' => '6']]],
                ],
                'totals' => [['metricValues' => [['value' => (string) $gaCount], ['value' => '6']]]],
            ])
            ->push(['result' => ['fields' => ['pv_count', 'visitor_count'], 'sum' => [[$baiduViews, 5]]]]);
    }

    private function insertFunnelRow(int $orgId, int $started, string $day = '2026-07-15'): void
    {
        DB::table('analytics_funnel_daily')->insert([
            'day' => $day,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'started_attempts' => $started,
            'submitted_attempts' => $started,
            'first_view_attempts' => $started,
            'order_created_attempts' => 0,
            'paid_attempts' => 0,
            'paid_revenue_cents' => 0,
            'unlocked_attempts' => 0,
            'report_ready_attempts' => 0,
            'pdf_download_attempts' => 0,
            'share_generated_attempts' => 0,
            'share_click_attempts' => 0,
            'last_refreshed_at' => CarbonImmutable::now('Asia/Shanghai'),
            'created_at' => CarbonImmutable::now('Asia/Shanghai'),
            'updated_at' => CarbonImmutable::now('Asia/Shanghai'),
        ]);
    }
}
