<?php

namespace App\Http\Controllers;

use App\Services\SeoIntel\UrlTruth\PublicCanonicalConsumerSnapshot;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    private const CACHE_CONTROL = 'public, max-age=3600, s-maxage=86400, stale-while-revalidate=604800';

    public function index(Request $request, PublicCanonicalConsumerSnapshot $snapshot): Response
    {
        $authority = strtolower(trim((string) config('services.seo.public_sitemap_authority', 'frontend')));
        if ($authority !== 'backend') {
            abort(404);
        }

        $payload = $snapshot->read();
        $xml = $snapshot->renderSitemapXml();
        $etag = '"'.$payload['fingerprint'].'"';

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
