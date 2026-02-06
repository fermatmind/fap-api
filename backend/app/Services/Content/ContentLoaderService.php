<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

final class ContentLoaderService
{
    public function readText(
        string $packId,
        string $dirVersion,
        string $relativePath,
        callable $reader
    ): ?string {
        $cache = $this->cacheStore();
        $key = $this->makeCacheKey($packId, $dirVersion, $relativePath);
        $ttl = $this->cacheTtlSeconds();

        $value = $cache->remember($key, $ttl, function () use ($reader): array {
            $raw = $reader();
            if (!is_string($raw)) {
                return [
                    'found' => false,
                    'raw' => null,
                ];
            }

            return [
                'found' => true,
                'raw' => $raw,
            ];
        });

        if (!is_array($value) || !($value['found'] ?? false)) {
            return null;
        }

        $raw = $value['raw'] ?? null;
        return is_string($raw) ? $raw : null;
    }

    public function readJson(
        string $packId,
        string $dirVersion,
        string $relativePath,
        callable $reader
    ): ?array {
        $raw = $this->readText($packId, $dirVersion, $relativePath, $reader);
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function makeCacheKey(string $packId, string $dirVersion, string $relativePath): string
    {
        $path = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        $seed = $packId . '|' . $dirVersion . '|' . $path;

        return 'content_loader:' . sha1($seed);
    }

    private function cacheStore(): CacheRepository
    {
        if (app()->environment('testing')) {
            return Cache::store();
        }

        $store = trim((string) config('content_packs.loader_cache_store', ''));
        if ($store === '' || strtolower($store) === 'default') {
            return Cache::store();
        }

        try {
            return Cache::store($store);
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }

    private function cacheTtlSeconds(): int
    {
        $ttl = (int) config('content_packs.loader_cache_ttl_seconds', 300);

        return $ttl > 0 ? $ttl : 300;
    }
}
