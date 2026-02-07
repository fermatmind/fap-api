<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ContentLoaderService
{
    /**
     * @param  callable(): (string|null)  $resolveAbsPath
     */
    public function readText(string $packId, string $dirVersion, string $relPath, callable $resolveAbsPath): ?string
    {
        $absPath = $this->resolvePath($packId, $dirVersion, $relPath, $resolveAbsPath);
        if ($absPath === null) {
            return null;
        }

        $mtime = $this->safeFileMtime($absPath);
        $key = $this->cacheKey('text', $packId, $dirVersion, $relPath, $mtime);

        return $this->cacheRemember($key, fn () => file_get_contents($absPath) ?: null);
    }

    /**
     * @param  callable(): (string|null)  $resolveAbsPath
     * @return array<string,mixed>|null
     */
    public function readJson(string $packId, string $dirVersion, string $relPath, callable $resolveAbsPath): ?array
    {
        $raw = $this->readText($packId, $dirVersion, $relPath, $resolveAbsPath);
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  callable(): (string|null)  $resolveAbsPath
     */
    private function resolvePath(string $packId, string $dirVersion, string $relPath, callable $resolveAbsPath): ?string
    {
        $ttl = $this->ttlSeconds();
        $pathKey = $this->cacheKey('path', $packId, $dirVersion, $relPath, 0);
        $sentinelMiss = '__MISS__';

        $absPath = $this->cacheRemember($pathKey, function () use ($resolveAbsPath, $sentinelMiss) {
            $p = $resolveAbsPath();
            if (! is_string($p) || $p === '') {
                return $sentinelMiss;
            }

            return $p;
        }, $ttl);

        if ($absPath === $sentinelMiss) {
            return null;
        }
        if (! is_string($absPath) || $absPath === '') {
            return null;
        }

        if (! is_file($absPath)) {
            $this->cacheForget($pathKey);

            $fresh = $resolveAbsPath();
            if (! is_string($fresh) || $fresh === '' || ! is_file($fresh)) {
                return null;
            }

            $this->cachePut($pathKey, $fresh, $ttl);

            return $fresh;
        }

        return $absPath;
    }

    private function safeFileMtime(string $absPath): int
    {
        $mt = @filemtime($absPath);

        return is_int($mt) ? $mt : 0;
    }

    private function cacheKey(string $kind, string $packId, string $dirVersion, string $relPath, int $mtime): string
    {
        $base = implode('|', [
            'content_loader',
            $kind,
            $packId,
            $dirVersion,
            ltrim($relPath, '/'),
            (string) $mtime,
        ]);

        return 'fap:'.hash('sha256', $base);
    }

    private function ttlSeconds(): int
    {
        $ttl = (int) config('content_packs.loader_cache_ttl_seconds', 300);

        return $ttl > 0 ? $ttl : 300;
    }

    private function cacheStoreName(): string
    {
        if (app()->environment('testing') || $this->isCiEnvironment()) {
            return 'array';
        }

        $store = (string) config('content_packs.loader_cache_store', 'array');

        return $store !== '' ? $store : 'array';
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function cacheRemember(string $key, callable $callback, ?int $ttlSeconds = null)
    {
        $ttl = $ttlSeconds ?? $this->ttlSeconds();
        $store = $this->cacheStoreName();

        try {
            return Cache::store($store)->remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning('ContentLoader cache store failed, falling back to direct read', [
                'store' => $store,
                'key_prefix' => substr($key, 0, 16),
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return $callback();
        }
    }

    private function cacheForget(string $key): void
    {
        $store = $this->cacheStoreName();

        try {
            Cache::store($store)->forget($key);
        } catch (\Throwable $e) {
            try {
                Cache::forget($key);
            } catch (\Throwable $fallback) {
                Log::warning('ContentLoader cache forget failed', [
                    'store' => $store,
                    'key_prefix' => substr($key, 0, 16),
                    'error' => $fallback->getMessage(),
                    'exception_class' => get_class($fallback),
                ]);
            }
        }
    }

    private function cachePut(string $key, string $value, int $ttlSeconds): void
    {
        $store = $this->cacheStoreName();

        try {
            Cache::store($store)->put($key, $value, $ttlSeconds);
        } catch (\Throwable $e) {
            try {
                Cache::put($key, $value, $ttlSeconds);
            } catch (\Throwable $fallback) {
                Log::warning('ContentLoader cache put failed', [
                    'store' => $store,
                    'key_prefix' => substr($key, 0, 16),
                    'error' => $fallback->getMessage(),
                    'exception_class' => get_class($fallback),
                ]);
            }
        }
    }

    private function isCiEnvironment(): bool
    {
        $ci = env('CI', false);
        if (is_bool($ci)) {
            return $ci;
        }

        return in_array(strtolower((string) $ci), ['1', 'true', 'yes', 'on'], true);
    }
}
