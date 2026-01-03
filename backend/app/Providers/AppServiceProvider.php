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

    // scale 固定用 default（你们 pack 的目录就是 default/...）
    $scaleCode = (string)(
        config('content.scale_code')
        ?? config('content.scale')
        ?? env('FAP_SCALE_CODE')
        ?? env('FAP_SCALE')
        ?? 'default'
    );

    // ✅ region / locale 以 content_packs 的默认值为准（来自 shared/.env）
    $region = (string) config('content_packs.default_region', 'GLOBAL');
    $locale = (string) config('content_packs.default_locale', 'en');

    // 防御：cn-mainland / cn_mainland 统一成 CN_MAINLAND
    $region = strtoupper(str_replace('-', '_', $region));

    // version：如果你传 null，就会落到 config('content.default_versions.<scaleCode>') 或 default_versions.default
    $version = config('content.version') ?? env('FAP_CONTENT_VERSION');
    $version = is_string($version) && trim($version) !== '' ? trim($version) : null;

    // ✅ 关键：ContentStore 需要 chain(list)，用 fallbackChain
    $chain = $resolver->resolveWithFallbackChain($scaleCode, $region, $locale, $version);

    // ctx / legacyDir：保持你原来的
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