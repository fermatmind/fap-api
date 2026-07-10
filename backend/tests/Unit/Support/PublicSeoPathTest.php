<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PublicSeoPath;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PublicSeoPathTest extends TestCase
{
    #[Test]
    public function public_paths_and_owned_canonical_urls_are_normalized(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $this->assertSame('/zh/articles/example', PublicSeoPath::normalizePath('//zh//articles/example/'));
        $this->assertSame('/en/tests/mbti', PublicSeoPath::fromCanonicalUrl('https://www.fermatmind.com/en/tests/mbti'));
        $this->assertSame('/', PublicSeoPath::fromCanonicalUrl('https://fermatmind.com/'));
    }

    #[Test]
    public function private_ambiguous_and_unowned_paths_fail_closed(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        foreach ([
            '/results/private-id',
            '/zh/checkout/order-id',
            '/en/ops/seo',
            '/api/v0.5/private',
            '/articles/example?token=secret',
            '/articles/%2e%2e/ops',
            '/articles\\example',
            'https://fermatmind.com/articles/example',
        ] as $path) {
            $this->assertNull(PublicSeoPath::normalizePath($path), $path);
        }

        foreach ([
            'http://fermatmind.com/en/articles/example',
            'https://example.invalid/en/articles/example',
            'https://fermatmind.com:8443/en/articles/example',
            'https://user@fermatmind.com/en/articles/example',
            'https://fermatmind.com/en/results/private-id',
            'https://fermatmind.com/en/articles/example?token=secret',
        ] as $url) {
            $this->assertNull(PublicSeoPath::fromCanonicalUrl($url), $url);
        }
    }
}
