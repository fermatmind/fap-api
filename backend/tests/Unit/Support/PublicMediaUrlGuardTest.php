<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PublicMediaUrlGuard;
use PHPUnit\Framework\TestCase;

final class PublicMediaUrlGuardTest extends TestCase
{
    public function test_sanitize_nullable_url_blocks_tencent_markers_and_keeps_safe_urls(): void
    {
        $this->assertNull(
            PublicMediaUrlGuard::sanitizeNullableUrl('https://bucket.cos.ap-shanghai.myqcloud.com/path.png')
        );
        $this->assertNull(
            PublicMediaUrlGuard::sanitizeNullableUrl('https://example.test/image.png?ci-process=cover')
        );
        $this->assertSame(
            '/images/local-cover.png',
            PublicMediaUrlGuard::sanitizeNullableUrl('/images/local-cover.png')
        );
        $this->assertSame(
            'https://cdn.example.test/cover.png',
            PublicMediaUrlGuard::sanitizeNullableUrl('https://cdn.example.test/cover.png')
        );
    }

    public function test_sanitize_array_fields_only_nulls_blocked_media_fields(): void
    {
        $payload = PublicMediaUrlGuard::sanitizeArrayFields([
            'og_image_url' => 'https://bucket.cos.ap-shanghai.myqcloud.com/og.png',
            'twitter_image_url' => 'https://cdn.example.test/twitter.png',
            'title' => 'SEO',
        ], ['og_image_url', 'twitter_image_url']);

        $this->assertSame([
            'og_image_url' => null,
            'twitter_image_url' => 'https://cdn.example.test/twitter.png',
            'title' => 'SEO',
        ], $payload);
    }
}
