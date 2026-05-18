<?php

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
