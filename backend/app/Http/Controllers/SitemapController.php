<?php

namespace App\Http\Controllers;

use App\Services\SEO\SitemapCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    private const CACHE_CONTROL = 'public, max-age=3600, s-maxage=86400, stale-while-revalidate=604800';

    public function index(Request $request, SitemapCache $cache, SitemapGenerator $generator)
    {
        $cached = $cache->get();
        if ($cached) {
            $xml = $cached['xml'];
            $etag = $cached['etag'];
        } else {
            $payload = $generator->generate();
            $etag = $cache->buildEtag(
                (string) ($payload['max_updated_at'] ?? ''),
                (int) ($payload['slug_count'] ?? 0),
                (array) ($payload['slug_list'] ?? [])
            );
            $xml = (string) ($payload['xml'] ?? '');
            $cache->put($xml, $etag);
        }

        $ifNoneMatch = trim((string) $request->header('If-None-Match', ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return response('', 304)
                ->header('Content-Type', 'application/xml; charset=utf-8')
                ->header('Cache-Control', self::CACHE_CONTROL)
                ->header('ETag', $etag);
        }

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=utf-8')
            ->header('Cache-Control', self::CACHE_CONTROL)
            ->header('ETag', $etag);
    }
}
