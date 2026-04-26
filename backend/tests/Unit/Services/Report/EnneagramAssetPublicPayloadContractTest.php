<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetMergeResolver;
use App\Services\Enneagram\Assets\EnneagramAssetPreviewPayloadBuilder;
use App\Services\Enneagram\Assets\EnneagramAssetPublicPayloadSanitizer;
use Tests\TestCase;
use Tests\Unit\Services\Enneagram\Assets\EnneagramAssetTestPaths;

final class EnneagramAssetPublicPayloadContractTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_public_preview_payload_exposes_only_allowlisted_asset_fields(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $merged = app(EnneagramAssetMergeResolver::class)->resolve($loader->load($this->batchAPath()), $loader->load($this->batchBPath()));
        $payload = app(EnneagramAssetPreviewPayloadBuilder::class)->build($merged, app(EnneagramAssetPreviewPayloadBuilder::class)->contextFor('1', 'clear'));
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);

        $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
        foreach ((array) $payload['modules'] as $module) {
            $content = (array) ($module['content'] ?? []);
            $this->assertArrayHasKey('body_zh', $content);
            $this->assertArrayHasKey('asset_key', $content);
            $this->assertArrayNotHasKey('selection_guidance', $content);
            $this->assertArrayNotHasKey('editor_note', $content);
            $this->assertArrayNotHasKey('qa_note', $content);
            $this->assertArrayNotHasKey('safety_note', $content);
        }
    }
}
