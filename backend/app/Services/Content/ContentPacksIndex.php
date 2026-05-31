<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class ContentPacksIndex
{
    public const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private ?ContentPacksIndexArtifactStore $artifactStore = null,
        private ?ContentPacksIndexFallbackScanner $fallbackScanner = null,
    ) {}

    public function getIndex(bool $refresh = false): array
    {
        $driver = (string) config('content_packs.driver', 'local');
        $driver = $driver === 's3' ? 's3' : 'local';

        $packsRoot = $driver === 's3'
            ? (string) config('content_packs.cache_dir', '')
            : (string) config('content_packs.root', '');

        $packsRootFs = $this->normalizeFilesystemRoot($packsRoot);
        $defaults = $this->defaults();

        $cacheKey = CacheKeys::packsIndex();
        $cache = $this->cacheStore();

        if (! $refresh) {
            $artifact = $this->artifactStore()->readConfigured($packsRootFs, $driver, $defaults);
            if (is_array($artifact)) {
                try {
                    $cache->put($cacheKey, $artifact, self::CACHE_TTL_SECONDS);
                } catch (\Throwable $e) {
                    // Artifact reads are already bounded by file signatures; cache write failure is non-fatal.
                }

                return $artifact;
            }

            try {
                $cached = $cache->get($cacheKey);
            } catch (\Throwable $e) {
                try {
                    $cache = Cache::store();
                    $cached = $cache->get($cacheKey);
                } catch (\Throwable $e2) {
                    $cached = null;
                }
            }

            if (is_array($cached) && $this->isCachedIndexUsable($cached, $packsRootFs, $driver, $defaults)) {
                return $cached;
            }
        }

        $index = $this->fallbackScanner()->scan($packsRootFs, $driver, $defaults);

        $this->cacheIndex($cache, $cacheKey, $index);

        return $index;
    }

    public function find(string $packId, string $dirVersion): array
    {
        $packId = trim($packId);
        $dirVersion = trim($dirVersion);
        if ($packId === '' || $dirVersion === '') {
            return [
                'ok' => false,
                'error' => 'NOT_FOUND',
            ];
        }

        $index = $this->getIndex(false);
        if ($index['ok'] ?? false) {
            $item = $this->findInItems((array) ($index['items'] ?? []), $packId, $dirVersion);
            if (is_array($item) && $this->isItemFresh($item)) {
                return [
                    'ok' => true,
                    'item' => $item,
                ];
            }
        }

        // Miss or stale hit -> force refresh once.
        $index = $this->getIndex(true);
        if ($index['ok'] ?? false) {
            $item = $this->findInItems((array) ($index['items'] ?? []), $packId, $dirVersion);
            if (is_array($item) && $this->isItemFresh($item)) {
                return [
                    'ok' => true,
                    'item' => $item,
                ];
            }
        }

        return [
            'ok' => false,
            'error' => 'NOT_FOUND',
        ];
    }

    private function cacheStore()
    {
        try {
            return Cache::store('hot_redis');
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }

    private function artifactStore(): ContentPacksIndexArtifactStore
    {
        if (! $this->artifactStore instanceof ContentPacksIndexArtifactStore) {
            $this->artifactStore = app(ContentPacksIndexArtifactStore::class);
        }

        return $this->artifactStore;
    }

    private function fallbackScanner(): ContentPacksIndexFallbackScanner
    {
        if (! $this->fallbackScanner instanceof ContentPacksIndexFallbackScanner) {
            $this->fallbackScanner = app(ContentPacksIndexFallbackScanner::class);
        }

        return $this->fallbackScanner;
    }

    private function normalizeFilesystemRoot(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $normalized = rtrim($path, '/\\');
        if ($this->isAbsolutePath($normalized)) {
            return $normalized;
        }

        return rtrim(base_path($normalized), '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }

    private function defaults(): array
    {
        return [
            'default_pack_id' => (string) config('content_packs.default_pack_id', ''),
            'default_dir_version' => (string) config('content_packs.default_dir_version', ''),
            'default_region' => (string) config('content_packs.default_region', ''),
            'default_locale' => (string) config('content_packs.default_locale', ''),
            'region_fallbacks' => (array) config('content_packs.region_fallbacks', []),
            'locale_fallback' => (bool) config('content_packs.locale_fallback', false),
        ];
    }

    private function isCachedIndexUsable(array $index, string $packsRootFs, string $driver, array $defaults): bool
    {
        if (($index['ok'] ?? false) !== true) {
            return false;
        }

        if ((string) ($index['driver'] ?? '') !== $driver) {
            return false;
        }

        if (rtrim((string) ($index['packs_root'] ?? ''), '/\\') !== rtrim($packsRootFs, '/\\')) {
            return false;
        }

        if ((array) ($index['defaults'] ?? []) !== $defaults) {
            return false;
        }

        return is_array($index['items'] ?? null) && (array) ($index['items'] ?? []) !== [];
    }

    private function cacheIndex($cache, string $cacheKey, array $index): void
    {
        if (! $this->isCacheableIndex($index)) {
            return;
        }

        try {
            $cache->put($cacheKey, $index, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $e) {
            try {
                Cache::store()->put($cacheKey, $index, self::CACHE_TTL_SECONDS);
            } catch (\Throwable $e2) {
                Log::warning('CONTENT_PACKS_INDEX_CACHE_WRITE_FAILED', [
                    'cache_key' => $cacheKey,
                    'store' => 'default',
                    'ttl' => self::CACHE_TTL_SECONDS,
                    'exception' => $e2,
                ]);
            }
        }
    }

    private function isCacheableIndex(array $index): bool
    {
        if (($index['ok'] ?? false) !== true) {
            return false;
        }

        return is_array($index['items'] ?? null) && (array) ($index['items'] ?? []) !== [];
    }

    private function findInItems(array $items, string $packId, string $dirVersion): ?array
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['pack_id'] ?? '') !== $packId) {
                continue;
            }
            if ((string) ($item['dir_version'] ?? '') !== $dirVersion) {
                continue;
            }

            return $item;
        }

        return null;
    }

    private function isItemFresh(array $item): bool
    {
        $manifestPath = (string) ($item['manifest_path'] ?? '');
        $versionPath = (string) ($item['version_path'] ?? '');
        $questionsPath = (string) ($item['questions_path'] ?? '');
        if ($manifestPath === '' || $versionPath === '' || $questionsPath === '') {
            return false;
        }

        $manifestSig = $this->fileSignature($manifestPath);
        $versionSig = $this->fileSignature($versionPath);
        $questionsSig = $this->fileSignature($questionsPath);

        if ($manifestSig === null || $versionSig === null || $questionsSig === null) {
            return false;
        }

        if (! $this->signatureMatches($item, 'manifest', $manifestSig)) {
            return false;
        }
        if (! $this->signatureMatches($item, 'version', $versionSig)) {
            return false;
        }
        if (! $this->signatureMatches($item, 'questions', $questionsSig)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{mtime:int,size:int,sha256:string}|null
     */
    private function fileSignature(string $path): ?array
    {
        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        try {
            clearstatcache(true, $path);
            $mtimeRaw = @filemtime($path);
            $sizeRaw = @filesize($path);
            $hashRaw = @hash_file('sha256', $path);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_int($mtimeRaw) && ! is_numeric($mtimeRaw)) {
            return null;
        }
        if (! is_int($sizeRaw) && ! is_numeric($sizeRaw)) {
            return null;
        }

        return [
            'mtime' => (int) $mtimeRaw,
            'size' => (int) $sizeRaw,
            'sha256' => is_string($hashRaw) ? $hashRaw : '',
        ];
    }

    /**
     * @param  array{mtime:int,size:int,sha256:string}  $signature
     */
    private function signatureMatches(array $item, string $prefix, array $signature): bool
    {
        $mtimeKey = $prefix.'_mtime';
        $sizeKey = $prefix.'_size';
        $hashKey = $prefix.'_sha256';

        $expectedHash = trim((string) ($item[$hashKey] ?? ''));
        if ($expectedHash !== '') {
            $actualHash = trim((string) ($signature['sha256'] ?? ''));

            return $actualHash !== '' && hash_equals($expectedHash, $actualHash);
        }

        if (! array_key_exists($mtimeKey, $item) || ! array_key_exists($sizeKey, $item)) {
            return false;
        }

        return (int) ($item[$mtimeKey] ?? -1) === (int) ($signature['mtime'] ?? -2)
            && (int) ($item[$sizeKey] ?? -1) === (int) ($signature['size'] ?? -2);
    }
}
