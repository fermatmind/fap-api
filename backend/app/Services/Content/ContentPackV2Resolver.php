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

        $roots = $this->candidateRootsFromStoragePath($storagePath);
        foreach ($roots as $root) {
            $compiledDir = is_dir($root.'/compiled') ? $root.'/compiled' : $root;
            if (is_file($compiledDir.'/manifest.json')) {
                return $compiledDir;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateRootsFromStoragePath(string $storagePath): array
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        if ($normalized === '') {
            return [];
        }

        $candidates = [];
        if (str_starts_with($normalized, '/')) {
            $candidates[] = rtrim($normalized, '/');
        } else {
            $relative = ltrim($normalized, '/');
            if (str_starts_with($relative, 'app/')) {
                $relative = substr($relative, 4);
            }
            $relative = ltrim($relative, '/');
            if ($relative !== '') {
                $candidates[] = rtrim(storage_path('app/'.$relative), '/');
            }

            if (str_starts_with($relative, 'private/packs_v2/')) {
                $mirror = 'content_packs_v2/'.substr($relative, strlen('private/packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            } elseif (str_starts_with($relative, 'content_packs_v2/')) {
                $mirror = 'private/packs_v2/'.substr($relative, strlen('content_packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (string $root): bool => $root !== '')));

        return $candidates;
    }
}
