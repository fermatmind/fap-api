<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\SEO;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Http\Controllers\Controller;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SitemapSourceController extends Controller
{
    public const CACHE_KEY_FRESH = 'seo:sitemap-source:v1:fresh';

    public const CACHE_KEY_STALE = 'seo:sitemap-source:v1:stale';

    public const CACHE_KEY_LOCK = 'seo:sitemap-source:v1:lock';

    public const FRESH_TTL_SECONDS = 600;

    public const STALE_TTL_SECONDS = 86400;

    private const LOCK_TTL_SECONDS = 120;

    public function index(SitemapGenerator $generator, CareerRuntimePublishProjectionLookup $projection): JsonResponse
    {
        $fresh = Cache::get(self::CACHE_KEY_FRESH);
        if (is_array($fresh)) {
            return $this->cachedResponse($fresh, 'hit');
        }

        $stale = Cache::get(self::CACHE_KEY_STALE);
        if (is_array($stale)) {
            return $this->cachedResponse($stale, 'stale');
        }

        $lock = Cache::lock(self::CACHE_KEY_LOCK, self::LOCK_TTL_SECONDS);
        if ($lock->get()) {
            try {
                $payload = $this->buildPayload($generator, $projection);
                $this->storeCache($payload);

                return $this->cachedResponse($payload, 'miss');
            } catch (\Throwable $throwable) {
                $staleRetry = Cache::get(self::CACHE_KEY_STALE);
                if (is_array($staleRetry)) {
                    return $this->cachedResponse($staleRetry, 'stale');
                }

                throw $throwable;
            } finally {
                $lock->release();
            }
        }

        $staleRetry = Cache::get(self::CACHE_KEY_STALE);
        if (is_array($staleRetry)) {
            return $this->cachedResponse($staleRetry, 'stale');
        }

        $payload = $this->buildPayload($generator, $projection);
        $this->storeCache($payload);

        return $this->cachedResponse($payload, 'miss');
    }

    /**
     * @return array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}
     */
    public function buildPayload(SitemapGenerator $generator, CareerRuntimePublishProjectionLookup $projection): array
    {
        $items = collect($generator->generateUrls())
            ->map(static function (array $item): array {
                return [
                    'loc' => (string) ($item['loc'] ?? ''),
                    'lastmod' => (string) ($item['lastmod'] ?? ''),
                ];
            })
            ->filter(static fn (array $item): bool => $item['loc'] !== '')
            ->map(fn (array $item): array => [
                ...$item,
                'loc' => $this->normalizeOwnedCanonicalUrl($item['loc']),
            ])
            ->filter(fn (array $item): bool => ! $this->isCareerJobDetailUrl($item['loc'])
                || $this->isRuntimePublishedCareerJobDetailUrl($item['loc'], $projection))
            ->values()
            ->all();

        return [
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param  array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}  $payload
     */
    private function cachedResponse(array $payload, string $cacheState): JsonResponse
    {
        $cacheControl = $cacheState === 'stale'
            ? 'public, max-age=60, s-maxage=120'
            : 'public, max-age=300, s-maxage=600';

        return response()->json($payload)
            ->header('X-Fermat-Cache', $cacheState)
            ->header('Cache-Control', $cacheControl);
    }

    /**
     * @param  array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}  $payload
     */
    private function storeCache(array $payload): void
    {
        Cache::put(self::CACHE_KEY_FRESH, $payload, self::FRESH_TTL_SECONDS);
        Cache::put(self::CACHE_KEY_STALE, $payload, self::STALE_TTL_SECONDS);
    }

    private function normalizeOwnedCanonicalUrl(string $loc): string
    {
        $normalized = trim($loc);
        if ($normalized === '') {
            return '';
        }

        $parts = parse_url($normalized);
        if (! is_array($parts)) {
            return $normalized;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'fermatmind.com' && $host !== 'www.fermatmind.com') {
            return $normalized;
        }

        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return 'https://fermatmind.com'.$path.$query;
    }

    private function isCareerJobDetailUrl(string $loc): bool
    {
        return $this->careerJobDetailRouteParts($loc) !== null;
    }

    private function isRuntimePublishedCareerJobDetailUrl(string $loc, CareerRuntimePublishProjectionLookup $projection): bool
    {
        $route = $this->careerJobDetailRouteParts($loc);
        if ($route === null) {
            return false;
        }

        $item = $projection->itemForSlug($route['slug'], $route['locale']);
        if (! is_array($item)) {
            return false;
        }

        return ($item['public_resolution_type'] ?? null) === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB
            && ($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED
            && ($item['detail_route_enabled'] ?? false) === true
            && ($item['sitemap_live'] ?? false) === true
            && ($item['canonical_self'] ?? false) === true
            && ($item['robots_indexable'] ?? false) === true
            && ($item['release_gate_pass'] ?? false) === true;
    }

    /**
     * @return array{locale: string, slug: string}|null
     */
    private function careerJobDetailRouteParts(string $loc): ?array
    {
        $parts = parse_url($loc);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        if (preg_match('#^/(en|zh)/career/jobs/([^/]+)$#i', $path, $matches) !== 1) {
            return null;
        }

        $slug = strtolower(trim(rawurldecode((string) $matches[2])));
        if ($slug === '') {
            return null;
        }

        return [
            'locale' => strtolower((string) $matches[1]) === 'zh' ? 'zh' : 'en',
            'slug' => $slug,
        ];
    }
}
