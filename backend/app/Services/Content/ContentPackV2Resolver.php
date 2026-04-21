<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Throwable;

final class ContentPackV2Resolver
{
    public function __construct(
        private readonly ContentPackV2Materializer $materializer,
        private readonly ContentPackV2RemoteRehydrateService $remoteRehydrate,
    ) {}

    public function resolveActiveCompiledPath(string $packId, string $packVersion): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return null;
        }

        try {
            $activation = DB::table('content_pack_activations')
                ->where('pack_id', $packId)
                ->where('pack_version', $packVersion)
                ->first();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'content_pack_activations')) {
                return null;
            }

            throw $e;
        }

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
                if (! $this->shouldMaterialize()) {
                    return $compiledDir;
                }

                try {
                    return $this->materializer->materialize($release, $compiledDir);
                } catch (Throwable $e) {
                    Log::warning('PACKS2_RESOLVER_MATERIALIZATION_FAILED', [
                        'release_id' => trim((string) ($release->id ?? '')),
                        'manifest_hash' => strtolower(trim((string) ($release->manifest_hash ?? ''))),
                        'storage_path' => $storagePath,
                        'source_compiled_dir' => $compiledDir,
                        'error' => $e->getMessage(),
                    ]);

                    return $compiledDir;
                }
            }
        }

        if (! $this->shouldRemoteRehydrate()) {
            return null;
        }

        try {
            return $this->remoteRehydrate->materializeFromRemote($release, $this->remoteRehydrateDisk());
        } catch (Throwable $e) {
            Log::warning('PACKS2_RESOLVER_REMOTE_REHYDRATE_FAILED', [
                'release_id' => trim((string) ($release->id ?? '')),
                'manifest_hash' => strtolower(trim((string) ($release->manifest_hash ?? ''))),
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function shouldRemoteRehydrate(): bool
    {
        return (bool) config('storage_rollout.packs_v2_remote_rehydrate_enabled', false);
    }

    private function remoteRehydrateDisk(): string
    {
        return trim((string) config('storage_rollout.blob_offload_disk', 's3'));
    }

    private function shouldMaterialize(): bool
    {
        return (bool) config('storage_rollout.resolver_materialization_enabled', false);
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
