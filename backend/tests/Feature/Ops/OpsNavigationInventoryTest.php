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
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OpsNavigationInventoryTest extends TestCase
{
    use RefreshDatabase;

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
            ArticleResource::class => ['Articles', '文章', 'heroicon-o-document-text'],
            CareerGuideResource::class => ['Career Guides', '职业指南', 'heroicon-o-book-open'],
            CareerJobResource::class => ['Career Jobs', '职业岗位', 'heroicon-o-briefcase'],
            ContentPageResource::class => ['Content Pages', '内容页面', 'heroicon-o-document-duplicate'],
            LandingSurfaceResource::class => ['Landing Surfaces', '落地页模块', 'heroicon-o-rectangle-group'],
            MediaAssetResource::class => ['Media Library', '媒体库', 'heroicon-o-photo'],
            PersonalityProfileResource::class => ['Personality', '人格内容', 'heroicon-o-sparkles'],
            TopicProfileResource::class => ['Topics', '主题页', 'heroicon-o-tag'],
        ];

        foreach (['en' => 0, 'zh_CN' => 1] as $locale => $labelIndex) {
            app()->setLocale($locale);

            foreach (array_keys($resources) as $index => $resource) {
                $this->assertTrue($resource::shouldRegisterNavigation());
                $this->assertSame(__('ops.group.content'), $resource::getNavigationGroup());
                $this->assertSame($index + 1, $resource::getNavigationSort());
                $this->assertSame($resources[$resource][$labelIndex], $resource::getNavigationLabel());
                $this->assertSame($resources[$resource][2], $resource::getNavigationIcon());
            }

            $this->assertNotSame(__('ops.group.content'), __('ops.group.editorial'));
            $this->assertNotSame(__('ops.group.content'), __('ops.group.taxonomy'));
            $this->assertNotSame(__('ops.group.content'), __('ops.group.content_workspace'));
        }
    }

    public function test_sidebar_items_receive_accessible_names_and_native_tooltips_after_navigation_updates(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('ops'));
        Filament::bootCurrentPanel();

        $hook = FilamentView::renderHook(PanelsRenderHook::BODY_END)->toHtml();

        $this->assertStringContainsString("querySelectorAll('.fi-sidebar-item-button')", $hook);
        $this->assertStringContainsString("querySelector('.fi-sidebar-item-label')", $hook);
        $this->assertStringContainsString("setAttribute('aria-label', label)", $hook);
        $this->assertStringContainsString("setAttribute('title', label)", $hook);
        $this->assertStringContainsString("addEventListener('livewire:navigated'", $hook);
        $this->assertStringContainsString('new MutationObserver(syncSidebarItemLabels)', $hook);

        $sourceCss = file_get_contents(resource_path('css/filament/ops/theme.css'));
        $compiledCss = file_get_contents(resource_path('css/filament/ops/theme.compiled.css'));

        $this->assertIsString($sourceCss);
        $this->assertIsString($compiledCss);
        $this->assertStringContainsString('.fi-sidebar-item-button:focus-visible', $sourceCss);
        $this->assertStringContainsString('.fi-sidebar-item-button:focus-visible', $compiledCss);
        $this->assertStringContainsString('outline:2px solid var(--ops-electric)', $compiledCss);
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

    public function test_hidden_content_entries_preserve_existing_read_permissions(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        foreach ([
            ArticleCategoryResource::class,
            ArticleTagResource::class,
            InterpretationGuideResource::class,
            PersonalityVariantCloneContentResource::class,
            ScaleRegistryResource::class,
            ScaleSlugResource::class,
            SupportArticleResource::class,
        ] as $resource) {
            $this->assertTrue($resource::canViewAny(), "Hidden resource [{$resource}] lost content_read access.");
        }

        $this->assertTrue(EditorialOperationsPage::canAccess());
        $this->assertTrue(ContentWorkspacePage::canAccess());

        $writer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $this->actingAs($writer, (string) config('admin.guard', 'admin'));

        foreach ([
            ArticleCategoryResource::class,
            ArticleTagResource::class,
            InterpretationGuideResource::class,
            ScaleRegistryResource::class,
            SupportArticleResource::class,
        ] as $resource) {
            $this->assertTrue($resource::canCreate(), "Hidden resource [{$resource}] lost content_write create access.");
        }

        $publisher = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);
        $this->actingAs($publisher, (string) config('admin.guard', 'admin'));
        $this->assertTrue(PersonalityVariantCloneContentResource::canCreate());
        $this->assertFalse(ScaleSlugResource::canCreate());
        $this->assertFalse(ScaleSlugResource::canEdit(null));
    }

    /** @param list<string> $permissions */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'nav_access_'.Str::lower(Str::random(6)),
            'email' => 'nav_access_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);
        $role = Role::query()->create([
            'name' => 'nav_access_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
