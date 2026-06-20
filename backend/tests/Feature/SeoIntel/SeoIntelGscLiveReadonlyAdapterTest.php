<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscReadonlyLiveAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscLiveReadonlyAdapterTest extends TestCase
{
    #[Test]
    public function gsc_live_preflight_is_blocked_by_default_without_external_calls_or_writes(): void
    {
        Http::fake();

        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'gsc_foundation',
            '--gsc-live-preflight' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $decoded['status'] ?? null);
        $this->assertSame('gsc_live_readonly_credential_preflight', data_get($decoded, 'metadata.mode'));
        $this->assertContains('gsc_enabled_false', $decoded['issues'] ?? []);
        $this->assertContains('gsc_live_api_disabled', $decoded['issues'] ?? []);
        $this->assertContains('gsc_property_url_missing', $decoded['issues'] ?? []);
        $this->assertContains('external_api_gate_disabled', $decoded['issues'] ?? []);
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.search_channel_enqueue_allowed', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.search_provider_submission_allowed', true));
        Http::assertNothingSent();
    }

    #[Test]
    public function gsc_live_preflight_can_be_ready_without_printing_credentials_or_calling_google(): void
    {
        Http::fake();
        $this->enableAccessTokenConfig();

        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'gsc_foundation',
            '--gsc-live-preflight' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertSame('ready', data_get($decoded, 'metadata.live_readiness.status'));
        $this->assertSame('access_token', data_get($decoded, 'metadata.live_readiness.auth_mode'));
        $this->assertSame('access_token_env', data_get($decoded, 'metadata.live_readiness.credential_source'));
        $this->assertTrue((bool) data_get($decoded, 'metadata.live_readiness.credential_valid'));
        $this->assertTrue((bool) data_get($decoded, 'metadata.live_readiness.live_read_allowed'));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertStringNotContainsString('secret-gsc-token', $output);
        $this->assertStringNotContainsString('Authorization', $output);
        Http::assertNothingSent();
    }

    #[Test]
    public function readonly_adapter_fetches_searchanalytics_rows_only_after_explicit_live_read_gate(): void
    {
        $this->enableAccessTokenConfig();

        Http::fake([
            'searchconsole.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'keys' => ['mbti测试', 'https://fermatmind.com/zh/articles/mbti-basics'],
                        'clicks' => 0,
                        'impressions' => 6,
                        'ctr' => 0,
                        'position' => 9.0,
                    ],
                ],
            ], 200),
        ]);

        $adapter = new GscReadonlyLiveAdapter;

        $blocked = $adapter->fetchSearchAnalyticsRows([
            'startDate' => '2026-06-01',
            'endDate' => '2026-06-17',
            'dimensions' => ['query', 'page'],
        ], [
            'allow_external_api_calls' => true,
        ]);

        $this->assertSame('blocked', $blocked['status'] ?? null);
        $this->assertContains('live_read_not_explicitly_requested', $blocked['issues'] ?? []);
        $this->assertFalse((bool) ($blocked['external_calls_attempted'] ?? true));
        Http::assertNothingSent();

        $result = $adapter->fetchSearchAnalyticsRows([
            'startDate' => '2026-06-01',
            'endDate' => '2026-06-17',
            'dimensions' => ['query', 'page'],
            'rowLimit' => 500,
        ], [
            'allow_external_api_calls' => true,
            'execute_live_read' => true,
        ]);

        $this->assertSame('success', $result['status'] ?? null);
        $this->assertTrue((bool) ($result['external_calls_attempted'] ?? false));
        $this->assertFalse((bool) ($result['writes_attempted'] ?? true));
        $this->assertSame(1, $result['rows_seen'] ?? null);
        $this->assertSame('live_gsc_api', $result['rows'][0]['data_origin'] ?? null);
        $this->assertSame('https://fermatmind.com/zh/articles/mbti-basics', $result['rows'][0]['page'] ?? null);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Afermatmind.com/searchAnalytics/query')
                && $request->hasHeader('Authorization', 'Bearer secret-gsc-token')
                && $request['rowLimit'] === 250
                && $request['type'] === 'web';
        });
    }

    #[Test]
    public function generated_contract_locks_no_write_no_submission_boundary(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/gsc-live-readonly-adapter.v1.json')),
            true
        );

        $this->assertIsArray($artifact);
        $this->assertSame('gsc-live-readonly-adapter.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GSC-LIVE-READONLY-ADAPTER-01', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['read_only'] ?? false));
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['live_api_enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['writes_enabled_by_default'] ?? true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.db_writes', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.opportunity_queue_enqueue', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.search_channel_submit', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.gsc_url_inspection_request_indexing', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.gsc_sitemap_submit', true));
    }

    private function enableAccessTokenConfig(): void
    {
        config([
            'seo_intel.gsc_enabled' => true,
            'seo_intel.gsc_live_api_enabled' => true,
            'seo_intel.allow_external_api_calls' => true,
            'seo_intel.gsc_property_url' => 'sc-domain:fermatmind.com',
            'seo_intel.gsc_readonly_adapter.auth_mode' => 'access_token',
            'seo_intel.gsc_readonly_adapter.access_token' => 'secret-gsc-token',
            'seo_intel.gsc_readonly_adapter.default_limit' => 250,
            'seo_intel.gsc_readonly_adapter.max_limit' => 250,
        ]);
    }
}
