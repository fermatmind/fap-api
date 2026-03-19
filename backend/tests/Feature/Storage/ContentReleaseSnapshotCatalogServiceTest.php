<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\ContentPackRelease;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentReleaseSnapshotCatalogServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-rollout-snapshot-'.Str::uuid();
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

    public function test_snapshot_catalog_records_db_metadata_without_touching_storage_files(): void
    {
        $fromRelease = ContentPackRelease::query()->create([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'PR13C-SNAPSHOT-FROM',
            'status' => 'success',
            'created_by' => 'test',
        ]);
        $toRelease = ContentPackRelease::query()->create([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'PR13C-SNAPSHOT-TO',
            'status' => 'success',
            'created_by' => 'test',
        ]);

        $service = app(ContentReleaseSnapshotCatalogService::class);

        $snapshot = $service->recordSnapshot([
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'from_content_pack_release_id' => $fromRelease->id,
            'to_content_pack_release_id' => $toRelease->id,
            'activation_before_release_id' => $fromRelease->id,
            'activation_after_release_id' => $toRelease->id,
            'reason' => 'scaffold_seed',
            'created_by' => 'codex',
            'meta_json' => ['note' => 'metadata only'],
        ]);

        $this->assertNotNull($snapshot->id);
        $this->assertDatabaseCount('content_release_snapshots', 1);
        $this->assertDatabaseHas('content_release_snapshots', [
            'id' => $snapshot->id,
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'from_content_pack_release_id' => $fromRelease->id,
            'to_content_pack_release_id' => $toRelease->id,
            'activation_before_release_id' => $fromRelease->id,
            'activation_after_release_id' => $toRelease->id,
            'reason' => 'scaffold_seed',
            'created_by' => 'codex',
        ]);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private');
    }
}
