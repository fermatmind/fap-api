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

    public const LOCK_TTL_SECONDS = 120;

    private const FALLBACK_LASTMOD = '2026-06-08T00:00:00+00:00';

    private const FALLBACK_PATHS = [
        '/',
        '/en',
        '/zh',
        '/en/tests',
        '/zh/tests',
        '/en/tests/mbti-personality-test-16-personality-types',
        '/zh/tests/mbti-personality-test-16-personality-types',
        '/en/tests/big-five-personality-test',
        '/zh/tests/big-five-personality-test',
        '/en/tests/enneagram-personality-test',
        '/zh/tests/enneagram-personality-test',
        '/en/tests/holland-career-interest-test-riasec',
        '/zh/tests/holland-career-interest-test-riasec',
        '/en/method-boundaries',
        '/zh/method-boundaries',
        '/en/reliability-validity',
        '/zh/reliability-validity',
        '/en/privacy',
        '/zh/privacy',
    ];

    private const PRIVATE_PATH_PATTERN = '#^/(?:en|zh)?/?(?:result|results|orders?|share|pay|payment|history)(?:/|$)|^/(?:en|zh)/tests/[^/]+/take(?:/|$)#i';

    public function index(): JsonResponse
    {
        $fresh = Cache::get(self::CACHE_KEY_FRESH);
        if (is_array($fresh)) {
            return $this->cachedResponse($fresh, 'hit');
        }

        $stale = Cache::get(self::CACHE_KEY_STALE);
        if (is_array($stale)) {
            return $this->cachedResponse($stale, 'stale');
        }

        return $this->cachedResponse($this->fallbackPayload(), 'fallback');
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
     * @return array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}
     */
    public function fallbackPayload(): array
    {
        $baseUrl = $this->fallbackBaseUrl();
        $items = collect(self::FALLBACK_PATHS)
            ->map(fn (string $path): string => $this->normalizeFallbackPath($path))
            ->unique()
            ->filter(fn (string $path): bool => ! $this->isPrivateFallbackPath($path))
            ->map(fn (string $path): array => [
                'loc' => $path === '/' ? $baseUrl : $baseUrl.$path,
                'lastmod' => self::FALLBACK_LASTMOD,
            ])
            ->values()
            ->all();

        return [
            'ok' => true,
            'source' => 'backend_sitemap_generator_fallback',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param  array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}  $payload
     */
    public function storeCache(array $payload): void
    {
        Cache::put(self::CACHE_KEY_FRESH, $payload, self::FRESH_TTL_SECONDS);
        Cache::put(self::CACHE_KEY_STALE, $payload, self::STALE_TTL_SECONDS);
    }

    /**
     * @param  array{ok: bool, source: string, count: int, items: list<array{loc: string, lastmod: string}>}  $payload
     */
    private function cachedResponse(array $payload, string $cacheState): JsonResponse
    {
        $cacheControl = match ($cacheState) {
            'stale' => 'public, max-age=60, s-maxage=120',
            'fallback' => 'public, max-age=30, s-maxage=60',
            default => 'public, max-age=300, s-maxage=600',
        };

        return response()->json($payload)
            ->header('X-Fermat-Cache', $cacheState)
            ->header('Cache-Control', $cacheControl);
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

    private function fallbackBaseUrl(): string
    {
        $configured = rtrim((string) config('app.frontend_url', config('app.url', 'https://fermatmind.com')), '/');
        if ($configured === '') {
            $configured = 'https://fermatmind.com';
        }

        $parts = parse_url($configured);
        if (! is_array($parts)) {
            return 'https://fermatmind.com';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? 'fermatmind.com'));
        if ($host === 'www.fermatmind.com') {
            $host = 'fermatmind.com';
        }

        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        return $scheme.'://'.$host;
    }

    private function normalizeFallbackPath(string $path): string
    {
        $normalized = '/'.ltrim(trim($path), '/');

        $trimmed = rtrim($normalized, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    private function isPrivateFallbackPath(string $path): bool
    {
        return preg_match(self::PRIVATE_PATH_PATTERN, $path) === 1;
    }
}
