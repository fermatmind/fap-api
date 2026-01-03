<?php

namespace App\Providers;

use App\Services\Content\ContentPackResolver;
use App\Services\Content\ContentStore;
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

        // Bind ContentStore so app(ContentStore::class) works (no "array $chain" DI error)
        $this->app->singleton(ContentStore::class, function ($app) {
            /** @var ContentPackResolver $resolver */
            $resolver = $app->make(ContentPackResolver::class);

            // ---- Resolve args (按你的项目约定尽量多兼容几个配置名) ----
            $scaleCode = (string) (
                config('content.scale_code')
                ?? config('content.scale')
                ?? env('FAP_SCALE_CODE')
                ?? env('FAP_SCALE')
                ?? 'default'
            );

            $region = (string) (
                config('content.region')
                ?? env('FAP_REGION')
                ?? 'CN'
            );

            $locale = (string) (
                config('content.locale')
                ?? config('app.locale')
                ?? env('APP_LOCALE')
                ?? 'zh-CN'
            );

            $version = config('content.version') ?? env('FAP_CONTENT_VERSION');
            $version = is_string($version) && trim($version) !== '' ? trim($version) : null;

            // ✅ resolver->resolve() 是“单 pack”，ContentStore 需要“chain(list)”
            // 所以这里包成数组。
            $pack = $resolver->resolve($scaleCode, $region, $locale, $version);
            $chain = [$pack];

            // ctx / legacyDir：先用最安全默认（不启用 legacy ctx loader）
            $ctx = [];
            $legacyDir = (string) (config('fap.content.legacy_dir') ?? '');

            return new ContentStore($chain, $ctx, $legacyDir);
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