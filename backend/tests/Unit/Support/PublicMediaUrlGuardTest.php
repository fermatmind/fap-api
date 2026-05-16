<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PublicMediaUrlGuard;
use Tests\TestCase;

final class PublicMediaUrlGuardTest extends TestCase
{
    public function test_sanitize_nullable_url_allows_only_explicit_public_media_origins(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'fap.media.asset_origin' => 'https://assets.fermatmind.com',
        ]);

        $this->assertNull(
            PublicMediaUrlGuard::sanitizeNullableUrl('https://bucket.cos.ap-shanghai.myqcloud.com/path.png')
        );
        $this->assertNull(
            PublicMediaUrlGuard::sanitizeNullableUrl('https://example.test/image.png?ci-process=cover')
        );

        foreach ([
            '/images/local-cover.png',
            'javascript:alert(1)',
            'data:image/png;base64,AAAA',
            'ftp://assets.fermatmind.com/static/cover.png',
            'http://assets.fermatmind.com/static/cover.png',
            'https://cdn.example.test/cover.png',
            'https://assets.fermatmind.com.evil.test/static/cover.png',
            'https://127.0.0.1/static/cover.png',
            'https://10.0.0.2/static/cover.png',
            'https://172.16.0.2/static/cover.png',
            'https://192.168.0.2/static/cover.png',
            'https://169.254.169.254/latest/meta-data',
            'https://localhost/static/cover.png',
            'https://[::1]/static/cover.png',
            'https://assets.fermatmind.com:8443/static/cover.png',
            'https://user:pass@assets.fermatmind.com/static/cover.png',
        ] as $blockedUrl) {
            $this->assertNull(PublicMediaUrlGuard::sanitizeNullableUrl($blockedUrl), $blockedUrl);
        }

        foreach ([
            'https://assets.fermatmind.com/static/share/mbti_wide_1200x630.png',
            'https://api.fermatmind.com/static/social/wechat-qr.jpg',
            'https://api.staging.fermatmind.com/static/articles/cover.png',
        ] as $allowedUrl) {
            $this->assertSame($allowedUrl, PublicMediaUrlGuard::sanitizeNullableUrl($allowedUrl), $allowedUrl);
        }
    }

    public function test_public_media_url_for_path_canonicalizes_internal_paths_to_the_asset_origin(): void
    {
        config(['fap.media.asset_origin' => 'https://assets.fermatmind.com']);

        $this->assertSame(
            'https://assets.fermatmind.com/storage/media-library/cover.png',
            PublicMediaUrlGuard::publicMediaUrlForPath('public', 'media-library/cover.png')
        );

        $this->assertSame(
            'https://assets.fermatmind.com/static/share/mbti_wide_1200x630.png',
            PublicMediaUrlGuard::publicMediaUrlForPath(null, '/static/share/mbti_wide_1200x630.png')
        );
    }

    public function test_sanitize_array_fields_recursively_nulls_unsafe_media_fields(): void
    {
        $payload = PublicMediaUrlGuard::sanitizeArrayFields([
            'og_image_url' => 'https://bucket.cos.ap-shanghai.myqcloud.com/og.png',
            'twitter_image_url' => 'https://assets.fermatmind.com/static/twitter.png',
            'variants' => [
                'hero' => ['url' => 'https://127.0.0.1/internal.png'],
                'card' => ['url' => 'https://api.fermatmind.com/static/card.png'],
            ],
            'title' => 'SEO',
        ], ['og_image_url', 'twitter_image_url', 'url']);

        $this->assertSame([
            'og_image_url' => null,
            'twitter_image_url' => 'https://assets.fermatmind.com/static/twitter.png',
            'variants' => [
                'hero' => ['url' => null],
                'card' => ['url' => 'https://api.fermatmind.com/static/card.png'],
            ],
            'title' => 'SEO',
        ], $payload);
    }

    public function test_sanitize_json_ld_image_fields_blocks_unsafe_image_urls_without_rewriting_canonical_urls(): void
    {
        $payload = PublicMediaUrlGuard::sanitizeJsonLdImageFields([
            '@type' => 'Article',
            'url' => 'https://fermatmind.com/en/articles/career-fit-guide',
            'mainEntityOfPage' => 'https://fermatmind.com/en/articles/career-fit-guide#webpage',
            'image' => [
                'https://api.fermatmind.com/static/articles/cover.png',
                'https://169.254.169.254/latest/meta-data',
                [
                    '@type' => 'ImageObject',
                    'url' => 'https://cdn.example.test/blocked.png',
                    'contentUrl' => 'https://assets.fermatmind.com/static/articles/content.png',
                ],
            ],
            'publisher' => [
                '@type' => 'Organization',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => 'https://localhost/logo.png',
                ],
            ],
        ]);

        $this->assertSame('https://fermatmind.com/en/articles/career-fit-guide', $payload['url']);
        $this->assertSame('https://fermatmind.com/en/articles/career-fit-guide#webpage', $payload['mainEntityOfPage']);
        $this->assertSame('https://api.fermatmind.com/static/articles/cover.png', $payload['image'][0]);
        $this->assertSame([
            '@type' => 'ImageObject',
            'url' => null,
            'contentUrl' => 'https://assets.fermatmind.com/static/articles/content.png',
        ], $payload['image'][1]);
        $this->assertSame([
            '@type' => 'ImageObject',
            'url' => null,
        ], $payload['publisher']['logo']);
    }
}
