<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\SeoDashboardAccessPage;
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
use App\Support\Rbac\RbacService;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_workspace_query_is_recoverable_and_unknown_values_fail_to_overview(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $organization = $this->createOrganization('SEO Workspace Route Org');
        $client = $this->withSession($this->opsSession($admin, $organization))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));

        $client->get('/ops/seo-operations?workspace=content')
            ->assertOk()
            ->assertSee('data-workspace="content"', false)
            ->assertSee('data-state="production_unproven"', false);

        $client->get('/ops/seo-operations?workspace=unknown')
            ->assertOk()
            ->assertSee('data-workspace="overview"', false);
    }

    public function test_seo_operations_page_renders_operational_seo_and_growth_signals(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('SEO Metrics Org');
        $otherOrg = $this->createOrganization('Other SEO Org');

        $articleReady = Article::query()->create([
            'org_id' => 0,
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
            'org_id' => 0,
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
            'org_id' => 0,
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
            'org_id' => 0,
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
            ->assertSee('ops-seo-page-header', false)
            ->assertSee('ops-seo-commandbar', false)
            ->assertSee('ops-seo-council-nav', false)
            ->assertSee('ops-trust-strip', false)
            ->assertSee('ops-seo-workbench-home', false)
            ->assertSee('data-contract-state="MEASUREMENT_HOLD"', false)
            ->assertSee('data-default-decision-count="3"', false)
            ->assertSee('data-max-decision-count="5"', false)
            ->assertSee('id="ops-seo-issue-filter"', false)
            ->assertSee('<select id="ops-seo-issue-filter"', false)
            ->assertDontSee('ops-seo-intro', false)
            ->assertSee('28-day visibility trend')
            ->assertSee('Priority decisions')
            ->assertSee('Weekly decisions are on MEASUREMENT_HOLD')
            ->assertSee('Data sources & freshness')
            ->assertDontSee('Growth diagnostics')
            ->assertDontSee('Current query snapshot')
            ->assertDontSee('vs last week')
            ->assertDontSee('updated recently')
            ->assertDontSee('SEO issue queue')
            ->assertDontSee('Article SEO gaps')
            ->assertDontSee('Career guide SEO gaps');

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
            ->assertSet('headlineFields.0.scope', 'global_articles')
            ->assertSet('headlineFields.0.source', 'primary.articles')
            ->assertSet('headlineFields.0.freshness', 'fresh')
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
            ->set('activeAutomationSection', 'operations')
            ->set('activeWorkspace', 'automation')
            ->assertSee('Published with discovery blockers')
            ->assertDontSee('Other Org SEO Article')
            ->set('scopeFilter', 'global_articles')
            ->assertCount('issueQueue', 1)
            ->set('scopeFilter', 'global_career')
            ->assertCount('issueQueue', 2)
            ->set('scopeFilter', 'combined')
            ->assertCount('issueQueue', 3)
            ->set('localeFilter', 'zh-CN')
            ->assertSet('scopeSummary.0.count', 0)
            ->assertSet('scopeSummary.1.count', 0)
            ->set('localeFilter', 'en')
            ->set('statusFilter', 'draft')
            ->assertSet('scopeSummary.0.count', 0)
            ->assertSet('scopeSummary.1.count', 1)
            ->set('statusFilter', 'all')
            ->set('activeWorkspace', 'performance')
            ->assertDontSee('The SEO read model is unavailable in this environment.')
            ->assertDontSee('12,840')
            ->set('activeWorkspace', 'technical')
            ->assertSee('Root-cause clusters')
            ->assertSee('Root-cause clusters are not production-proven')
            ->set('activeWorkspace', 'performance')
            ->assertSee('The opportunity read model is unavailable in this environment.');
    }

    public function test_seo_operations_can_apply_bulk_actions_to_fix_operational_gaps(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $selectedOrg = $this->createOrganization('SEO Fix Org');

        $article = Article::query()->create([
            'org_id' => 0,
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
            'org_id' => 0,
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
            ->set('activeAutomationSection', 'operations')
            ->set('activeWorkspace', 'automation')
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
            'org_id' => 0,
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
            'org_id' => 0,
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
            ->set('activeAutomationSection', 'operations')
            ->set('activeWorkspace', 'automation')
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

    public function test_seo_operations_issue_queue_is_capped(): void
    {
        $selectedOrg = $this->createOrganization('SEO Bound Org');

        foreach (range(1, SeoOperationsService::MAX_ISSUE_QUEUE_ITEMS + 10) as $index) {
            $article = Article::query()->create([
                'org_id' => 0,
                'slug' => 'seo-bound-article-'.$index,
                'locale' => 'en',
                'title' => 'SEO Bound Article '.$index,
                'excerpt' => 'Bound article excerpt',
                'content_md' => 'Bound body',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'published_at' => Carbon::now()->subDay(),
            ]);
            ArticleSeoMeta::query()->create([
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'locale' => 'en',
                'seo_title' => '',
                'seo_description' => '',
                'canonical_url' => '',
                'og_title' => '',
                'og_description' => '',
                'og_image_url' => '',
                'robots' => '',
                'is_indexable' => false,
            ]);
        }

        $queue = app(SeoOperationsService::class)->buildIssueQueue([0], 'article', 'all');

        $this->assertCount(SeoOperationsService::MAX_ISSUE_QUEUE_ITEMS, $queue['items']);
    }

    public function test_seo_operations_service_rejects_tenant_article_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('global org_id=0 authority');

        app(SeoOperationsService::class)->buildIssueQueue([42], 'article', 'all');
    }

    public function test_export_report_returns_real_csv_download_with_headers_and_content(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('SEO Export Org');

        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => 'export-article',
            'locale' => 'en',
            'title' => '=HYPERLINK("https://example.invalid","x")',
            'excerpt' => 'Export excerpt',
            'content_md' => 'Body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::now()->subDay(),
        ]);
        // Intentionally leave SEO meta gapped so the article surfaces in the
        // issue queue and its real title is exported.
        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => '',
            'seo_description' => '',
            'canonical_url' => '',
            'og_title' => '',
            'og_description' => '',
            'og_image_url' => '',
            'robots' => '',
            'is_indexable' => true,
        ]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/seo-operations', 'GET'));

        $context = app(OrgContext::class);
        $context->set((int) $selectedOrg->id, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
        $response = Livewire::test(SeoOperationsPage::class)
            ->instance()
            ->exportReport();

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('seo-operations-', $disposition);
        $this->assertStringContainsString('.csv', $disposition);

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('section,label,value,suffix,tone,scope,source,collected_at,source_updated_at,freshness,locale_filter,status_filter', $body);
        $this->assertStringContainsString('issue_queue', $body);
        $this->assertStringContainsString('issue_clusters,cluster_uid,root_cause,content_type,template,field,severity,affected_url_count,issue_count,evidence_count,priority_score,priority_impact,priority_confidence,priority_effort,priority_reason,gsc_included,gsc_clicks,gsc_impressions,status,source,recommendation', $body);
        $this->assertStringContainsString('cluster_urls,cluster_uid,issue_uid,canonical_path,locale,page_entity_type,severity,status,source,evidence_fingerprint', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
        $this->assertStringNotContainsString(',"=HYPERLINK', $body);
    }

    public function test_export_report_is_forbidden_without_read_permission(): void
    {
        $admin = $this->createAdminWithPermissions([]);

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create('/ops/seo-operations', 'GET'));

        $page = new SeoOperationsPage;

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $page->exportReport();
    }

    public function test_issue_queue_renders_in_server_driven_pages_of_25_without_truncating_export_state(): void
    {
        $page = new SeoOperationsPage;
        $page->issueQueue = array_map(
            static fn (int $index): array => ['selection_key' => 'article:'.$index],
            range(1, 63),
        );

        $this->assertCount(25, $page->visibleIssueQueue());
        $this->assertSame('article:1', $page->visibleIssueQueue()[0]['selection_key']);

        $page->nextIssueQueuePage();
        $this->assertSame(2, $page->issueQueuePage);
        $this->assertCount(25, $page->visibleIssueQueue());
        $this->assertSame('article:26', $page->visibleIssueQueue()[0]['selection_key']);

        $page->nextIssueQueuePage();
        $this->assertSame(3, $page->issueQueuePage);
        $this->assertCount(13, $page->visibleIssueQueue());
        $this->assertCount(63, $page->issueQueue);
    }

    public function test_saved_views_restore_the_complete_workspace_state(): void
    {
        $page = new SeoOperationsPage;

        $page->applySavedView('global_article_blockers');

        $this->assertSame('global_article_blockers', $page->savedView);
        $this->assertSame('automation', $page->activeWorkspace);
        $this->assertSame('global_articles', $page->scopeFilter);
        $this->assertSame('article', $page->typeFilter);
        $this->assertSame(SeoOperationsService::ISSUE_GROWTH, $page->issueFilter);
        $this->assertSame('all', $page->localeFilter);
        $this->assertSame('published', $page->statusFilter);
        $this->assertSame('priority', $page->sortBy);
        $this->assertSame('workflow', $page->displayPreset);
        $this->assertSame(['priority', 'impact', 'owner', 'sla', 'status', 'action'], $page->displayFields);

        $page->applySavedView('global_career_gaps');

        $this->assertSame('global_career', $page->scopeFilter);
        $this->assertSame('affected_urls', $page->sortBy);
        $this->assertSame('decision', $page->displayPreset);
    }

    public function test_seo_workspace_declares_bounded_rendering_and_overflow_contracts(): void
    {
        $view = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));
        $theme = (string) file_get_contents(resource_path('css/filament/ops/theme.css'));

        $this->assertSame(25, SeoOperationsPage::ISSUE_QUEUE_PER_PAGE);
        $this->assertLessThanOrEqual(45, SeoOperationsPage::MAX_INITIAL_QUERY_COUNT);
        $this->assertLessThanOrEqual(1500, SeoOperationsPage::MAX_INITIAL_RESPONSE_MS);
        $this->assertLessThanOrEqual(100, SeoOperationsPage::MAX_RENDERED_TABLE_ROWS);
        $this->assertStringContainsString('role="toolbar"', $view);
        $this->assertStringContainsString('data-query-budget=', $view);
        $this->assertStringContainsString('data-response-budget-ms=', $view);
        $this->assertStringContainsString('data-dom-row-budget=', $view);
        $this->assertStringContainsString('wire:model.live="sortBy"', $view);
        $this->assertStringContainsString('wire:model.live="displayPreset"', $view);
        $this->assertStringContainsString('.ops-seo-workspace-panel .ops-table-shell', $theme);
        $this->assertStringContainsString('overflow-x: auto', $theme);
        $this->assertStringContainsString('overflow-x: clip', $theme);
    }

    public function test_initial_seo_workspace_response_stays_within_query_and_time_budgets(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $organization = $this->createOrganization('SEO Performance Budget Org');
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });
        $startedAt = hrtime(true);

        $this->withSession($this->opsSession($admin, $organization))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo-operations')
            ->assertOk();

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        $this->assertLessThanOrEqual(SeoOperationsPage::MAX_INITIAL_QUERY_COUNT, $queryCount);
        $this->assertLessThanOrEqual(SeoOperationsPage::MAX_INITIAL_RESPONSE_MS, $elapsedMs);
    }

    public function test_request_permission_cache_is_invalidated_by_role_changes(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $roleName = (string) $admin->roles()->value('name');
        $rbac = app(RbacService::class);

        $this->assertTrue($admin->hasPermission(PermissionNames::ADMIN_CONTENT_READ));
        $rbac->revokeRole($admin, $roleName);
        $this->assertFalse($admin->hasPermission(PermissionNames::ADMIN_CONTENT_READ));
        $rbac->grantRole($admin, $roleName);
        $this->assertTrue($admin->hasPermission(PermissionNames::ADMIN_CONTENT_READ));
    }

    public function test_legacy_seo_route_redirects_to_the_canonical_operations_entry_without_rendering_a_dashboard(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_OPS_READ]);
        $organization = $this->createOrganization('Legacy SEO Redirect Org');

        $this->withSession($this->opsSession($admin, $organization))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/seo?locale=en')
            ->assertRedirect('/ops/seo-operations');

        $legacySource = (string) file_get_contents(app_path('Filament/Ops/Pages/SeoDashboardAccessPage.php'));
        $this->assertStringContainsString('shouldRegisterNavigation = false', $legacySource);
        $this->assertStringContainsString('SeoOperationsPage::getUrl()', $legacySource);
        $this->assertStringNotContainsString('SeoDashboardOverviewReadService', $legacySource);
        $this->assertStringNotContainsString('statusCards', $legacySource);
        $this->assertFileDoesNotExist(resource_path('views/filament/ops/pages/seo-dashboard-access.blade.php'));
    }

    public function test_canonical_entry_is_the_only_registered_seo_navigation_and_all_workspaces_use_real_state_contracts(): void
    {
        $legacyNavigation = new \ReflectionProperty(SeoDashboardAccessPage::class, 'shouldRegisterNavigation');
        $page = (string) file_get_contents(app_path('Filament/Ops/Pages/SeoOperationsPage.php'));
        $service = (string) file_get_contents(app_path('Services/Ops/SeoOperationsReadService.php'));

        $this->assertFalse($legacyNavigation->getValue());
        foreach (['overview', 'performance', 'technical', 'url-truth', 'content', 'automation'] as $workspace) {
            $this->assertStringContainsString("'{$workspace}'", $page);
        }
        foreach (['state', 'source', 'observed_at', 'updated_at', 'unavailable_reason'] as $field) {
            $this->assertStringContainsString("'{$field}'", $service);
        }
        $this->assertStringContainsString("str_contains(\$normalized, 'hash')", $service);
        $this->assertStringContainsString("str_ends_with(\$normalized, '_sha')", $service);
        $this->assertStringContainsString('global_search_submission_disabled', $service);
        $this->assertStringContainsString("'not_implemented'", $service);
        $this->assertStringContainsString("'measurement_hold'", $service);
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
