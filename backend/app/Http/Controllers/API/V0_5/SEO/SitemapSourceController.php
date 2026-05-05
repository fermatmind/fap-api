<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\SEO;

use App\Http\Controllers\Controller;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Http\JsonResponse;

class SitemapSourceController extends Controller
{
    public function index(SitemapGenerator $generator): JsonResponse
    {
        $approvedCareerJobDetailLocs = collect($generator->generateApprovedCareerJobDetailUrls())
            ->map(fn (array $item): string => $this->normalizeOwnedCanonicalUrl((string) ($item['loc'] ?? '')))
            ->filter()
            ->flip();

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
                || $approvedCareerJobDetailLocs->has($item['loc']))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => count($items),
            'items' => $items,
        ]);
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
        $parts = parse_url($loc);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';

        return preg_match('#^/(?:en|zh)/career/jobs/[^/]+$#i', $path) === 1;
    }
}
