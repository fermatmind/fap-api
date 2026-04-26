<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetSelector;
use Tests\TestCase;

final class EnneagramAssetSelectorTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_selects_assets_by_type_category_and_preview_context(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $context = app(EnneagramAssetPreviewPayloadBuilder::class)->contextFor('1', 'clear');
        $selected = app(EnneagramAssetSelector::class)->selectByCategory($merged, $context);

        $this->assertArrayHasKey('page1_summary', $selected);
        $this->assertArrayHasKey('core_motivation', $selected);
        $this->assertSame('1', $selected['page1_summary']['type_id']);
        $this->assertSame('1', $selected['core_motivation']['type_id']);
        $this->assertStringContainsString('1R_A', $selected['page1_summary']['asset_key']);
        $this->assertStringContainsString('1R_B', $selected['core_motivation']['asset_key']);
    }
}
