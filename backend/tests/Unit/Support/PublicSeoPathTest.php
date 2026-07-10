<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueueEligibilityEvaluator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PublicSeoPathTest extends TestCase
{
    #[Test]
    public function public_paths_and_owned_canonical_urls_are_normalized(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $this->assertSame('/zh/articles/example', SearchChannelQueueEligibilityEvaluator::normalizePublicPath('//zh//articles/example/'));
        $this->assertSame('/en/tests/mbti', SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl('https://www.fermatmind.com/en/tests/mbti'));
        $this->assertSame('/', SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl('https://fermatmind.com/'));
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
            $this->assertNull(SearchChannelQueueEligibilityEvaluator::normalizePublicPath($path), $path);
        }

        foreach ([
            'http://fermatmind.com/en/articles/example',
            'https://example.invalid/en/articles/example',
            'https://fermatmind.com:8443/en/articles/example',
            'https://user@fermatmind.com/en/articles/example',
            'https://fermatmind.com/en/results/private-id',
            'https://fermatmind.com/en/articles/example?token=secret',
        ] as $url) {
            $this->assertNull(SearchChannelQueueEligibilityEvaluator::publicPathFromCanonicalUrl($url), $url);
        }
    }
}
