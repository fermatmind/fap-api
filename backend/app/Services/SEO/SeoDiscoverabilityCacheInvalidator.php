<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use Illuminate\Support\Facades\Cache;

final class SeoDiscoverabilityCacheInvalidator
{
    /**
     * Flush article discoverability caches.
     *
     * Deletes the fresh sitemap-source cache and backend XML sitemap/ETag caches,
     * but KEEPS the stale sitemap-source cache as a safety net during the gap window
     * between publish and the next seo:warm-sitemap-source-cache run.
     *
     * @return list<string>
     */
    public function flushArticleDiscoverabilityCaches(): array
    {
        return $this->flushSharedDiscoverabilityCaches();
    }

    /**
     * @return list<string>
     */
    public function flushPersonalityPublicContentDiscoverabilityCaches(): array
    {
        return $this->flushSharedDiscoverabilityCaches();
    }

    /**
     * Legacy alias hard purge has no stale fallback authority: both sitemap-source
     * generations and the rendered sitemap document caches must be invalidated.
     *
     * @return list<string>
     */
    public function flushPersonalityPublicContentHardPurgeCaches(): array
    {
        $keys = [
            'sitemap-source:fresh' => SitemapSourceController::CACHE_KEY_FRESH,
            'sitemap-source:stale' => SitemapSourceController::CACHE_KEY_STALE,
            'sitemap:xml' => SitemapCache::XML_CACHE_KEY,
            'sitemap:etag' => SitemapCache::ETAG_CACHE_KEY,
        ];

        foreach ($keys as $key) {
            // A missing key is already invalidated, so false remains an idempotent success.
            Cache::forget($key);
        }

        return array_keys($keys);
    }

    /**
     * @return list<string>
     */
    private function flushSharedDiscoverabilityCaches(): array
    {
        $keys = [
            SitemapSourceController::CACHE_KEY_FRESH,
            SitemapCache::XML_CACHE_KEY,
            SitemapCache::ETAG_CACHE_KEY,
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        return $keys;
    }
}
