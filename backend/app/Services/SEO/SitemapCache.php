<?php

namespace App\Services\SEO;

use Illuminate\Support\Facades\Cache;

class SitemapCache
{
    public const XML_CACHE_KEY = 'seo:sitemap:xml:v1';
    public const ETAG_CACHE_KEY = 'seo:sitemap:etag:v1';
    public const TTL_SECONDS = 86400;

    public function get(): ?array
    {
        $xml = Cache::get(self::XML_CACHE_KEY);
        $etag = Cache::get(self::ETAG_CACHE_KEY);

        if (!is_string($xml) || $xml === '') {
            return null;
        }

        if (!is_string($etag) || $etag === '') {
            return null;
        }

        return [
            'xml' => $xml,
            'etag' => $etag,
        ];
    }

    public function put(string $xml, string $etag): void
    {
        Cache::put(self::XML_CACHE_KEY, $xml, self::TTL_SECONDS);
        Cache::put(self::ETAG_CACHE_KEY, $etag, self::TTL_SECONDS);
    }

    public function buildEtag(string $maxUpdatedAt, int $slugCount, array $slugList): string
    {
        $normalizedList = array_values($slugList);
        $slugListHash = sha1(implode("\n", $normalizedList));
        $etagBase = $maxUpdatedAt . '|' . $slugCount . '|' . $slugListHash;

        return '"' . sha1($etagBase) . '"';
    }
}
