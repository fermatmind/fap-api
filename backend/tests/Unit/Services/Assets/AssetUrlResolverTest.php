<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Assets;

use App\Services\Assets\AssetUrlResolver;
use App\Services\Content\ContentPacksIndex;
use App\Support\RegionContext;
use Tests\TestCase;

class AssetUrlResolverTest extends TestCase
{
    public function test_it_ignores_tencent_override_and_falls_back_to_local_assets_base(): void
    {
        config([
            'app.url' => 'https://fermatmind.com',
            'cdn_map.fallback_assets_base_url' => 'https://fermatmind.com/storage/content_assets',
            'cdn_map.map.CN_MAINLAND.assets_base_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com',
        ]);

        $resolver = new AssetUrlResolver(new ContentPacksIndex(), new RegionContext());

        $resolved = $resolver->resolve(
            'MBTI-CN-v0.2.1-TEST',
            '2026-04-10',
            'assets/hero/infj-a.webp',
            'CN_MAINLAND',
            'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/runtime-override'
        );

        $this->assertSame(
            'https://fermatmind.com/storage/content_assets/MBTI-CN-v0.2.1-TEST/2026-04-10/assets/hero/infj-a.webp',
            $resolved
        );
    }
}
