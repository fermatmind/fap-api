<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\ContentPackRelease;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentReleaseManifestCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-rollout-manifest-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_manifest_upsert_is_idempotent_and_file_map_updates_without_duplicate_rows(): void
    {
        $release = ContentPackRelease::query()->create([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'PR13C-MANIFEST',
            'status' => 'success',
            'created_by' => 'test',
        ]);

        $service = app(ContentReleaseManifestCatalogService::class);

        $service->upsertManifest([
            'content_pack_release_id' => $release->id,
            'manifest_hash' => str_repeat('c', 64),
            'storage_path' => 'content_packs_v2/BIG5_OCEAN/v1/release-1/manifest.json',
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => str_repeat('d', 64),
            'content_hash' => str_repeat('e', 64),
            'norms_version' => '2026-Q1',
            'source_commit' => str_repeat('f', 40),
            'payload_json' => ['schema' => 'v1'],
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('1', 64),
                'size_bytes' => 101,
                'role' => 'manifest',
                'content_type' => 'application/json',
            ],
        ]);

        $manifest = $service->upsertManifest([
            'content_pack_release_id' => $release->id,
            'manifest_hash' => str_repeat('c', 64),
            'storage_path' => 'content_packs_v2/BIG5_OCEAN/v1/release-1/manifest.json',
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'compiled_hash' => str_repeat('d', 64),
            'content_hash' => str_repeat('9', 64),
            'norms_version' => '2026-Q2',
            'source_commit' => str_repeat('f', 40),
            'payload_json' => ['schema' => 'v1', 'updated' => true],
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('2', 64),
                'size_bytes' => 202,
                'role' => 'manifest',
                'content_type' => 'application/json',
                'checksum' => 'sha256:compiled-manifest',
            ],
            [
                'logical_path' => 'compiled/questions.json',
                'blob_hash' => str_repeat('3', 64),
                'size_bytes' => 303,
                'role' => 'questions',
                'content_type' => 'application/json',
            ],
        ]);

        $this->assertDatabaseCount('content_release_manifests', 1);
        $this->assertDatabaseCount('content_release_manifest_files', 2);
        $this->assertDatabaseHas('content_release_manifests', [
            'manifest_hash' => str_repeat('c', 64),
            'content_pack_release_id' => $release->id,
            'content_hash' => str_repeat('9', 64),
            'norms_version' => '2026-Q2',
        ]);
        $this->assertDatabaseHas('content_release_manifest_files', [
            'content_release_manifest_id' => $manifest->id,
            'logical_path' => 'compiled/manifest.json',
            'blob_hash' => str_repeat('2', 64),
            'size_bytes' => 202,
            'checksum' => 'sha256:compiled-manifest',
        ]);
        $this->assertDatabaseHas('content_release_manifest_files', [
            'content_release_manifest_id' => $manifest->id,
            'logical_path' => 'compiled/questions.json',
            'blob_hash' => str_repeat('3', 64),
            'size_bytes' => 303,
        ]);

        $reloaded = $service->findByManifestHash(str_repeat('c', 64));
        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->files);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private');
    }

    public function test_manifest_upsert_keeps_omitted_file_rows_to_preserve_additive_scaffold_semantics(): void
    {
        $release = ContentPackRelease::query()->create([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'PR13C-MANIFEST-ADDITIVE',
            'status' => 'success',
            'created_by' => 'test',
        ]);

        $service = app(ContentReleaseManifestCatalogService::class);

        $service->upsertManifest([
            'content_pack_release_id' => $release->id,
            'manifest_hash' => str_repeat('d', 64),
            'storage_path' => 'content_packs_v2/BIG5_OCEAN/v1/release-2/manifest.json',
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('4', 64),
                'size_bytes' => 404,
            ],
            [
                'logical_path' => 'compiled/questions.json',
                'blob_hash' => str_repeat('5', 64),
                'size_bytes' => 505,
            ],
        ]);

        $service->upsertManifest([
            'content_pack_release_id' => $release->id,
            'manifest_hash' => str_repeat('d', 64),
            'storage_path' => 'content_packs_v2/BIG5_OCEAN/v1/release-2/manifest.json',
        ], [
            [
                'logical_path' => 'compiled/manifest.json',
                'blob_hash' => str_repeat('6', 64),
                'size_bytes' => 606,
            ],
        ]);

        $this->assertDatabaseCount('content_release_manifests', 1);
        $this->assertDatabaseCount('content_release_manifest_files', 2);
        $this->assertDatabaseHas('content_release_manifest_files', [
            'logical_path' => 'compiled/manifest.json',
            'blob_hash' => str_repeat('6', 64),
            'size_bytes' => 606,
        ]);
        $this->assertDatabaseHas('content_release_manifest_files', [
            'logical_path' => 'compiled/questions.json',
            'blob_hash' => str_repeat('5', 64),
            'size_bytes' => 505,
        ]);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private');
    }
}
