<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ReleaseMetadataBackfillService
{
    public function __construct(
        private readonly BlobCatalogService $blobCatalogService,
        private readonly ContentReleaseManifestCatalogService $manifestCatalogService,
        private readonly ReleaseStorageLocator $releaseStorageLocator,
    ) {}

    /**
     * @return array{
     *   schema_version:int,
     *   mode:string,
     *   generated_at:string,
     *   release_rows_scanned:int,
     *   release_rows_backfillable:int,
     *   release_rows_backfilled:int,
     *   release_rows_skipped:int,
     *   backup_roots_scanned:int,
     *   backup_roots_backfillable:int,
     *   backup_roots_backfilled:int,
     *   artifact_files_scanned:int,
     *   artifact_blobs_upserted:int,
     *   manifests_upserted:int,
     *   manifest_files_upserted:int,
     *   blobs_upserted:int,
     *   missing_physical_sources:int,
     *   warnings:list<string>
     * }
     */
    public function run(bool $execute): array
    {
        $summary = [
            'schema_version' => 1,
            'mode' => $execute ? 'execute' : 'dry_run',
            'generated_at' => now()->toAtomString(),
            'release_rows_scanned' => 0,
            'release_rows_backfillable' => 0,
            'release_rows_backfilled' => 0,
            'release_rows_skipped' => 0,
            'backup_roots_scanned' => 0,
            'backup_roots_backfillable' => 0,
            'backup_roots_backfilled' => 0,
            'artifact_files_scanned' => 0,
            'artifact_blobs_upserted' => 0,
            'manifests_upserted' => 0,
            'manifest_files_upserted' => 0,
            'blobs_upserted' => 0,
            'missing_physical_sources' => 0,
            'warnings' => [],
        ];
        $processedRoots = [];

        $releases = DB::table('content_pack_releases')
            ->where('status', 'success')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($releases as $release) {
            $this->processRelease($release, $execute, $summary, $processedRoots);
        }

        foreach ($this->releaseStorageLocator->legacyBackupRoots() as $backupRoot) {
            $normalizedRoot = $this->normalizeRoot($backupRoot);
            if ($normalizedRoot === '' || isset($processedRoots[$normalizedRoot])) {
                continue;
            }

            $this->processBackupRoot($backupRoot, $execute, $summary, $processedRoots);
        }

        $artifactEntries = $this->collectArtifactEntries();
        $summary['artifact_files_scanned'] = count($artifactEntries);
        if ($execute) {
            foreach ($artifactEntries as $entry) {
                try {
                    $bytes = (string) Storage::disk('local')->get($entry['relative_path']);
                    $this->blobCatalogService->upsertBlob([
                        'hash' => hash('sha256', $bytes),
                        'disk' => 'local',
                        'storage_path' => $this->blobCatalogService->storagePathForHash(hash('sha256', $bytes)),
                        'size_bytes' => strlen($bytes),
                        'content_type' => $entry['content_type'],
                        'encoding' => 'identity',
                        'ref_count' => 0,
                        'last_verified_at' => now(),
                    ]);
                    $summary['artifact_blobs_upserted']++;
                    $summary['blobs_upserted']++;
                } catch (\Throwable $e) {
                    $this->appendWarning($summary, 'artifact blob catalog failed for '.$entry['relative_path'].': '.$e->getMessage());
                }
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,bool>  $processedRoots
     */
    private function processRelease(object $release, bool $execute, array &$summary, array &$processedRoots): void
    {
        $summary['release_rows_scanned']++;

        $source = $this->releaseStorageLocator->resolveReleaseSource($release);
        if ($source === null) {
            $summary['release_rows_skipped']++;
            $summary['missing_physical_sources']++;
            $this->appendWarning($summary, 'missing physical source for release '.trim((string) ($release->id ?? '')));

            return;
        }

        $normalizedRoot = $this->normalizeRoot((string) $source['root']);
        if ($normalizedRoot !== '') {
            $processedRoots[$normalizedRoot] = true;
        }

        $summary['release_rows_backfillable']++;

        $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot($source['root']);
        $manifestHash = trim((string) ($release->manifest_hash ?? ''));
        if ($manifestHash === '') {
            $manifestHash = $manifestMeta['manifest_hash'];
        }

        $packVersion = $this->derivePackVersion($release, $manifestMeta['decoded']);
        $sourceCommit = trim((string) ($release->source_commit ?? $release->git_sha ?? ''));
        $storagePathForPatch = trim((string) ($source['storage_path_for_patch'] ?? ''));

        $patch = [];
        if (trim((string) ($release->pack_version ?? '')) === '' && $packVersion !== '') {
            $patch['pack_version'] = $packVersion;
        }
        if ($this->isEmptyJsonColumn($release->manifest_json ?? null) && $manifestMeta['raw_json'] !== '') {
            $patch['manifest_json'] = $manifestMeta['raw_json'];
        }
        if (trim((string) ($release->storage_path ?? '')) === '' && $storagePathForPatch !== '') {
            $patch['storage_path'] = $storagePathForPatch;
        }
        if (trim((string) ($release->source_commit ?? '')) === '' && $sourceCommit !== '') {
            $patch['source_commit'] = $sourceCommit;
        }

        $compiledFiles = $this->releaseStorageLocator->collectCompiledFilesFromRoot($source['root']);
        if ($compiledFiles === []) {
            $summary['release_rows_skipped']++;
            $summary['missing_physical_sources']++;
            $this->appendWarning($summary, 'no compiled files found for release '.trim((string) ($release->id ?? '')));

            return;
        }

        if (! $execute) {
            return;
        }

        if ($patch !== []) {
            DB::table('content_pack_releases')
                ->where('id', (string) $release->id)
                ->update($patch);
        }

        $blobCatalogFailed = false;
        foreach ($compiledFiles as $file) {
            try {
                $this->blobCatalogService->upsertBlob([
                    'hash' => $file['hash'],
                    'disk' => 'local',
                    'storage_path' => $this->blobCatalogService->storagePathForHash($file['hash']),
                    'size_bytes' => $file['size_bytes'],
                    'content_type' => $file['content_type'],
                    'encoding' => 'identity',
                    'ref_count' => 0,
                    'last_verified_at' => now(),
                ]);
                $summary['blobs_upserted']++;
            } catch (\Throwable $e) {
                $blobCatalogFailed = true;
                $this->appendWarning(
                    $summary,
                    'release blob catalog failed for '.trim((string) ($release->id ?? '')).' '.$file['logical_path'].': '.$e->getMessage()
                );
            }
        }

        if ($manifestHash !== '' && ! $blobCatalogFailed) {
            $existingManifest = $this->manifestCatalogService->findByManifestHash($manifestHash);
            $manifestStoragePath = trim((string) ($existingManifest?->storage_path ?? ''));
            if ($manifestStoragePath === '') {
                $manifestStoragePath = $storagePathForPatch;
            }

            $manifestFiles = [];
            foreach ($compiledFiles as $file) {
                $manifestFiles[] = [
                    'logical_path' => $file['logical_path'],
                    'blob_hash' => $file['hash'],
                    'size_bytes' => $file['size_bytes'],
                    'role' => $file['role'],
                    'content_type' => $file['content_type'],
                    'encoding' => 'identity',
                    'checksum' => 'sha256:'.$file['hash'],
                ];
            }

            try {
                $this->manifestCatalogService->upsertManifest([
                    'content_pack_release_id' => $existingManifest?->content_pack_release_id ?: (string) ($release->id ?? ''),
                    'manifest_hash' => $manifestHash,
                    'storage_disk' => trim((string) ($existingManifest?->storage_disk ?? '')) !== '' ? (string) $existingManifest->storage_disk : 'local',
                    'storage_path' => $manifestStoragePath,
                    'pack_id' => strtoupper(trim((string) ($release->to_pack_id ?? $manifestMeta['pack_id'] ?? ''))),
                    'pack_version' => $packVersion !== '' ? $packVersion : null,
                    'compiled_hash' => trim((string) ($release->compiled_hash ?? $manifestMeta['compiled_hash'] ?? '')) ?: null,
                    'content_hash' => trim((string) ($release->content_hash ?? $manifestMeta['content_hash'] ?? '')) ?: null,
                    'norms_version' => trim((string) ($release->norms_version ?? $manifestMeta['norms_version'] ?? '')) ?: null,
                    'source_commit' => $sourceCommit !== '' ? $sourceCommit : null,
                    'payload_json' => $manifestMeta['decoded'] !== [] ? $manifestMeta['decoded'] : null,
                ], $manifestFiles);
                $summary['manifests_upserted']++;
                $summary['manifest_files_upserted'] += count($manifestFiles);
            } catch (\Throwable $e) {
                $this->appendWarning($summary, 'release manifest upsert failed for '.trim((string) ($release->id ?? '')).': '.$e->getMessage());
            }
        }

        $summary['release_rows_backfilled']++;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,bool>  $processedRoots
     */
    private function processBackupRoot(string $backupRoot, bool $execute, array &$summary, array &$processedRoots): void
    {
        $normalizedRoot = $this->normalizeRoot($backupRoot);
        if ($normalizedRoot === '') {
            return;
        }

        $processedRoots[$normalizedRoot] = true;
        $summary['backup_roots_scanned']++;

        $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot($backupRoot);
        $compiledFiles = $this->releaseStorageLocator->collectCompiledFilesFromRoot($backupRoot);
        if ($compiledFiles === []) {
            $summary['missing_physical_sources']++;
            $this->appendWarning($summary, 'no compiled files found for backup root '.$backupRoot);

            return;
        }

        $summary['backup_roots_backfillable']++;

        if (! $execute) {
            return;
        }

        $blobCatalogFailed = false;
        foreach ($compiledFiles as $file) {
            try {
                $this->blobCatalogService->upsertBlob([
                    'hash' => $file['hash'],
                    'disk' => 'local',
                    'storage_path' => $this->blobCatalogService->storagePathForHash($file['hash']),
                    'size_bytes' => $file['size_bytes'],
                    'content_type' => $file['content_type'],
                    'encoding' => 'identity',
                    'ref_count' => 0,
                    'last_verified_at' => now(),
                ]);
                $summary['blobs_upserted']++;
            } catch (\Throwable $e) {
                $blobCatalogFailed = true;
                $this->appendWarning(
                    $summary,
                    'backup blob catalog failed for '.$backupRoot.' '.$file['logical_path'].': '.$e->getMessage()
                );
            }
        }

        $manifestHash = trim((string) ($manifestMeta['manifest_hash'] ?? ''));
        if ($manifestHash !== '' && ! $blobCatalogFailed) {
            $existingManifest = $this->manifestCatalogService->findByManifestHash($manifestHash);
            $manifestStoragePath = trim((string) ($existingManifest?->storage_path ?? ''));
            if ($manifestStoragePath === '') {
                $manifestStoragePath = $backupRoot;
            }

            $backupContext = $this->releaseStorageLocator->backupRootContext($backupRoot);
            $backupReleaseId = trim((string) ($backupContext['release_id'] ?? ''));
            if ($backupReleaseId !== '' && ! DB::table('content_pack_releases')->where('id', $backupReleaseId)->exists()) {
                $backupReleaseId = '';
            }
            $manifestFiles = [];
            foreach ($compiledFiles as $file) {
                $manifestFiles[] = [
                    'logical_path' => $file['logical_path'],
                    'blob_hash' => $file['hash'],
                    'size_bytes' => $file['size_bytes'],
                    'role' => $file['role'],
                    'content_type' => $file['content_type'],
                    'encoding' => 'identity',
                    'checksum' => 'sha256:'.$file['hash'],
                ];
            }

            try {
                $this->manifestCatalogService->upsertManifest([
                    'content_pack_release_id' => $existingManifest?->content_pack_release_id
                        ?: ($backupReleaseId !== '' ? $backupReleaseId : null),
                    'manifest_hash' => $manifestHash,
                    'storage_disk' => trim((string) ($existingManifest?->storage_disk ?? '')) !== '' ? (string) $existingManifest->storage_disk : 'local',
                    'storage_path' => $manifestStoragePath,
                    'pack_id' => strtoupper(trim((string) ($manifestMeta['pack_id'] ?? ''))) ?: null,
                    'pack_version' => trim((string) ($manifestMeta['pack_version'] ?? '')) ?: null,
                    'compiled_hash' => trim((string) ($manifestMeta['compiled_hash'] ?? '')) ?: null,
                    'content_hash' => trim((string) ($manifestMeta['content_hash'] ?? '')) ?: null,
                    'norms_version' => trim((string) ($manifestMeta['norms_version'] ?? '')) ?: null,
                    'source_commit' => trim((string) ($existingManifest?->source_commit ?? '')) ?: null,
                    'payload_json' => $manifestMeta['decoded'] !== [] ? $manifestMeta['decoded'] : null,
                ], $manifestFiles);
                $summary['manifests_upserted']++;
                $summary['manifest_files_upserted'] += count($manifestFiles);
            } catch (\Throwable $e) {
                $this->appendWarning($summary, 'backup manifest upsert failed for '.$backupRoot.': '.$e->getMessage());
            }
        }

        $summary['backup_roots_backfilled']++;
    }

    /**
     * @return list<array{relative_path:string,content_type:?string}>
     */
    private function collectArtifactEntries(): array
    {
        $disk = Storage::disk('local');
        $entries = [];

        foreach (['artifacts/reports', 'artifacts/pdf', 'reports', 'private/reports'] as $prefix) {
            foreach ($disk->allFiles($prefix) as $relativePath) {
                $entries[$relativePath] = [
                    'relative_path' => $relativePath,
                    'content_type' => $this->contentTypeForArtifactPath($relativePath),
                ];
            }
        }

        ksort($entries);

        return array_values($entries);
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function derivePackVersion(object $release, array $manifest): string
    {
        $version = trim((string) ($release->pack_version ?? ''));
        if ($version !== '') {
            return $version;
        }

        $version = trim((string) ($manifest['pack_version'] ?? $manifest['content_package_version'] ?? ''));
        if ($version !== '') {
            return $version;
        }

        $toVersionId = trim((string) ($release->to_version_id ?? ''));
        if ($toVersionId !== '') {
            $versionRow = DB::table('content_pack_versions')->where('id', $toVersionId)->first();
            if ($versionRow !== null) {
                $version = trim((string) ($versionRow->content_package_version ?? ''));
                if ($version !== '') {
                    return $version;
                }
            }
        }

        return '';
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

    private function isEmptyJsonColumn(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function appendWarning(array &$summary, string $message): void
    {
        if (count($summary['warnings']) < 20) {
            $summary['warnings'][] = $message;
        }
    }
}
