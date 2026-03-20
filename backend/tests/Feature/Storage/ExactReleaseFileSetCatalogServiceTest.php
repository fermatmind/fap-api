<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\ContentPackRelease;
use App\Services\Storage\ExactReleaseFileSetCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExactReleaseFileSetCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_manifest_upsert_is_idempotent_and_replaces_omitted_file_rows(): void
    {
        $release = ContentPackRelease::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'PR16-EXACT',
            'status' => 'success',
            'created_by' => 'test',
        ]);

        $service = app(ExactReleaseFileSetCatalogService::class);
        $sourcePath = '/tmp/content-releases/source-pack-1';
        $manifestHash = str_repeat('a', 64);

        $service->upsertExactManifest([
            'content_pack_release_id' => null,
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => $sourcePath,
            'manifest_hash' => $manifestHash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => str_repeat('b', 64),
            'content_hash' => str_repeat('c', 64),
            'norms_version' => '2026Q1',
            'source_commit' => str_repeat('d', 40),
            'payload_json' => ['schema' => 'storage.exact.test.v1'],
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('1', 64),
                'size_bytes' => 101,
                'role' => 'manifest',
                'content_type' => 'application/json',
                'checksum' => 'sha256:first-manifest',
            ],
            [
                'logical_path' => 'compiled/questions.json',
                'blob_hash' => str_repeat('2', 64),
                'size_bytes' => 202,
                'role' => 'questions',
                'content_type' => 'application/json',
            ],
        ]);

        $manifest = $service->upsertExactManifest([
            'content_pack_release_id' => $release->id,
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => $sourcePath,
            'manifest_hash' => $manifestHash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => str_repeat('e', 64),
            'content_hash' => str_repeat('f', 64),
            'norms_version' => '2026Q2',
            'source_commit' => str_repeat('d', 40),
            'payload_json' => ['schema' => 'storage.exact.test.v1', 'updated' => true],
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('3', 64),
                'size_bytes' => 303,
                'role' => 'manifest',
                'content_type' => 'application/json',
                'checksum' => 'sha256:updated-manifest',
            ],
        ]);

        $this->assertDatabaseCount('content_release_exact_manifests', 1);
        $this->assertDatabaseCount('content_release_exact_manifest_files', 1);
        $this->assertDatabaseHas('content_release_exact_manifests', [
            'content_pack_release_id' => $release->id,
            'source_kind' => 'legacy.source_pack',
            'source_disk' => 'local',
            'source_storage_path' => $sourcePath,
            'manifest_hash' => $manifestHash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => str_repeat('e', 64),
            'content_hash' => str_repeat('f', 64),
            'norms_version' => '2026Q2',
            'file_count' => 1,
            'total_size_bytes' => 303,
        ]);
        $this->assertDatabaseHas('content_release_exact_manifest_files', [
            'content_release_exact_manifest_id' => $manifest->id,
            'logical_path' => 'compiled/manifest.json',
            'blob_hash' => str_repeat('3', 64),
            'size_bytes' => 303,
            'checksum' => 'sha256:updated-manifest',
        ]);
        $this->assertDatabaseMissing('content_release_exact_manifest_files', [
            'content_release_exact_manifest_id' => $manifest->id,
            'logical_path' => 'compiled/questions.json',
        ]);

        $reloaded = $service->findByIdentity('legacy.source_pack', 'local', $sourcePath, $manifestHash, 'BIG5_OCEAN', 'v1');
        $this->assertNotNull($reloaded);
        $this->assertCount(1, $reloaded->files);
        $this->assertSame($release->id, $reloaded->content_pack_release_id);
    }

    public function test_exact_manifest_identity_keeps_distinct_roots_for_same_manifest_hash(): void
    {
        $release = ContentPackRelease::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'action' => 'rollback',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'PR16-EXACT-DISTINCT',
            'status' => 'success',
            'created_by' => 'test',
        ]);

        $service = app(ExactReleaseFileSetCatalogService::class);
        $manifestHash = str_repeat('b', 64);

        $service->upsertExactManifest([
            'content_pack_release_id' => $release->id,
            'source_kind' => 'legacy.previous_pack',
            'source_disk' => 'local',
            'source_storage_path' => '/tmp/content-releases/backups/release-a/previous_pack',
            'manifest_hash' => $manifestHash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('7', 64),
                'size_bytes' => 707,
            ],
        ]);

        $service->upsertExactManifest([
            'content_pack_release_id' => $release->id,
            'source_kind' => 'legacy.previous_pack',
            'source_disk' => 'local',
            'source_storage_path' => '/tmp/content-releases/backups/release-b/previous_pack',
            'manifest_hash' => $manifestHash,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('8', 64),
                'size_bytes' => 808,
            ],
        ]);

        $this->assertDatabaseCount('content_release_exact_manifests', 2);
        $this->assertNotNull($service->findByIdentity(
            'legacy.previous_pack',
            'local',
            '/tmp/content-releases/backups/release-a/previous_pack',
            $manifestHash,
            'BIG5_OCEAN',
            'v1',
        ));
        $this->assertNotNull($service->findByIdentity(
            'legacy.previous_pack',
            'local',
            '/tmp/content-releases/backups/release-b/previous_pack',
            $manifestHash,
            'BIG5_OCEAN',
            'v1',
        ));
    }
}
