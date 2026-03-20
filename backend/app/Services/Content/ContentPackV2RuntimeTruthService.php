<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\Storage\ReleaseStorageLocator;

final class ContentPackV2RuntimeTruthService
{
    public function __construct(
        private readonly ReleaseStorageLocator $releaseStorageLocator,
        private readonly ContentPackV2RemoteRehydrateService $remoteRehydrate,
    ) {}

    /**
     * @return array{
     *   primary_available:bool,
     *   mirror_available:bool,
     *   remote_fallback_available:bool,
     *   runtime_safe_if_primary_removed:bool,
     *   runtime_safe_if_mirror_removed:bool,
     *   reason:?string,
     *   remote_fallback_enabled:bool,
     *   remote_fallback_reason:?string,
     *   primary_root:string,
     *   mirror_root:string
     * }
     */
    public function inspectRelease(object $release, string $disk): array
    {
        $storagePath = trim((string) ($release->storage_path ?? ''));
        [$primaryRoot, $mirrorRoot] = $this->resolveV2Roots($storagePath);

        $primaryAvailable = $primaryRoot !== '' && $this->releaseStorageLocator->compiledDirFromRoot($primaryRoot) !== null;
        $mirrorAvailable = $mirrorRoot !== '' && $this->releaseStorageLocator->compiledDirFromRoot($mirrorRoot) !== null;

        $remoteFallbackEnabled = (bool) config('storage_rollout.packs_v2_remote_rehydrate_enabled', false);
        $remoteFallbackAvailable = false;
        $remoteFallbackReason = null;

        if ($remoteFallbackEnabled) {
            try {
                $probe = $this->remoteRehydrate->probeRemoteFallback($release, $disk);
                $remoteFallbackAvailable = (bool) ($probe['available'] ?? false);
                $remoteFallbackReason = $remoteFallbackAvailable
                    ? null
                    : trim((string) ($probe['reason'] ?? 'PACKS2_REMOTE_REHYDRATE_PROBE_FAILED'));
            } catch (\Throwable $e) {
                $remoteFallbackReason = $e->getMessage();
            }
        } else {
            $remoteFallbackReason = 'PACKS2_REMOTE_REHYDRATE_DISABLED';
        }

        $runtimeSafeIfPrimaryRemoved = $mirrorAvailable || $remoteFallbackAvailable;
        $runtimeSafeIfMirrorRemoved = $primaryAvailable || $remoteFallbackAvailable;

        $reason = null;
        if (! $runtimeSafeIfPrimaryRemoved || ! $runtimeSafeIfMirrorRemoved) {
            $reason = $remoteFallbackReason;
        }

        return [
            'primary_available' => $primaryAvailable,
            'mirror_available' => $mirrorAvailable,
            'remote_fallback_available' => $remoteFallbackAvailable,
            'runtime_safe_if_primary_removed' => $runtimeSafeIfPrimaryRemoved,
            'runtime_safe_if_mirror_removed' => $runtimeSafeIfMirrorRemoved,
            'reason' => $reason,
            'remote_fallback_enabled' => $remoteFallbackEnabled,
            'remote_fallback_reason' => $remoteFallbackReason,
            'primary_root' => $primaryRoot,
            'mirror_root' => $mirrorRoot,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveV2Roots(string $storagePath): array
    {
        $primaryRoot = '';
        $mirrorRoot = '';

        foreach ($this->releaseStorageLocator->candidateRootsFromStoragePath($storagePath) as $root) {
            $normalized = $this->normalizeRoot($root);
            if ($normalized === '') {
                continue;
            }

            if ($primaryRoot === '' && str_contains($normalized, '/app/private/packs_v2/')) {
                $primaryRoot = $normalized;

                continue;
            }

            if ($mirrorRoot === '' && str_contains($normalized, '/app/content_packs_v2/')) {
                $mirrorRoot = $normalized;
            }
        }

        return [$primaryRoot, $mirrorRoot];
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }
}
