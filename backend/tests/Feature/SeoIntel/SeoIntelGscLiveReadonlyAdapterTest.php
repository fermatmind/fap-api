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
    public function gsc_live_read_command_returns_only_sanitized_artifact_without_writes_or_submissions(): void
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

        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'gsc_foundation',
            '--gsc-live-read' => true,
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--start-date' => '2026-06-01',
            '--end-date' => '2026-06-17',
            '--limit' => 500,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertSame('gsc_live_readonly_sidecar_read', data_get($decoded, 'metadata.mode'));
        $this->assertTrue((bool) ($decoded['external_calls_attempted'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.writes_committed', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.cms_write_allowed', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.search_channel_enqueue_allowed', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.search_provider_submission_allowed', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.indexing_request_allowed', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.opportunity_queue_eligible', true));
        $this->assertSame(1, $decoded['items_seen'] ?? null);
        $this->assertSame(250, data_get($decoded, 'metadata.row_limit'));
        $this->assertSame('2026-06-01', data_get($decoded, 'metadata.date_window.start_date'));
        $this->assertSame('2026-06-17', data_get($decoded, 'metadata.date_window.end_date'));
        $this->assertSame('live_gsc_api', data_get($decoded, 'metadata.data_quality_gate.data_origins.0'));
        $this->assertNotEmpty(data_get($decoded, 'metadata.safe_row_preview.0.query_hash'));
        $this->assertNotEmpty(data_get($decoded, 'metadata.safe_row_preview.0.canonical_url_hash'));
        $this->assertSame('m****试', data_get($decoded, 'metadata.safe_row_preview.0.query_display_masked'));
        $this->assertStringNotContainsString('mbti测试', $output);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', $output);
        $this->assertStringNotContainsString('secret-gsc-token', $output);
        $this->assertStringNotContainsString('Authorization', $output);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Afermatmind.com/searchAnalytics/query')
                && $request->hasHeader('Authorization', 'Bearer secret-gsc-token')
                && $request['rowLimit'] === 250
                && $request['startDate'] === '2026-06-01'
                && $request['endDate'] === '2026-06-17'
                && $request['dimensions'] === ['query', 'page']
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

    #[Test]
    public function hk_sidecar_runner_contract_locks_isolated_readonly_boundary(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/gsc-hk-sidecar-runner.v1.json')),
            true
        );

        $this->assertIsArray($artifact);
        $this->assertSame('gsc-hk-sidecar-runner.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GSC-HK-SIDECAR-RUNNER-01', $artifact['task'] ?? null);
        $this->assertSame('cn-hongkong', $artifact['runner_region'] ?? null);
        $this->assertTrue((bool) ($artifact['read_only'] ?? false));
        $this->assertFalse((bool) ($artifact['uses_88cn_web_process'] ?? true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.db_writes', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.seo_gsc_daily_import', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.opportunity_queue_enqueue', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.search_channel_submit', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.gsc_url_inspection_request_indexing', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.generic_proxy', true));
        $this->assertTrue((bool) ($artifact['requires_operator_approval_before_secret_install'] ?? false));
        $this->assertTrue((bool) ($artifact['requires_operator_approval_before_live_read'] ?? false));
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
