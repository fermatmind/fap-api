<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;

final class ContentPackV2Resolver
{
    public function resolveActiveCompiledPath(string $packId, string $packVersion): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return null;
        }

        $activation = DB::table('content_pack_activations')
            ->where('pack_id', $packId)
            ->where('pack_version', $packVersion)
            ->first();

        if (! $activation) {
            return null;
        }

        $releaseId = trim((string) ($activation->release_id ?? ''));
        if ($releaseId === '') {
            return null;
        }

        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        if (! $release) {
            return null;
        }

        $releasePack = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        if ($releasePack !== $packId) {
            return null;
        }

        return $this->resolveCompiledPathFromRelease($release);
    }

    public function resolveCompiledPathByManifestHash(string $packId, string $packVersion, string $manifestHash): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        $manifestHash = strtolower(trim($manifestHash));
        if ($packId === '' || $packVersion === '' || $manifestHash === '') {
            return null;
        }

        $query = DB::table('content_pack_releases')
            ->where('to_pack_id', $packId)
            ->where('manifest_hash', $manifestHash)
            ->where('status', 'success')
            ->orderByDesc('created_at');

        $rows = $query->get();
        foreach ($rows as $release) {
            $releaseVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
            if ($releaseVersion !== '' && $releaseVersion !== $packVersion) {
                continue;
            }

            $compiledPath = $this->resolveCompiledPathFromRelease($release);
            if ($compiledPath !== null) {
                return $compiledPath;
            }
        }

        return null;
    }

    private function resolveCompiledPathFromRelease(object $release): ?string
    {
        $storagePath = trim((string) ($release->storage_path ?? ''));
        if ($storagePath === '') {
            return null;
        }

        $roots = [$this->resolveStorageRoot($storagePath)];
        foreach ($this->legacyFallbackRoots($release, $storagePath) as $legacyRoot) {
            $roots[] = $legacyRoot;
        }

        $roots = array_values(array_unique(array_filter($roots, static fn (string $root): bool => $root !== '')));
        foreach ($roots as $root) {
            $compiledDir = $root;
            if (is_dir($root.'/compiled')) {
                $compiledDir = $root.'/compiled';
            }

            if (is_file($compiledDir.'/manifest.json')) {
                return $compiledDir;
            }
        }

        return null;
    }

    private function resolveStorageRoot(string $storagePath): string
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return rtrim($normalized, '/');
        }

        $relative = ltrim($normalized, '/');
        if (str_starts_with($relative, 'app/')) {
            $relative = substr($relative, 4);
        }

        return rtrim(storage_path('app/'.$relative), '/');
    }

    /**
     * @return list<string>
     */
    private function legacyFallbackRoots(object $release, string $storagePath): array
    {
        $normalizedStoragePath = str_replace('\\', '/', trim($storagePath));
        $isNewPath = str_contains($normalizedStoragePath, 'private/packs_v2/');
        if (! $isNewPath) {
            return [];
        }

        $packId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $packVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
        if ($packId === '' || $packVersion === '') {
            return [];
        }

        $releaseId = trim((string) ($release->id ?? ''));
        $manifestHash = strtolower(trim((string) ($release->manifest_hash ?? '')));

        $candidates = [];
        if ($manifestHash !== '') {
            $candidates[] = storage_path('app/content_packs_v2/'.$packId.'/'.$packVersion.'/'.$manifestHash);
        }
        if ($releaseId !== '') {
            $candidates[] = storage_path('app/content_packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId);
        }

        if (preg_match('#private/packs_v2/[^/]+/[^/]+/([^/]+)$#', trim($normalizedStoragePath, '/'), $m) === 1) {
            $storageLeaf = trim((string) ($m[1] ?? ''));
            if ($storageLeaf !== '') {
                $candidates[] = storage_path('app/content_packs_v2/'.$packId.'/'.$packVersion.'/'.$storageLeaf);
            }
        }

        return array_values(array_unique($candidates));
    }
}
