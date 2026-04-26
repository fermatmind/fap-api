<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetPublicPayloadSanitizer;
use Tests\TestCase;

final class EnneagramAssetPreviewPayloadBuilderTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_builds_36_preview_payloads_without_internal_metadata(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $payloads = app(EnneagramAssetPreviewPayloadBuilder::class)->buildAll($merged);
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertCount(36, $payloads);

        foreach ($payloads as $payload) {
            $this->assertTrue($payload['preview_mode']);
            $this->assertFalse($payload['production_import_allowed']);
            $this->assertFalse($payload['full_replacement_allowed']);
            $this->assertSame([], $payload['blocked_reasons']);
            $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
            $this->assertNotEmpty($payload['modules']);
        }
    }
}
