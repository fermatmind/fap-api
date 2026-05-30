<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ContentPacksIndexFallbackScanner
{
    public function scan(string $packsRootFs, string $driver, array $defaults): array
    {
        if ($packsRootFs === '' || ! File::isDirectory($packsRootFs)) {
            Log::error('CONTENT_PACKS_ROOT_INVALID', [
                'driver' => $driver,
                'packs_root' => $packsRootFs,
            ]);

            return [
                'ok' => false,
                'driver' => $driver,
                'packs_root' => $packsRootFs,
                'defaults' => $defaults,
                'items' => [],
                'by_pack_id' => [],
            ];
        }

        $scanned = $this->scanIndex($packsRootFs, $defaults);
        $items = (array) ($scanned['items'] ?? []);
        if ($items === []) {
            Log::error('CONTENT_PACKS_INDEX_EMPTY', [
                'driver' => $driver,
                'packs_root' => $packsRootFs,
            ]);
        }

        return [
            'ok' => true,
            'driver' => $driver,
            'packs_root' => $packsRootFs,
            'defaults' => $defaults,
            'items' => $items,
            'by_pack_id' => (array) ($scanned['by_pack_id'] ?? []),
        ];
    }

    private function scanIndex(string $packsRootFs, array $defaults): array
    {
        $items = [];
        $seen = [];
        $byPackId = [];
        $latest = [];
        $rootNorm = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $packsRootFs), '/');

        $stats = [
            'manifests_seen' => 0,
            'skipped_deprecated' => 0,
            'skipped_manifest_json_invalid' => 0,
            'skipped_manifest_consistency' => 0,
            'skipped_version_invalid' => 0,
            'skipped_questions_invalid' => 0,
            'skipped_duplicate' => 0,
            'accepted' => 0,
        ];

        foreach ($this->manifestFilesUnder($packsRootFs) as $file) {
            $stats['manifests_seen']++;

            $manifestPath = $file->getPathname();
            $manifestPathNorm = str_replace(DIRECTORY_SEPARATOR, '/', $manifestPath);
            if (str_contains($manifestPathNorm, '/_deprecated/')) {
                $stats['skipped_deprecated']++;

                continue;
            }

            try {
                $manifestRaw = File::get($manifestPath);
            } catch (\Throwable $e) {
                continue;
            }

            $manifest = json_decode($manifestRaw, true);
            if (! is_array($manifest)) {
                $stats['skipped_manifest_json_invalid']++;

                continue;
            }

            $packId = trim((string) ($manifest['pack_id'] ?? ''));
            if ($packId === '') {
                continue;
            }

            $packDir = dirname($manifestPath);
            $dirVersion = basename($packDir);
            if (! $this->isManifestConsistent($manifest, $dirVersion, $packId)) {
                $stats['skipped_manifest_consistency']++;

                continue;
            }

            $versionPath = $packDir.DIRECTORY_SEPARATOR.'version.json';
            $version = $this->readJsonFile($versionPath);
            if (! is_array($version)) {
                $stats['skipped_version_invalid']++;

                continue;
            }
            if (! $this->isVersionConsistent(
                $version,
                $packId,
                (string) ($manifest['content_package_version'] ?? ''),
                $dirVersion
            )) {
                $stats['skipped_version_invalid']++;

                continue;
            }

            $questionsPath = $packDir.DIRECTORY_SEPARATOR.'questions.json';
            if (! File::exists($questionsPath)) {
                $stats['skipped_questions_invalid']++;

                continue;
            }
            if (! $this->isValidJsonArrayDocument($questionsPath)) {
                $stats['skipped_questions_invalid']++;

                continue;
            }

            $key = $packId.'|'.$dirVersion;
            if (isset($seen[$key])) {
                $stats['skipped_duplicate']++;

                continue;
            }

            $packPath = $this->relativePath($rootNorm, $packDir);
            $manifestSig = $this->fileSignature($manifestPath);
            $versionSig = $this->fileSignature($versionPath);
            $questionsSig = $this->fileSignature($questionsPath);
            if ($manifestSig === null || $versionSig === null || $questionsSig === null) {
                continue;
            }

            $updatedAt = max(
                (int) ($manifestSig['mtime'] ?? 0),
                (int) ($versionSig['mtime'] ?? 0),
                (int) ($questionsSig['mtime'] ?? 0)
            );

            $stats['accepted']++;

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
                'version_path' => $versionPath,
                'manifest_mtime' => (int) ($manifestSig['mtime'] ?? 0),
                'manifest_size' => (int) ($manifestSig['size'] ?? 0),
                'version_mtime' => (int) ($versionSig['mtime'] ?? 0),
                'version_size' => (int) ($versionSig['size'] ?? 0),
                'questions_mtime' => (int) ($questionsSig['mtime'] ?? 0),
                'questions_size' => (int) ($questionsSig['size'] ?? 0),
                'updated_at' => $updatedAt,
            ];

            $this->recordByPackIdVersion(
                $byPackId,
                $latest,
                $packId,
                $dirVersion,
                $updatedAt
            );

            $seen[$key] = true;
        }

        usort($items, function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['pack_id'] ?? ''), (string) ($b['pack_id'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['dir_version'] ?? ''), (string) ($b['dir_version'] ?? ''));
        });

        $this->debugLog('CONTENT_PACKS_INDEX_SCAN_SUMMARY', [
            'packs_root' => $packsRootFs,
            'stats' => $stats,
        ]);

        return [
            'items' => $items,
            'by_pack_id' => $this->finalizeByPackId($byPackId, $latest, $defaults),
        ];
    }

    private function recordByPackIdVersion(
        array &$byPackId,
        array &$latest,
        string $packId,
        string $dirVersion,
        int $updatedAt
    ): void {
        if ($packId === '' || $dirVersion === '') {
            return;
        }

        if (! isset($byPackId[$packId])) {
            $byPackId[$packId] = [
                'default_dir_version' => '',
                'versions' => [],
            ];
        }

        $byPackId[$packId]['versions'][$dirVersion] = true;

        if (! isset($latest[$packId]) || $updatedAt > (int) ($latest[$packId]['updated_at'] ?? 0)) {
            $latest[$packId] = [
                'dir_version' => $dirVersion,
                'updated_at' => $updatedAt,
            ];
        }
    }

    private function finalizeByPackId(array $byPackId, array $latest, array $defaults): array
    {
        $defaultPackId = (string) ($defaults['default_pack_id'] ?? '');
        $defaultDirVersion = (string) ($defaults['default_dir_version'] ?? '');

        foreach ($byPackId as $packId => $info) {
            $versions = array_keys((array) ($info['versions'] ?? []));
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

    /**
     * @return \Generator<int, SplFileInfo>
     */
    private function manifestFilesUnder(string $packsRootFs): \Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packsRootFs, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($file->getFilename() !== 'manifest.json') {
                continue;
            }

            yield $file;
        }
    }

    private function relativePath(string $rootNorm, string $path): string
    {
        $pathNorm = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $rootNorm = rtrim($rootNorm, '/');

        if ($rootNorm !== '' && str_starts_with($pathNorm, $rootNorm.'/')) {
            return substr($pathNorm, strlen($rootNorm) + 1);
        }

        return ltrim($pathNorm, '/');
    }

    /**
     * @return array{mtime:int,size:int}|null
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
        ];
    }

    private function readJsonFile(string $path): ?array
    {
        if ($path === '' || ! File::isFile($path)) {
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

    private function isValidJsonArrayDocument(string $path): bool
    {
        if ($path === '' || ! File::isFile($path)) {
            return false;
        }

        $firstMeaningfulByte = $this->firstNonWhitespaceByte($path);
        if ($firstMeaningfulByte === null) {
            return false;
        }

        return $firstMeaningfulByte === '[' || $firstMeaningfulByte === '{';
    }

    private function firstNonWhitespaceByte(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return null;
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);
                if (! is_string($chunk) || $chunk === '') {
                    continue;
                }

                $length = strlen($chunk);
                for ($index = 0; $index < $length; $index++) {
                    $char = $chunk[$index];
                    if (! ctype_space($char)) {
                        return $char;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
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

    private function debugLog(string $event, array $context = []): void
    {
        if (! (bool) config('content_packs.debug_log', false)) {
            return;
        }

        Log::warning($event, $context);
    }
}
