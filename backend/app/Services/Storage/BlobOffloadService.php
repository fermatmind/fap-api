<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\StorageBlobLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class BlobOffloadService
{
    private const LOCATION_KIND_REMOTE_COPY = 'remote_copy';

    public function __construct(
        private readonly ArtifactStore $artifactStore,
        private readonly BlobCatalogService $blobCatalogService,
        private readonly BlobReachabilityService $blobReachabilityService,
        private readonly ReleaseStorageLocator $releaseStorageLocator,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $disk): array
    {
        $disk = $this->normalizeDisk($disk);
        $sourceMap = $this->collectSourceMap();

        $candidates = [];
        $skipped = [];
        $candidateBytes = 0;
        $skippedBytes = 0;

        $reachableHashes = $this->reachableBlobHashes();
        $catalogedBlobs = DB::table('storage_blobs')
            ->when(
                $reachableHashes !== [],
                static fn ($query) => $query->whereIn('hash', $reachableHashes),
                static fn ($query) => $query->whereRaw('1 = 0')
            )
            ->orderBy('hash')
            ->get();

        foreach ($catalogedBlobs as $blob) {
            $hash = strtolower(trim((string) ($blob->hash ?? '')));
            if (! $this->isValidHash($hash)) {
                continue;
            }

            $remotePath = $this->remotePathForHash($hash);
            $sizeBytes = max(0, (int) ($blob->size_bytes ?? 0));
            $contentType = trim((string) ($blob->content_type ?? ''));
            $existingLocation = StorageBlobLocation::query()
                ->where('blob_hash', $hash)
                ->where('disk', $disk)
                ->where('storage_path', $remotePath)
                ->first();

            if ($existingLocation !== null) {
                $skipped[] = [
                    'blob_hash' => $hash,
                    'reason' => 'already_offloaded',
                    'size_bytes' => $sizeBytes,
                    'remote_path' => $remotePath,
                    'source_kind' => null,
                    'source_path' => null,
                ];
                $skippedBytes += $sizeBytes;

                continue;
            }

            $source = $sourceMap[$hash] ?? null;
            if ($source === null) {
                $skipped[] = [
                    'blob_hash' => $hash,
                    'reason' => 'no_source',
                    'size_bytes' => $sizeBytes,
                    'remote_path' => $remotePath,
                    'source_kind' => null,
                    'source_path' => null,
                ];
                $skippedBytes += $sizeBytes;

                continue;
            }

            if ((string) ($source['source_kind'] ?? '') === 'live_alias') {
                $skipped[] = [
                    'blob_hash' => $hash,
                    'reason' => 'live_alias_only_source',
                    'size_bytes' => $sizeBytes,
                    'remote_path' => $remotePath,
                    'source_kind' => 'live_alias',
                    'source_path' => (string) ($source['source_path'] ?? ''),
                ];
                $skippedBytes += $sizeBytes;

                continue;
            }

            $candidates[] = [
                'blob_hash' => $hash,
                'remote_path' => $remotePath,
                'size_bytes' => $sizeBytes,
                'content_type' => $contentType !== '' ? $contentType : ($source['content_type'] ?? null),
                'source_kind' => (string) ($source['source_kind'] ?? ''),
                'source_path' => (string) ($source['source_path'] ?? ''),
                'source_root' => (string) ($source['source_root'] ?? ''),
                'source_relative_path' => (string) ($source['source_relative_path'] ?? ''),
                'storage_class' => $this->storageClass(),
            ];
            $candidateBytes += $sizeBytes;
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp((string) $a['blob_hash'], (string) $b['blob_hash']));
        usort($skipped, static fn (array $a, array $b): int => strcmp((string) $a['blob_hash'], (string) $b['blob_hash']));

        return [
            'schema' => (string) config('storage_rollout.blob_offload_plan_schema_version', 'storage_blob_offload_plan.v1'),
            'generated_at' => now()->toAtomString(),
            'disk' => $disk,
            'summary' => [
                'candidate_count' => count($candidates),
                'skipped_count' => count($skipped),
                'bytes' => $candidateBytes,
                'candidate_bytes' => $candidateBytes,
                'skipped_bytes' => $skippedBytes,
            ],
            'candidates' => $candidates,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan): array
    {
        $disk = $this->normalizeDisk((string) ($plan['disk'] ?? ''));
        $remoteDisk = Storage::disk($disk);
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));

        $summary = [
            'uploaded_count' => 0,
            'verified_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'bytes' => 0,
            'uploaded_bytes' => 0,
            'warnings' => [],
        ];

        $candidates = is_array($plan['candidates'] ?? null) ? $plan['candidates'] : [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $hash = strtolower(trim((string) ($candidate['blob_hash'] ?? '')));
            $sourcePath = trim((string) ($candidate['source_path'] ?? ''));
            $remotePath = trim((string) ($candidate['remote_path'] ?? ''));
            $expectedSize = max(0, (int) ($candidate['size_bytes'] ?? 0));
            $sourceKind = trim((string) ($candidate['source_kind'] ?? ''));

            if (! $this->isValidHash($hash) || $sourcePath === '' || $remotePath === '') {
                $summary['failed_count']++;
                $this->appendWarning($summary, 'invalid offload candidate '.json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                continue;
            }

            if (! is_file($sourcePath)) {
                $summary['failed_count']++;
                $this->appendWarning($summary, 'source file missing for '.$hash.': '.$sourcePath);

                continue;
            }

            $bytes = (string) File::get($sourcePath);
            if (hash('sha256', $bytes) !== $hash) {
                $summary['failed_count']++;
                $this->appendWarning($summary, 'source hash mismatch for '.$hash.' from '.$sourcePath);

                continue;
            }

            if ($remoteDisk->exists($remotePath)) {
                $verified = $this->verifyRemoteObject($remoteDisk, $remotePath, $hash, $expectedSize);
                if (! ($verified['ok'] ?? false)) {
                    $summary['failed_count']++;
                    $this->appendWarning($summary, 'remote verify failed for existing object '.$remotePath);

                    continue;
                }

                $this->upsertLocation($hash, $disk, $remotePath, $verified, $sourceKind, $sourcePath, $driver);
                $summary['verified_count']++;
                $summary['skipped_count']++;

                continue;
            }

            try {
                $options = [];
                if ($driver === 's3' && $this->storageClass() !== null) {
                    $options['StorageClass'] = $this->storageClass();
                }

                if ($options === []) {
                    $remoteDisk->put($remotePath, $bytes);
                } else {
                    $remoteDisk->put($remotePath, $bytes, $options);
                }
            } catch (\Throwable $e) {
                $summary['failed_count']++;
                $this->appendWarning($summary, 'upload failed for '.$hash.' -> '.$remotePath.': '.$e->getMessage());

                continue;
            }

            $verified = $this->verifyRemoteObject($remoteDisk, $remotePath, $hash, $expectedSize);
            if (! ($verified['ok'] ?? false)) {
                $summary['failed_count']++;
                $this->appendWarning($summary, 'remote verify failed after upload for '.$hash.' -> '.$remotePath);

                continue;
            }

            $this->upsertLocation($hash, $disk, $remotePath, $verified, $sourceKind, $sourcePath, $driver);
            $summary['uploaded_count']++;
            $summary['verified_count']++;
            $summary['bytes'] += (int) ($verified['size_bytes'] ?? 0);
            $summary['uploaded_bytes'] += (int) ($verified['size_bytes'] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collectSourceMap(): array
    {
        $sourceMap = [];

        foreach ($this->artifactSourceEntries() as $entry) {
            $this->registerSource($sourceMap, $entry);
        }

        foreach ($this->releaseRows() as $release) {
            $source = $this->releaseStorageLocator->resolveReleaseSource($release);
            if ($source === null) {
                continue;
            }

            $root = (string) ($source['root'] ?? '');
            $resolvedFrom = $this->sourceKindForResolvedSource($source);
            foreach ($this->releaseStorageLocator->collectCompiledFilesFromRoot($root) as $file) {
                $logicalPath = (string) ($file['logical_path'] ?? '');
                $sourcePath = $this->absolutePathFromRoot($root, $logicalPath);
                if (! is_file($sourcePath)) {
                    continue;
                }

                $this->registerSource($sourceMap, [
                    'blob_hash' => (string) ($file['hash'] ?? ''),
                    'source_kind' => $resolvedFrom,
                    'source_path' => $sourcePath,
                    'source_root' => $root,
                    'source_relative_path' => $logicalPath,
                    'content_type' => $file['content_type'] ?? null,
                    'priority' => 30,
                ]);
            }
        }

        foreach ($this->releaseStorageLocator->legacyBackupRoots() as $root) {
            foreach ($this->releaseStorageLocator->collectCompiledFilesFromRoot($root) as $file) {
                $logicalPath = (string) ($file['logical_path'] ?? '');
                $sourcePath = $this->absolutePathFromRoot($root, $logicalPath);
                if (! is_file($sourcePath)) {
                    continue;
                }

                $this->registerSource($sourceMap, [
                    'blob_hash' => (string) ($file['hash'] ?? ''),
                    'source_kind' => 'backup',
                    'source_path' => $sourcePath,
                    'source_root' => $root,
                    'source_relative_path' => $logicalPath,
                    'content_type' => $file['content_type'] ?? null,
                    'priority' => 40,
                ]);
            }
        }

        foreach ($this->releaseStorageLocator->liveAliasRoots() as $root) {
            foreach ($this->releaseStorageLocator->collectCompiledFilesFromRoot($root) as $file) {
                $logicalPath = (string) ($file['logical_path'] ?? '');
                $sourcePath = $this->absolutePathFromRoot($root, $logicalPath);
                if (! is_file($sourcePath)) {
                    continue;
                }

                $this->registerSource($sourceMap, [
                    'blob_hash' => (string) ($file['hash'] ?? ''),
                    'source_kind' => 'live_alias',
                    'source_path' => $sourcePath,
                    'source_root' => $root,
                    'source_relative_path' => $logicalPath,
                    'content_type' => $file['content_type'] ?? null,
                    'priority' => 90,
                ]);
            }
        }

        return $sourceMap;
    }

    /**
     * @return list<object>
     */
    private function releaseRows(): array
    {
        return DB::table('content_pack_releases')
            ->where(function ($query): void {
                $query->where('status', 'success')
                    ->orWhereIn('id', $this->activeReleaseIds())
                    ->orWhereIn('id', $this->snapshotReleaseIds());
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
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
     * @return list<array<string,mixed>>
     */
    private function artifactSourceEntries(): array
    {
        $disk = Storage::disk('local');
        $entries = [];

        foreach ($disk->allFiles('artifacts/reports') as $relativePath) {
            $this->pushArtifactEntry($entries, $relativePath, 'artifact_canonical', 10);
        }
        foreach ($disk->allFiles('artifacts/pdf') as $relativePath) {
            $this->pushArtifactEntry($entries, $relativePath, 'artifact_canonical', 10);
        }

        foreach (['reports', 'private/reports'] as $prefix) {
            foreach ($disk->allFiles($prefix) as $relativePath) {
                if (! $this->shouldTreatLegacyArtifactAsRoot($relativePath)) {
                    continue;
                }

                $this->pushArtifactEntry($entries, $relativePath, 'artifact_legacy', 20);
            }
        }

        return $entries;
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     */
    private function pushArtifactEntry(array &$entries, string $relativePath, string $sourceKind, int $priority): void
    {
        $absolutePath = storage_path('app/private/'.ltrim($relativePath, '/'));
        if (! is_file($absolutePath)) {
            return;
        }

        $bytes = (string) Storage::disk('local')->get($relativePath);
        $entries[] = [
            'blob_hash' => hash('sha256', $bytes),
            'source_kind' => $sourceKind,
            'source_path' => $absolutePath,
            'source_root' => dirname($absolutePath),
            'source_relative_path' => $relativePath,
            'content_type' => $this->contentTypeForArtifactPath($relativePath),
            'priority' => $priority,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $sourceMap
     * @param  array<string,mixed>  $entry
     */
    private function registerSource(array &$sourceMap, array $entry): void
    {
        $hash = strtolower(trim((string) ($entry['blob_hash'] ?? '')));
        if (! $this->isValidHash($hash)) {
            return;
        }

        $priority = (int) ($entry['priority'] ?? PHP_INT_MAX);
        if (isset($sourceMap[$hash]) && $priority >= (int) ($sourceMap[$hash]['priority'] ?? PHP_INT_MAX)) {
            return;
        }

        $entry['priority'] = $priority;
        $entry['blob_hash'] = $hash;
        $sourceMap[$hash] = $entry;
    }

    /**
     * @param  mixed  $remoteDisk
     * @return array<string,mixed>
     */
    private function verifyRemoteObject($remoteDisk, string $remotePath, string $expectedHash, int $expectedSize): array
    {
        try {
            if (! $remoteDisk->exists($remotePath)) {
                return ['ok' => false];
            }

            $bytes = $remoteDisk->get($remotePath);
            if (! is_string($bytes)) {
                return ['ok' => false];
            }

            $verifiedHash = hash('sha256', $bytes);
            $verifiedSize = strlen($bytes);
            if ($verifiedHash !== $expectedHash) {
                return ['ok' => false];
            }
            if ($expectedSize > 0 && $verifiedSize !== $expectedSize) {
                return ['ok' => false];
            }

            return [
                'ok' => true,
                'size_bytes' => $verifiedSize,
                'checksum' => 'sha256:'.$verifiedHash,
                'etag' => $this->bestEffortRemoteChecksum($remoteDisk, $remotePath),
                'storage_class' => $this->storageClass(),
                'verified_hash' => $verifiedHash,
            ];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    /**
     * @param  array<string,mixed>  $verified
     */
    private function upsertLocation(
        string $hash,
        string $disk,
        string $remotePath,
        array $verified,
        string $sourceKind,
        string $sourcePath,
        string $driver,
    ): void {
        StorageBlobLocation::query()->updateOrCreate(
            [
                'blob_hash' => $hash,
                'disk' => $disk,
                'storage_path' => $remotePath,
            ],
            [
                'location_kind' => self::LOCATION_KIND_REMOTE_COPY,
                'size_bytes' => (int) ($verified['size_bytes'] ?? 0),
                'checksum' => $verified['checksum'] ?? null,
                'etag' => $verified['etag'] ?? null,
                'storage_class' => $verified['storage_class'] ?? $this->storageClass(),
                'verified_at' => now(),
                'meta_json' => [
                    'driver' => $driver,
                    'source_kind' => $sourceKind,
                    'source_path' => $sourcePath,
                    'verified_checksum_sha256' => (string) ($verified['verified_hash'] ?? ''),
                    'requested_storage_class' => $this->storageClass(),
                    'bucket' => $this->diskConfigValue($disk, 'bucket'),
                    'region' => $this->diskConfigValue($disk, 'region'),
                    'endpoint' => $this->diskConfigValue($disk, 'endpoint'),
                    'url' => $this->diskConfigValue($disk, 'url'),
                ],
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function reachableBlobHashes(): array
    {
        $plan = $this->blobReachabilityService->buildPlan();
        $hashes = data_get($plan, 'reachable.blob_hashes', []);
        if (! is_array($hashes)) {
            return [];
        }

        $normalized = [];
        foreach ($hashes as $hash) {
            $hash = strtolower(trim((string) $hash));
            if ($this->isValidHash($hash)) {
                $normalized[$hash] = true;
            }
        }

        return array_keys($normalized);
    }

    private function diskConfigValue(string $disk, string $key): ?string
    {
        $value = trim((string) config('filesystems.disks.'.$disk.'.'.$key, ''));

        return $value !== '' ? $value : null;
    }

    private function remotePathForHash(string $hash): string
    {
        $prefix = trim((string) config('storage_rollout.blob_offload_prefix', 'rollout/blobs'), '/');
        $suffix = 'sha256/'.substr($hash, 0, 2).'/'.$hash;

        return $prefix === '' ? $suffix : $prefix.'/'.$suffix;
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function sourceKindForResolvedSource(array $source): string
    {
        $resolvedFrom = str_replace('.', '_', trim((string) ($source['resolved_from'] ?? 'release')));
        if ($resolvedFrom === '') {
            $resolvedFrom = 'release';
        }

        if ($resolvedFrom !== 'release_storage_path') {
            return $resolvedFrom;
        }

        $storagePath = str_replace('\\', '/', trim((string) ($source['storage_path_for_patch'] ?? '')));
        $root = str_replace('\\', '/', trim((string) ($source['root'] ?? '')));

        if (str_contains($storagePath, 'private/packs_v2/') && str_contains($root, '/app/content_packs_v2/')) {
            return 'v2_mirror';
        }

        if (str_contains($storagePath, 'content_packs_v2/') && str_contains($root, '/app/private/packs_v2/')) {
            return 'v2_primary';
        }

        return $resolvedFrom;
    }

    private function absolutePathFromRoot(string $root, string $logicalPath): string
    {
        return rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($logicalPath, '/'));
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

        if (preg_match('#^(?:private/)?reports/big5/([^/]+)/report_(free|full)\.pdf$#i', $relativePath) === 1) {
            return null;
        }

        return null;
    }

    private function contentTypeForArtifactPath(string $relativePath): ?string
    {
        $relativePath = strtolower($relativePath);

        return match (true) {
            str_ends_with($relativePath, '.json') => 'application/json',
            str_ends_with($relativePath, '.pdf') => 'application/pdf',
            default => null,
        };
    }

    /**
     * @param  mixed  $remoteDisk
     */
    private function bestEffortRemoteChecksum($remoteDisk, string $remotePath): ?string
    {
        if (! method_exists($remoteDisk, 'checksum')) {
            return null;
        }

        try {
            $checksum = $remoteDisk->checksum($remotePath);

            return is_string($checksum) && $checksum !== '' ? $checksum : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDisk(string $disk): string
    {
        $disk = trim($disk);
        if ($disk === '') {
            $disk = trim((string) config('storage_rollout.blob_offload_disk', 'local'));
        }

        if ($disk === '') {
            throw new \InvalidArgumentException('blob offload disk is required');
        }

        return $disk;
    }

    private function storageClass(): ?string
    {
        $storageClass = trim((string) config('storage_rollout.blob_offload_storage_class', ''));

        return $storageClass !== '' ? $storageClass : null;
    }

    private function isValidHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function appendWarning(array &$summary, string $message): void
    {
        if (! isset($summary['warnings']) || ! is_array($summary['warnings'])) {
            $summary['warnings'] = [];
        }

        if (count($summary['warnings']) < 20) {
            $summary['warnings'][] = $message;
        }
    }
}
