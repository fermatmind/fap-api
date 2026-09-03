<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\SeoIntel\UrlTruth\PublicCanonicalConsumerSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SitemapXmlTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        Cache::flush();
        parent::tearDown();
    }

    public function test_sitemap_xml_reads_only_current_public_url_truth_and_supports_etag_readback(): void
    {
        $this->prepareUrlTruth();
        $this->insertUrl('/en/articles/trusted', 'en', 'article', 'trusted', '2026-08-01 08:30:00');
        $this->insertUrl('/zh/career/jobs/hold', 'zh-CN', 'career_job', 'hold', null);
        $this->insertUrl('/en/private', 'en', 'article', 'trusted', null, isPrivate: true);
        $this->insertUrl('/en/noindex', 'en', 'article', 'trusted', null, metadata: ['robots' => 'noindex,follow']);
        $this->insertUrl('/en/redirect', 'en', 'article', 'trusted', null, attributes: ['redirect_only' => true]);
        $this->insertUrl('/en/noncanonical', 'en', 'article', 'trusted', null, metadata: ['canonical_self' => false]);

        $response = $this->get('/sitemap.xml')->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('s-maxage=86400', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=604800', $cacheControl);
        $response->assertHeaderMissing('Set-Cookie');

        $body = (string) $response->getContent();
        $this->assertSame([
            'https://fermatmind.com/en/articles/trusted',
            'https://fermatmind.com/zh/career/jobs/hold',
        ], $this->xmlUrls($body));
        $this->assertStringContainsString('<lastmod>2026-08-01T08:30:00+00:00</lastmod>', $body);
        $this->assertSame(1, substr_count($body, '<lastmod>'));

        $etag = (string) $response->headers->get('ETag');
        $this->assertMatchesRegularExpression('/\A"[a-f0-9]{64}"\z/', $etag);
        $notModified = $this->withHeaders(['If-None-Match' => $etag])
            ->get('/sitemap.xml')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag)
            ->assertHeaderMissing('Set-Cookie');
        $this->assertSame($cacheControl, (string) $notModified->headers->get('Cache-Control'));
    }

    public function test_sitemap_xml_returns_404_when_frontend_is_public_authority(): void
    {
        config(['services.seo.public_sitemap_authority' => 'frontend']);

        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_sitemap_xml_uses_the_configured_canonical_host_and_rejects_foreign_urls(): void
    {
        $this->prepareUrlTruth('https://staging.fermatmind.com');
        $this->insertUrl('/en/tests/host-check', 'en', 'test', 'trusted', null, 'https://staging.fermatmind.com');
        $this->insertUrl('/en/tests/foreign', 'en', 'test', 'trusted', null, 'https://api.fermatmind.com');

        $body = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertSame(['https://staging.fermatmind.com/en/tests/host-check'], $this->xmlUrls($body));
        $this->assertStringNotContainsString('api.fermatmind.com', $body);
    }

    public function test_sitemap_xml_returns_validated_lkg_when_url_truth_refresh_fails(): void
    {
        $this->prepareUrlTruth();
        $this->insertUrl('/en/articles/lkg', 'en', 'article', 'trusted', '2026-08-01 08:30:00');

        $first = $this->get('/sitemap.xml')->assertOk();
        $etag = (string) $first->headers->get('ETag');
        Cache::forget(PublicCanonicalConsumerSnapshot::FRESH_CACHE_KEY);
        Schema::connection('seo_intel')->drop('seo_url_entities');

        $second = $this->get('/sitemap.xml')->assertOk();
        $second->assertHeader('ETag', $etag);
        $this->assertSame((string) $first->getContent(), (string) $second->getContent());
        $this->assertSame(['https://fermatmind.com/en/articles/lkg'], $this->xmlUrls((string) $second->getContent()));
    }

    private function prepareUrlTruth(string $canonicalHost = 'https://fermatmind.com'): void
    {
        config([
            'cache.default' => 'array',
            'services.seo.public_sitemap_authority' => 'backend',
            'app.frontend_url' => $canonicalHost,
            'seo_intel.public_canonical_host' => $canonicalHost,
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');
        Cache::flush();

        $defaultConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('seo_intel');
        try {
            foreach ([
                '2026_05_17_000100_create_seo_urls_table.php',
                '2026_05_17_000200_create_seo_url_entities_table.php',
                '2026_08_25_020000_expand_url_truth_current_bindings.php',
                '2026_08_28_030000_expand_url_truth_material_authority.php',
            ] as $file) {
                (require database_path('migrations/seo_intel/'.$file))->up();
            }
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $attributes */
    private function insertUrl(
        string $path,
        string $locale,
        string $type,
        string $materialState,
        ?string $materialLastmod,
        string $host = 'https://fermatmind.com',
        bool $isPrivate = false,
        array $metadata = [],
        array $attributes = [],
    ): void {
        $url = rtrim($host, '/').$path;
        $hash = hash('sha256', rtrim($url, '/'));
        $entity = trim(basename($path), '/');

        DB::connection('seo_intel')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => $url,
            'locale' => $locale,
            'page_entity_type' => $type,
            'page_family' => $type,
            'entity_id_or_slug' => $entity,
            'source_authority' => 'cms',
            'authority_revision' => hash('sha256', 'authority|'.$url),
            'canonical_revision' => $hash,
            'indexability_state' => 'indexable',
            'is_private_flow' => $isPrivate,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'material_authority_state' => $materialState,
            'material_lastmod_at' => $materialLastmod,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::connection('seo_intel')->table('seo_url_entities')->insert([
            'canonical_url_hash' => $hash,
            'locale' => $locale,
            'page_entity_type' => $type,
            'page_family' => $type,
            'entity_id_or_slug' => $entity,
            'entity_source' => 'cms',
            'authority_status' => 'published_approved',
            'authority_revision' => hash('sha256', 'authority|'.$url),
            'canonical_revision' => $hash,
            'binding_status' => 'current',
            'current_binding_key' => hash('sha256', $type.'|'.$entity.'|'.$locale),
            'attributes_json' => json_encode($attributes, JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** @return list<string> */
    private function xmlUrls(string $body): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $body, $matches);

        return $matches[1];
    }
}
