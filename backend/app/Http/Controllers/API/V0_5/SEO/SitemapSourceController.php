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
        $items = collect($generator->generateUrls())
            ->map(static function (array $item): array {
                return [
                    'loc' => (string) ($item['loc'] ?? ''),
                    'lastmod' => (string) ($item['lastmod'] ?? ''),
                ];
            })
            ->filter(static fn (array $item): bool => $item['loc'] !== '')
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => count($items),
            'items' => $items,
        ]);
    }
}
