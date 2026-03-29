<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ContentSearchPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\ContentSearchService;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentSearchPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_content_search_page_renders_for_content_read_admin_and_returns_cms_results(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Content Search Org');
        $otherOrg = $this->createOrganization('Other Content Search Org');

        Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'quarterly-review-article',
            'locale' => 'en',
            'title' => 'Quarterly Review Article',
            'excerpt' => 'Quarterly search article excerpt',
            'content_md' => 'Quarterly review content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        Article::query()->create([
            'org_id' => (int) $otherOrg->id,
            'slug' => 'other-org-article',
            'locale' => 'en',
            'title' => 'Other Org Article',
            'excerpt' => 'Should not appear',
            'content_md' => 'Other content',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        ArticleCategory::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'quarterly-category',
            'name' => 'Quarterly Category',
            'description' => 'Quarterly category description',
            'is_active' => true,
        ]);
        ArticleTag::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'quarterly-tag',
            'name' => 'Quarterly Tag',
            'is_active' => true,
        ]);
        CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'quarterly-guide',
            'slug' => 'quarterly-guide',
            'locale' => 'en',
            'title' => 'Quarterly Career Guide',
            'excerpt' => 'Quarterly guide excerpt',
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
        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'quarterly-job',
            'slug' => 'quarterly-job',
            'locale' => 'en',
            'title' => 'Quarterly Career Job',
            'excerpt' => 'Quarterly job excerpt',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-search')
            ->assertOk()
            ->assertSee('Content search')
            ->assertSee('Search by title / slug / excerpt / category / tag');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-search', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentSearchPage::class)
            ->assertOk()
            ->set('query', 'Quarterly')
            ->set('typeFilter', 'all')
            ->call('runSearch')
            ->assertSet('items.0.label', 'Quarterly Review Article');

        $result = app(ContentSearchService::class)->search('Quarterly', [(int) $selectedOrg->id]);

        $labels = array_map(static fn (array $item): string => (string) ($item['label'] ?? ''), $result['items']);

        $this->assertContains('Quarterly Review Article', $labels);
        $this->assertContains('Quarterly Category', $labels);
        $this->assertContains('Quarterly Tag', $labels);
        $this->assertContains('Quarterly Career Guide', $labels);
        $this->assertContains('Quarterly Career Job', $labels);
        $this->assertNotContains('Other Org Article', $labels);
    }

    public function test_content_search_bulk_archive_and_down_rank_apply_lifecycle_changes(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $selectedOrg = $this->createOrganization('Lifecycle Search Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'search-archive-article',
            'locale' => 'en',
            'title' => 'Search Archive Article',
            'excerpt' => 'Archive article excerpt',
            'content_md' => 'Archive article body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Search Archive Article SEO',
            'seo_description' => 'Search Archive Article SEO Description',
            'canonical_url' => 'https://example.test/articles/search-archive-article',
            'og_title' => 'Search Archive Article OG',
            'og_description' => 'Search Archive Article OG Description',
            'og_image_url' => 'https://example.test/images/search-archive-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $guide = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'search-guide-down-rank',
            'slug' => 'search-guide-down-rank',
            'locale' => 'en',
            'title' => 'Search Guide Down Rank',
            'excerpt' => 'Guide down-rank excerpt',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide down-rank body',
            'body_html' => '<p>Guide down-rank body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
            'published_at' => now()->subDay(),
        ]);
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guide->id,
            'seo_title' => 'Search Guide Down Rank SEO',
            'seo_description' => 'Search Guide Down Rank SEO Description',
            'canonical_url' => 'https://example.test/guides/search-guide-down-rank',
            'og_title' => 'Search Guide Down Rank OG',
            'og_description' => 'Search Guide Down Rank OG Description',
            'og_image_url' => 'https://example.test/images/search-guide-down-rank.png',
            'twitter_title' => 'Search Guide Down Rank Twitter',
            'twitter_description' => 'Search Guide Down Rank Twitter Description',
            'twitter_image_url' => 'https://example.test/images/search-guide-down-rank-twitter.png',
            'robots' => 'index,follow',
        ]);

        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'search-job-soft-delete',
            'slug' => 'search-job-soft-delete',
            'locale' => 'en',
            'title' => 'Search Job Soft Delete',
            'excerpt' => 'Job soft delete excerpt',
            'body_md' => 'Job soft delete body',
            'body_html' => '<p>Job soft delete body</p>',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => now()->subDay(),
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'seo_title' => 'Search Job Soft Delete SEO',
            'seo_description' => 'Search Job Soft Delete SEO Description',
            'canonical_url' => 'https://example.test/jobs/search-job-soft-delete',
            'og_title' => 'Search Job Soft Delete OG',
            'og_description' => 'Search Job Soft Delete OG Description',
            'og_image_url' => 'https://example.test/images/search-job-soft-delete.png',
            'twitter_title' => 'Search Job Soft Delete Twitter',
            'twitter_description' => 'Search Job Soft Delete Twitter Description',
            'twitter_image_url' => 'https://example.test/images/search-job-soft-delete-twitter.png',
            'robots' => 'index,follow',
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-search', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentSearchPage::class)
            ->assertOk()
            ->set('query', 'Search')
            ->set('typeFilter', 'all')
            ->set('bulkAction', 'archive')
            ->set('selectedTargets', ['article:'.$article->id])
            ->call('applyBulkAction');

        $article->refresh();
        $this->assertSame('archived', $article->lifecycle_state);
        $this->assertSame('draft', $article->status);
        $this->assertFalse($article->is_public);
        $this->assertFalse($article->is_indexable);
        $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale=en&org_id='.(int) $selectedOrg->id)
            ->assertNotFound();

        Livewire::test(ContentSearchPage::class)
            ->assertOk()
            ->set('query', 'Search')
            ->set('typeFilter', 'all')
            ->set('bulkAction', 'down_rank')
            ->set('selectedTargets', ['guide:'.$guide->id])
            ->call('applyBulkAction');

        $guide->refresh();
        $guideSeo = $guide->seoMeta()->first();
        $this->assertSame('downranked', $guide->lifecycle_state);
        $this->assertTrue($guide->is_public);
        $this->assertFalse($guide->is_indexable);
        $this->assertNotNull($guideSeo);
        $this->assertSame('noindex,follow', (string) $guideSeo->robots);

        Livewire::test(ContentSearchPage::class)
            ->assertOk()
            ->set('query', 'Search')
            ->set('typeFilter', 'all')
            ->set('bulkAction', 'soft_delete')
            ->set('selectedTargets', ['job:'.$job->id])
            ->call('applyBulkAction');

        $job->refresh();
        $jobSeo = $job->seoMeta()->first();
        $this->assertSame('soft_deleted', $job->lifecycle_state);
        $this->assertSame('draft', $job->status);
        $this->assertFalse($job->is_public);
        $this->assertFalse($job->is_indexable);
        $this->assertNotNull($jobSeo);
        $this->assertSame('noindex,nofollow', (string) $jobSeo->robots);
    }

    public function test_content_search_supports_lifecycle_and_stale_filters(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Lifecycle Filter Org');

        $archivedArticle = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'archived-filter-article',
            'locale' => 'en',
            'title' => 'Archived Filter Article',
            'excerpt' => 'Archived filter excerpt',
            'content_md' => 'Archived filter body',
            'status' => 'draft',
            'lifecycle_state' => 'archived',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $archivedArticle->forceFill(['updated_at' => now()->subDays(20)])->save();

        Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'active-filter-article',
            'locale' => 'en',
            'title' => 'Active Filter Article',
            'excerpt' => 'Active filter excerpt',
            'content_md' => 'Active filter body',
            'status' => 'draft',
            'lifecycle_state' => 'active',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/content-search', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(ContentSearchPage::class)
            ->assertOk()
            ->set('query', 'Filter')
            ->set('lifecycleFilter', 'archived')
            ->set('staleFilter', 'only_stale')
            ->call('runSearch')
            ->assertSee('Archived Filter Article')
            ->assertDontSee('Active Filter Article');
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
