<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Ops\SeoOperationsService;
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

final class SeoOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.frontend_url', 'https://example.test');
        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_seo_operations_page_requires_org_selection(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $this->withSession([
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo-operations')
            ->assertRedirectContains('/ops/select-org');
    }

    public function test_seo_operations_page_renders_operational_seo_and_growth_signals(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('SEO Metrics Org');
        $otherOrg = $this->createOrganization('Other SEO Org');

        $articleReady = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'seo-ready-article',
            'locale' => 'en',
            'title' => 'SEO Ready Article',
            'excerpt' => 'Ready article excerpt',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $articleReady->id,
            'locale' => 'en',
            'seo_title' => 'SEO Ready Article Title',
            'seo_description' => 'SEO Ready Article Description',
            'canonical_url' => 'https://example.test/en/articles/seo-ready-article',
            'og_title' => 'SEO Ready OG Title',
            'og_description' => 'SEO Ready OG Description',
            'og_image_url' => 'https://example.test/images/seo-ready-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $articleGap = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'seo-gap-article',
            'locale' => 'en',
            'title' => 'SEO Gap Article',
            'excerpt' => 'Gap article excerpt',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => Carbon::now()->subDay(),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $articleGap->id,
            'locale' => 'en',
            'seo_title' => 'SEO Gap Article Title',
            'seo_description' => '',
            'canonical_url' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'robots' => '',
            'is_indexable' => false,
        ]);

        $otherOrgArticle = Article::query()->create([
            'org_id' => (int) $otherOrg->id,
            'slug' => 'other-org-seo-article',
            'locale' => 'en',
            'title' => 'Other Org SEO Article',
            'excerpt' => 'Other org article',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $otherOrg->id,
            'article_id' => (int) $otherOrgArticle->id,
            'locale' => 'en',
            'seo_title' => 'Other Org SEO Title',
            'seo_description' => 'Other Org SEO Description',
            'canonical_url' => 'https://example.test/en/articles/other-org-seo-article',
            'og_title' => 'Other Org OG Title',
            'og_description' => 'Other Org OG Description',
            'og_image_url' => 'https://example.test/images/other-org-seo-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $guideReady = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'seo-ready-guide',
            'slug' => 'seo-ready-guide',
            'locale' => 'en',
            'title' => 'SEO Ready Guide',
            'excerpt' => 'Ready guide excerpt',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
            'published_at' => Carbon::now()->subDay(),
        ]);
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guideReady->id,
            'seo_title' => 'SEO Ready Guide Title',
            'seo_description' => 'SEO Ready Guide Description',
            'canonical_url' => 'https://example.test/en/career/guides/seo-ready-guide',
            'og_title' => 'SEO Ready Guide OG Title',
            'og_description' => 'SEO Ready Guide OG Description',
            'og_image_url' => 'https://example.test/images/seo-ready-guide.png',
            'twitter_title' => 'SEO Ready Guide Twitter Title',
            'twitter_description' => 'SEO Ready Guide Twitter Description',
            'twitter_image_url' => 'https://example.test/images/seo-ready-guide-twitter.png',
            'robots' => 'index,follow',
        ]);

        $guideGap = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'seo-gap-guide',
            'slug' => 'seo-gap-guide',
            'locale' => 'en',
            'title' => 'SEO Gap Guide',
            'excerpt' => 'Gap guide excerpt',
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
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guideGap->id,
            'seo_title' => 'SEO Gap Guide Title',
            'seo_description' => '',
            'canonical_url' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image_url' => '',
            'robots' => '',
        ]);

        $jobReady = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'seo-ready-job',
            'slug' => 'seo-ready-job',
            'locale' => 'en',
            'title' => 'SEO Ready Job',
            'excerpt' => 'Ready job excerpt',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subDay(),
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $jobReady->id,
            'seo_title' => 'SEO Ready Job Title',
            'seo_description' => 'SEO Ready Job Description',
            'canonical_url' => 'https://example.test/en/career/jobs/seo-ready-job',
            'og_title' => 'SEO Ready Job OG Title',
            'og_description' => 'SEO Ready Job OG Description',
            'og_image_url' => 'https://example.test/images/seo-ready-job.png',
            'twitter_title' => 'SEO Ready Job Twitter Title',
            'twitter_description' => 'SEO Ready Job Twitter Description',
            'twitter_image_url' => 'https://example.test/images/seo-ready-job-twitter.png',
            'robots' => 'index,follow',
        ]);

        $jobGap = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'seo-gap-job',
            'slug' => 'seo-gap-job',
            'locale' => 'en',
            'title' => 'SEO Gap Job',
            'excerpt' => 'Gap job excerpt',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'published_at' => Carbon::now()->subDay(),
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $jobGap->id,
            'seo_title' => 'SEO Gap Job Title',
            'seo_description' => '',
            'canonical_url' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image_url' => '',
            'robots' => '',
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo-operations')
            ->assertOk()
            ->assertSee('SEO operations')
            ->assertSee('Growth diagnostics')
            ->assertSee('SEO issue queue')
            ->assertSee('Article SEO gaps')
            ->assertSee('Career guide SEO gaps');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/seo-operations', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(SeoOperationsPage::class)
            ->assertOk()
            ->assertSet('headlineFields.0.value', '50% (1/2)')
            ->assertSet('headlineFields.1.value', '50% (2/4)')
            ->assertSet('headlineFields.2.value', '5')
            ->assertSet('headlineFields.3.value', '3')
            ->assertSet('headlineFields.4.value', '3')
            ->assertSet('coverageFields.0.value', '50% (1/2)')
            ->assertSet('coverageFields.1.value', '50% (1/2)')
            ->assertSet('coverageFields.2.value', '50% (1/2)')
            ->assertSet('coverageFields.3.value', '50% (1/2)')
            ->assertSet('coverageFields.4.value', '3')
            ->assertSet('growthFields.0.value', '2')
            ->assertSet('growthFields.1.value', '3')
            ->assertSet('growthFields.2.value', '1')
            ->assertSet('growthFields.3.value', '50% (3/6)')
            ->assertCount('issueQueue', 3)
            ->assertSee('Published with discovery blockers')
            ->assertDontSee('Other Org SEO Article');
    }

    public function test_seo_operations_can_apply_bulk_actions_to_fix_operational_gaps(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $selectedOrg = $this->createOrganization('SEO Fix Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'fix-me-article',
            'locale' => 'en',
            'title' => 'Fix Me Article',
            'excerpt' => 'Fix me article excerpt',
            'content_md' => 'Fix body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => Carbon::now()->subDay(),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => '',
            'seo_description' => '',
            'canonical_url' => 'https://example.test/wrong-article',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'robots' => '',
            'is_indexable' => true,
        ]);

        $guide = CareerGuide::query()->create([
            'org_id' => 0,
            'guide_code' => 'fix-guide',
            'slug' => 'fix-guide',
            'locale' => 'en',
            'title' => 'Fix Guide',
            'excerpt' => 'Fix guide excerpt',
            'category_slug' => 'career-planning',
            'body_md' => 'Guide body',
            'body_html' => '<p>Guide body</p>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
            'published_at' => Carbon::now()->subDay(),
        ]);
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guide->id,
            'seo_title' => '',
            'seo_description' => '',
            'canonical_url' => 'https://example.test/wrong-guide',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'twitter_title' => '',
            'twitter_description' => '',
            'twitter_image_url' => '',
            'robots' => '',
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/seo-operations', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(SeoOperationsPage::class)
            ->set('selectedTargets', [
                'article:'.$article->id,
                'guide:'.$guide->id,
            ])
            ->set('bulkAction', SeoOperationsService::ACTION_FILL_METADATA)
            ->call('applyBulkAction')
            ->assertSet('selectedTargets', [])
            ->assertCount('issueQueue', 2)
            ->set('selectedTargets', [
                'article:'.$article->id,
                'guide:'.$guide->id,
            ])
            ->set('bulkAction', SeoOperationsService::ACTION_SYNC_CANONICAL)
            ->call('applyBulkAction')
            ->set('selectedTargets', [
                'article:'.$article->id,
            ])
            ->set('bulkAction', SeoOperationsService::ACTION_MARK_INDEXABLE)
            ->call('applyBulkAction')
            ->set('issueFilter', SeoOperationsService::ISSUE_GROWTH)
            ->assertCount('issueQueue', 0)
            ->set('issueFilter', SeoOperationsService::ISSUE_SOCIAL)
            ->assertCount('issueQueue', 2)
            ->assertSee('Fix Guide')
            ->assertSee('Fix Me Article');

        $article->refresh();
        $guide->refresh();
        $articleSeo = $article->seoMeta()->firstOrFail();
        $guideSeo = $guide->seoMeta()->firstOrFail();

        $this->assertTrue((bool) $article->is_indexable);
        $this->assertSame('https://example.test/en/articles/fix-me-article', $articleSeo->canonical_url);
        $this->assertSame('index,follow', $articleSeo->robots);
        $this->assertSame('Fix Me Article', $articleSeo->seo_title);
        $this->assertSame('https://example.test/en/career/guides/fix-guide', $guideSeo->canonical_url);
        $this->assertSame('index,follow', $guideSeo->robots);
        $this->assertSame('Fix Guide', $guideSeo->seo_title);
    }

    public function test_social_only_gap_stays_seo_ready_but_remains_operable_in_issue_queue(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $selectedOrg = $this->createOrganization('SEO Social Gap Org');

        $article = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'social-gap-article',
            'locale' => 'en',
            'title' => 'Social Gap Article',
            'excerpt' => 'Social gap article excerpt',
            'content_md' => 'Social gap body',
            'cover_image_url' => 'https://example.test/images/social-gap-article.png',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        ArticleSeoMeta::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Social Gap Article Title',
            'seo_description' => 'Social Gap Article Description',
            'canonical_url' => 'https://example.test/en/articles/social-gap-article',
            'og_title' => 'Social Gap OG Title',
            'og_description' => 'Social Gap OG Description',
            'og_image_url' => '',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/seo-operations', 'POST'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        Livewire::test(SeoOperationsPage::class)
            ->assertSet('headlineFields.0.value', '100% (1/1)')
            ->set('issueFilter', SeoOperationsService::ISSUE_SOCIAL)
            ->assertCount('issueQueue', 1)
            ->assertSee('Social preview gaps')
            ->set('selectedTargets', [
                'article:'.$article->id,
            ])
            ->set('bulkAction', SeoOperationsService::ACTION_FILL_METADATA)
            ->call('applyBulkAction')
            ->assertSet('selectedTargets', [])
            ->assertCount('issueQueue', 0);

        $articleSeo = $article->seoMeta()->firstOrFail();

        $this->assertSame('https://example.test/images/social-gap-article.png', $articleSeo->og_image_url);
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
            \App\Http\Middleware\SetOpsLocale::SESSION_KEY => 'en',
            \App\Http\Middleware\SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ];
    }
}
