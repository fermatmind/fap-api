<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentOverviewPage;
use App\Filament\Ops\Pages\ContentReleasePage;
use App\Filament\Ops\Pages\ContentWorkspacePage;
use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\ContentPackReleaseResource;
use App\Filament\Ops\Resources\ContentPackVersionResource;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentCmsProductLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_read_admin_can_open_workspace_pages_but_not_release_surface(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->seedCmsSurface();

        $session = $this->opsSession((int) $admin->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-overview')
            ->assertOk()
            ->assertSee('Content overview')
            ->assertSee('Workspace health');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-workspace')
            ->assertOk()
            ->assertSee('Editorial')
            ->assertSee('Taxonomy');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertForbidden();
    }

    public function test_content_write_admin_can_create_article_but_not_open_release_surface(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/create')
            ->assertOk();

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/article-categories/create')
            ->assertOk();

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertForbidden();
    }

    public function test_legacy_publish_permission_still_allows_write_but_not_release(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/create')
            ->assertOk();

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertForbidden();
    }

    public function test_content_release_admin_can_open_release_surface_but_not_article_create(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $article = $this->seedArticle([
            'title' => 'Release Queue Article',
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        $guide = $this->seedGuide([
            'title' => 'Release Queue Guide',
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'published_at' => null,
        ]);

        $job = $this->seedJob([
            'title' => 'Release Queue Job',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'published_at' => null,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertOk()
            ->assertSee('Release workspace')
            ->assertSee('Filters')
            ->assertSee('Article')
            ->assertSee('Career Guide')
            ->assertSee('Career Job')
            ->assertDontSee('Content Pack Release')
            ->assertDontSee('Content Pack Version');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/create')
            ->assertForbidden();
    }

    public function test_content_release_admin_can_release_draft_content_records(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $article = $this->seedArticle([
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);
        $guide = $this->seedGuide([
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'published_at' => null,
        ]);
        $job = $this->seedJob([
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'published_at' => null,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        ArticleResource::releaseRecord($article);
        CareerGuideResource::releaseRecord($guide);
        CareerJobResource::releaseRecord($job);

        $article->refresh();
        $guide->refresh();
        $job->refresh();

        $this->assertSame('published', $article->status);
        $this->assertFalse($article->is_public);
        $this->assertNotNull($article->published_at);

        $this->assertSame(CareerGuide::STATUS_PUBLISHED, $guide->status);
        $this->assertFalse($guide->is_public);
        $this->assertNotNull($guide->published_at);

        $this->assertSame(CareerJob::STATUS_PUBLISHED, $job->status);
        $this->assertFalse($job->is_public);
        $this->assertNotNull($job->published_at);
    }

    public function test_content_write_admin_cannot_release_draft_content_records(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $article = $this->seedArticle([
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->expectException(AuthorizationException::class);

        ArticleResource::releaseRecord($article);
    }

    public function test_content_overview_stays_on_visible_cms_modules_only(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->seedCmsSurface();

        $session = $this->opsSession((int) $admin->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-overview')
            ->assertOk()
            ->assertSee('Current org editorial')
            ->assertSee('Global career content')
            ->assertDontSee('Content versions')
            ->assertDontSee('Release records');
    }

    public function test_navigation_groups_match_cms_bootstrap_blueprint(): void
    {
        $this->assertSame(__('ops.group.content_overview'), ContentOverviewPage::getNavigationGroup());
        $this->assertSame(__('ops.group.content_overview'), ContentWorkspacePage::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), ArticleResource::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), CareerGuideResource::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), CareerJobResource::getNavigationGroup());
        $this->assertSame(__('ops.group.taxonomy'), ArticleCategoryResource::getNavigationGroup());
        $this->assertSame(__('ops.group.taxonomy'), ArticleTagResource::getNavigationGroup());
        $this->assertSame(__('ops.group.content_release'), ContentReleasePage::getNavigationGroup());
        $this->assertSame(__('ops.group.content_control_plane'), ContentPackVersionResource::getNavigationGroup());
        $this->assertSame(__('ops.group.content_control_plane'), ContentPackReleaseResource::getNavigationGroup());
    }

    public function test_article_and_taxonomy_resources_are_scoped_to_selected_org_only(): void
    {
        $selectedOrg = Organization::query()->create([
            'name' => 'Scoped Org',
            'owner_user_id' => 9111,
            'status' => 'active',
            'domain' => 'scoped-org.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        $otherOrg = Organization::query()->create([
            'name' => 'Other Org',
            'owner_user_id' => 9222,
            'status' => 'active',
            'domain' => 'other-org.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        $selectedArticle = Article::query()->create([
            'org_id' => $selectedOrg->id,
            'slug' => 'selected-article',
            'locale' => 'en',
            'title' => 'Selected Article',
            'content_md' => 'Selected article body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $otherArticle = Article::query()->create([
            'org_id' => $otherOrg->id,
            'slug' => 'other-article',
            'locale' => 'en',
            'title' => 'Other Article',
            'content_md' => 'Other article body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $selectedCategory = ArticleCategory::query()->create([
            'org_id' => $selectedOrg->id,
            'slug' => 'selected-category',
            'name' => 'Selected Category',
            'is_active' => true,
        ]);
        $otherCategory = ArticleCategory::query()->create([
            'org_id' => $otherOrg->id,
            'slug' => 'other-category',
            'name' => 'Other Category',
            'is_active' => true,
        ]);
        $selectedTag = ArticleTag::query()->create([
            'org_id' => $selectedOrg->id,
            'slug' => 'selected-tag',
            'name' => 'Selected Tag',
            'is_active' => true,
        ]);
        $otherTag = ArticleTag::query()->create([
            'org_id' => $otherOrg->id,
            'slug' => 'other-tag',
            'name' => 'Other Tag',
            'is_active' => true,
        ]);

        $request = Request::create('/ops/articles', 'GET');
        app()->instance('request', $request);

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, 9001, 'admin');
        app()->instance(OrgContext::class, $context);

        $articleIds = ArticleResource::getEloquentQuery()->pluck('id')->all();
        $categoryIds = ArticleCategoryResource::getEloquentQuery()->pluck('id')->all();
        $tagIds = ArticleTagResource::getEloquentQuery()->pluck('id')->all();

        $this->assertSame([(int) $selectedArticle->id], $articleIds);
        $this->assertNotContains((int) $otherArticle->id, $articleIds);
        $this->assertSame([(int) $selectedCategory->id], $categoryIds);
        $this->assertNotContains((int) $otherCategory->id, $categoryIds);
        $this->assertSame([(int) $selectedTag->id], $tagIds);
        $this->assertNotContains((int) $otherTag->id, $tagIds);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'cms_product_layer_'.Str::lower(Str::random(6)),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => (string) config('admin.guard', 'admin'),
            ]);

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSession(int $adminUserId): array
    {
        $org = Organization::query()->create([
            'name' => 'CMS Product Layer Org',
            'owner_user_id' => 9001,
            'status' => 'active',
            'domain' => 'cms-product-layer.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        return [
            'ops_org_id' => $org->id,
            'ops_admin_totp_verified_user_id' => $adminUserId,
        ];
    }

    private function seedCmsSurface(): void
    {
        $this->seedArticle();
        $this->seedGuide();
        $this->seedJob();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedArticle(array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'org_id' => 0,
            'slug' => 'release-article-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'Release Article',
            'excerpt' => 'Editorial release candidate.',
            'content_md' => 'Article body',
            'content_html' => '<p>Article body</p>',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedGuide(array $overrides = []): CareerGuide
    {
        return CareerGuide::query()->create(array_merge([
            'org_id' => 0,
            'guide_code' => 'release-guide-'.Str::lower(Str::random(6)),
            'slug' => 'release-guide-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'Release Guide',
            'excerpt' => 'Guide release candidate.',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedJob(array $overrides = []): CareerJob
    {
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'release-job-'.Str::lower(Str::random(6)),
            'slug' => 'release-job-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'Release Job',
            'excerpt' => 'Job release candidate.',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }
}
