<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use Tests\TestCase;

final class SeoPlatform11BPrivateNegativeSetTest extends TestCase
{
    public function test_structural_private_routes_fail_closed_without_natural_language_false_positives(): void
    {
        $set = app(SeoPrivateRouteNegativeSet::class);
        foreach (['/en/result/abc', '/ZH/orders/123/', '/account/profile', '/%2565n%252fresult%252fabc', '/api/v0_5/attempts/123'] as $path) {
            $this->assertTrue($set->classify($path)['private'], $path);
        }
        $this->assertTrue($set->classify(null, 'orders.show')['private']);
        $this->assertTrue($set->classify(null, null, 'payment')['private']);
        $this->assertFalse($set->classify('/articles/order-of-operations')['private']);
        $this->assertFalse($set->classify('/articles/public-result-research')['private']);
    }
}
