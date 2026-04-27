<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class EnneagramRegistryReleaseResolver
{
    public const PACK_ID = 'ENNEAGRAM';

    public const PACK_VERSION = 'v2';

    public function __construct(
        private readonly ContentPathAliasResolver $pathAliasResolver,
    ) {}

    public function repoFallbackRegistryRoot(?string $version = null): string
    {
        $packBase = $this->pathAliasResolver->resolveBackendPackRoot(self::PACK_ID);

        return rtrim($packBase, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$this->normalizeRegistryVersion($version)
            .DIRECTORY_SEPARATOR.'registry';
    }

    public function activeRegistryRoot(?string $version = null): ?string
    {
        $release = $this->resolveActiveRelease($this->normalizeRegistryVersion($version));
        if ($release === null) {
            return null;
        }

        return $this->resolveRegistryRootFromStoragePath((string) ($release->storage_path ?? ''));
    }

    public function runtimeRegistryRoot(?string $version = null): string
    {
        return $this->activeRegistryRoot($version) ?? $this->repoFallbackRegistryRoot($version);
    }

    /**
     * @return array{
     *   root:string,
     *   source:string,
     *   active_release_id:?string,
     *   active_storage_path:?string
     * }
     */
    public function runtimeRegistryContext(?string $version = null): array
    {
        $normalizedVersion = $this->normalizeRegistryVersion($version);
        $release = $this->resolveActiveRelease($normalizedVersion);
        $activeRoot = $release !== null
            ? $this->resolveRegistryRootFromStoragePath((string) ($release->storage_path ?? ''))
            : null;

        if (is_string($activeRoot) && $activeRoot !== '') {
            return [
                'root' => $activeRoot,
                'source' => 'active_release',
                'active_release_id' => trim((string) ($release->id ?? '')) ?: null,
                'active_storage_path' => trim((string) ($release->storage_path ?? '')) ?: null,
            ];
        }

        return [
            'root' => $this->repoFallbackRegistryRoot($normalizedVersion),
            'source' => 'repo_fallback',
            'active_release_id' => null,
            'active_storage_path' => null,
        ];
    }

    private function normalizeRegistryVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : self::PACK_VERSION;
    }

    private function resolveActiveRelease(string $version): ?object
    {
        try {
            $activation = DB::table('content_pack_activations')
                ->where('pack_id', self::PACK_ID)
                ->where('pack_version', $version)
                ->first();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'content_pack_activations')) {
                return null;
            }

            throw $e;
        }

        if ($activation === null) {
            return null;
        }

        $releaseId = trim((string) ($activation->release_id ?? ''));
        if ($releaseId === '') {
            return null;
        }

        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        if ($release === null) {
            return null;
        }

        $releasePackId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $releaseVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
        if ($releasePackId !== self::PACK_ID || ($releaseVersion !== '' && $releaseVersion !== $version)) {
            return null;
        }

        return $release;
    }

    private function resolveRegistryRootFromStoragePath(string $storagePath): ?string
    {
        foreach ($this->candidateRootsFromStoragePath($storagePath) as $root) {
            if (is_file($root.DIRECTORY_SEPARATOR.'manifest.json')) {
                return $root;
            }

            $registryRoot = $root.DIRECTORY_SEPARATOR.'registry';
            if (is_file($registryRoot.DIRECTORY_SEPARATOR.'manifest.json')) {
                return $registryRoot;
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

        if (str_starts_with($normalized, 'repo://')) {
            $normalized = trim(substr($normalized, strlen('repo://')), '/');

            return $normalized !== '' ? [base_path($normalized)] : [];
        }

        $candidates = [];
        if (str_starts_with($normalized, '/')) {
            $candidates[] = rtrim($normalized, '/');
        } else {
            $relative = ltrim($normalized, '/');
            $candidates[] = rtrim(storage_path('app/'.$relative), '/');

            if (str_starts_with($relative, 'app/')) {
                $candidates[] = rtrim(storage_path(substr($relative, 4)), '/');
            }

            if (str_starts_with($relative, 'private/packs_v2/')) {
                $mirror = 'content_packs_v2/'.substr($relative, strlen('private/packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            } elseif (str_starts_with($relative, 'content_packs_v2/')) {
                $mirror = 'private/packs_v2/'.substr($relative, strlen('content_packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $root): bool => $root !== '')));
    }
}
