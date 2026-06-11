<?php

use App\Http\Controllers\Ops\ArticleDraftPreviewController;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\EnsureCmsAdminAuthorized;
use App\Http\Middleware\OpsAccessControl;
use App\Http\Middleware\RequireOpsOrgSelected;
use App\Http\Middleware\ResolveOrgContext;
use App\Http\Middleware\SetOpsRequestContext;
use App\Http\Controllers\SitemapController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $requestHost = strtolower((string) request()->getHost());
    $opsAllowedHost = strtolower(trim((string) config('ops.allowed_host', '')));
    $opsAdminUrlHost = strtolower((string) parse_url((string) config('admin.url', ''), PHP_URL_HOST));

    $isOpsEntryHost = config('admin.panel_enabled') && (
        ($opsAllowedHost !== '' && $requestHost === $opsAllowedHost)
        || ($opsAdminUrlHost !== '' && $requestHost === $opsAdminUrlHost)
        || str_starts_with($requestHost, 'ops.')
    );

    if ($isOpsEntryHost) {
        return redirect('/ops');
    }

    if ($requestHost === 'api.fermatmind.com' || $requestHost === 'staging-api.fermatmind.com') {
        return response()->json([
            'ok' => true,
            'service' => 'FermatMind API',
            'message' => 'API root is online. Use versioned /api routes for application traffic.',
            'healthz' => 'restricted',
        ]);
    }

    return view('welcome');
});

Route::get('/sitemap.xml', [SitemapController::class, 'index'])
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
    ]);

if (config('admin.panel_enabled')) {
    Route::permanentRedirect('/admin', '/ops');
    Route::get('/admin/{path}', fn (string $path) => redirect('/ops/'.$path, 301))
        ->where('path', '.*');

    Route::get('/ops/article-preview/{article}', ArticleDraftPreviewController::class)
        ->middleware([
            SetOpsRequestContext::class,
            AdminAuth::class,
            ResolveOrgContext::class,
            EnsureAdminTotpVerified::class,
            RequireOpsOrgSelected::class,
            OpsAccessControl::class,
            EnsureCmsAdminAuthorized::class.':read',
        ])
        ->whereNumber('article')
        ->name('ops.articles.preview');

    foreach ([
        'categories' => 'article-categories',
        'tags' => 'article-tags',
        'approvals' => 'admin-approvals',
    ] as $legacyPath => $canonicalPath) {
        Route::get('/ops/'.$legacyPath, static function (Request $request) use ($canonicalPath) {
            $query = $request->getQueryString();

            return redirect('/ops/'.$canonicalPath.($query !== null && $query !== '' ? '?'.$query : ''), 302);
        });
    }
} else {
    Route::get('/admin', fn () => abort(404));
    Route::get('/ops', fn () => abort(404));
}

if (! config('tenant.panel_enabled')) {
    Route::get('/tenant', fn () => abort(404));
}
