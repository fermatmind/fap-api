<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Routing\Route as IlluminateRoute;
use Tests\TestCase;

final class OpsNavigationInventoryTest extends TestCase
{
    /** @var list<string> */
    private const PRODUCTION_ENTRIES = [
        'ops',
        'ops/select-org',
        'ops/article-categories',
        'ops/articles',
        'ops/article-tags',
        'ops/career-guides',
        'ops/career-jobs',
        'ops/content-pages',
        'ops/interpretation-guides',
        'ops/landing-surfaces',
        'ops/media-assets',
        'ops/personality',
        'ops/personality-desktop-clone',
        'ops/scale-registries',
        'ops/scale-slugs',
        'ops/support-articles',
        'ops/topics',
        'ops/editorial-operations',
        'ops/content-workspace',
        'ops/benefit-grants',
        'ops/orders',
        'ops/payment-events',
        'ops/skus',
        'ops/funnel-conversion',
        'ops/payment-attempts',
        'ops/content-pack-releases',
        'ops/content-pack-versions',
        'ops/content-release',
        'ops/editorial-review',
        'ops/post-release-observability',
        'ops/enneagram-registry-release',
        'ops/daily-giving-records',
        'ops/content-overview',
        'ops/content-search',
        'ops/content-metrics',
        'ops/seo-operations',
        'ops/content-growth-attribution',
        'ops/seo',
        'ops/article-publishing-ops',
        'ops/global-search',
        'ops/attempts',
        'ops/results',
        'ops/order-lookup',
        'ops/reports',
        'ops/delivery-tools',
        'ops/secure-link',
        'ops/article-translation-ops',
        'ops/audit-logs',
        'ops/deploys',
        'ops/health-checks',
        'ops/public-content-health',
        'ops/queue-monitor',
        'ops/webhook-monitor',
        'ops/mbti-insights',
        'ops/question-analytics',
        'ops/quality-research',
        'ops/test-kpi-daily',
        'ops/admin-approvals',
        'ops/admin-users',
        'ops/permissions',
        'ops/roles',
        'ops/go-live-gate',
        'ops/organizations',
        'ops/organizations-import',
    ];

    public function test_all_64_production_navigation_entries_keep_their_routes(): void
    {
        $registeredUris = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (IlluminateRoute $route): string => $route->uri())
            ->unique();

        $this->assertCount(64, self::PRODUCTION_ENTRIES);

        foreach (self::PRODUCTION_ENTRIES as $uri) {
            $this->assertTrue($registeredUris->contains($uri), "Missing production Ops navigation route [{$uri}].");
        }
    }
}
