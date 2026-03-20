<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class ContentPackV2Materializer
{
    public function materialize(object $release, string $sourceCompiledDir): string
    {
        $sourceCompiledDir = rtrim(str_replace('\\', '/', trim($sourceCompiledDir)), '/');
        if ($sourceCompiledDir === '' || ! is_file($sourceCompiledDir.'/manifest.json')) {
            throw new RuntimeException('PACKS2_MATERIALIZATION_SOURCE_INVALID');
        }

        $targetRoot = $this->targetRoot($release);
        $targetCompiledDir = $targetRoot.'/compiled';
        $sentinelPath = $targetRoot.'/.materialization.json';

        if ($this->isFresh($release, $targetCompiledDir, $sentinelPath)) {
            return $targetCompiledDir;
        }

        if (File::isDirectory($targetRoot)) {
            File::deleteDirectory($targetRoot);
        }

        File::ensureDirectoryExists(dirname($targetRoot));
        if (! File::copyDirectory($sourceCompiledDir, $targetCompiledDir)) {
            throw new RuntimeException('PACKS2_MATERIALIZATION_COPY_FAILED');
        }

        File::put($sentinelPath, json_encode([
            'release_id' => $this->releaseId($release),
            'storage_path' => $this->storagePath($release),
            'manifest_hash' => $this->manifestHash($release),
            'source_compiled_dir' => $sourceCompiledDir,
            'materialized_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        if (! is_file($targetCompiledDir.'/manifest.json')) {
            throw new RuntimeException('PACKS2_MATERIALIZATION_MANIFEST_MISSING');
        }

        return $targetCompiledDir;
    }

    public function targetCompiledDir(object $release): string
    {
        return $this->targetRoot($release).'/compiled';
    }

    private function isFresh(object $release, string $targetCompiledDir, string $sentinelPath): bool
    {
        if (! is_file($targetCompiledDir.'/manifest.json') || ! is_file($sentinelPath)) {
            return false;
        }

        $decoded = json_decode((string) File::get($sentinelPath), true);
        if (! is_array($decoded)) {
            return false;
        }

        return (string) ($decoded['storage_path'] ?? '') === $this->storagePath($release)
            && (string) ($decoded['manifest_hash'] ?? '') === $this->manifestHash($release);
    }

    private function targetRoot(object $release): string
    {
        $packId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $packVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
        $storageIdentity = $this->storageIdentity($release);
        $manifestHash = $this->manifestHash($release);

        if ($packId === '' || $packVersion === '' || $storageIdentity === '' || $manifestHash === '') {
            throw new RuntimeException('PACKS2_MATERIALIZATION_CONTEXT_INVALID');
        }

        return storage_path('app/private/packs_v2_materialized/'.$packId.'/'.$packVersion.'/'.$storageIdentity.'/'.$manifestHash);
    }

    private function releaseId(object $release): string
    {
        return trim((string) ($release->id ?? ''));
    }

    private function manifestHash(object $release): string
    {
        return strtolower(trim((string) ($release->manifest_hash ?? '')));
    }

    private function storagePath(object $release): string
    {
        return trim((string) ($release->storage_path ?? ''));
    }

    private function storageIdentity(object $release): string
    {
        $storagePath = $this->storagePath($release);
        if ($storagePath === '') {
            return '';
        }

        return hash('sha256', $storagePath);
    }
}
