<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseManifest;
use App\Models\ContentReleaseManifestFile;
use Illuminate\Support\Facades\DB;

class ContentReleaseManifestCatalogService
{
    public function upsertManifest(array $manifest, array $files = []): ContentReleaseManifest
    {
        $manifestHash = trim((string) ($manifest['manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            throw new \InvalidArgumentException('manifest_hash is required');
        }

        return DB::transaction(function () use ($manifest, $files, $manifestHash): ContentReleaseManifest {
            $record = ContentReleaseManifest::query()->updateOrCreate(
                ['manifest_hash' => $manifestHash],
                [
                    'content_pack_release_id' => $manifest['content_pack_release_id'] ?? null,
                    'schema_version' => (string) ($manifest['schema_version'] ?? config('storage_rollout.manifest_schema_version', 'storage_manifest.v1')),
                    'storage_disk' => (string) ($manifest['storage_disk'] ?? 'local'),
                    'storage_path' => (string) ($manifest['storage_path'] ?? ''),
                    'pack_id' => $manifest['pack_id'] ?? null,
                    'pack_version' => $manifest['pack_version'] ?? null,
                    'compiled_hash' => $manifest['compiled_hash'] ?? null,
                    'content_hash' => $manifest['content_hash'] ?? null,
                    'norms_version' => $manifest['norms_version'] ?? null,
                    'source_commit' => $manifest['source_commit'] ?? null,
                    'payload_json' => $manifest['payload_json'] ?? null,
                ]
            );

            // PR-13C stays strictly additive: file rows are inserted or updated,
            // but omitted rows are not deleted until a runtime producer contract exists.
            foreach ($files as $file) {
                $logicalPath = trim((string) ($file['logical_path'] ?? ''));
                if ($logicalPath === '') {
                    continue;
                }

                ContentReleaseManifestFile::query()->updateOrCreate(
                    [
                        'content_release_manifest_id' => (int) $record->getKey(),
                        'logical_path' => $logicalPath,
                    ],
                    [
                        'blob_hash' => (string) ($file['blob_hash'] ?? ''),
                        'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                        'role' => $file['role'] ?? null,
                        'content_type' => $file['content_type'] ?? null,
                        'encoding' => (string) ($file['encoding'] ?? 'identity'),
                        'checksum' => $file['checksum'] ?? null,
                    ]
                );
            }

            return $record->fresh('files') ?? $record->load('files');
        });
    }

    public function findByManifestHash(string $manifestHash): ?ContentReleaseManifest
    {
        $manifestHash = trim($manifestHash);
        if ($manifestHash === '') {
            return null;
        }

        return ContentReleaseManifest::query()
            ->with('files')
            ->where('manifest_hash', $manifestHash)
            ->first();
    }
}
