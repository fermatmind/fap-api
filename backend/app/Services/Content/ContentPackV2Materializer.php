<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

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
            $this->promoteLastKnownGood($release, $targetCompiledDir);

            return $targetCompiledDir;
        }

        File::ensureDirectoryExists(dirname($targetRoot));
        $stagingRoot = $targetRoot.'.staging-'.bin2hex(random_bytes(8));
        $backupRoot = $targetRoot.'.replaced-'.bin2hex(random_bytes(8));

        try {
            if (! File::copyDirectory($sourceCompiledDir, $stagingRoot.'/compiled')) {
                throw new RuntimeException('PACKS2_MATERIALIZATION_COPY_FAILED');
            }

            $this->validateStagingTree($release, $stagingRoot.'/compiled');
            $this->writeJsonAtomically($stagingRoot.'/.materialization.json', [
                'release_id' => $this->releaseId($release),
                'storage_path' => $this->storagePath($release),
                'manifest_hash' => $this->manifestHash($release),
                'source_compiled_dir' => $sourceCompiledDir,
                'materialized_at' => now()->toIso8601String(),
            ]);

            if (File::isDirectory($targetRoot) && ! rename($targetRoot, $backupRoot)) {
                throw new RuntimeException('PACKS2_MATERIALIZATION_OLD_VERSION_PRESERVE_FAILED');
            }
            if (! rename($stagingRoot, $targetRoot)) {
                if (File::isDirectory($backupRoot)) {
                    rename($backupRoot, $targetRoot);
                }
                throw new RuntimeException('PACKS2_MATERIALIZATION_ATOMIC_SWITCH_FAILED');
            }

            File::deleteDirectory($backupRoot);
            $this->promoteLastKnownGood($release, $targetCompiledDir);

            return $targetCompiledDir;
        } catch (Throwable $e) {
            File::deleteDirectory($stagingRoot);
            if (! File::isDirectory($targetRoot) && File::isDirectory($backupRoot)) {
                rename($backupRoot, $targetRoot);
            }
            throw $e;
        }
    }

    public function targetCompiledDir(object $release): string
    {
        return $this->targetRoot($release).'/compiled';
    }

    public function lastKnownGoodCompiledDir(string $packId, string $packVersion): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if (! $this->safeSegment($packId) || ! $this->safeSegment($packVersion)) {
            return null;
        }

        $pointerPath = storage_path('app/private/packs_v2_materialized/'.$packId.'/'.$packVersion.'/.lkg.json');
        if (! is_file($pointerPath)) {
            return null;
        }

        $pointer = json_decode((string) File::get($pointerPath), true);
        $compiledDir = is_array($pointer) ? trim((string) ($pointer['compiled_dir'] ?? '')) : '';
        $expectedRoot = storage_path('app/private/packs_v2_materialized/'.$packId.'/'.$packVersion).'/';
        if ($compiledDir === '' || ! str_starts_with($compiledDir.'/', $expectedRoot) || ! is_file($compiledDir.'/manifest.json')) {
            return null;
        }

        return $compiledDir;
    }

    private function validateStagingTree(object $release, string $compiledDir): void
    {
        $manifestPath = $compiledDir.'/manifest.json';
        if (! is_file($manifestPath) || is_link($manifestPath)) {
            throw new RuntimeException('PACKS2_MATERIALIZATION_MANIFEST_MISSING');
        }

        $files = File::allFiles($compiledDir);
        if ($files === []) {
            throw new RuntimeException('PACKS2_MATERIALIZATION_EMPTY');
        }

        foreach ($files as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('PACKS2_MATERIALIZATION_SYMLINK_FORBIDDEN');
            }
            if (strtolower($file->getExtension()) === 'json') {
                json_decode((string) File::get($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $manifestPayload = (string) File::get($manifestPath);
        json_decode($manifestPayload, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($this->manifestHash($release), hash('sha256', $manifestPayload))) {
            throw new RuntimeException('PACKS2_MATERIALIZATION_MANIFEST_HASH_MISMATCH');
        }
    }

    private function promoteLastKnownGood(object $release, string $compiledDir): void
    {
        $packId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $packVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
        $this->writeJsonAtomically(dirname($compiledDir, 3).'/.lkg.json', [
            'release_id' => $this->releaseId($release),
            'manifest_hash' => $this->manifestHash($release),
            'compiled_dir' => $compiledDir,
            'promoted_at' => now()->toIso8601String(),
            'pack_id' => $packId,
            'pack_version' => $packVersion,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function writeJsonAtomically(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(8));
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (File::put($temporaryPath, $encoded) === false || ! rename($temporaryPath, $path)) {
            File::delete($temporaryPath);
            throw new RuntimeException('PACKS2_MATERIALIZATION_POINTER_SWITCH_FAILED');
        }
    }

    private function safeSegment(string $value): bool
    {
        return $value !== '' && preg_match('/\A[A-Za-z0-9._-]+\z/', $value) === 1;
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
