<?php

declare(strict_types=1);

namespace Tests\Feature\V0_4;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootTest extends TestCase
{
    use RefreshDatabase;

    public function test_boot_returns_cache_headers_and_payload(): void
    {
        $response = $this->getJson('/api/v0.4/boot', [
            'X-Region' => 'CN_MAINLAND',
            'Accept-Language' => 'zh-CN,zh;q=0.9',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
        ]);

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertNotSame('', $cache);
        $this->assertStringContainsString('max-age=300', $cache);
        $this->assertStringContainsString('public', $cache);
        $response->assertHeader('Vary', 'X-Region, Accept-Language, X-FAP-Locale');
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_boot_etag_returns_304(): void
    {
        $first = $this->getJson('/api/v0.4/boot', [
            'X-Region' => 'CN_MAINLAND',
            'Accept-Language' => 'zh-CN',
        ]);

        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $second = $this->get('/api/v0.4/boot', [
            'X-Region' => 'CN_MAINLAND',
            'Accept-Language' => 'zh-CN',
            'If-None-Match' => $etag,
        ]);

        $second->assertStatus(304);
        $second->assertHeader('ETag', $etag);
        $cache = (string) $second->headers->get('Cache-Control');
        $this->assertNotSame('', $cache);
        $this->assertStringContainsString('max-age=300', $cache);
        $this->assertStringContainsString('public', $cache);
        $second->assertHeader('Vary', 'X-Region, Accept-Language, X-FAP-Locale');
    }

    public function test_boot_differs_by_region(): void
    {
        $us = $this->getJson('/api/v0.4/boot', [
            'X-Region' => 'US',
            'Accept-Language' => 'en-US,en;q=0.9',
        ]);

        $us->assertStatus(200);
        $us->assertJson([
            'ok' => true,
            'region' => 'US',
            'locale' => 'en-US',
            'currency' => 'USD',
        ]);

        $base = (string) config('cdn_map.map.US.assets_base_url');
        $this->assertNotSame('', $base);
        $this->assertSame($base, (string) $us->json('cdn.assets_base_url'));

        $cn = $this->getJson('/api/v0.4/boot', [
            'X-Region' => 'CN_MAINLAND',
            'Accept-Language' => 'zh-CN',
        ]);

        $cn->assertStatus(200);
        $cn->assertJson([
            'ok' => true,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'currency' => 'CNY',
        ]);

        $this->assertNotSame($cn->json('cdn.assets_base_url'), $us->json('cdn.assets_base_url'));
    }

    public function test_boot_prefers_x_fap_locale_over_accept_language(): void
    {
        $response = $this->getJson('/api/v0.4/boot', [
            'X-Region' => 'US',
            'Accept-Language' => 'en-US,en;q=0.9',
            'X-FAP-Locale' => 'zh-CN',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('locale', 'zh-CN');
        $response->assertHeader('Vary', 'X-Region, Accept-Language, X-FAP-Locale');
    }
}
