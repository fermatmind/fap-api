<?php

declare(strict_types=1);

namespace Tests\Unit\PersonalityCms\DesktopClone;

use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use Tests\TestCase;

class PersonalityDesktopCloneAssetSlotSupportTest extends TestCase
{
    public function test_ready_slot_degrades_to_placeholder_when_only_tencent_url_is_present(): void
    {
        $normalized = PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlot([
            'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_HERO_ILLUSTRATION,
            'label' => 'Hero illustration',
            'aspect_ratio' => '236:160',
            'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_READY,
            'asset_ref' => [
                'provider' => PersonalityDesktopCloneAssetSlotSupport::ASSET_PROVIDER_CDN,
                'path' => null,
                'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/assets/mbti/hero.webp',
                'version' => 'v1',
                'checksum' => 'sha256:test',
            ],
        ]);

        $this->assertSame(PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER, $normalized['status']);
        $this->assertNull($normalized['asset_ref']);
    }

    public function test_ready_slot_keeps_local_path_when_tencent_url_has_non_tencent_fallback_path(): void
    {
        $normalized = PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlot([
            'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_HERO_ILLUSTRATION,
            'label' => 'Hero illustration',
            'aspect_ratio' => '236:160',
            'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_READY,
            'asset_ref' => [
                'provider' => PersonalityDesktopCloneAssetSlotSupport::ASSET_PROVIDER_INTERNAL,
                'path' => 'storage/content_assets/mbti/desktop/hero.webp',
                'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/assets/mbti/hero.webp',
                'version' => 'v1',
                'checksum' => 'sha256:test',
            ],
        ]);

        $this->assertSame(PersonalityDesktopCloneAssetSlotSupport::STATUS_READY, $normalized['status']);
        $this->assertSame('storage/content_assets/mbti/desktop/hero.webp', $normalized['asset_ref']['path']);
        $this->assertNull($normalized['asset_ref']['url']);
    }
}
