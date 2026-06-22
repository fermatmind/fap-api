<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ContentReleaseManifestCatalogService;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContentReleaseIdLengthSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_release_tables_accept_exact_enneagram_candidate_release_id(): void
    {
        $releaseId = 'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4';
        $this->assertSame(58, strlen($releaseId));

        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'enneagram_registry_import_inactive_candidate',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v2',
            'to_pack_id' => 'ENNEAGRAM',
            'status' => 'success',
            'message' => 'schema regression exact release id fixture',
            'created_by' => 'test',
            'manifest_hash' => 'sha256:'.str_repeat('a', 64),
            'compiled_hash' => 'sha256:'.str_repeat('b', 64),
            'content_hash' => 'sha256:'.str_repeat('a', 64),
            'pack_version' => 'v2',
            'storage_path' => 'private/content_releases/ENNEAGRAM/v2/'.$releaseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ContentReleaseManifestCatalogService::class)->upsertManifest([
            'content_pack_release_id' => $releaseId,
            'manifest_hash' => str_repeat('c', 64),
            'schema_version' => 'enneagram.inactive_candidate_release_manifest.v1',
            'storage_disk' => 'local',
            'storage_path' => 'private/content_releases/ENNEAGRAM/v2/'.$releaseId,
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'compiled_hash' => str_repeat('b', 64),
            'content_hash' => str_repeat('a', 64),
            'payload_json' => ['release_id' => $releaseId],
        ]);

        app(ExactReleaseFileSetCatalogService::class)->upsertExactManifest([
            'content_pack_release_id' => $releaseId,
            'source_identity_hash' => str_repeat('d', 64),
            'manifest_hash' => str_repeat('e', 64),
            'source_kind' => 'inactive_candidate',
            'source_disk' => 'local',
            'source_storage_path' => 'private/content_releases/ENNEAGRAM/v2/'.$releaseId.'/candidate',
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'compiled_hash' => str_repeat('b', 64),
            'content_hash' => str_repeat('a', 64),
        ]);

        DB::table('content_pack_activations')->insert([
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'release_id' => $releaseId,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ContentReleaseSnapshotCatalogService::class)->recordSnapshot([
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'from_content_pack_release_id' => $releaseId,
            'to_content_pack_release_id' => $releaseId,
            'activation_before_release_id' => $releaseId,
            'activation_after_release_id' => $releaseId,
            'reason' => 'schema_regression',
            'created_by' => 'test',
        ]);

        $this->assertDatabaseHas('content_pack_releases', ['id' => $releaseId]);
        $this->assertDatabaseHas('content_release_manifests', ['content_pack_release_id' => $releaseId]);
        $this->assertDatabaseHas('content_release_exact_manifests', ['content_pack_release_id' => $releaseId]);
        $this->assertDatabaseHas('content_pack_activations', ['release_id' => $releaseId]);
        $this->assertDatabaseHas('content_release_snapshots', [
            'from_content_pack_release_id' => $releaseId,
            'to_content_pack_release_id' => $releaseId,
            'activation_before_release_id' => $releaseId,
            'activation_after_release_id' => $releaseId,
        ]);
    }
}
