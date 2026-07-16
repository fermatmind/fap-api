<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\ProviderFreshness\BaiduTongjiAdapter;
use App\Services\Analytics\ProviderFreshness\GoogleAnalyticsDataAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnalyticsProviderFreshnessAdapterTest extends TestCase
{
    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = CarbonImmutable::parse('2026-07-15', 'Asia/Shanghai');
        config()->set('analytics.provider_freshness.enabled', true);
        config()->set('analytics.provider_freshness.max_attempts', 3);
        config()->set('analytics.provider_freshness.retry_base_delay_ms', 0);
        config()->set('analytics.provider_freshness.retry_jitter_ms', 0);
        $this->configureGoogle();
        $this->configureBaidu();
    }

    public function test_missing_provider_configuration_sends_no_requests(): void
    {
        config()->set('analytics.provider_freshness.ga4.property_id', '');
        config()->set('analytics.provider_freshness.baidu.site_id', '');
        Http::fake();

        $this->assertSame('unconfigured', app(GoogleAnalyticsDataAdapter::class)->fetch($this->day)['outcome']);
        $this->assertSame('unconfigured', app(BaiduTongjiAdapter::class)->fetch($this->day)['outcome']);
        Http::assertNothingSent();
    }

    public function test_ga4_success_uses_readonly_service_account_and_aggregate_dimensions_only(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'ga-test-token'])
            ->push($this->gaPayload(31, 20, 25, 6));

        $result = app(GoogleAnalyticsDataAdapter::class)->fetch($this->day);

        $this->assertSame('success', $result['outcome']);
        $this->assertSame(['event_count' => 31, 'active_users' => 20, 'page_view' => 25, 'view_landing' => 6], $result['metrics']);
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return true;
            }

            $claims = $this->jwtClaims((string) ($request->data()['assertion'] ?? ''));

            return ($claims['scope'] ?? null) === 'https://www.googleapis.com/auth/analytics.readonly'
                && ($claims['aud'] ?? null) === 'https://oauth2.googleapis.com/token'
                && (($claims['exp'] ?? 0) - ($claims['iat'] ?? 0)) === 3600;
        });
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'analyticsdata.googleapis.com')) {
                return true;
            }

            $encoded = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return str_contains($encoded, 'eventCount')
                && str_contains($encoded, 'activeUsers')
                && str_contains($encoded, 'page_view')
                && str_contains($encoded, 'view_landing')
                && ! str_contains($encoded, 'clientId')
                && ! str_contains($encoded, 'session');
        });
    }

    public function test_ga4_does_not_retry_authentication_failure(): void
    {
        Http::fakeSequence()->push(['error' => 'invalid_grant'], 401);

        $result = app(GoogleAnalyticsDataAdapter::class)->fetch($this->day);

        $this->assertSame('authentication_failed', $result['diagnostic_code']);
        Http::assertSentCount(1);
    }

    public function test_ga4_retries_connection_timeout_with_a_finite_budget(): void
    {
        Http::fake(Http::failedConnection('secret connection detail'));
        $timeout = app(GoogleAnalyticsDataAdapter::class)->fetch($this->day);
        $this->assertSame('connection_failed', $timeout['diagnostic_code']);
        Http::assertSentCount(3);
    }

    public function test_ga4_retries_429_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'ga-test-token'])
            ->push([], 429)
            ->push($this->gaPayload(5, 4, 4, 1));
        $this->assertSame('success', app(GoogleAnalyticsDataAdapter::class)->fetch($this->day)['outcome']);
        Http::assertSentCount(3);
    }

    public function test_ga4_retries_5xx_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'ga-test-token'])
            ->push([], 503)
            ->push($this->gaPayload(7, 5, 6, 1));
        $this->assertSame('success', app(GoogleAnalyticsDataAdapter::class)->fetch($this->day)['outcome']);
        Http::assertSentCount(3);
    }

    public function test_ga4_rejects_malformed_aggregate_payload_without_leaking_secrets(): void
    {
        Http::fakeSequence()->push(['access_token' => 'ga-test-token'])->push(['rows' => []]);

        $result = app(GoogleAnalyticsDataAdapter::class)->fetch($this->day);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame('malformed_payload', $result['diagnostic_code']);
        $this->assertStringNotContainsString('ga-test-token', $encoded);
        $this->assertStringNotContainsString('123456789', $encoded);
        $this->assertStringNotContainsString('PRIVATE KEY', $encoded);
    }

    public function test_baidu_success_queries_official_site_level_daily_pv_uv_fields(): void
    {
        Http::fake([$this->baiduReportPattern() => Http::response($this->baiduPayload(44, 19))]);

        $result = app(BaiduTongjiAdapter::class)->fetch($this->day);

        $this->assertSame('success', $result['outcome']);
        $this->assertSame(['page_views' => 44, 'visitors' => 19], $result['metrics']);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['method'] ?? null) === 'trend/time/a'
                && ($data['metrics'] ?? null) === 'pv_count,visitor_count'
                && ($data['gran'] ?? null) === 'day'
                && ($data['start_date'] ?? null) === '20260715'
                && ! array_key_exists('visitor_id', $data)
                && ! array_key_exists('session', $data);
        });
    }

    public function test_baidu_official_refresh_flow_is_supported_without_persisting_credentials(): void
    {
        config()->set('analytics.provider_freshness.baidu.access_token', '');
        config()->set('analytics.provider_freshness.baidu.refresh_token', 'baidu-refresh-secret');
        config()->set('analytics.provider_freshness.baidu.client_id', 'baidu-client');
        config()->set('analytics.provider_freshness.baidu.client_secret', 'baidu-client-secret');
        Http::fakeSequence()
            ->push(['access_token' => 'refreshed-token'])
            ->push($this->baiduPayload(9, 4));

        $result = app(BaiduTongjiAdapter::class)->fetch($this->day);

        $this->assertSame('success', $result['outcome']);
        $this->assertStringNotContainsString('refreshed-token', json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertSent(function (Request $request): bool {
            return ! str_contains($request->url(), 'oauth/2.0/token')
                || ($request->data()['grant_type'] ?? null) === 'refresh_token';
        });
    }

    public function test_baidu_does_not_retry_401(): void
    {
        Http::fakeSequence()->push([], 401);
        $this->assertSame('authentication_failed', app(BaiduTongjiAdapter::class)->fetch($this->day)['diagnostic_code']);
        Http::assertSentCount(1);
    }

    public function test_baidu_retries_connection_timeout_with_a_finite_budget(): void
    {
        Http::fake(Http::failedConnection('private network detail'));
        $this->assertSame('connection_failed', app(BaiduTongjiAdapter::class)->fetch($this->day)['diagnostic_code']);
        Http::assertSentCount(3);
    }

    public function test_baidu_retries_429_then_succeeds(): void
    {
        Http::fakeSequence()->push([], 429)->push($this->baiduPayload(2, 1));
        $this->assertSame('success', app(BaiduTongjiAdapter::class)->fetch($this->day)['outcome']);
        Http::assertSentCount(2);
    }

    public function test_baidu_retries_5xx_then_succeeds(): void
    {
        Http::fakeSequence()->push([], 502)->push($this->baiduPayload(3, 2));
        $this->assertSame('success', app(BaiduTongjiAdapter::class)->fetch($this->day)['outcome']);
        Http::assertSentCount(2);
    }

    public function test_baidu_rejects_malformed_payload_and_redacts_configured_values_from_result(): void
    {
        Http::fake([$this->baiduReportPattern() => Http::response(['result' => ['fields' => ['pv_count'], 'sum' => []]])]);

        $result = app(BaiduTongjiAdapter::class)->fetch($this->day);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame('malformed_payload', $result['diagnostic_code']);
        $this->assertStringNotContainsString('baidu-access-secret', $encoded);
        $this->assertStringNotContainsString('987654321', $encoded);
    }

    private function configureGoogle(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        config()->set('analytics.provider_freshness.ga4.enabled', true);
        config()->set('analytics.provider_freshness.ga4.property_id', '123456789');
        config()->set('analytics.provider_freshness.ga4.service_account_json', json_encode([
            'client_email' => 'analytics-reader@example.test',
            'private_key' => $privateKey,
        ], JSON_THROW_ON_ERROR));
    }

    private function configureBaidu(): void
    {
        config()->set('analytics.provider_freshness.baidu.enabled', true);
        config()->set('analytics.provider_freshness.baidu.site_id', '987654321');
        config()->set('analytics.provider_freshness.baidu.access_token', 'baidu-access-secret');
    }

    /** @return array<string,mixed> */
    private function gaPayload(int $total, int $users, int $pageViews, int $landings): array
    {
        return [
            'rows' => [
                ['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => (string) $pageViews], ['value' => (string) $users]]],
                ['dimensionValues' => [['value' => 'view_landing']], 'metricValues' => [['value' => (string) $landings], ['value' => (string) $users]]],
            ],
            'totals' => [['metricValues' => [['value' => (string) $total], ['value' => (string) $users]]]],
        ];
    }

    /** @return array<string,mixed> */
    private function baiduPayload(int $views, int $visitors): array
    {
        return ['result' => ['fields' => ['pv_count', 'visitor_count'], 'sum' => [[$views, $visitors]]]];
    }

    /** @return array<string,mixed> */
    private function jwtClaims(string $jwt): array
    {
        $encoded = explode('.', $jwt)[1] ?? '';
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $claims = $decoded === false ? null : json_decode($decoded, true);

        return is_array($claims) ? $claims : [];
    }

    private function baiduReportPattern(): string
    {
        return '*tongji/report/getData*';
    }
}
