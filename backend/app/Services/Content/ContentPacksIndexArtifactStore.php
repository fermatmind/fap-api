<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class ContentPacksIndexArtifactStore
{
    public const SCHEMA_VERSION = 'content_packs_index.artifact.v1';

    public const FILENAME = 'content-packs-index.json';

    public function isEnabled(): bool
    {
        return (bool) config('content_packs.index_artifact_enabled', false);
    }

    public function configuredPath(): string
    {
        return $this->normalizeOutputPath((string) config('content_packs.index_artifact_path', ''));
    }

    public function readConfigured(string $packsRootFs, string $driver, array $defaults): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $path = $this->configuredPath();
        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        return $this->read($path, $packsRootFs, $driver, $defaults);
    }

    public function read(string $path, string $packsRootFs, string $driver, array $defaults): ?array
    {
        $path = $this->normalizeOutputPath($path);
        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable $e) {
            $this->warn('CONTENT_PACKS_INDEX_ARTIFACT_READ_FAILED', [
                'path' => $path,
                'exception' => $e::class,
            ]);

            return null;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            $this->warn('CONTENT_PACKS_INDEX_ARTIFACT_JSON_INVALID', [
                'path' => $path,
            ]);

            return null;
        }

        return $this->hydrate($payload, $packsRootFs, $driver, $defaults, $path);
    }

    public function write(array $index, string $path): array
    {
        $path = $this->normalizeOutputPath($path);
        if ($path === '') {
            throw new \RuntimeException('content pack index artifact output path is required');
        }

        $payload = $this->buildPayload($index);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode content pack index artifact');
        }

        $dir = dirname($path);
        File::ensureDirectoryExists($dir, 0750);

        $tmpPath = $dir.DIRECTORY_SEPARATOR.'.'.basename($path).'.tmp.'.bin2hex(random_bytes(8));
        try {
            File::put($tmpPath, $encoded.PHP_EOL);
            if (! @rename($tmpPath, $path)) {
                throw new \RuntimeException('failed to move content pack index artifact into place');
            }
        } finally {
            if (File::exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return [
            'path' => $path,
            'schema_version' => self::SCHEMA_VERSION,
            'item_count' => count((array) ($payload['items'] ?? [])),
        ];
    }

    public function buildPayload(array $index): array
    {
        $packsRoot = rtrim((string) ($index['packs_root'] ?? ''), '/\\');
        $items = [];

        foreach ((array) ($index['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $artifactItem = $item;
            foreach (['manifest_path', 'questions_path', 'version_path'] as $pathKey) {
                $artifactItem[$pathKey] = $this->artifactPathValue($packsRoot, (string) ($item[$pathKey] ?? ''));
            }
            foreach (['manifest', 'questions', 'version'] as $prefix) {
                $hash = $this->fileHash((string) ($item[$prefix.'_path'] ?? ''));
                if ($hash !== null) {
                    $artifactItem[$prefix.'_sha256'] = $hash;
                }
            }

            $items[] = $artifactItem;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now('UTC')->toIso8601String(),
            'driver' => (string) ($index['driver'] ?? 'local'),
            'packs_root' => $packsRoot,
            'defaults' => (array) ($index['defaults'] ?? []),
            'items' => $items,
            'by_pack_id' => (array) ($index['by_pack_id'] ?? []),
            'summary' => [
                'item_count' => count($items),
            ],
        ];
    }

    private function hydrate(array $payload, string $packsRootFs, string $driver, array $defaults, string $path): ?array
    {
        if ((string) ($payload['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            $this->warn('CONTENT_PACKS_INDEX_ARTIFACT_SCHEMA_INVALID', ['path' => $path]);

            return null;
        }

        if ((string) ($payload['driver'] ?? '') !== $driver) {
            $this->warn('CONTENT_PACKS_INDEX_ARTIFACT_DRIVER_MISMATCH', [
                'path' => $path,
                'artifact_driver' => (string) ($payload['driver'] ?? ''),
                'runtime_driver' => $driver,
            ]);

            return null;
        }

        if ((array) ($payload['defaults'] ?? []) !== $defaults) {
            $this->warn('CONTENT_PACKS_INDEX_ARTIFACT_DEFAULTS_MISMATCH', ['path' => $path]);

            return null;
        }

        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                return null;
            }

            $hydrated = $item;
            foreach (['manifest_path', 'questions_path', 'version_path'] as $pathKey) {
                $hydrated[$pathKey] = $this->hydratePath($packsRootFs, (string) ($item[$pathKey] ?? ''));
            }

            if (! $this->isArtifactItemValid($hydrated)) {
                $this->warn('CONTENT_PACKS_INDEX_ARTIFACT_ITEM_INVALID', [
                    'path' => $path,
                    'pack_id' => (string) ($hydrated['pack_id'] ?? ''),
                    'dir_version' => (string) ($hydrated['dir_version'] ?? ''),
                ]);

                return null;
            }
            unset($hydrated['manifest_sha256'], $hydrated['questions_sha256'], $hydrated['version_sha256']);

            $items[] = $hydrated;
        }

        return [
            'ok' => true,
            'driver' => $driver,
            'packs_root' => $packsRootFs,
            'defaults' => $defaults,
            'items' => $items,
            'by_pack_id' => (array) ($payload['by_pack_id'] ?? []),
        ];
    }

    private function isArtifactItemValid(array $item): bool
    {
        foreach (['pack_id', 'dir_version', 'manifest_path', 'questions_path', 'version_path'] as $required) {
            if (trim((string) ($item[$required] ?? '')) === '') {
                return false;
            }
        }

        foreach (['manifest', 'questions', 'version'] as $prefix) {
            $path = (string) ($item[$prefix.'_path'] ?? '');
            $signature = $this->fileSignature($path);
            if ($signature === null) {
                return false;
            }
            if ((int) ($item[$prefix.'_size'] ?? -1) !== (int) ($signature['size'] ?? -2)) {
                return false;
            }

            $expectedHash = trim((string) ($item[$prefix.'_sha256'] ?? ''));
            if ($expectedHash !== '') {
                $actualHash = $this->fileHash($path);
                if ($actualHash === null || ! hash_equals($expectedHash, $actualHash)) {
                    return false;
                }

                continue;
            }

            if ((int) ($item[$prefix.'_mtime'] ?? -1) !== (int) ($signature['mtime'] ?? -2)) {
                return false;
            }
        }

        return true;
    }

    private function artifactPathValue(string $packsRoot, string $path): string
    {
        if ($path === '') {
            return '';
        }

        $pathNorm = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $rootNorm = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $packsRoot), '/');
        if ($rootNorm !== '' && str_starts_with($pathNorm, $rootNorm.'/')) {
            return substr($pathNorm, strlen($rootNorm) + 1);
        }

        return $path;
    }

    private function hydratePath(string $packsRootFs, string $path): string
    {
        $path = trim($path);
        if ($path === '' || $this->isAbsolutePath($path)) {
            return $path;
        }

        return rtrim($packsRootFs, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function normalizeOutputPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
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

    /**
     * @return array{mtime:int,size:int}|null
     */
    private function fileSignature(string $path): ?array
    {
        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        try {
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

    private function fileHash(string $path): ?string
    {
        if ($path === '' || ! File::isFile($path)) {
            return null;
        }

        try {
            $hash = hash_file('sha256', $path);
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private function warn(string $event, array $context): void
    {
        if (! (bool) config('content_packs.debug_log', false)) {
            return;
        }

        Log::warning($event, $context);
    }
}
