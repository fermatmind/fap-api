<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\StorageBlob;
use App\Models\StorageBlobLocation;

class BlobCatalogService
{
    public function storagePathForHash(string $hash): string
    {
        $hash = $this->normalizeHash($hash);

        return 'blobs/sha256/'.substr($hash, 0, 2).'/'.$hash;
    }

    public function upsertBlob(array $payload): StorageBlob
    {
        $hash = $this->normalizeHash((string) ($payload['hash'] ?? ''));

        $disk = trim((string) ($payload['disk'] ?? 'local'));
        $storagePath = trim((string) ($payload['storage_path'] ?? $this->storagePathForHash($hash)));
        $storagePath = $storagePath !== '' ? $storagePath : $this->storagePathForHash($hash);

        $existing = StorageBlob::query()->find($hash);

        $existingPathOwner = StorageBlob::query()
            ->where('disk', $disk)
            ->where('storage_path', $storagePath)
            ->where('hash', '!=', $hash)
            ->first();

        if ($existingPathOwner !== null) {
            throw new \DomainException('disk + storage_path is already cataloged to a different hash');
        }

        $record = StorageBlob::query()->updateOrCreate(
            ['hash' => $hash],
            [
                'disk' => $disk,
                'storage_path' => $storagePath,
                'size_bytes' => (int) ($payload['size_bytes'] ?? 0),
                'content_type' => $payload['content_type'] ?? null,
                'encoding' => (string) ($payload['encoding'] ?? 'identity'),
                'ref_count' => max(0, (int) ($payload['ref_count'] ?? $existing?->ref_count ?? 0)),
                'first_seen_at' => $payload['first_seen_at'] ?? $existing?->first_seen_at ?? now(),
                'last_verified_at' => $payload['last_verified_at'] ?? $existing?->last_verified_at,
            ]
        );

        return $record->fresh() ?? $record;
    }

    public function upsertBlobLocation(array $payload): StorageBlobLocation
    {
        $blobHash = $this->normalizeHash((string) ($payload['blob_hash'] ?? ''));

        $disk = trim((string) ($payload['disk'] ?? 'local'));
        $storagePath = trim((string) ($payload['storage_path'] ?? $this->storagePathForHash($blobHash)));
        $storagePath = $storagePath !== '' ? $storagePath : $this->storagePathForHash($blobHash);

        $existingPathOwner = StorageBlobLocation::query()
            ->where('disk', $disk)
            ->where('storage_path', $storagePath)
            ->where('blob_hash', '!=', $blobHash)
            ->first();

        if ($existingPathOwner !== null) {
            throw new \DomainException('disk + storage_path is already cataloged to a different blob');
        }

        $record = StorageBlobLocation::query()->updateOrCreate(
            [
                'disk' => $disk,
                'storage_path' => $storagePath,
            ],
            [
                'blob_hash' => $blobHash,
                'location_kind' => (string) ($payload['location_kind'] ?? 'canonical_file'),
                'size_bytes' => (int) ($payload['size_bytes'] ?? 0),
                'checksum' => $payload['checksum'] ?? null,
                'etag' => $payload['etag'] ?? null,
                'storage_class' => $payload['storage_class'] ?? null,
                'verified_at' => $payload['verified_at'] ?? now(),
                'meta_json' => is_array($payload['meta_json'] ?? null) ? $payload['meta_json'] : null,
            ]
        );

        return $record->fresh() ?? $record;
    }

    public function findByHash(string $hash): ?StorageBlob
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        return StorageBlob::query()->find($hash);
    }

    private function normalizeHash(string $hash): string
    {
        $hash = strtolower(trim($hash));
        if ($hash === '') {
            throw new \InvalidArgumentException('hash is required');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException('hash must be a 64 character sha256 hex digest');
        }

        return $hash;
    }
}
