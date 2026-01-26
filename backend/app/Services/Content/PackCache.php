<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Contracts\ContentSourceDriver;
use App\Services\Content\Drivers\LocalDriver;
use App\Services\Content\Drivers\S3Driver;
use RuntimeException;

final class PackCache
{
    public function ensureCached(string $pack): string
    {
        $pack = $this->normalizePack($pack);

        $cacheRoot = rtrim((string)config('content_packs.cache_dir', ''), '/');
        if ($cacheRoot === '') {
            throw new RuntimeException('Missing config: content_packs.cache_dir');
        }

        $localPackDir = $cacheRoot . '/' . $pack;

        $lockHandle = $this->acquireLock($cacheRoot, $pack);
        try {
            $driverName = (string)config('content_packs.driver', 'local');
            $driver = $this->makeDriver($driverName);
            $ttl = (int)config('content_packs.cache_ttl_seconds', 3600);

            $manifestKey = $pack . '/manifest.json';
            $sourceEtag = $driver->etag($manifestKey);

            $metaPath = $localPackDir . '/.pack_cache_meta.json';
            $meta = $this->loadMeta($metaPath);

            if ($this->needsRefresh($localPackDir, $meta, $ttl, $sourceEtag)) {
                $this->refresh($pack, $localPackDir, $driver, $driverName, $sourceEtag);
            }
        } finally {
            $this->releaseLock($lockHandle);
        }

        return $this->fsPath($localPackDir);
    }

    private function refresh(string $pack, string $localPackDir, ContentSourceDriver $driver, string $driverName, ?string $sourceEtag): void
    {
        $keys = $driver->list($pack . '/');

        $localPackDirFs = $this->fsPath($localPackDir);
        if (!is_dir($localPackDirFs) && !mkdir($localPackDirFs, 0775, true) && !is_dir($localPackDirFs)) {
            throw new RuntimeException("Failed to create cache dir: {$localPackDirFs}");
        }

        $prefix = rtrim($pack, '/') . '/';
        foreach ($keys as $key) {
            $key = $this->normalizeKey($key);
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $rel = substr($key, strlen($prefix));
            if ($rel === '') {
                continue;
            }

            $contents = $driver->get($key);
            $this->writeFileAtomic($localPackDir, $rel, $contents);
        }

        $meta = $this->makeMeta($pack, $sourceEtag, $driverName);
        $meta->saveAtomic($this->fsPath($localPackDir . '/.pack_cache_meta.json'));
    }

    private function needsRefresh(string $localPackDir, ?PackCacheMeta $meta, int $ttl, ?string $sourceEtag): bool
    {
        $localPackDirFs = $this->fsPath($localPackDir);

        if (!is_dir($localPackDirFs)) return true;
        if ($meta === null) return true;
        if ($meta->fetchedAt <= 0) return true;
        if ((time() - $meta->fetchedAt) >= $ttl) return true;

        if ($sourceEtag !== null && $meta->manifestEtag !== null && $sourceEtag !== $meta->manifestEtag) {
            return true;
        }

        return false;
    }

    private function makeMeta(string $pack, ?string $sourceEtag, string $driverName): PackCacheMeta
    {
        $driverName = $driverName === 's3' ? 's3' : 'local';

        $source = $driverName === 's3'
            ? [
                'disk' => (string)config('content_packs.s3_disk', 's3'),
                'prefix' => (string)config('content_packs.s3_prefix', ''),
            ]
            : [
                'root' => (string)config('content_packs.root', ''),
            ];

        return new PackCacheMeta(
            $pack,
            time(),
            $sourceEtag,
            $driverName,
            $source
        );
    }

    private function loadMeta(string $path): ?PackCacheMeta
    {
        $pathFs = $this->fsPath($path);
        try {
            return PackCacheMeta::fromFile($pathFs);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function makeDriver(string $driverName): ContentSourceDriver
    {
        if ($driverName === 's3') {
            return new S3Driver(
                (string)config('content_packs.s3_disk', 's3'),
                (string)config('content_packs.s3_prefix', '')
            );
        }

        return new LocalDriver((string)config('content_packs.root', ''));
    }

    private function normalizePack(string $pack): string
    {
        $pack = str_replace(DIRECTORY_SEPARATOR, '/', $pack);
        $pack = trim($pack, '/');

        if ($pack === '') {
            throw new RuntimeException('Pack cannot be empty.');
        }

        if (str_contains($pack, '..')) {
            throw new RuntimeException('Invalid pack (.. not allowed).');
        }

        return $pack;
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace(DIRECTORY_SEPARATOR, '/', $key);
        $key = ltrim($key, '/');

        if (str_contains($key, '..')) {
            throw new RuntimeException('Invalid content key (.. not allowed).');
        }

        return $key;
    }

    private function writeFileAtomic(string $localPackDir, string $rel, string $contents): void
    {
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $rel = ltrim($rel, '/');

        if ($rel === '' || str_contains($rel, '..')) {
            throw new RuntimeException('Invalid cache write path.');
        }

        $dest = $localPackDir . '/' . $rel;
        $destFs = $this->fsPath($dest);
        $dirFs = dirname($destFs);

        if (!is_dir($dirFs) && !mkdir($dirFs, 0775, true) && !is_dir($dirFs)) {
            throw new RuntimeException("Failed to create cache dir: {$dirFs}");
        }

        $tmp = tempnam($dirFs, '.tmp-');
        if ($tmp === false) {
            throw new RuntimeException("Failed to create temp file in: {$dirFs}");
        }

        if (file_put_contents($tmp, $contents) === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp cache file: {$tmp}");
        }

        if (!rename($tmp, $destFs)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to move cache file into place: {$destFs}");
        }
    }

    private function acquireLock(string $cacheRoot, string $pack)
    {
        $lockDir = $cacheRoot . '/.locks';
        $lockDirFs = $this->fsPath($lockDir);
        if (!is_dir($lockDirFs) && !mkdir($lockDirFs, 0775, true) && !is_dir($lockDirFs)) {
            throw new RuntimeException("Failed to create lock dir: {$lockDirFs}");
        }

        $lockPath = $lockDir . '/' . sha1($pack) . '.lock';
        $lockPathFs = $this->fsPath($lockPath);
        $handle = fopen($lockPathFs, 'c');
        if ($handle === false) {
            throw new RuntimeException("Failed to open lock file: {$lockPathFs}");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException("Failed to lock cache file: {$lockPathFs}");
        }

        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function fsPath(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
