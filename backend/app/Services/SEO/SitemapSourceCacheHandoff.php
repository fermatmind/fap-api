<?php

declare(strict_types=1);

namespace App\Services\SEO;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Support\PublicProjectionCache as Cache;
use Illuminate\Cache\RedisStore;

final class SitemapSourceCacheHandoff
{
    // Compare code and small authority manifests, never enumerate Career page bodies or the database.
    public const INPUT_PATHS = [
        '.env', 'composer.lock', 'config', 'app/Providers',
        'app/Services/SEO', 'app/Services/Cms', 'app/Services/Scale',
        'app/Services/Career', 'app/Services/Enneagram', 'app/Models',
        'app/Domain/Career', 'app/Domain/Personality',
        'app/Support/CanonicalFrontendUrl.php', 'app/Support/PublicProjectionCache.php',
        'app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php',
        'app/Console/Commands/WarmSitemapSourceCacheCommand.php',
        'content_assets/career/current/manifest.json',
        'content_assets/personality_public/current/manifest.json',
        'content_assets/public_content_53/enneagram_public_copy.en.json',
        'content_assets/public_content_53/enneagram_metrics.en.json',
    ];

    public function carry(string $previousBackend): array
    {
        $previous = realpath($previousBackend);
        $candidate = realpath(base_path());
        if (! is_string($previous) || ! is_string($candidate)
            || basename($previous) !== 'backend' || basename($candidate) !== 'backend'
            || dirname(dirname($previous)) !== dirname(dirname($candidate))) {
            throw new \RuntimeException('SITEMAP_HANDOFF_RELEASE_SCOPE');
        }
        if (! hash_equals($this->fingerprint($previous), $this->fingerprint($candidate))) {
            throw new \RuntimeException('SITEMAP_HANDOFF_INPUT_CHANGED');
        }

        $store = Cache::store()->getStore();
        if (! $store instanceof RedisStore) {
            throw new \RuntimeException('SITEMAP_HANDOFF_STORE_UNSUPPORTED');
        }
        $sourceKey = SitemapSourceController::CACHE_KEY_FRESH.':release:'.hash('sha256', $previous);
        $candidateLock = Cache::lock(SitemapSourceController::CACHE_KEY_LOCK, 30);

        return $candidateLock->block(5, function () use ($sourceKey, $store): array {
            // Publication invalidators use the same mutation boundary; never copy during withdrawal.
            return Cache::mutation(function () use ($sourceKey, $store): array {
                $payload = Cache::get($sourceKey);
                $ttl = (int) $store->connection()->ttl($store->getPrefix().$sourceKey);
                // Leave a full scheduler interval for the new generation's natural refresh.
                if ($ttl < 300 || $ttl > SitemapSourceController::FRESH_TTL_SECONDS || ! is_array($payload)) {
                    throw new \RuntimeException('SITEMAP_HANDOFF_SOURCE_NOT_FRESH');
                }
                // Preserve remaining freshness, source validation, independent keys and readback.
                app(SitemapSourceController::class)->storeCache($payload, $ttl);

                return ['status' => 'carried', 'count' => $payload['count'], 'ttl_seconds' => $ttl];
            });
        });
    }

    private function fingerprint(string $root): string
    {
        $files = [];
        foreach (self::INPUT_PATHS as $relative) {
            $path = $root.'/'.$relative;
            if (! file_exists($path)) {
                throw new \RuntimeException('SITEMAP_HANDOFF_INPUT_UNAVAILABLE');
            }
            $paths = is_dir($path)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
                : [new \SplFileInfo($path)];
            foreach ($paths as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $name = substr($file->getPathname(), strlen($root) + 1);
                if ($name === 'app/Services/SEO/SitemapSourceCacheHandoff.php') {
                    continue;
                }
                $digest = hash_file('sha256', $file->getPathname());
                if (! is_string($digest)) {
                    throw new \RuntimeException('SITEMAP_HANDOFF_INPUT_UNREADABLE');
                }
                $files[$name] = $digest;
            }
        }
        ksort($files, SORT_STRING);

        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
