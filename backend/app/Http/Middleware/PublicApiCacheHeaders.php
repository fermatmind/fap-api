<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicApiCacheHeaders
{
    private const CACHE_CONTROL = 'public, max-age=60, s-maxage=300, stale-while-revalidate=600';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isPublicAnonymousRequest($request) || ! $this->isCacheableResponse($response)) {
            return $response;
        }

        $existing = strtolower((string) $response->headers->get('Cache-Control'));
        if (str_contains($existing, 'no-store')) {
            return $response;
        }

        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        $response->setVary(['Accept-Encoding', 'X-FAP-Locale'], false);

        return $response;
    }

    private function isPublicAnonymousRequest(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->bearerToken() !== null) {
            return false;
        }

        foreach (['X-FM-Token', 'X-Org-Id', 'X-FM-Org-Id'] as $header) {
            if (trim((string) $request->header($header, '')) !== '') {
                return false;
            }
        }

        foreach (['fm_token', 'ops_org_id', (string) config('session.cookie')] as $cookie) {
            if ($cookie !== '' && $request->cookies->has($cookie)) {
                return false;
            }
        }

        return (int) $request->query('org_id', 0) === 0;
    }

    private function isCacheableResponse(Response $response): bool
    {
        return ($response->isSuccessful() || $response->getStatusCode() === 304)
            && ! $response->headers->has('Set-Cookie');
    }
}
