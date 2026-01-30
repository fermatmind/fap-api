<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetsBaseUrlFromCdnMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_raven_assets_use_cdn_map_base_url(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\Pr16IqRavenDemoSeeder']);

        $response = $this->getJson('/api/v0.3/scales/IQ_RAVEN/questions', [
            'X-Region' => 'US',
            'Accept-Language' => 'en-US',
        ]);

        $response->assertStatus(200);

        $doc = $response->json('questions');
        $image = $doc['items'][0]['assets']['image'] ?? '';

        $base = (string) config('cdn_map.map.US.assets_base_url');
        $this->assertNotSame('', $base);
        $expectedPrefix = rtrim($base, '/') . '/default/IQ-RAVEN-CN-v0.3.0-DEMO/';
        $this->assertIsString($image);
        $this->assertStringStartsWith($expectedPrefix, $image);
    }
}
