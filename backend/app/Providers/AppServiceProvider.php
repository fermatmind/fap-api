<?php

namespace App\Providers;

use App\Models\Attempt;
use App\Services\Content\ContentPack;
use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentStore;
use App\Services\ContentPackResolver;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind ContentPackResolver so app(ContentPackResolver::class) works everywhere.
        $this->app->singleton(ContentPackResolver::class, function () {
            return new ContentPackResolver();
        });

        // Bind ContentStore so app(ContentStore::class) works without hardcoded default scale.
        $this->app->singleton(ContentStore::class, function ($app) {
            /** @var ContentPackResolver $resolver */
            $resolver = $app->make(ContentPackResolver::class);
            /** @var ContentPacksIndex $packsIndex */
            $packsIndex = $app->make(ContentPacksIndex::class);

            $request = $app->bound('request') ? $app->make('request') : null;

            $attempt = null;
            $attemptId = '';
            $packId = '';
            $dirVersion = '';
            $contentPackageVersion = '';
            $scaleCode = '';
            $region = '';
            $locale = '';

            if ($request instanceof Request) {
                $attemptId = (string) (
                    $request->route('attempt_id')
                    ?? $request->route('id')
                    ?? $request->input('attempt_id')
                    ?? $request->header('X-Attempt-Id')
                    ?? ''
                );

                $packId = (string) (
                    $request->route('pack_id')
                    ?? $request->query('pack_id')
                    ?? $request->input('pack_id')
                    ?? $request->header('X-Pack-Id')
                    ?? ''
                );

                $dirVersion = (string) (
                    $request->route('dir_version')
                    ?? $request->query('dir_version')
                    ?? $request->input('dir_version')
                    ?? $request->header('X-Dir-Version')
                    ?? ''
                );

                $scaleCode = (string) (
                    $request->query('scale_code')
                    ?? $request->input('scale_code')
                    ?? $request->header('X-Scale-Code')
                    ?? ''
                );

                $region = (string) (
                    $request->query('region')
                    ?? $request->input('region')
                    ?? $request->header('X-Region')
                    ?? ''
                );

                $locale = (string) (
                    $request->query('locale')
                    ?? $request->input('locale')
                    ?? $request->header('X-Locale')
                    ?? ''
                );
            }

            if ($attemptId !== '') {
                $attempt = Attempt::where('id', $attemptId)->first();
            }

            if ($attempt) {
                $packId = (string) ($attempt->pack_id ?? $packId);
                $dirVersion = (string) ($attempt->dir_version ?? $dirVersion);
                $contentPackageVersion = (string) ($attempt->content_package_version ?? '');
                $scaleCode = (string) ($attempt->scale_code ?? $scaleCode);
                $region = (string) ($attempt->region ?? $region);
                $locale = (string) ($attempt->locale ?? $locale);
            }

            if ($packId === '') {
                $packId = (string) config('content_packs.default_pack_id', '');
            }
            if ($dirVersion === '') {
                $dirVersion = (string) config('content_packs.default_dir_version', '');
            }

            if ($packId !== '' && $dirVersion !== '') {
                $found = $packsIndex->find($packId, $dirVersion);
                if ($found['ok'] ?? false) {
                    $item = $found['item'] ?? [];
                    if ($contentPackageVersion === '') {
                        $contentPackageVersion = (string) ($item['content_package_version'] ?? '');
                    }
                    if ($scaleCode === '') {
                        $scaleCode = (string) ($item['scale_code'] ?? '');
                    }
                    if ($region === '') {
                        $region = (string) ($item['region'] ?? '');
                    }
                    if ($locale === '') {
                        $locale = (string) ($item['locale'] ?? '');
                    }
                }
            }

            $extractVersion = function (string $raw): string {
                $raw = trim($raw);
                if ($raw === '') {
                    return '';
                }

                if (substr_count($raw, '.') >= 3) {
                    $parts = explode('.', $raw);
                    return (string) implode('.', array_slice($parts, 3));
                }

                $pos = strripos($raw, '-v');
                if ($pos !== false) {
                    return substr($raw, $pos + 1);
                }

                if (str_starts_with($raw, 'v')) {
                    return $raw;
                }

                return '';
            };

            if ($contentPackageVersion === '') {
                $contentPackageVersion = $extractVersion($dirVersion);
            }
            if ($contentPackageVersion === '') {
                $contentPackageVersion = $extractVersion($packId);
            }

            if ($region === '') {
                $region = (string) config('content_packs.default_region', 'GLOBAL');
            }
            if ($locale === '') {
                $locale = (string) config('content_packs.default_locale', 'en');
            }
            if ($scaleCode === '' && $packId !== '') {
                $scaleCode = (string) strtok($packId, '.');
            }
            if ($scaleCode === '') {
                $scaleCode = 'MBTI';
            }

            $resolved = $resolver->resolve(
                $scaleCode,
                $region,
                $locale,
                (string) $contentPackageVersion,
                $dirVersion
            );

            $makePack = function (array $manifest, string $baseDir): ContentPack {
                return new ContentPack(
                    packId: (string) ($manifest['pack_id'] ?? ''),
                    scaleCode: (string) ($manifest['scale_code'] ?? ''),
                    region: (string) ($manifest['region'] ?? ''),
                    locale: (string) ($manifest['locale'] ?? ''),
                    version: (string) ($manifest['content_package_version'] ?? ''),
                    basePath: $baseDir,
                    manifest: $manifest,
                );
            };

            $chain = [];
            $chain[] = $makePack($resolved->manifest ?? [], (string) ($resolved->baseDir ?? ''));

            $fallbacks = is_array($resolved->fallbackChain ?? null) ? $resolved->fallbackChain : [];
            foreach ($fallbacks as $fb) {
                if (!is_array($fb)) {
                    continue;
                }
                $manifest = is_array($fb['manifest'] ?? null) ? $fb['manifest'] : [];
                $baseDir = (string) ($fb['base_dir'] ?? '');
                if ($manifest && $baseDir !== '') {
                    $chain[] = $makePack($manifest, $baseDir);
                }
            }

            $legacyDir = $dirVersion !== '' ? $dirVersion : basename((string) ($resolved->baseDir ?? ''));

            return new ContentStore($chain, [], $legacyDir);
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
