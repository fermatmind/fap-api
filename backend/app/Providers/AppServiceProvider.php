<?php

namespace App\Providers;

use App\Services\Content\ContentPackResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind ContentPackResolver so app(ContentPackResolver::class) works everywhere
        $this->app->singleton(ContentPackResolver::class, function () {
            $packsRoot = config('content.packs_root');
            if (!is_string($packsRoot) || trim($packsRoot) === '') {
                // Fail fast with a clear error if config is missing
                $packsRoot = base_path('../content_packages');
            }
            return new ContentPackResolver($packsRoot);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}