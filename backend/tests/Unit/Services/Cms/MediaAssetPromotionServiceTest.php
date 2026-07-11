<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\MediaAsset;
use App\Services\Cms\MediaAssetPromotionService;
use App\Services\Cms\MediaVariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class MediaAssetPromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_verified_pipeline_promotes_public_atomically(): void
    {
        $asset = $this->verifiedAsset();
        $service = app(MediaAssetPromotionService::class);

        $verified = $service->markVerified($asset);
        $this->assertSame(MediaAsset::STATUS_VERIFIED, $verified->status);
        $this->assertFalse($verified->is_public);

        $published = $service->promote($verified);
        $this->assertSame(MediaAsset::STATUS_PUBLISHED, $published->status);
        $this->assertTrue($published->is_public);
    }

    public function test_missing_required_variant_blocks_verification_and_publication(): void
    {
        $asset = $this->verifiedAsset();
        $asset->variants()->where('variant_key', 'og')->delete();

        try {
            app(MediaAssetPromotionService::class)->markVerified($asset);
            $this->fail('Missing required variant must block media verification.');
        } catch (RuntimeException $exception) {
            $this->assertSame('MEDIA_VARIANT_NOT_VERIFIED:og', $exception->getMessage());
        }

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_DRAFT, $asset->status);
        $this->assertFalse($asset->is_public);
    }

    public function test_direct_promotion_from_draft_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MEDIA_ASSET_NOT_VERIFIED');

        app(MediaAssetPromotionService::class)->promote($this->verifiedAsset());
    }

    private function verifiedAsset(): MediaAsset
    {
        $asset = MediaAsset::query()->create([
            'org_id' => 0,
            'asset_key' => 'promotion-contract-'.uniqid(),
            'disk' => 'public',
            'path' => 'media/source.jpg',
            'url' => 'https://assets.fermatmind.com/storage/media/source.jpg',
            'status' => MediaAsset::STATUS_DRAFT,
            'is_public' => false,
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_VERIFIED,
        ]);

        foreach (array_merge(['original'], MediaVariantGenerator::variantKeys()) as $key) {
            $asset->variants()->create([
                'variant_key' => $key,
                'path' => 'media/'.$key.'.jpg',
                'url' => 'https://assets.fermatmind.com/storage/media/'.$key.'.jpg',
                'sync_status' => MediaAsset::SYNC_SYNCED,
                'cdn_status' => MediaAsset::CDN_VERIFIED,
            ]);
        }

        return $asset->fresh('variants');
    }
}
