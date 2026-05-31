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

        $fingerprint = $this->safeFileFingerprint($absPath);
        $key = $this->cacheKey('text', $packId, $dirVersion, $relPath, $fingerprint);

        return $this->cacheRemember($key, fn () => $this->safeReadText($absPath));
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
        $pathKey = $this->cacheKey('path', $packId, $dirVersion, $relPath, '0');
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

    private function safeFileFingerprint(string $absPath): string
    {
        try {
            $mt = @filemtime($absPath);
            $size = @filesize($absPath);
            $hash = @hash_file('sha256', $absPath);
        } catch (\Throwable) {
            return '0:0:';
        }

        $mtime = is_int($mt) || is_numeric($mt) ? (int) $mt : 0;
        $bytes = is_int($size) || is_numeric($size) ? (int) $size : 0;
        $sha256 = is_string($hash) ? $hash : '';

        return $mtime.':'.$bytes.':'.$sha256;
    }

    private function safeReadText(string $absPath): ?string
    {
        try {
            $raw = @file_get_contents($absPath);
        } catch (\Throwable) {
            return null;
        }

        return is_string($raw) ? $raw : null;
    }

    private function cacheKey(string $kind, string $packId, string $dirVersion, string $relPath, string $fingerprint): string
    {
        $normalizedRelPath = ltrim(str_replace('\\', '/', $relPath), '/');
        $base = sha1($packId.'|'.$dirVersion.'|'.$normalizedRelPath);
        $fingerprintHash = sha1($fingerprint);

        return "content_loader:{$kind}:{$base}:{$fingerprintHash}";
    }

    private function ttlSeconds(): int
    {
        $ttl = (int) config('content_packs.loader_cache_ttl_seconds', 300);

        return max(1, $ttl);
    }

    private function cacheStoreName(): string
    {
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
            Log::warning('ContentLoader cache store failed, falling back to array store', [
                'store' => $store,
                'key_prefix' => substr($key, 0, 16),
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
        }

        try {
            return Cache::store('array')->remember($key, $ttl, $callback);
        } catch (\Throwable $fallback) {
            Log::warning('ContentLoader array cache fallback failed, falling back to direct read', [
                'store' => $store,
                'key_prefix' => substr($key, 0, 16),
                'error' => $fallback->getMessage(),
                'exception_class' => get_class($fallback),
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
}
