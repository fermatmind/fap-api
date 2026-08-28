<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\UrlTruth\PublicCanonicalConsumerSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform10PublicConsumerConvergenceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function sitemap_llms_and_llms_full_share_the_exact_public_canonical_set_and_material_lastmod(): void
    {
        $this->prepareSchema();
        $this->insertUrl('/en/articles/trusted', 'en', 'article', 'trusted', '2026-08-01 08:30:00');
        $this->insertUrl('/zh/career/jobs/hold', 'zh-CN', 'career_job', 'hold', null);
        $this->insertUrl('/en/private', 'en', 'article', 'trusted', '2026-08-02 00:00:00', isPrivate: true);
        $this->insertUrl('/en/noindex', 'en', 'article', 'trusted', '2026-08-02 00:00:00', metadata: ['robots' => 'noindex,follow']);
        $this->insertUrl('/en/redirect', 'en', 'article', 'trusted', '2026-08-02 00:00:00', attributes: ['redirect_only' => true]);
        $this->insertUrl('/en/noncanonical', 'en', 'article', 'trusted', '2026-08-02 00:00:00', metadata: ['canonical_self' => false]);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        $llmsFull = $this->get('/llms-full.txt')->assertOk()->getContent();

        $expected = [
            'https://fermatmind.com/en/articles/trusted',
            'https://fermatmind.com/zh/career/jobs/hold',
        ];
        self::assertSame($expected, $this->xmlUrls($sitemap));
        self::assertSame($expected, $this->textUrls($llms));
        self::assertSame($expected, $this->textUrls($llmsFull));
        self::assertStringContainsString('<lastmod>2026-08-01T08:30:00+00:00</lastmod>', $sitemap);
        self::assertStringContainsString('https://fermatmind.com/en/articles/trusted | Last-Modified: 2026-08-01T08:30:00+00:00', $llms);
        self::assertStringNotContainsString('https://fermatmind.com/zh/career/jobs/hold | Last-Modified:', $llms);
        self::assertSame(1, substr_count($sitemap, '<lastmod>'));
        self::assertSame(1, substr_count($llms, ' | Last-Modified:'));
        self::assertSame(1, substr_count($llmsFull, ' | Last-Modified:'));
    }

    #[Test]
    public function generation_failure_returns_the_validated_lkg_without_changing_the_active_pointer(): void
    {
        $this->prepareSchema();
        $this->insertUrl('/en/articles/lkg', 'en', 'article', 'trusted', '2026-07-01 00:00:00');
        $service = app(PublicCanonicalConsumerSnapshot::class);
        $first = $service->read();
        $pointer = Cache::get(PublicCanonicalConsumerSnapshot::POINTER_CACHE_KEY);

        Cache::forget(PublicCanonicalConsumerSnapshot::FRESH_CACHE_KEY);
        Schema::connection('seo_intel')->drop('seo_url_entities');

        $fallback = $service->read();
        self::assertSame($first, $fallback);
        self::assertSame($pointer, Cache::get(PublicCanonicalConsumerSnapshot::POINTER_CACHE_KEY));
        self::assertSame(['https://fermatmind.com/en/articles/lkg'], array_column($fallback['items'], 'loc'));
    }

    #[Test]
    public function closeout_receipt_proves_bilingual_snapshot_and_lkg_without_a_destructive_probe(): void
    {
        $this->prepareSchema();
        $this->insertUrl('/en/articles/closeout', 'en', 'article', 'trusted', '2026-08-01 00:00:00');
        $service = app(PublicCanonicalConsumerSnapshot::class);
        $previous = $service->read();

        $this->insertUrl('/zh/career/jobs/closeout', 'zh-CN', 'career_job', 'hold', null);
        $receipt = $service->closeoutReceipt();

        self::assertSame('success', $receipt['status']);
        self::assertSame(2, $receipt['url_count']);
        self::assertNotSame($previous['fingerprint'], $receipt['snapshot_fingerprint']);
        self::assertSame(
            $previous,
            Cache::get('seo:url-truth-consumers:v1:snapshot:'.$previous['fingerprint']),
        );
        self::assertSame(['en' => 1, 'zh-CN' => 1], $receipt['locale_counts']);
        self::assertSame(1, $receipt['with_material_lastmod']);
        self::assertSame(1, $receipt['without_material_lastmod']);
        self::assertSame($receipt['snapshot_fingerprint'], $receipt['repeat_fingerprint']);
        self::assertTrue((bool) data_get($receipt, 'lkg.active_pointer_bound'));
        self::assertTrue((bool) data_get($receipt, 'lkg.immutable_snapshot_readable'));
        self::assertTrue((bool) data_get($receipt, 'lkg.recovery_ready_without_destructive_probe'));
        self::assertFalse((bool) data_get($receipt, 'boundaries.destructive_probe_performed', true));
        self::assertFalse((bool) data_get($receipt, 'boundaries.raw_urls_emitted', true));
    }

    #[Test]
    public function failed_closeout_keeps_the_previous_pointer_and_readable_lkg(): void
    {
        $this->prepareSchema();
        $this->insertUrl('/en/articles/closeout-lkg', 'en', 'article', 'trusted', '2026-08-01 00:00:00');
        $service = app(PublicCanonicalConsumerSnapshot::class);
        $previous = $service->read();
        $pointer = Cache::get(PublicCanonicalConsumerSnapshot::POINTER_CACHE_KEY);
        Schema::connection('seo_intel')->drop('seo_url_entities');

        try {
            $service->closeoutReceipt();
            self::fail('Closeout must fail when the candidate source is unavailable.');
        } catch (\RuntimeException) {
            self::assertSame($pointer, Cache::get(PublicCanonicalConsumerSnapshot::POINTER_CACHE_KEY));
            self::assertSame($previous, $service->read());
        }
    }

    #[Test]
    public function consumer_path_contains_no_clock_based_lastmod_fallback(): void
    {
        foreach ([
            app_path('Services/SeoIntel/UrlTruth/PublicCanonicalConsumerSnapshot.php'),
            app_path('Http/Controllers/SitemapController.php'),
            app_path('Http/Controllers/API/V0_5/SEO/LlmsController.php'),
        ] as $path) {
            $source = (string) file_get_contents($path);
            self::assertStringNotContainsString('now(', $source, $path);
            self::assertStringNotContainsString('Carbon::now', $source, $path);
        }
    }

    private function prepareSchema(): void
    {
        config([
            'cache.default' => 'array',
            'services.seo.public_sitemap_authority' => 'backend',
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
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
        DB::setDefaultConnection('seo_intel');
        foreach ([
            '2026_05_17_000100_create_seo_urls_table.php',
            '2026_05_17_000200_create_seo_url_entities_table.php',
            '2026_08_25_020000_expand_url_truth_current_bindings.php',
            '2026_08_28_030000_expand_url_truth_material_authority.php',
        ] as $file) {
            (require database_path('migrations/seo_intel/'.$file))->up();
        }
        DB::setDefaultConnection((string) config('database.default'));
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $attributes */
    private function insertUrl(
        string $path,
        string $locale,
        string $type,
        string $materialState,
        ?string $materialLastmod,
        bool $isPrivate = false,
        array $metadata = [],
        array $attributes = [],
    ): void {
        $url = 'https://fermatmind.com'.$path;
        $hash = hash('sha256', rtrim($url, '/'));
        $entity = trim(basename($path), '/');
        $identity = hash('sha256', $type.'|'.$entity.'|'.$locale);
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
            'current_binding_key' => $identity,
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

    /** @return list<string> */
    private function textUrls(string $body): array
    {
        preg_match_all('#^- (https://[^\s|]+)#m', $body, $matches);

        return $matches[1];
    }
}
