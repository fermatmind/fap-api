<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use Illuminate\Support\Facades\Cache;

final class SeoDiscoverabilityCacheInvalidator
{
    /**
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
     * @return list<string>
     */
    private function flushSharedDiscoverabilityCaches(): array
    {
        $keys = [
            SitemapSourceController::CACHE_KEY_FRESH,
            SitemapSourceController::CACHE_KEY_STALE,
            SitemapCache::XML_CACHE_KEY,
            SitemapCache::ETAG_CACHE_KEY,
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        return $keys;
    }
}
