<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\ContentPackRelease;
use App\Models\ContentPackVersion;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->seedReleaseSurface();

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
            ->assertSee('Editorial workspaces')
            ->assertSee('Content data');

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
            ->assertSee('Career Job');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-pack-releases')
            ->assertOk();

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

    private function seedReleaseSurface(): void
    {
        $version = ContentPackVersion::query()->create([
            'id' => (string) Str::uuid(),
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'content_2026_03',
            'dir_version_alias' => 'MBTI-CN-v0.3-cms-workspace',
            'source_type' => 'upload',
            'source_ref' => 'private://content/cms-workspace/version.zip',
            'sha256' => str_repeat('a', 64),
            'manifest_json' => ['pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3'],
            'extracted_rel_path' => 'content/cms-workspace/version',
            'created_by' => 'ops_admin',
        ]);

        ContentPackRelease::query()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 1,
            'action' => 'release',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'MBTI-CN-v0.3-cms-workspace',
            'from_version_id' => $version->id,
            'to_version_id' => $version->id,
            'from_pack_id' => $version->pack_id,
            'to_pack_id' => $version->pack_id,
            'status' => 'pending',
            'probe_ok' => true,
            'probe_json' => ['ok' => true],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
