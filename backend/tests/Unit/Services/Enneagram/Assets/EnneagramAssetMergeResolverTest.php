<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use Tests\TestCase;

final class EnneagramAssetMergeResolverTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_merges_1r_a_and_1r_b_without_full_replacement(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $resolver = app(EnneagramAssetMergeResolver::class);

        $merged = $resolver->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));

        $this->assertFalse($merged['production_import_allowed']);
        $this->assertFalse($merged['full_replacement_allowed']);
        $this->assertCount(738, $merged['items']);
        $this->assertContains('page1_summary', data_get($merged, 'replacement_coverage.batch_1r_a_replaces'));
        $this->assertContains('core_motivation', data_get($merged, 'replacement_coverage.batch_1r_b_replaces'));
    }
}
