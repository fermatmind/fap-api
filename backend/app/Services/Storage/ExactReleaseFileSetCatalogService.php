<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseExactManifest;
use App\Models\ContentReleaseExactManifestFile;
use Illuminate\Support\Facades\DB;

final class ExactReleaseFileSetCatalogService
{
    public function upsertExactManifest(array $manifest, array $files = []): ContentReleaseExactManifest
    {
        $manifestHash = trim((string) ($manifest['manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            throw new \InvalidArgumentException('manifest_hash is required');
        }

        $sourceKind = trim((string) ($manifest['source_kind'] ?? ''));
        if ($sourceKind === '') {
            throw new \InvalidArgumentException('source_kind is required');
        }

        $sourceDisk = trim((string) ($manifest['source_disk'] ?? 'local'));
        $sourceStoragePath = $this->normalizeStoragePath((string) ($manifest['source_storage_path'] ?? ''));
        if ($sourceStoragePath === '') {
            throw new \InvalidArgumentException('source_storage_path is required');
        }

        $packId = $this->normalizePackId($manifest['pack_id'] ?? null);
        $packVersion = $this->normalizeNullableString($manifest['pack_version'] ?? null);
        $sourceIdentityHash = trim((string) ($manifest['source_identity_hash'] ?? ''));
        if ($sourceIdentityHash === '') {
            $sourceIdentityHash = $this->sourceIdentityHash($sourceKind, $sourceDisk, $sourceStoragePath);
        }
        $exactIdentityHash = trim((string) ($manifest['exact_identity_hash'] ?? ''));
        if ($exactIdentityHash === '') {
            $exactIdentityHash = $this->exactIdentityHash($packId, $packVersion, $sourceKind, $sourceDisk, $sourceStoragePath, $manifestHash);
        }

        $normalizedFiles = [];
        foreach ($files as $file) {
            $logicalPath = trim((string) ($file['logical_path'] ?? ''));
            if ($logicalPath === '') {
                continue;
            }

            $normalizedFiles[$logicalPath] = [
                'blob_hash' => trim((string) ($file['blob_hash'] ?? '')),
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                'role' => $file['role'] ?? null,
                'content_type' => $file['content_type'] ?? null,
                'encoding' => (string) ($file['encoding'] ?? 'identity'),
                'checksum' => $file['checksum'] ?? null,
            ];
        }

        return DB::transaction(function () use ($exactIdentityHash, $manifest, $manifestHash, $normalizedFiles, $packId, $packVersion, $sourceDisk, $sourceIdentityHash, $sourceKind, $sourceStoragePath): ContentReleaseExactManifest {
            $existing = ContentReleaseExactManifest::query()
                ->where('exact_identity_hash', $exactIdentityHash)
                ->first();

            $record = $existing ?? new ContentReleaseExactManifest([
                'source_identity_hash' => $sourceIdentityHash,
                'manifest_hash' => $manifestHash,
                'exact_identity_hash' => $exactIdentityHash,
            ]);

            $record->fill([
                'content_pack_release_id' => $this->preferredReleaseId($existing?->content_pack_release_id, $manifest['content_pack_release_id'] ?? null),
                'source_identity_hash' => $sourceIdentityHash,
                'exact_identity_hash' => $exactIdentityHash,
                'schema_version' => (string) ($manifest['schema_version'] ?? config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1')),
                'source_kind' => $sourceKind,
                'source_disk' => $sourceDisk,
                'source_storage_path' => $sourceStoragePath,
                'pack_id' => $packId,
                'pack_version' => $packVersion,
                'compiled_hash' => $manifest['compiled_hash'] ?? null,
                'content_hash' => $manifest['content_hash'] ?? null,
                'norms_version' => $manifest['norms_version'] ?? null,
                'source_commit' => $manifest['source_commit'] ?? null,
                'file_count' => count($normalizedFiles),
                'total_size_bytes' => array_sum(array_column($normalizedFiles, 'size_bytes')),
                'payload_json' => array_key_exists('payload_json', $manifest) ? $manifest['payload_json'] : $existing?->payload_json,
                'sealed_at' => $manifest['sealed_at'] ?? now(),
                'last_verified_at' => $manifest['last_verified_at'] ?? now(),
            ]);
            $record->save();

            foreach ($normalizedFiles as $logicalPath => $file) {
                ContentReleaseExactManifestFile::query()->updateOrCreate(
                    [
                        'content_release_exact_manifest_id' => (int) $record->getKey(),
                        'logical_path' => $logicalPath,
                    ],
                    $file
                );
            }

            $filePaths = array_keys($normalizedFiles);
            $deleteQuery = ContentReleaseExactManifestFile::query()
                ->where('content_release_exact_manifest_id', (int) $record->getKey());
            if ($filePaths === []) {
                $deleteQuery->delete();
            } else {
                $deleteQuery->whereNotIn('logical_path', $filePaths)->delete();
            }

            return $record->fresh('files') ?? $record->load('files');
        });
    }

    public function findByIdentity(
        string $sourceKind,
        string $sourceDisk,
        string $sourceStoragePath,
        string $manifestHash,
        ?string $packId = null,
        ?string $packVersion = null,
    ): ?ContentReleaseExactManifest {
        $manifestHash = trim($manifestHash);
        $sourceKind = trim($sourceKind);
        $sourceDisk = trim($sourceDisk);
        $sourceStoragePath = $this->normalizeStoragePath($sourceStoragePath);
        $packId = $this->normalizePackId($packId);
        $packVersion = $this->normalizeNullableString($packVersion);

        if ($manifestHash === '' || $sourceKind === '' || $sourceDisk === '' || $sourceStoragePath === '') {
            return null;
        }

        return ContentReleaseExactManifest::query()
            ->with('files')
            ->where('exact_identity_hash', $this->exactIdentityHash(
                $packId,
                $packVersion,
                $sourceKind,
                $sourceDisk,
                $sourceStoragePath,
                $manifestHash,
            ))
            ->first();
    }

    public function sourceIdentityHash(string $sourceKind, string $sourceDisk, string $sourceStoragePath): string
    {
        return hash('sha256', trim($sourceKind).'|'.trim($sourceDisk).'|'.$this->normalizeStoragePath($sourceStoragePath));
    }

    public function exactIdentityHash(
        ?string $packId,
        ?string $packVersion,
        string $sourceKind,
        string $sourceDisk,
        string $sourceStoragePath,
        string $manifestHash,
    ): string {
        return hash('sha256', implode('|', [
            $this->normalizePackId($packId) ?? '',
            $this->normalizeNullableString($packVersion) ?? '',
            trim($sourceKind),
            trim($sourceDisk),
            $this->normalizeStoragePath($sourceStoragePath),
            trim($manifestHash),
        ]));
    }

    private function normalizeStoragePath(string $path): string
    {
        return str_replace('\\', '/', rtrim(trim($path), '/\\'));
    }

    private function normalizePackId(mixed $value): ?string
    {
        $packId = strtoupper(trim((string) $value));

        return $packId !== '' ? $packId : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function preferredReleaseId(mixed $existing, mixed $incoming): ?string
    {
        $incomingId = trim((string) $incoming);
        if ($incomingId !== '') {
            return $incomingId;
        }

        $existingId = trim((string) $existing);

        return $existingId !== '' ? $existingId : null;
    }
}
