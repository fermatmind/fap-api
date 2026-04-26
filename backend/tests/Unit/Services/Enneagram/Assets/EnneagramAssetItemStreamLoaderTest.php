<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use Tests\TestCase;

final class EnneagramAssetItemStreamLoaderTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_loads_batch_1r_a_and_1r_b_item_streams_without_mutating_body_text(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $batchA = $loader->load($this->batchAPath());
        $batchB = $loader->load($this->batchBPath());

        $this->assertCount(315, $batchA['items']);
        $this->assertCount(423, $batchB['items']);
        $this->assertSame(315, (int) data_get($batchA, 'metadata.asset_count'));
        $this->assertSame(423, (int) data_get($batchB, 'metadata.asset_count'));

        $rawA = json_decode((string) file_get_contents($this->batchAPath()), true, 512, JSON_THROW_ON_ERROR);
        $rawB = json_decode((string) file_get_contents($this->batchBPath()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(data_get($rawA, 'items.0.body_zh'), data_get($batchA, 'items.0.body_zh'));
        $this->assertSame(data_get($rawB, 'items.0.body_zh'), data_get($batchB, 'items.0.body_zh'));
    }
}
