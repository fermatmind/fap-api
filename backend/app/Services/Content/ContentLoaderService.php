<?php

declare(strict_types=1);

namespace App\Services\Content;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ContentLoaderService
{
    public function readText(
        string $packId,
        string $dirVersion,
        string $relativePath,
        callable $reader
    ): ?string {
        $key = $this->makeCacheKey($packId, $dirVersion, $relativePath);
        $ttl = $this->cacheTtlSeconds();

        $value = $this->rememberSafe($key, $ttl, function () use ($reader): array {
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

    private function resolvedStore(): string
    {
        $appEnv = strtolower((string) config('app.env', 'production'));
        if (in_array($appEnv, ['ci', 'testing', 'local'], true)) {
            return 'array';
        }

        $defaultStore = (string) config('cache.default', 'array');
        $store = trim((string) config('content_packs.loader_cache_store', $defaultStore));
        if ($store === '' || strtolower($store) === 'default') {
            return $defaultStore;
        }

        return $store;
    }

    private function rememberSafe(string $key, int $ttlSeconds, Closure $callback)
    {
        $store = $this->resolvedStore();

        try {
            return Cache::store($store)->remember($key, $ttlSeconds, $callback);
        } catch (\Throwable $e) {
            Log::warning('Content loader cache store failed, using array fallback.', [
                'cache_store' => $store,
                'env' => (string) config('app.env', 'production'),
                'error_class' => $e::class,
            ]);
        }

        try {
            return Cache::store('array')->remember($key, $ttlSeconds, $callback);
        } catch (\Throwable $e) {
            return $callback();
        }
    }

    private function cacheTtlSeconds(): int
    {
        $ttl = (int) config('content_packs.loader_cache_ttl_seconds', 300);

        return $ttl > 0 ? $ttl : 300;
    }
}
