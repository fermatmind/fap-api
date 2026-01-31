<?php

namespace Tests\Feature\SEO;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapXmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_xml_is_cached_and_filtered(): void
    {
        $nowA = Carbon::create(2026, 1, 30, 10, 0, 0);
        $nowB = Carbon::create(2026, 1, 31, 12, 0, 0);

        DB::table('scales_registry')->insert([
            [
                'code' => 'PR24_ALPHA',
                'org_id' => 0,
                'primary_slug' => 'alpha',
                'slugs_json' => json_encode(['beta', 'alpha']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $nowA,
                'updated_at' => $nowA,
            ],
            [
                'code' => 'PR24_GAMMA',
                'org_id' => 0,
                'primary_slug' => 'gamma',
                'slugs_json' => json_encode(['delta']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $nowB,
                'updated_at' => $nowB,
            ],
            [
                'code' => 'PR24_HIDDEN',
                'org_id' => 0,
                'primary_slug' => 'hidden',
                'slugs_json' => json_encode(['hidden-alt']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 0,
                'created_at' => $nowB,
                'updated_at' => $nowB,
            ],
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/xml', $contentType);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('s-maxage=86400', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=604800', $cacheControl);

        $etag = (string) $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $response->assertHeaderMissing('Set-Cookie');

        $body = (string) $response->getContent();
        $this->assertStringContainsString('<loc>https://fermatmind.com/tests/alpha</loc>', $body);
        $this->assertStringContainsString('<loc>https://fermatmind.com/tests/beta</loc>', $body);
        $this->assertStringContainsString('<loc>https://fermatmind.com/tests/gamma</loc>', $body);
        $this->assertStringContainsString('<loc>https://fermatmind.com/tests/delta</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://fermatmind.com/tests/hidden</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://fermatmind.com/tests/hidden-alt</loc>', $body);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $body);
        $this->assertStringContainsString('<priority>0.7</priority>', $body);
        $this->assertStringContainsString('<lastmod>2026-01-30</lastmod>', $body);
        $this->assertStringContainsString('<lastmod>2026-01-31</lastmod>', $body);
        $this->assertSame(1, substr_count($body, '<loc>https://fermatmind.com/tests/alpha</loc>'));

        $second = $this->withHeaders(['If-None-Match' => $etag])->get('/sitemap.xml');
        $second->assertStatus(304);
        $cacheControl304 = (string) $second->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl304);
        $this->assertStringContainsString('max-age=3600', $cacheControl304);
        $this->assertStringContainsString('s-maxage=86400', $cacheControl304);
        $this->assertStringContainsString('stale-while-revalidate=604800', $cacheControl304);
        $second->assertHeader('ETag', $etag);
        $second->assertHeaderMissing('Set-Cookie');
        $this->assertSame('', (string) $second->getContent());
    }
}
