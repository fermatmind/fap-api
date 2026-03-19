<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\BlobCatalogService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BlobCatalogServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-rollout-blob-'.Str::uuid();
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

    public function test_storage_rollout_tables_exist_and_blob_upsert_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasTable('storage_blobs'));
        $this->assertTrue(Schema::hasTable('content_release_manifests'));
        $this->assertTrue(Schema::hasTable('content_release_manifest_files'));
        $this->assertTrue(Schema::hasTable('content_release_snapshots'));

        $service = app(BlobCatalogService::class);

        $first = $service->upsertBlob([
            'hash' => str_repeat('a', 64),
            'disk' => 'local',
            'storage_path' => 'artifacts/reports/demo/report.json',
            'size_bytes' => 128,
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 1,
            'first_seen_at' => now()->subMinute(),
        ]);

        $second = $service->upsertBlob([
            'hash' => str_repeat('a', 64),
            'disk' => 'local',
            'storage_path' => 'artifacts/reports/demo/report.json',
            'size_bytes' => 256,
            'content_type' => 'application/vnd.test+json',
            'encoding' => 'gzip',
            'ref_count' => 3,
            'last_verified_at' => now(),
        ]);

        $this->assertSame(str_repeat('a', 64), $first->hash);
        $this->assertSame(str_repeat('a', 64), $second->hash);
        $this->assertDatabaseCount('storage_blobs', 1);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => str_repeat('a', 64),
            'storage_path' => 'artifacts/reports/demo/report.json',
            'size_bytes' => 256,
            'content_type' => 'application/vnd.test+json',
            'encoding' => 'gzip',
            'ref_count' => 3,
        ]);
        $this->assertNotNull($service->findByHash(str_repeat('a', 64)));
        $this->assertNull($service->findByHash(str_repeat('b', 64)));
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private');
    }

    public function test_blob_upsert_rejects_rebinding_the_same_storage_path_to_a_different_hash(): void
    {
        $service = app(BlobCatalogService::class);

        $service->upsertBlob([
            'hash' => str_repeat('a', 64),
            'disk' => 'local',
            'storage_path' => 'artifacts/reports/demo/report.json',
            'size_bytes' => 128,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('disk + storage_path is already cataloged to a different hash');

        try {
            $service->upsertBlob([
                'hash' => str_repeat('b', 64),
                'disk' => 'local',
                'storage_path' => 'artifacts/reports/demo/report.json',
                'size_bytes' => 256,
            ]);
        } finally {
            $this->assertDatabaseCount('storage_blobs', 1);
            $this->assertDatabaseHas('storage_blobs', [
                'hash' => str_repeat('a', 64),
                'storage_path' => 'artifacts/reports/demo/report.json',
            ]);
            $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private');
        }
    }

    public function test_blob_upsert_derives_a_stable_hash_storage_path_when_omitted(): void
    {
        $service = app(BlobCatalogService::class);
        $hash = str_repeat('c', 64);

        $blob = $service->upsertBlob([
            'hash' => $hash,
            'disk' => 'local',
            'size_bytes' => 512,
            'content_type' => 'application/json',
        ]);

        $this->assertSame('blobs/sha256/cc/'.$hash, $blob->storage_path);
        $this->assertSame('blobs/sha256/cc/'.$hash, $service->storagePathForHash($hash));
        $this->assertNotNull($blob->first_seen_at);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => $hash,
            'storage_path' => 'blobs/sha256/cc/'.$hash,
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
        ]);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private');
    }
}
