<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SitemapController;

Route::get('/', function () {
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
    Route::get('/admin/{path}', fn (string $path) => redirect('/ops/' . $path, 301))
        ->where('path', '.*');
} else {
    Route::get('/admin', fn () => abort(404));
    Route::get('/ops', fn () => abort(404));
}

if (!config('tenant.panel_enabled')) {
    Route::get('/tenant', fn () => abort(404));
}
