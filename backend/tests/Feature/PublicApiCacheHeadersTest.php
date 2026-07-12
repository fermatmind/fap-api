<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PublicApiCacheHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicApiCacheHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(PublicApiCacheHeaders::class)->group(function (): void {
            Route::get('/_test/public-cache', fn () => response()->json(['ok' => true]));
            Route::get('/_test/public-cache/private', fn () => response()->json(['ok' => true])
                ->header('Cache-Control', 'private, no-store'));
        });
    }

    public function test_anonymous_org_zero_get_receives_shared_cache_headers(): void
    {
        $response = $this->getJson('/_test/public-cache?org_id=0', ['X-FAP-Locale' => 'zh-CN']);

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
        $this->assertStringContainsString('X-FAP-Locale', implode(', ', $response->headers->all('Vary')));
    }

    public function test_authenticated_or_tenant_requests_do_not_receive_public_cache_headers(): void
    {
        $authenticated = $this->withToken('private-token')->getJson('/_test/public-cache');
        $tenant = $this->getJson('/_test/public-cache?org_id=42');

        foreach ([$authenticated, $tenant] as $response) {
            $cacheControl = (string) $response->headers->get('Cache-Control');
            $this->assertStringNotContainsString('public', $cacheControl);
            $this->assertStringNotContainsString('s-maxage', $cacheControl);
        }
    }

    public function test_existing_private_no_store_header_is_preserved(): void
    {
        $cacheControl = (string) $this->getJson('/_test/public-cache/private')->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_only_declared_public_v05_get_routes_use_the_cache_middleware(): void
    {
        $publicRoute = Route::getRoutes()->match(Request::create('/api/v0.5/articles', 'GET'));
        $privateRoute = Route::getRoutes()->match(Request::create('/api/v0.5/career/shortlist/state', 'GET'));

        $this->assertContains(PublicApiCacheHeaders::class, $publicRoute->gatherMiddleware());
        $this->assertNotContains(PublicApiCacheHeaders::class, $privateRoute->gatherMiddleware());
    }
}
