<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Services\SEO\SitemapCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class BigFive114SitemapAuthorityRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_packages_produce_exact_114_canonical_big_five_sitemap_entries(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->artisan('personality-public-assets:import', [
            '--source' => '../generated/big-five-124-publish-import-dryrun/big_five_124_merged_v1_seed.json',
            '--allow-indexable' => true,
            '--write' => true,
        ])->assertExitCode(0);

        Cache::put(SitemapSourceController::CACHE_KEY_FRESH, ['old' => true], 600);
        Cache::put(SitemapSourceController::CACHE_KEY_STALE, ['safety' => true], 600);
        Cache::put(SitemapCache::XML_CACHE_KEY, '<old/>', 600);
        Cache::put(SitemapCache::ETAG_CACHE_KEY, 'old-etag', 600);

        $this->artisan('personality-public-assets:import', [
            '--source' => '../generated/big-five-114-indexability-publish-gate/big_five_93_indexability_promotion_v1_seed.json',
            '--allow-indexable' => true,
            '--write' => true,
        ])
            ->expectsOutputToContain('discoverability_cache_keys_flushed=seo:sitemap-source:v1:fresh')
            ->assertExitCode(0);

        self::assertNull(Cache::get(SitemapSourceController::CACHE_KEY_FRESH));
        self::assertSame(['safety' => true], Cache::get(SitemapSourceController::CACHE_KEY_STALE));
        self::assertNull(Cache::get(SitemapCache::XML_CACHE_KEY));
        self::assertNull(Cache::get(SitemapCache::ETAG_CACHE_KEY));

        app(PublicCareerAuthorityResponseCache::class)->warm();
        $paths = collect(app(SitemapGenerator::class)->generateUrls())
            ->pluck('loc')
            ->filter(static fn (string $loc): bool => preg_match('#^https://fermatmind\.com/(?:en|zh)/personality/big-five(?:/|$)#', $loc) === 1)
            ->map(static fn (string $loc): string => (string) parse_url($loc, PHP_URL_PATH))
            ->unique()
            ->sort()
            ->values();

        self::assertCount(104, $paths);
        self::assertCount(52, $paths->filter(static fn (string $path): bool => str_starts_with($path, '/en/')));
        self::assertCount(52, $paths->filter(static fn (string $path): bool => str_starts_with($path, '/zh/')));
        self::assertCount(30, $paths->filter(static fn (string $path): bool => str_starts_with($path, '/en/personality/big-five/facets/')));
        self::assertCount(30, $paths->filter(static fn (string $path): bool => str_starts_with($path, '/zh/personality/big-five/facets/')));
        self::assertContains('/en/personality/big-five/facets', $paths);
        self::assertContains('/zh/personality/big-five/facets', $paths);

        foreach (BigFiveCanonicalRouteCatalog::ZH_REDIRECT_ONLY_ALIASES as $alias) {
            self::assertNotContains('/zh/personality/big-five/'.$alias, $paths);
            self::assertNotContains('/en/personality/big-five/'.$alias, $paths);
        }
    }
}
