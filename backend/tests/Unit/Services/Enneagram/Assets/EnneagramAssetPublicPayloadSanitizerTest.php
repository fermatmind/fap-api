<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetPublicPayloadSanitizer;
use Tests\TestCase;

final class EnneagramAssetPublicPayloadSanitizerTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_strips_internal_metadata_and_preserves_body_text_exactly(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $sanitizer = app(EnneagramAssetPublicPayloadSanitizer::class);
        $item = $loader->load($this->batchAPath())['items'][0];

        $payload = $sanitizer->sanitizeItem($item);

        $this->assertSame($item['body_zh'], $payload['body_zh']);
        $this->assertSame([], $sanitizer->internalMetadataLeaks($payload));
        foreach (EnneagramAssetPublicPayloadSanitizer::INTERNAL_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $payload);
        }
    }
}
