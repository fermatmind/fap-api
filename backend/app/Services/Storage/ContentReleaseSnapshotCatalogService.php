<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseSnapshot;

class ContentReleaseSnapshotCatalogService
{
    public function recordSnapshot(array $payload): ContentReleaseSnapshot
    {
        $record = ContentReleaseSnapshot::query()->create([
            'pack_id' => (string) ($payload['pack_id'] ?? ''),
            'pack_version' => $payload['pack_version'] ?? null,
            'from_content_pack_release_id' => $payload['from_content_pack_release_id'] ?? null,
            'to_content_pack_release_id' => $payload['to_content_pack_release_id'] ?? null,
            'activation_before_release_id' => $payload['activation_before_release_id'] ?? null,
            'activation_after_release_id' => $payload['activation_after_release_id'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'created_by' => $payload['created_by'] ?? null,
            'meta_json' => $payload['meta_json'] ?? null,
        ]);

        return $record->fresh() ?? $record;
    }
}
