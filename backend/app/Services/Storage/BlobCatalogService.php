<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\StorageBlob;

class BlobCatalogService
{
    public function upsertBlob(array $payload): StorageBlob
    {
        $hash = trim((string) ($payload['hash'] ?? ''));
        if ($hash === '') {
            throw new \InvalidArgumentException('hash is required');
        }

        $disk = trim((string) ($payload['disk'] ?? 'local'));
        $storagePath = trim((string) ($payload['storage_path'] ?? ''));
        if ($storagePath === '') {
            throw new \InvalidArgumentException('storage_path is required');
        }

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
                'ref_count' => (int) ($payload['ref_count'] ?? 0),
                'first_seen_at' => $payload['first_seen_at'] ?? null,
                'last_verified_at' => $payload['last_verified_at'] ?? null,
            ]
        );

        return $record->fresh() ?? $record;
    }

    public function findByHash(string $hash): ?StorageBlob
    {
        $hash = trim($hash);
        if ($hash === '') {
            return null;
        }

        return StorageBlob::query()->find($hash);
    }
}
