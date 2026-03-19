<?php

declare(strict_types=1);

namespace App\Services\Content\Publisher;

use App\Services\Storage\BlobCatalogService;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ContentPackV2Publisher
{
    public function __construct(
        private readonly BlobCatalogService $blobCatalogService,
        private readonly ContentReleaseManifestCatalogService $manifestCatalogService,
        private readonly ContentReleaseSnapshotCatalogService $snapshotCatalogService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function publishCompiled(string $packId, string $packVersion, array $options = []): array
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            throw new RuntimeException('PACK_ID_OR_VERSION_REQUIRED');
        }

        $sourceCompiledDir = base_path('content_packs/'.$packId.'/'.$packVersion.'/compiled');
        if (! File::isDirectory($sourceCompiledDir)) {
            throw new RuntimeException('COMPILED_DIR_NOT_FOUND: '.$sourceCompiledDir);
        }

        $manifestPath = $sourceCompiledDir.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('MANIFEST_NOT_FOUND: '.$manifestPath);
        }

        $manifestRaw = (string) File::get($manifestPath);
        $manifest = json_decode($manifestRaw, true);
        if (! is_array($manifest)) {
            throw new RuntimeException('MANIFEST_INVALID_JSON');
        }

        $manifestHash = strtolower(trim((string) ($manifest['compiled_hash'] ?? '')));
        if ($manifestHash === '') {
            $manifestHash = strtolower(trim((string) ($manifest['content_hash'] ?? '')));
        }
        if ($manifestHash === '') {
            $manifestHash = hash('sha256', $manifestRaw);
        }

        $compiledHash = strtolower(trim((string) ($manifest['compiled_hash'] ?? $manifestHash)));
        $contentHash = strtolower(trim((string) ($manifest['content_hash'] ?? '')));
        $normsVersion = trim((string) ($manifest['norms_version'] ?? data_get($manifest, 'norms.norms_version', '')));

        $releaseId = (string) Str::uuid();
        $primaryStoragePath = 'private/packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;
        $mirrorStoragePath = 'content_packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;

        $this->copyCompiledToStoragePath($sourceCompiledDir, $primaryStoragePath);
        $this->copyCompiledToStoragePath($sourceCompiledDir, $mirrorStoragePath);

        $storagePath = $primaryStoragePath;

        $sourceCommit = trim((string) ($options['source_commit'] ?? ''));
        $createdBy = trim((string) ($options['created_by'] ?? 'packs2'));
        if ($createdBy === '') {
            $createdBy = 'packs2';
        }

        $now = now();
        $release = [
            'id' => $releaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => $packVersion,
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => $packId,
            'status' => 'success',
            'message' => 'published via packs2',
            'created_by' => $createdBy,
            'probe_ok' => null,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $compiledHash,
            'content_hash' => $contentHash !== '' ? $contentHash : null,
            'norms_version' => $normsVersion !== '' ? $normsVersion : null,
            'git_sha' => $sourceCommit !== '' ? $sourceCommit : null,
            'pack_version' => $packVersion,
            'manifest_json' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $storagePath,
            'source_commit' => $sourceCommit !== '' ? $sourceCommit : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('content_pack_releases')->insert($release);
        $this->dualWritePublishedReleaseMetadata($release);

        return $release;
    }

    public function activateRelease(string $releaseId): void
    {
        $this->switchActivation($releaseId, 'packs2_activate');
    }

    public function rollbackToRelease(string $packId, string $packVersion, string $toReleaseId): void
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        $toReleaseId = trim($toReleaseId);

        if ($packId === '' || $packVersion === '' || $toReleaseId === '') {
            throw new RuntimeException('ROLLBACK_ARGUMENTS_REQUIRED');
        }

        $release = DB::table('content_pack_releases')->where('id', $toReleaseId)->first();
        if (! $release) {
            throw new RuntimeException('RELEASE_NOT_FOUND');
        }

        $releasePackId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $releasePackVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));

        if ($releasePackId !== $packId || $releasePackVersion !== $packVersion) {
            throw new RuntimeException('ROLLBACK_TARGET_MISMATCH');
        }

        $this->switchActivation($toReleaseId, 'packs2_rollback');

        DB::table('content_pack_releases')->insert([
            'id' => (string) Str::uuid(),
            'action' => 'packs2_rollback',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => $packVersion,
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => $packId,
            'to_pack_id' => $packId,
            'status' => 'success',
            'message' => 'rollback to release '.$toReleaseId,
            'created_by' => 'packs2',
            'manifest_hash' => (string) ($release->manifest_hash ?? ''),
            'compiled_hash' => (string) ($release->compiled_hash ?? ''),
            'content_hash' => (string) ($release->content_hash ?? ''),
            'norms_version' => (string) ($release->norms_version ?? ''),
            'git_sha' => (string) ($release->git_sha ?? ''),
            'pack_version' => $packVersion,
            'manifest_json' => (string) ($release->manifest_json ?? ''),
            'storage_path' => (string) ($release->storage_path ?? ''),
            'source_commit' => (string) ($release->source_commit ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReleases(string $packId, string $packVersion, int $limit = 20): array
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return [];
        }

        $activeReleaseId = trim((string) DB::table('content_pack_activations')
            ->where('pack_id', $packId)
            ->where('pack_version', $packVersion)
            ->value('release_id'));

        $rows = DB::table('content_pack_releases')
            ->where('to_pack_id', $packId)
            ->where(function ($q) use ($packVersion): void {
                $q->where('pack_version', $packVersion)
                    ->orWhere(function ($q2) use ($packVersion): void {
                        $q2->whereNull('pack_version')->where('dir_alias', $packVersion);
                    });
            })
            ->whereIn('action', ['packs2_publish', 'packs2_rollback'])
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'release_id' => (string) ($row->id ?? ''),
                'action' => (string) ($row->action ?? ''),
                'pack_id' => (string) ($row->to_pack_id ?? ''),
                'pack_version' => (string) ($row->pack_version ?? $row->dir_alias ?? ''),
                'manifest_hash' => (string) ($row->manifest_hash ?? ''),
                'storage_path' => (string) ($row->storage_path ?? ''),
                'source_commit' => (string) ($row->source_commit ?? $row->git_sha ?? ''),
                'created_at' => (string) ($row->created_at ?? ''),
                'is_active' => $activeReleaseId !== '' && (string) ($row->id ?? '') === $activeReleaseId,
            ];
        }

        return $items;
    }

    public function resolveLatestReleaseId(string $packId, string $packVersion): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return null;
        }

        $row = DB::table('content_pack_releases')
            ->where('to_pack_id', $packId)
            ->where(function ($q) use ($packVersion): void {
                $q->where('pack_version', $packVersion)
                    ->orWhere(function ($q2) use ($packVersion): void {
                        $q2->whereNull('pack_version')->where('dir_alias', $packVersion);
                    });
            })
            ->where('action', 'packs2_publish')
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->first();

        if (! $row) {
            return null;
        }

        return (string) ($row->id ?? '');
    }

    private function releaseHasCompiledPayload(object $release): bool
    {
        $storagePath = trim((string) ($release->storage_path ?? ''));
        if ($storagePath === '') {
            return false;
        }

        $root = $this->absoluteStorageRoot($storagePath);

        return is_file($root.'/compiled/manifest.json') || is_file($root.'/manifest.json');
    }

    private function switchActivation(string $releaseId, string $reason): void
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            throw new RuntimeException('RELEASE_ID_REQUIRED');
        }

        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        if (! $release) {
            throw new RuntimeException('RELEASE_NOT_FOUND');
        }

        if (! $this->releaseHasCompiledPayload($release)) {
            throw new RuntimeException('RELEASE_COMPILED_PAYLOAD_MISSING');
        }

        $packId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $packVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
        if ($packId === '' || $packVersion === '') {
            throw new RuntimeException('RELEASE_PACK_CONTEXT_INVALID');
        }

        $activationBeforeReleaseId = trim((string) DB::table('content_pack_activations')
            ->where('pack_id', $packId)
            ->where('pack_version', $packVersion)
            ->value('release_id'));

        $now = now();
        DB::table('content_pack_activations')->updateOrInsert(
            [
                'pack_id' => $packId,
                'pack_version' => $packVersion,
            ],
            [
                'release_id' => $releaseId,
                'activated_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        if ($activationBeforeReleaseId !== $releaseId) {
            $this->dualWriteActivationSnapshot(
                $packId,
                $packVersion,
                $activationBeforeReleaseId !== '' ? $activationBeforeReleaseId : null,
                $releaseId,
                $reason,
                $release,
            );
        }
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function dualWritePublishedReleaseMetadata(array $release): void
    {
        if (! $this->shouldDualWriteContentPackV2()) {
            return;
        }
        if (! $this->shouldCatalogBlobs() && ! $this->shouldCatalogManifest()) {
            return;
        }

        $storagePath = trim((string) ($release['storage_path'] ?? ''));
        if ($storagePath === '') {
            return;
        }

        $manifestHash = trim((string) ($release['manifest_hash'] ?? ''));

        try {
            $compiledFiles = $this->collectCompiledFiles($storagePath);
        } catch (Throwable $e) {
            Log::warning('PACKS2_METADATA_FILE_SCAN_FAILED', [
                'storage_path' => $storagePath,
                'release_id' => (string) ($release['id'] ?? ''),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $blobCatalogHadFailures = false;
        if ($this->shouldCatalogBlobs()) {
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
                } catch (Throwable $e) {
                    Log::warning('PACKS2_BLOB_CATALOG_WRITE_FAILED', [
                        'release_id' => (string) ($release['id'] ?? ''),
                        'storage_path' => (string) $file['logical_path'],
                        'blob_hash' => (string) $file['hash'],
                        'error' => $e->getMessage(),
                    ]);
                    $blobCatalogHadFailures = true;
                }
            }
        }

        if (! $this->shouldCatalogManifest()) {
            return;
        }
        if ($this->shouldCatalogBlobs() && $blobCatalogHadFailures) {
            Log::warning('PACKS2_MANIFEST_CATALOG_SKIPPED_DUE_TO_BLOB_FAILURE', [
                'release_id' => (string) ($release['id'] ?? ''),
                'manifest_hash' => $manifestHash,
                'storage_path' => $storagePath,
            ]);

            return;
        }

        $existingManifest = $manifestHash !== ''
            ? $this->manifestCatalogService->findByManifestHash($manifestHash)
            : null;
        $manifestReleaseId = $existingManifest?->content_pack_release_id ?: ($release['id'] ?? null);
        $manifestStorageDisk = trim((string) ($existingManifest?->storage_disk ?? ''));
        if ($manifestStorageDisk === '') {
            $manifestStorageDisk = 'local';
        }
        $manifestStoragePath = trim((string) ($existingManifest?->storage_path ?? ''));
        if ($manifestStoragePath === '') {
            $manifestStoragePath = $storagePath;
        }

        $payloadJson = $release['manifest_json'] ?? null;
        if (is_string($payloadJson) && $payloadJson !== '') {
            $decoded = json_decode($payloadJson, true);
            $payloadJson = is_array($decoded) ? $decoded : null;
        }

        $files = [];
        foreach ($compiledFiles as $file) {
            $files[] = [
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
                'content_pack_release_id' => $manifestReleaseId,
                'manifest_hash' => $manifestHash,
                'storage_disk' => $manifestStorageDisk,
                'storage_path' => $manifestStoragePath,
                'pack_id' => $release['to_pack_id'] ?? null,
                'pack_version' => $release['pack_version'] ?? null,
                'compiled_hash' => $release['compiled_hash'] ?? null,
                'content_hash' => $release['content_hash'] ?? null,
                'norms_version' => $release['norms_version'] ?? null,
                'source_commit' => $release['source_commit'] ?? null,
                'payload_json' => is_array($payloadJson) ? $payloadJson : null,
            ], $files);
        } catch (Throwable $e) {
            Log::warning('PACKS2_MANIFEST_CATALOG_WRITE_FAILED', [
                'release_id' => (string) ($release['id'] ?? ''),
                'manifest_hash' => (string) ($release['manifest_hash'] ?? ''),
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dualWriteActivationSnapshot(
        string $packId,
        string $packVersion,
        ?string $activationBeforeReleaseId,
        string $activationAfterReleaseId,
        string $reason,
        object $release,
    ): void {
        if (! $this->shouldCatalogSnapshot()) {
            return;
        }

        try {
            $this->snapshotCatalogService->recordSnapshot([
                'pack_id' => $packId,
                'pack_version' => $packVersion,
                'from_content_pack_release_id' => $activationBeforeReleaseId,
                'to_content_pack_release_id' => $activationAfterReleaseId,
                'activation_before_release_id' => $activationBeforeReleaseId,
                'activation_after_release_id' => $activationAfterReleaseId,
                'reason' => $reason,
                'created_by' => 'packs2',
                'meta_json' => [
                    'manifest_hash' => (string) ($release->manifest_hash ?? ''),
                    'storage_path' => (string) ($release->storage_path ?? ''),
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('PACKS2_SNAPSHOT_CATALOG_WRITE_FAILED', [
                'pack_id' => $packId,
                'pack_version' => $packVersion,
                'activation_before_release_id' => $activationBeforeReleaseId,
                'activation_after_release_id' => $activationAfterReleaseId,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array{logical_path:string,hash:string,size_bytes:int,content_type:?string,role:?string}>
     */
    private function collectCompiledFiles(string $storagePath): array
    {
        $root = $this->absoluteStorageRoot($storagePath);
        $compiledDir = $root.'/compiled';
        if (! is_dir($compiledDir)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($compiledDir) as $file) {
            $absolutePath = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolutePath, strlen(rtrim($root, '/\\')))), '/');
            $bytes = (string) File::get($absolutePath);
            $hash = hash('sha256', $bytes);
            $files[] = [
                'logical_path' => $relative,
                'hash' => $hash,
                'size_bytes' => strlen($bytes),
                'content_type' => $this->contentTypeForLogicalPath($relative),
                'role' => $relative === 'compiled/manifest.json' ? 'manifest' : null,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp((string) $a['logical_path'], (string) $b['logical_path']));

        return $files;
    }

    private function contentTypeForLogicalPath(string $logicalPath): ?string
    {
        return str_ends_with(strtolower($logicalPath), '.json') ? 'application/json' : null;
    }

    private function absoluteStorageRoot(string $storagePath): string
    {
        return storage_path('app/'.ltrim(str_starts_with($storagePath, 'app/') ? substr($storagePath, 4) : $storagePath, '/'));
    }

    private function shouldDualWriteContentPackV2(): bool
    {
        return (bool) config('storage_rollout.content_pack_v2_dual_write_enabled', false);
    }

    private function shouldCatalogBlobs(): bool
    {
        return $this->shouldDualWriteContentPackV2()
            && (bool) config('storage_rollout.blob_catalog_enabled', false);
    }

    private function shouldCatalogManifest(): bool
    {
        return $this->shouldDualWriteContentPackV2()
            && (bool) config('storage_rollout.manifest_catalog_enabled', false);
    }

    private function shouldCatalogSnapshot(): bool
    {
        return $this->shouldDualWriteContentPackV2()
            && (bool) config('storage_rollout.snapshot_catalog_enabled', false);
    }

    private function copyCompiledToStoragePath(string $sourceCompiledDir, string $storagePath): void
    {
        $targetRoot = storage_path('app/'.$storagePath);
        $targetCompiledDir = $targetRoot.'/compiled';

        if (File::isDirectory($targetRoot)) {
            File::deleteDirectory($targetRoot);
        }

        File::ensureDirectoryExists(dirname($targetCompiledDir));
        if (! File::copyDirectory($sourceCompiledDir, $targetCompiledDir)) {
            throw new RuntimeException('COPY_COMPILED_FAILED: '.$storagePath);
        }
    }
}
