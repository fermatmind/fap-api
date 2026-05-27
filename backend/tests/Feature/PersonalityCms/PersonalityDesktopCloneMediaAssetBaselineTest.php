<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use Tests\TestCase;

final class PersonalityDesktopCloneMediaAssetBaselineTest extends TestCase
{
    public function test_mbti_desktop_clone_baseline_has_ready_media_library_backed_slots(): void
    {
        $clone = $this->decodeJson(base_path('../content_baselines/personality_clone/mbti_desktop_clone.zh-CN.json'));
        $mediaRows = collect($this->decodeJson(base_path('../content_baselines/media_assets/mbti_desktop_clone_assets.v1.json')))
            ->keyBy(fn (array $row): string => (string) ($row['asset_key'] ?? ''));

        $this->assertCount(32, $clone['variants']);
        $this->assertCount(224, $mediaRows);

        foreach ($clone['variants'] as $variant) {
            $fullCode = (string) $variant['full_code'];
            $fullCodeSlug = strtolower($fullCode);
            $assetSlots = $variant['asset_slots_json'] ?? [];

            $this->assertCount(7, $assetSlots, $fullCode);
            $this->assertSame(PersonalityDesktopCloneAssetSlotSupport::allowedSlotIds(), array_column($assetSlots, 'slotId'));

            foreach ($assetSlots as $slot) {
                $slotId = (string) ($slot['slotId'] ?? '');
                $assetKey = sprintf('mbti.desktop_clone.%s.%s', $fullCodeSlug, $slotId);
                $path = sprintf('/static/mbti/desktop-clone/%s/%s.svg', $fullCodeSlug, $slotId);

                $this->assertSame(PersonalityDesktopCloneAssetSlotSupport::STATUS_READY, $slot['status'] ?? null, $assetKey);
                $this->assertSame($assetKey, data_get($slot, 'meta.media_library_asset_key'));
                $this->assertSame(PersonalityDesktopCloneAssetSlotSupport::ASSET_PROVIDER_CDN, data_get($slot, 'assetRef.provider'));
                $this->assertSame($path, data_get($slot, 'assetRef.path'));
                $this->assertSame('https://assets.fermatmind.com'.$path, data_get($slot, 'assetRef.url'));
                $this->assertStringStartsWith('sha256:', (string) data_get($slot, 'assetRef.checksum'));
                $this->assertNotSame('', trim((string) ($slot['alt'] ?? '')));

                $this->assertTrue($mediaRows->has($assetKey), $assetKey);
                $media = $mediaRows->get($assetKey);
                $this->assertSame($path, $media['path'] ?? null);
                $this->assertSame('https://assets.fermatmind.com'.$path, $media['url'] ?? null);
                $this->assertSame('image/svg+xml', $media['mime_type'] ?? null);
                $this->assertSame($slot['alt'], $media['alt'] ?? null);
                $this->assertFileExists(base_path('public'.$path), $path);
            }
        }
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function decodeJson(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
