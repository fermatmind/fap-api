<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Tests\TestCase;

final class SeoPlatform11BPrivateNegativeSetTest extends TestCase
{
    public function test_structural_private_routes_fail_closed_without_natural_language_false_positives(): void
    {
        $set = app(SeoPrivateRouteNegativeSet::class);
        foreach (['/en/result/abc', '/ZH/orders/123/', '/account/profile', '/%252565n%25252fresult%25252fabc', '/api/v0_5/attempts/123', '/zh/api/v1/shares/abc', '/api/v2/report_private/abc'] as $path) {
            $this->assertTrue($set->classify($path)['private'], $path);
        }
        $this->assertTrue($set->classify(null, 'orders.show')['private']);
        $this->assertTrue($set->classify(null, null, 'payment')['private']);
        $this->assertFalse($set->classify('/articles/order-of-operations')['private']);
        $this->assertFalse($set->classify('/articles/public-result-research')['private']);
    }

    public function test_page_family_registry_is_the_canonical_36_probe_authority(): void
    {
        $registry = app(PageFamilyPolicyRegistry::class);
        $set = app(SeoPrivateRouteNegativeSet::class);
        $this->assertCount(22, $registry->privatePathSegments());
        $this->assertCount(14, $registry->privatePageEntityTypes());
        $this->assertCount(36, $registry->negativeSetProbes());

        $rejected = 0;
        foreach ($registry->negativeSetProbes() as $probe) {
            $rejected += (int) $set->classify($probe['canonical_path'], null, $probe['page_entity_type'])['private'];
        }
        $this->assertSame(36, $rejected);
    }
}
