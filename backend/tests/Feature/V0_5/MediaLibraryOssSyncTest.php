<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\AdminUser;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Cms\MediaAssetStorageSyncService;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaLibraryOssSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_media_upload_generates_variants_syncs_to_oss_and_verifies_cdn(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);

        $response = $this
            ->actingAsContentWriter()
            ->post('/api/v0.5/internal/media-assets/pr-media-01-cover/upload', [
                'file' => UploadedFile::fake()->image('cover.png', 2400, 1350),
                'alt' => 'PR-MEDIA-01 cover',
                'status' => MediaAsset::STATUS_PUBLISHED,
                'is_public' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.asset_key', 'pr-media-01-cover')
            ->assertJsonPath('asset.sync_status', MediaAsset::SYNC_SYNCED)
            ->assertJsonPath('asset.cdn_status', MediaAsset::CDN_VERIFIED);

        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $variantKey) {
            $response->assertJsonFragment([
                'variant_key' => $variantKey,
                'sync_status' => MediaAsset::SYNC_SYNCED,
                'cdn_status' => MediaAsset::CDN_VERIFIED,
            ]);
        }

        Storage::disk('s3')->assertExists('storage/media-library/variants/pr-media-01-cover/hero_1600x900.jpg');
        Storage::disk('s3')->assertExists('storage/media-library/variants/pr-media-01-cover/card_800x450.jpg');
        Storage::disk('s3')->assertExists('storage/media-library/variants/pr-media-01-cover/thumbnail_400x225.jpg');
        Storage::disk('s3')->assertExists('storage/media-library/variants/pr-media-01-cover/og_1200x630.jpg');
        Storage::disk('s3')->assertExists('storage/media-library/variants/pr-media-01-cover/preload_64x36.jpg');

        $this->assertDatabaseHas('media_assets', [
            'asset_key' => 'pr-media-01-cover',
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_VERIFIED,
            'last_error' => null,
        ]);
    }

    public function test_media_upload_records_sync_failure_without_failing_cms_upload(): void
    {
        Storage::fake('public');
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 'missing-media-disk',
            'fap.media.cdn_verify_enabled' => false,
        ]);

        $response = $this
            ->actingAsContentWriter()
            ->post('/api/v0.5/internal/media-assets/pr-media-01-failed-cover/upload', [
                'file' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
                'alt' => 'PR-MEDIA-01 failed cover',
                'status' => MediaAsset::STATUS_PUBLISHED,
                'is_public' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.sync_status', MediaAsset::SYNC_FAILED)
            ->assertJsonPath('asset.cdn_status', MediaAsset::CDN_SKIPPED);

        $asset = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'pr-media-01-failed-cover')->firstOrFail();
        $this->assertSame(MediaAsset::SYNC_FAILED, (string) $asset->sync_status);
        $this->assertNotSame('', trim((string) $asset->last_error));
    }

    public function test_media_sync_does_not_mark_synced_when_target_put_returns_false(): void
    {
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => false,
        ]);

        $asset = MediaAsset::query()->create([
            'org_id' => 0,
            'asset_key' => 'pr-media-01-put-false-cover',
            'disk' => 'public',
            'path' => 'media-library/sources/pr-media-01-put-false-cover/source.jpg',
            'url' => null,
            'mime_type' => 'image/jpeg',
            'width' => 1600,
            'height' => 900,
            'bytes' => 1234,
            'alt' => 'Put false cover',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_PENDING,
            'cdn_status' => MediaAsset::CDN_NOT_VERIFIED,
        ]);
        MediaVariant::query()->create([
            'media_asset_id' => (int) $asset->id,
            'variant_key' => 'hero',
            'path' => 'media-library/variants/pr-media-01-put-false-cover/hero_1600x900.jpg',
            'url' => null,
            'mime_type' => 'image/jpeg',
            'width' => 1600,
            'height' => 900,
            'bytes' => 1234,
            'sync_status' => MediaAsset::SYNC_PENDING,
            'cdn_status' => MediaAsset::CDN_NOT_VERIFIED,
        ]);

        $sourceDisk = \Mockery::mock();
        $sourceDisk->shouldReceive('exists')
            ->with('media-library/sources/pr-media-01-put-false-cover/source.jpg')
            ->andReturnTrue();
        $sourceDisk->shouldReceive('exists')
            ->with('media-library/variants/pr-media-01-put-false-cover/hero_1600x900.jpg')
            ->andReturnTrue();
        $sourceDisk->shouldReceive('get')
            ->with('media-library/sources/pr-media-01-put-false-cover/source.jpg')
            ->andReturn('source-binary');
        $sourceDisk->shouldReceive('get')
            ->with('media-library/variants/pr-media-01-put-false-cover/hero_1600x900.jpg')
            ->andReturn('variant-binary');

        $targetDisk = \Mockery::mock();
        $targetDisk->shouldReceive('put')
            ->with('storage/media-library/sources/pr-media-01-put-false-cover/source.jpg', 'source-binary', 'public')
            ->andReturnFalse();
        $targetDisk->shouldReceive('put')
            ->with('storage/media-library/variants/pr-media-01-put-false-cover/hero_1600x900.jpg', 'variant-binary', 'public')
            ->andReturnFalse();
        $targetDisk->shouldNotReceive('exists');

        Storage::shouldReceive('disk')->with('public')->andReturn($sourceDisk);
        Storage::shouldReceive('disk')->with('s3')->andReturn($targetDisk);

        app(MediaAssetStorageSyncService::class)->sync($asset);

        $fresh = $asset->fresh('variants');
        $this->assertSame(MediaAsset::SYNC_FAILED, (string) $fresh->sync_status);
        $this->assertSame(MediaAsset::CDN_NOT_VERIFIED, (string) $fresh->cdn_status);
        $this->assertStringContainsString('target write failed: s3:storage/media-library/sources/pr-media-01-put-false-cover/source.jpg', (string) $fresh->last_error);
        $this->assertNull($fresh->synced_at);

        $variant = $fresh->variants->firstWhere('variant_key', 'hero');
        $this->assertNotNull($variant);
        $this->assertSame(MediaAsset::SYNC_FAILED, (string) $variant->sync_status);
        $this->assertStringContainsString('target write failed: s3:storage/media-library/variants/pr-media-01-put-false-cover/hero_1600x900.jpg', (string) $variant->last_error);
        $this->assertNull($variant->synced_at);
    }

    private function actingAsContentWriter(): self
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
            'description' => null,
        ]);

        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionNames::ADMIN_CONTENT_WRITE],
            ['description' => null]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $this
            ->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'));
    }
}
