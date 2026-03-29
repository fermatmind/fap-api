<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentMetricsPage;
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
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentMetricsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_content_metrics_page_requires_org_selection(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-metrics')
            ->assertRedirectContains('/ops/select-org');
    }

    public function test_content_metrics_page_renders_selected_org_and_global_metrics(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Metrics Org');
        $otherOrg = $this->createOrganization('Other Metrics Org');

        $staleTimestamp = Carbon::now()->subDays(21);

        $staleArticle = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'stale-metrics-article',
            'locale' => 'en',
            'title' => 'Stale Metrics Article',
            'excerpt' => 'Old draft',
            'content_md' => 'Draft body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $staleArticle->forceFill(['updated_at' => $staleTimestamp])->save();

        Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'public-metrics-article',
            'locale' => 'en',
            'title' => 'Public Metrics Article',
            'excerpt' => 'Public article',
            'content_md' => 'Public body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);

        Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'visibility-gap-article',
            'locale' => 'en',
            'title' => 'Visibility Gap Article',
            'excerpt' => 'Gap article',
            'content_md' => 'Gap body',
            'status' => 'published',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => Carbon::now()->subDay(),
        ]);

        Article::query()->create([
            'org_id' => (int) $otherOrg->id,
            'slug' => 'other-org-metrics-article',
            'locale' => 'en',
            'title' => 'Other Org Metrics Article',
            'excerpt' => 'Other org article',
            'content_md' => 'Other body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);

        ArticleCategory::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'metrics-category',
            'name' => 'Metrics Category',
            'is_active' => true,
        ]);
        ArticleTag::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'metrics-tag',
            'name' => 'Metrics Tag',
            'is_active' => true,
        ]);

        $staleGuide = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'stale-guide',
            'slug' => 'stale-guide',
            'locale' => 'en',
            'title' => 'Stale Career Guide',
            'excerpt' => 'Guide draft',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
        ]);
        $staleGuide->forceFill(['updated_at' => $staleTimestamp])->save();

        CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'published-guide',
            'slug' => 'published-guide',
            'locale' => 'en',
            'title' => 'Published Career Guide',
            'excerpt' => 'Guide public',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide public body',
            'body_html' => '<p>Guide public body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
            'published_at' => Carbon::now()->subDay(),
        ]);

        $staleJob = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'stale-job',
            'slug' => 'stale-job',
            'locale' => 'en',
            'title' => 'Stale Career Job',
            'excerpt' => 'Job draft',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ]);
        $staleJob->forceFill(['updated_at' => $staleTimestamp])->save();

        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'published-job',
            'slug' => 'published-job',
            'locale' => 'en',
            'title' => 'Published Career Job',
            'excerpt' => 'Job public',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subDay(),
        ]);

        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'private-job',
            'slug' => 'private-job',
            'locale' => 'en',
            'title' => 'Private Career Job',
            'excerpt' => 'Job private',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subDay(),
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-metrics')
            ->assertOk()
            ->assertSee('Content metrics')
            ->assertSee('Metric snapshot')
            ->assertSee('Boundary health')
            ->assertSee('Freshness and pressure')
            ->assertSee('Current org stale drafts')
            ->assertSee('Publish gap watch')
            ->assertSee('Latest record:');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-metrics', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentMetricsPage::class)
            ->assertOk()
            ->assertSet('headlineFields.0.value', '3')
            ->assertSet('headlineFields.1.value', '1')
            ->assertSet('headlineFields.2.value', '5')
            ->assertSet('headlineFields.3.value', '3')
            ->assertSet('headlineFields.4.value', '3')
            ->assertSet('scopeFields.0.value', '2')
            ->assertSet('scopeFields.1.value', '0')
            ->assertSet('scopeFields.2.value', '33% (1/3)')
            ->assertSet('scopeFields.3.value', '67% (2/3)')
            ->assertSet('scopeFields.4.value', '40% (2/5)')
            ->assertSet('scopeFields.5.value', '2')
            ->assertSet('freshnessCards.0.value', '1')
            ->assertSet('freshnessCards.0.latest_title', 'Stale Metrics Article')
            ->assertSet('freshnessCards.1.value', '1')
            ->assertSet('freshnessCards.1.latest_title', 'Stale Career Guide')
            ->assertSet('freshnessCards.2.value', '1')
            ->assertSet('freshnessCards.2.latest_title', 'Stale Career Job')
            ->assertSet('freshnessCards.3.value', '2');
    }

    public function test_content_metrics_can_archive_stale_article_drafts(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $selectedOrg = $this->createOrganization('Stale Archive Org');

        $staleArticle = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'stale-archive-article',
            'locale' => 'en',
            'title' => 'Stale Archive Article',
            'excerpt' => 'Stale archive excerpt',
            'content_md' => 'Stale archive body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $staleArticle->forceFill(['updated_at' => Carbon::now()->subDays(30)])->save();

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-metrics', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentMetricsPage::class)
            ->assertOk()
            ->assertSet('freshnessCards.0.value', '1')
            ->call('archiveStale', 'article')
            ->assertSet('freshnessCards.0.value', '0');

        $staleArticle->refresh();
        $this->assertSame('archived', $staleArticle->lifecycle_state);
        $this->assertFalse($staleArticle->is_indexable);
    }

    public function test_content_metrics_excludes_archived_drafts_from_active_stale_pressure(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Archived Draft Metrics Org');

        $archivedDraft = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'archived-draft-metrics-article',
            'locale' => 'en',
            'title' => 'Archived Draft Metrics Article',
            'excerpt' => 'Archived draft excerpt',
            'content_md' => 'Archived draft body',
            'status' => 'draft',
            'lifecycle_state' => 'archived',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $archivedDraft->forceFill(['updated_at' => Carbon::now()->subDays(30)])->save();

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-metrics', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentMetricsPage::class)
            ->assertOk()
            ->assertSet('headlineFields.3.value', '0')
            ->assertSet('scopeFields.1.value', '1')
            ->assertSet('freshnessCards.0.value', '0');
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'owner_user_id' => random_int(1000, 9999),
            'status' => 'active',
            'domain' => Str::slug($name).'.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'ops_'.Str::lower(Str::random(6)),
            'email' => 'ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null],
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    /**
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $selectedOrg): array
    {
        return [
            'ops_org_id' => (int) $selectedOrg->id,
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];
    }
}
