<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Models\MediaAsset;
use App\Services\Cms\MediaAssetStorageSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MediaAssetStorageSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cdn_verification_rejects_private_media_urls_without_fetching_them(): void
    {
        Http::fake();
        config(['fap.media.cdn_verify_enabled' => true]);

        $asset = $this->assetWithUrl('https://169.254.169.254/latest/meta-data');

        $verified = app(MediaAssetStorageSyncService::class)->verifyCdn($asset);

        $this->assertSame(MediaAsset::CDN_FAILED, (string) $verified->cdn_status);
        $this->assertStringContainsString('missing or blocked CDN URL', (string) $verified->last_error);
        Http::assertNothingSent();
    }

    public function test_cdn_verification_fails_closed_on_redirect_responses(): void
    {
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 302, [
                'Content-Type' => 'text/html',
                'Location' => 'https://127.0.0.1/internal.png',
            ]),
        ]);
        config(['fap.media.cdn_verify_enabled' => true]);

        $asset = $this->assetWithUrl('https://assets.fermatmind.com/static/articles/cover.png');

        $verified = app(MediaAssetStorageSyncService::class)->verifyCdn($asset);

        $this->assertSame(MediaAsset::CDN_FAILED, (string) $verified->cdn_status);
        $this->assertStringContainsString('returned 302 text/html', (string) $verified->last_error);
    }

    private function assetWithUrl(string $url): MediaAsset
    {
        return MediaAsset::query()->create([
            'org_id' => 0,
            'asset_key' => 'asset_'.md5($url),
            'disk' => 'public_static',
            'path' => 'static/articles/cover.png',
            'url' => $url,
            'mime_type' => 'image/png',
            'width' => 1200,
            'height' => 630,
            'bytes' => 1234,
            'alt' => 'cover',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_NOT_VERIFIED,
        ]);
    }
}
