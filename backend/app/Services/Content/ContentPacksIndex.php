<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final class ContentPacksIndex
{
    public const CACHE_TTL_SECONDS = 30;

    public function getIndex(bool $refresh = false): array
    {
        $driver = (string) config('content_packs.driver', 'local');
        $driver = $driver === 's3' ? 's3' : 'local';

        $packsRoot = $driver === 's3'
            ? (string) config('content_packs.cache_dir', '')
            : (string) config('content_packs.root', '');

        $packsRootFs = rtrim($packsRoot, "/\\");
        $defaults = $this->defaults();

        $cacheKey = CacheKeys::packsIndex();
        $cache = $this->cacheStore();

        if (!$refresh) {
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

            if (is_array($cached)) {
                return $cached;
            }
        }

        if ($packsRootFs === '' || !File::isDirectory($packsRootFs)) {
            return [
                'ok' => false,
                'driver' => $driver,
                'packs_root' => $packsRootFs,
                'defaults' => $defaults,
                'items' => [],
                'by_pack_id' => [],
            ];
        }

        $items = $this->scanItems($packsRootFs);
        $byPackId = $this->buildByPackId($items, $defaults);

        $index = [
            'ok' => true,
            'driver' => $driver,
            'packs_root' => $packsRootFs,
            'defaults' => $defaults,
            'items' => $items,
            'by_pack_id' => $byPackId,
        ];

        try {
            $cache->put($cacheKey, $index, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $e) {
            try {
                Cache::store()->put($cacheKey, $index, self::CACHE_TTL_SECONDS);
            } catch (\Throwable $e2) {
                // ignore cache write failure
            }
        }

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

    private function scanItems(string $packsRootFs): array
    {
        $items = [];
        $seen = [];
        $rootNorm = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $packsRootFs), '/');

        foreach (File::allFiles($packsRootFs) as $file) {
            if ($file->getFilename() !== 'manifest.json') {
                continue;
            }

            $manifestPath = $file->getPathname();
            $manifestPathNorm = str_replace(DIRECTORY_SEPARATOR, '/', $manifestPath);
            if (str_contains($manifestPathNorm, '/_deprecated/')) {
                continue;
            }

            try {
                $manifestRaw = File::get($manifestPath);
            } catch (\Throwable $e) {
                continue;
            }

            $manifest = json_decode($manifestRaw, true);
            if (!is_array($manifest)) {
                continue;
            }

            $packId = trim((string) ($manifest['pack_id'] ?? ''));
            if ($packId === '') {
                continue;
            }

            $packDir = dirname($manifestPath);
            $dirVersion = basename($packDir);
            if (!$this->isManifestConsistent($manifest, $dirVersion, $packId)) {
                continue;
            }

            $versionPath = $packDir . DIRECTORY_SEPARATOR . 'version.json';
            $version = $this->readJsonFile($versionPath);
            if (!is_array($version)) {
                continue;
            }
            if (!$this->isVersionConsistent(
                $version,
                $packId,
                (string) ($manifest['content_package_version'] ?? ''),
                $dirVersion
            )) {
                continue;
            }

            $questionsPath = $packDir . DIRECTORY_SEPARATOR . 'questions.json';
            if (!File::exists($questionsPath)) {
                continue;
            }
            if (!is_array($this->readJsonFile($questionsPath))) {
                continue;
            }

            $key = $packId . '|' . $dirVersion;
            if (isset($seen[$key])) {
                continue;
            }

            $packPath = $this->relativePath($rootNorm, $packDir);
            $updatedAt = @filemtime($manifestPath);
            $updatedAt = is_int($updatedAt) ? $updatedAt : 0;

            $items[] = [
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => (string) ($manifest['content_package_version'] ?? ''),
                'scale_code' => (string) ($manifest['scale_code'] ?? ''),
                'region' => (string) ($manifest['region'] ?? ''),
                'locale' => (string) ($manifest['locale'] ?? ''),
                'pack_path' => $packPath,
                'manifest_path' => $manifestPath,
                'questions_path' => $questionsPath,
                'updated_at' => $updatedAt,
            ];

            $seen[$key] = true;
        }

        usort($items, function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['pack_id'] ?? ''), (string) ($b['pack_id'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['dir_version'] ?? ''), (string) ($b['dir_version'] ?? ''));
        });

        return $items;
    }

    private function buildByPackId(array $items, array $defaults): array
    {
        $byPackId = [];
        $latest = [];
        $defaultPackId = (string) ($defaults['default_pack_id'] ?? '');
        $defaultDirVersion = (string) ($defaults['default_dir_version'] ?? '');

        foreach ($items as $item) {
            $packId = (string) ($item['pack_id'] ?? '');
            $dirVersion = (string) ($item['dir_version'] ?? '');
            if ($packId === '' || $dirVersion === '') {
                continue;
            }

            if (!isset($byPackId[$packId])) {
                $byPackId[$packId] = [
                    'default_dir_version' => '',
                    'versions' => [],
                ];
            }

            $byPackId[$packId]['versions'][] = $dirVersion;

            $updatedAt = (int) ($item['updated_at'] ?? 0);
            if (!isset($latest[$packId]) || $updatedAt > (int) ($latest[$packId]['updated_at'] ?? 0)) {
                $latest[$packId] = [
                    'dir_version' => $dirVersion,
                    'updated_at' => $updatedAt,
                ];
            }
        }

        foreach ($byPackId as $packId => $info) {
            $versions = array_values(array_unique($info['versions'] ?? []));
            sort($versions, SORT_STRING);

            $default = '';
            if ($packId === $defaultPackId && $defaultDirVersion !== '') {
                $default = $defaultDirVersion;
            } else {
                $default = (string) ($latest[$packId]['dir_version'] ?? '');
                if ($default === '') {
                    $default = (string) ($versions[0] ?? '');
                }
            }

            $byPackId[$packId] = [
                'default_dir_version' => $default,
                'versions' => $versions,
            ];
        }

        ksort($byPackId, SORT_STRING);

        return $byPackId;
    }

    private function relativePath(string $rootNorm, string $path): string
    {
        $pathNorm = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $rootNorm = rtrim($rootNorm, '/');

        if ($rootNorm !== '' && str_starts_with($pathNorm, $rootNorm . '/')) {
            return substr($pathNorm, strlen($rootNorm) + 1);
        }

        return ltrim($pathNorm, '/');
    }

    private function findInItems(array $items, string $packId, string $dirVersion): ?array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
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
        $questionsPath = (string) ($item['questions_path'] ?? '');
        $packId = trim((string) ($item['pack_id'] ?? ''));
        $dirVersion = trim((string) ($item['dir_version'] ?? ''));
        $contentVersion = trim((string) ($item['content_package_version'] ?? ''));

        if ($manifestPath === '' || $questionsPath === '' || $packId === '' || $dirVersion === '' || $contentVersion === '') {
            return false;
        }

        $manifest = $this->readJsonFile($manifestPath);
        if (!is_array($manifest) || !$this->isManifestConsistent($manifest, $dirVersion, $packId)) {
            return false;
        }
        if ((string) ($manifest['content_package_version'] ?? '') !== $contentVersion) {
            return false;
        }

        $packDir = dirname($manifestPath);
        $versionPath = $packDir . DIRECTORY_SEPARATOR . 'version.json';
        $version = $this->readJsonFile($versionPath);
        if (!is_array($version) || !$this->isVersionConsistent($version, $packId, $contentVersion, $dirVersion)) {
            return false;
        }

        return is_array($this->readJsonFile($questionsPath));
    }

    private function readJsonFile(string $path): ?array
    {
        if ($path === '' || !File::isFile($path)) {
            return null;
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable $e) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isManifestConsistent(array $manifest, string $dirVersion, string $packId): bool
    {
        $schemaVersion = (string) ($manifest['schema_version'] ?? '');
        if ($schemaVersion !== 'pack-manifest@v1') {
            return false;
        }

        if ((string) ($manifest['pack_id'] ?? '') !== $packId) {
            return false;
        }
        if ((string) ($manifest['content_package_version'] ?? '') === '') {
            return false;
        }

        foreach (['scale_code', 'region', 'locale'] as $required) {
            if (trim((string) ($manifest[$required] ?? '')) === '') {
                return false;
            }
        }

        if ($dirVersion === '') {
            return false;
        }

        return true;
    }

    private function isVersionConsistent(
        array $version,
        string $manifestPackId,
        string $manifestContentVersion,
        string $dirVersion
    ): bool {
        $versionPackId = trim((string) ($version['pack_id'] ?? ''));
        $versionContentVersion = trim((string) ($version['content_package_version'] ?? ''));
        $versionDir = trim((string) ($version['dir_version'] ?? ''));

        if ($versionPackId === '' || $versionContentVersion === '' || $versionDir === '') {
            return false;
        }

        if ($versionPackId !== $manifestPackId) {
            return false;
        }
        if ($versionContentVersion !== $manifestContentVersion) {
            return false;
        }
        if ($versionDir !== $dirVersion) {
            return false;
        }

        return true;
    }
}
