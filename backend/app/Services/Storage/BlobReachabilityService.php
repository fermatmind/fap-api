<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BlobReachabilityService
{
    public function __construct(
        private readonly ReleaseStorageLocator $releaseStorageLocator,
        private readonly ArtifactStore $artifactStore,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(): array
    {
        $activeReleaseIds = $this->activeReleaseIds();
        $snapshotReleaseIds = $this->snapshotReleaseIds();

        $releaseRows = DB::table('content_pack_releases')
            ->where(function ($query): void {
                $query->where('status', 'success')
                    ->orWhereIn('id', $this->activeReleaseIds())
                    ->orWhereIn('id', $this->snapshotReleaseIds());
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $reachableHashes = [];
        $reachableManifestHashes = [];
        $releaseStoragePaths = [];

        foreach ($releaseRows as $release) {
            $manifestHash = strtolower(trim((string) ($release->manifest_hash ?? '')));
            if ($manifestHash !== '') {
                $reachableManifestHashes[$manifestHash] = true;
            }

            $source = $this->releaseStorageLocator->resolveReleaseSource($release);
            if ($source === null) {
                continue;
            }

            $releaseStoragePaths[] = $source['root'];
            $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot($source['root']);
            if ($manifestMeta['manifest_hash'] !== '') {
                $reachableManifestHashes[strtolower($manifestMeta['manifest_hash'])] = true;
            }

            foreach ($this->releaseStorageLocator->collectCompiledFilesFromRoot($source['root']) as $file) {
                $reachableHashes[(string) $file['hash']] = true;
            }
        }

        foreach ($this->releaseStorageLocator->legacyBackupRoots() as $backupRoot) {
            $releaseStoragePaths[] = $backupRoot;
            $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot($backupRoot);
            if ($manifestMeta['manifest_hash'] !== '') {
                $reachableManifestHashes[strtolower($manifestMeta['manifest_hash'])] = true;
            }

            foreach ($this->releaseStorageLocator->collectCompiledFilesFromRoot($backupRoot) as $file) {
                $reachableHashes[(string) $file['hash']] = true;
            }
        }

        $liveAliasPaths = [];
        foreach ($this->releaseStorageLocator->liveAliasRoots() as $liveAliasRoot) {
            $liveAliasPaths[] = $liveAliasRoot;
            $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot($liveAliasRoot);
            if ($manifestMeta['manifest_hash'] !== '') {
                $reachableManifestHashes[strtolower($manifestMeta['manifest_hash'])] = true;
            }

            foreach ($this->releaseStorageLocator->collectCompiledFilesFromRoot($liveAliasRoot) as $file) {
                $reachableHashes[(string) $file['hash']] = true;
            }
        }

        $artifactPaths = [];
        foreach ($this->artifactRootFiles() as $entry) {
            $artifactPaths[] = $entry['relative_path'];

            try {
                $bytes = (string) Storage::disk('local')->get($entry['relative_path']);
                $reachableHashes[hash('sha256', $bytes)] = true;
            } catch (\Throwable) {
                continue;
            }
        }

        $manifestIds = DB::table('content_release_manifests')
            ->whereIn('manifest_hash', array_keys($reachableManifestHashes))
            ->pluck('id');

        if ($manifestIds->isNotEmpty()) {
            $manifestFileHashes = DB::table('content_release_manifest_files')
                ->whereIn('content_release_manifest_id', $manifestIds->all())
                ->pluck('blob_hash');
            foreach ($manifestFileHashes as $blobHash) {
                $hash = strtolower(trim((string) $blobHash));
                if ($hash !== '') {
                    $reachableHashes[$hash] = true;
                }
            }
        }

        $catalogedBlobs = DB::table('storage_blobs')
            ->select(['hash', 'size_bytes'])
            ->get();

        $reachableBlobHashes = [];
        $unreachableBlobHashes = [];
        $reachableBytes = 0;
        $unreachableBytes = 0;

        foreach ($catalogedBlobs as $blob) {
            $hash = strtolower(trim((string) ($blob->hash ?? '')));
            if ($hash === '') {
                continue;
            }

            $sizeBytes = max(0, (int) ($blob->size_bytes ?? 0));
            if (isset($reachableHashes[$hash])) {
                $reachableBlobHashes[] = $hash;
                $reachableBytes += $sizeBytes;
            } else {
                $unreachableBlobHashes[] = $hash;
                $unreachableBytes += $sizeBytes;
            }
        }

        sort($reachableBlobHashes);
        sort($unreachableBlobHashes);
        sort($activeReleaseIds);
        sort($snapshotReleaseIds);
        $releaseStoragePaths = array_values(array_unique($releaseStoragePaths));
        sort($releaseStoragePaths);
        $artifactPaths = array_values(array_unique($artifactPaths));
        sort($artifactPaths);
        $liveAliasPaths = array_values(array_unique($liveAliasPaths));
        sort($liveAliasPaths);

        return [
            'schema' => 'storage_blob_gc_plan.v1',
            'generated_at' => now()->toAtomString(),
            'summary' => [
                'root_release_count' => count($releaseStoragePaths),
                'root_activation_count' => count($activeReleaseIds),
                'root_snapshot_release_ref_count' => count($snapshotReleaseIds),
                'root_artifact_count' => count($artifactPaths),
                'reachable_blob_count' => count($reachableBlobHashes),
                'unreachable_blob_count' => count($unreachableBlobHashes),
                'planned_deletion_count' => 0,
                'reachable_bytes' => $reachableBytes,
                'unreachable_bytes' => $unreachableBytes,
                'planned_deletion_bytes' => 0,
            ],
            'roots' => [
                'active_release_ids' => $activeReleaseIds,
                'snapshot_release_ids' => $snapshotReleaseIds,
                'release_storage_paths' => $releaseStoragePaths,
                'artifact_paths' => $artifactPaths,
                'live_alias_paths' => $liveAliasPaths,
            ],
            'reachable' => [
                'blob_hashes' => $reachableBlobHashes,
            ],
            'unreachable' => [
                'blob_hashes' => $unreachableBlobHashes,
            ],
            'planned_deletions' => [
                'dry_run_only' => true,
                'blob_hashes' => [],
            ],
            'bytes' => [
                'reachable' => $reachableBytes,
                'unreachable' => $unreachableBytes,
                'planned_deletions' => 0,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function activeReleaseIds(): array
    {
        return DB::table('content_pack_activations')
            ->pluck('release_id')
            ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function snapshotReleaseIds(): array
    {
        $columns = [
            'from_content_pack_release_id',
            'to_content_pack_release_id',
            'activation_before_release_id',
            'activation_after_release_id',
        ];

        $ids = [];
        foreach (DB::table('content_release_snapshots')->get($columns) as $row) {
            foreach ($columns as $column) {
                $value = trim((string) ($row->{$column} ?? ''));
                if ($value !== '') {
                    $ids[$value] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<array{relative_path:string}>
     */
    private function artifactRootFiles(): array
    {
        $disk = Storage::disk('local');
        $entries = [];

        foreach ($disk->allFiles('artifacts/reports') as $relativePath) {
            $entries[$relativePath] = ['relative_path' => $relativePath];
        }
        foreach ($disk->allFiles('artifacts/pdf') as $relativePath) {
            $entries[$relativePath] = ['relative_path' => $relativePath];
        }

        foreach (['reports', 'private/reports'] as $prefix) {
            foreach ($disk->allFiles($prefix) as $relativePath) {
                if (! $this->shouldTreatLegacyArtifactAsRoot($relativePath)) {
                    continue;
                }

                $entries[$relativePath] = ['relative_path' => $relativePath];
            }
        }

        ksort($entries);

        return array_values($entries);
    }

    private function shouldTreatLegacyArtifactAsRoot(string $relativePath): bool
    {
        $canonicalTarget = $this->canonicalArtifactTargetForLegacyPath($relativePath);
        if ($canonicalTarget === null) {
            return true;
        }

        return ! Storage::disk('local')->exists($canonicalTarget);
    }

    private function canonicalArtifactTargetForLegacyPath(string $relativePath): ?string
    {
        if (preg_match('#^(?:private/)?reports/([^/]+)/report\.json$#', $relativePath, $matches) === 1) {
            return $this->artifactStore->reportCanonicalPath('MBTI', (string) $matches[1]);
        }

        if (preg_match('#^(?:private/)?reports/([^/]+)/([^/]+)/report\.json$#', $relativePath, $matches) === 1) {
            return $this->artifactStore->reportCanonicalPath((string) $matches[1], (string) $matches[2]);
        }

        if (preg_match('#^(?:private/)?reports/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#i', $relativePath, $matches) === 1) {
            return $this->artifactStore->pdfCanonicalPath(
                (string) $matches[1],
                (string) $matches[2],
                (string) $matches[3],
                strtolower((string) $matches[4]),
            );
        }

        if (preg_match('#^(?:private/)?reports/big5/([^/]+)/report_(free|full)\.pdf$#i', $relativePath, $matches) === 1) {
            return null;
        }

        return null;
    }
}
