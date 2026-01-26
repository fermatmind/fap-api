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
        $index = $this->getIndex(false);
        if (!($index['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'NOT_FOUND',
            ];
        }

        $items = $index['items'] ?? [];
        foreach ($items as $item) {
            if (($item['pack_id'] ?? '') === $packId && ($item['dir_version'] ?? '') === $dirVersion) {
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

            $packId = $manifest['pack_id'] ?? null;
            if (!is_string($packId) || trim($packId) === '') {
                continue;
            }

            $packDir = dirname($manifestPath);
            $questionsPath = $packDir . DIRECTORY_SEPARATOR . 'questions.json';
            if (!File::exists($questionsPath)) {
                continue;
            }

            $dirVersion = basename($packDir);

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
}
