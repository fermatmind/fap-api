<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentWorkspacePage;
use App\Filament\Ops\Pages\EditorialOperationsPage;
use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\ContentPageResource;
use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Filament\Ops\Resources\LandingSurfaceResource;
use App\Filament\Ops\Resources\MediaAssetResource;
use App\Filament\Ops\Resources\PersonalityProfileResource;
use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource;
use App\Filament\Ops\Resources\ScaleRegistryResource;
use App\Filament\Ops\Resources\ScaleSlugResource;
use App\Filament\Ops\Resources\SupportArticleResource;
use App\Filament\Ops\Resources\TopicProfileResource;
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

    public function test_content_navigation_has_exactly_eight_fixed_bilingual_entries(): void
    {
        $resources = [
            ArticleResource::class => ['Articles', '文章'],
            CareerGuideResource::class => ['Career Guides', '职业指南'],
            CareerJobResource::class => ['Career Jobs', '职业岗位'],
            ContentPageResource::class => ['Content Pages', '内容页面'],
            LandingSurfaceResource::class => ['Landing Surfaces', '落地页模块'],
            MediaAssetResource::class => ['Media Library', '媒体库'],
            PersonalityProfileResource::class => ['Personality', '人格内容'],
            TopicProfileResource::class => ['Topics', '主题页'],
        ];

        foreach (['en' => 0, 'zh_CN' => 1] as $locale => $labelIndex) {
            app()->setLocale($locale);

            foreach (array_keys($resources) as $index => $resource) {
                $this->assertTrue($resource::shouldRegisterNavigation());
                $this->assertSame(__('ops.group.content'), $resource::getNavigationGroup());
                $this->assertSame($index + 1, $resource::getNavigationSort());
                $this->assertSame($resources[$resource][$labelIndex], $resource::getNavigationLabel());
            }
        }
    }

    public function test_hidden_content_entries_keep_index_create_and_edit_routes(): void
    {
        $hiddenResources = [
            ArticleCategoryResource::class,
            ArticleTagResource::class,
            InterpretationGuideResource::class,
            PersonalityVariantCloneContentResource::class,
            ScaleRegistryResource::class,
            ScaleSlugResource::class,
            SupportArticleResource::class,
        ];

        foreach ($hiddenResources as $resource) {
            $this->assertFalse($resource::shouldRegisterNavigation());
            $this->assertSame(['index', 'create', 'edit'], array_keys($resource::getPages()));
        }

        $this->assertFalse(EditorialOperationsPage::shouldRegisterNavigation());
        $this->assertFalse(ContentWorkspacePage::shouldRegisterNavigation());

        $registeredUris = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (IlluminateRoute $route): string => $route->uri())
            ->unique();

        foreach ([
            'article-categories',
            'article-tags',
            'interpretation-guides',
            'personality-desktop-clone',
            'scale-registries',
            'scale-slugs',
            'support-articles',
        ] as $slug) {
            $this->assertTrue($registeredUris->contains("ops/{$slug}"));
            $this->assertTrue($registeredUris->contains("ops/{$slug}/create"));
            $this->assertTrue($registeredUris->contains("ops/{$slug}/{record}/edit"));
        }

        $this->assertTrue($registeredUris->contains('ops/editorial-operations'));
        $this->assertTrue($registeredUris->contains('ops/content-workspace'));
    }
}
