<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnalyticsProviderFreshnessCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('analytics.provider_freshness.enabled', true);
        config()->set('analytics.provider_freshness.ga4.enabled', false);
        config()->set('analytics.provider_freshness.baidu.enabled', false);
        config()->set('analytics.provider_freshness.cache_store', 'array');
        Cache::store('array')->flush();
    }

    public function test_dry_run_emits_sanitized_json_without_writing_cache_or_sending_unconfigured_requests(): void
    {
        Http::fake();

        $exit = Artisan::call('analytics:refresh-provider-freshness', [
            '--json' => true,
            '--dry-run' => true,
        ]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('analytics.provider_freshness.v1', $decoded['schema_version']);
        $this->assertSame('unconfigured', $decoded['providers']['ga4']['status']);
        $this->assertSame('unconfigured', $decoded['providers']['baidu']['status']);
        $this->assertNull(Cache::store('array')->get('analytics:provider-freshness:v1'));
        $this->assertStringNotContainsString('token', strtolower($output));
        $this->assertStringNotContainsString('private_key', strtolower($output));
        Http::assertNothingSent();
    }
}
