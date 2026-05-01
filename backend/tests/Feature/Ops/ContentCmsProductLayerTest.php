<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Filament\Ops\Pages\ContentOverviewPage;
use App\Filament\Ops\Pages\ContentReleasePage;
use App\Filament\Ops\Pages\ContentWorkspacePage;
use App\Filament\Ops\Pages\EditorialOperationsPage;
use App\Filament\Ops\Pages\EditorialReviewPage;
use App\Filament\Ops\Resources\ArticleCategoryResource;
use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\ArticleTagResource;
use App\Filament\Ops\Resources\CareerGuideResource;
use App\Filament\Ops\Resources\CareerJobResource;
use App\Filament\Ops\Resources\ContentPackReleaseResource;
use App\Filament\Ops\Resources\ContentPackVersionResource;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\EditorialReview;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ContentCmsProductLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

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
            ->get('/ops/editorial-operations')
            ->assertOk()
            ->assertSee('Editorial operations')
            ->assertSee('Operations snapshot')
            ->assertSee('Articles')
            ->assertSee('Career Guides')
            ->assertSee('Career Jobs');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertForbidden();

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
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

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Editorial review')
            ->assertSee('Review queue');
    }

    public function test_approval_review_admin_can_open_editorial_review_but_not_release_surface(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Editorial review');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertForbidden();

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/create')
            ->assertForbidden();
    }

    public function test_legacy_publish_permission_still_allows_write_and_release_as_compatibility_bridge(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_PUBLISH,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $article = $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
            'title' => 'Legacy Publish Queue Article',
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/create')
            ->assertOk();

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertOk()
            ->assertSee('Release workspace')
            ->assertSee('Legacy Publish Queue Article');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->approveRecord($admin, (int) $session['ops_org_id'], 'article', $article);
        ArticleResource::releaseRecord($article);

        $article->refresh();
        $this->assertSame('published', $article->status);
    }

    public function test_content_release_admin_can_open_release_surface_but_not_article_create(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $session = $this->opsSession((int) $admin->id);

        $article = $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
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

        $this->approveRecord($admin, (int) $session['ops_org_id'], 'article', $article);
        $this->approveRecord($admin, (int) $session['ops_org_id'], 'guide', $guide);
        $this->approveRecord($admin, (int) $session['ops_org_id'], 'job', $job);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Editorial review')
            ->assertSee('Review snapshot')
            ->assertSee('Review queue')
            ->assertSee('Release Queue')
            ->assertSee('Release Queue Article')
            ->assertSee('Release Queue Guide')
            ->assertSee('Release Queue Job');

        $releaseResponse = $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertOk()
            ->assertSee('Release workspace')
            ->assertSee('Filters')
            ->assertSee('Article')
            ->assertSee('Career Guide')
            ->assertSee('Career Job')
            ->assertSee('Review state');

        $releaseMainHtml = Str::between($releaseResponse->content(), '<main', '</main>') ?: $releaseResponse->content();
        $this->assertStringNotContainsString('Content Pack Release', $releaseMainHtml);
        $this->assertStringNotContainsString('Content Pack Version', $releaseMainHtml);

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
        $session = $this->opsSession((int) $admin->id);

        $article = $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
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
        $this->approveRecord($admin, (int) $session['ops_org_id'], 'article', $article);
        $this->approveRecord($admin, (int) $session['ops_org_id'], 'guide', $guide);
        $this->approveRecord($admin, (int) $session['ops_org_id'], 'job', $job);

        ArticleResource::releaseRecord($article);
        CareerGuideResource::releaseRecord($guide);
        CareerJobResource::releaseRecord($job);

        $article->refresh();
        $guide->refresh();
        $job->refresh();

        $this->assertSame('published', $article->status);
        $this->assertTrue($article->is_public);
        $this->assertNotNull($article->published_at);

        $this->assertSame(CareerGuide::STATUS_PUBLISHED, $guide->status);
        $this->assertTrue($guide->is_public);
        $this->assertNotNull($guide->published_at);

        $this->assertSame(CareerJob::STATUS_PUBLISHED, $job->status);
        $this->assertTrue($job->is_public);
        $this->assertNotNull($job->published_at);

        $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale=en&org_id='.(int) $session['ops_org_id'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('article.slug', $article->slug);
    }

    public function test_article_release_marks_translation_published_without_changing_source_linkage(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $selectedOrg = Organization::query()->create([
            'name' => 'Selected Ops Org',
            'owner_user_id' => 9444,
            'status' => 'active',
            'domain' => 'selected-ops.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
        $selectedOrgId = (int) $selectedOrg->id;

        $source = $this->seedArticle([
            'org_id' => 0,
            'slug' => 'source-article-'.Str::lower(Str::random(6)),
            'locale' => 'zh-CN',
            'title' => '中文源文',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_locale' => 'zh-CN',
            'translation_group_id' => 'article-source-fixture',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subDay(),
        ]);

        $translation = $this->seedArticle([
            'org_id' => 0,
            'slug' => 'translation-article-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'English review draft',
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'source_locale' => 'zh-CN',
            'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'source_version_hash' => (string) $source->source_version_hash,
            'translated_from_version_hash' => (string) $source->source_version_hash,
            'title' => 'English review draft',
            'excerpt' => 'Reviewed English excerpt.',
            'content_md' => 'Reviewed English body.',
            'seo_title' => 'English SEO title',
            'seo_description' => 'English SEO description',
        ]);

        $translation->forceFill(['working_revision_id' => (int) $revision->id])->save();

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->approveRecord($admin, $selectedOrgId, 'article', $translation);
        $this->setOpsContext($selectedOrgId, $admin, '/ops/content-release');

        ArticleResource::releaseRecord($translation);

        $translation->refresh();
        $revision->refresh();
        $source->refresh();

        $this->assertSame('published', $translation->status);
        $this->assertTrue($translation->is_public);
        $this->assertSame(Article::TRANSLATION_STATUS_PUBLISHED, $translation->translation_status);
        $this->assertSame((int) $revision->id, (int) $translation->published_revision_id);
        $this->assertSame((int) $source->id, (int) $translation->source_article_id);
        $this->assertSame((string) $source->translation_group_id, (string) $translation->translation_group_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $revision->revision_status);
        $this->assertSame(0, (int) $revision->org_id);
        $this->assertSame(Article::TRANSLATION_STATUS_SOURCE, $source->translation_status);
        $this->assertSame('published', $source->status);
    }

    public function test_article_release_rejects_unapproved_translation_revision(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $session = $this->opsSession((int) $admin->id);
        $selectedOrgId = (int) $session['ops_org_id'];

        $source = $this->seedArticle([
            'org_id' => $selectedOrgId,
            'slug' => 'release-source-'.Str::lower(Str::random(6)),
            'locale' => 'zh-CN',
            'title' => '中文源文',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_locale' => 'zh-CN',
            'translation_group_id' => 'article-release-review-gate',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subDay(),
        ]);

        $translation = $this->seedArticle([
            'org_id' => $selectedOrgId,
            'slug' => 'release-translation-'.Str::lower(Str::random(6)),
            'locale' => 'en',
            'title' => 'English review draft',
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'source_locale' => 'zh-CN',
            'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => $selectedOrgId,
            'article_id' => (int) $translation->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'source_version_hash' => (string) $source->source_version_hash,
            'translated_from_version_hash' => (string) $source->source_version_hash,
            'title' => 'English review draft',
            'excerpt' => 'Reviewed English excerpt.',
            'content_md' => 'Reviewed English body.',
        ]);
        $translation->forceFill(['working_revision_id' => (int) $revision->id])->save();

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->approveRecord($admin, $selectedOrgId, 'article', $translation);
        $this->setOpsContext($selectedOrgId, $admin, '/ops/content-release');

        $this->expectException(AuthorizationException::class);

        ArticleResource::releaseRecord($translation);
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

    public function test_content_overview_current_org_cards_do_not_count_global_article_taxonomy_rows(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);

        $session = $this->opsSession((int) $admin->id);
        $selectedOrgId = (int) $session['ops_org_id'];

        Article::query()->create([
            'org_id' => $selectedOrgId,
            'slug' => 'selected-org-article',
            'locale' => 'en',
            'title' => 'Selected Org Article',
            'content_md' => 'Selected article body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        Article::query()->create([
            'org_id' => 0,
            'slug' => 'global-article',
            'locale' => 'en',
            'title' => 'Global Article',
            'content_md' => 'Global article body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        ArticleCategory::query()->create([
            'org_id' => $selectedOrgId,
            'slug' => 'selected-category',
            'name' => 'Selected Category',
            'is_active' => true,
        ]);
        ArticleCategory::query()->create([
            'org_id' => 0,
            'slug' => 'global-category',
            'name' => 'Global Category',
            'is_active' => true,
        ]);
        ArticleTag::query()->create([
            'org_id' => $selectedOrgId,
            'slug' => 'selected-tag',
            'name' => 'Selected Tag',
            'is_active' => true,
        ]);
        ArticleTag::query()->create([
            'org_id' => 0,
            'slug' => 'global-tag',
            'name' => 'Global Tag',
            'is_active' => true,
        ]);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-overview')
            ->assertOk()
            ->assertSee('Current org editorial')
            ->assertSee('1', false)
            ->assertSee('Current org taxonomy')
            ->assertSee('2', false)
            ->assertDontSee('Global Article');
    }

    public function test_cms_workspace_pages_require_org_selection_before_rendering(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $session = [
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];

        foreach ([
            '/ops/content-overview',
            '/ops/content-workspace',
            '/ops/editorial-review',
            '/ops/content-release',
            '/ops/editorial-operations',
        ] as $path) {
            $this->withSession($session)
                ->actingAs($admin, (string) config('admin.guard', 'admin'))
                ->get($path)
                ->assertRedirectContains('/ops/select-org');
        }
    }

    public function test_navigation_groups_match_cms_bootstrap_blueprint(): void
    {
        $this->assertSame(__('ops.group.content_overview'), ContentOverviewPage::getNavigationGroup());
        $this->assertSame(__('ops.group.content'), ContentWorkspacePage::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), EditorialOperationsPage::getNavigationGroup());
        $this->assertSame(__('ops.group.translation'), ArticleTranslationOpsPage::getNavigationGroup());
        $this->assertSame(__('ops.group.content_release'), EditorialReviewPage::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), ArticleResource::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), CareerGuideResource::getNavigationGroup());
        $this->assertSame(__('ops.group.editorial'), CareerJobResource::getNavigationGroup());
        $this->assertSame(__('ops.group.taxonomy'), ArticleCategoryResource::getNavigationGroup());
        $this->assertSame(__('ops.group.taxonomy'), ArticleTagResource::getNavigationGroup());
        $this->assertSame(__('ops.group.content_release'), ContentReleasePage::getNavigationGroup());
        $this->assertSame(__('ops.group.content_control_plane'), ContentPackVersionResource::getNavigationGroup());
        $this->assertSame(__('ops.group.content_control_plane'), ContentPackReleaseResource::getNavigationGroup());
        $this->assertFalse(Route::has('filament.ops.resources.content-releases.index'));
    }

    public function test_editorial_review_surface_marks_missing_inputs_for_attention(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
            'title' => 'Review Article',
            'excerpt' => null,
            'content_md' => '',
            'status' => 'draft',
        ]);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Review Article')
            ->assertSee('Needs attention')
            ->assertSee('Missing: body, excerpt');
    }

    public function test_editorial_review_approvals_are_persisted_and_gate_release_queue(): void
    {
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);
        $publisher = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $session = $this->opsSession((int) $owner->id);
        $selectedOrgId = (int) $session['ops_org_id'];

        $article = $this->seedArticle([
            'org_id' => $selectedOrgId,
            'title' => 'Approved Release Article',
            'excerpt' => 'Ready excerpt',
            'content_md' => 'Ready body',
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => $selectedOrgId,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Approved Release Article SEO Title',
            'seo_description' => 'Approved Release Article SEO Description',
            'canonical_url' => 'https://example.test/articles/approved-release-article',
            'og_title' => 'Approved Release Article OG Title',
            'og_description' => 'Approved Release Article OG Description',
            'og_image_url' => 'https://example.test/images/approved-release-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->setOpsContext($selectedOrgId, $owner, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->set('ownerAssignments.article_'.$article->id, (string) $owner->id)
            ->call('assignOwnerItem', 'article', (int) $article->id);

        $this->setOpsContext($selectedOrgId, $reviewer, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->set('reviewerAssignments.article_'.$article->id, (string) $reviewer->id)
            ->call('assignReviewerItem', 'article', (int) $article->id);

        $this->setOpsContext($selectedOrgId, $owner, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->call('submitItem', 'article', (int) $article->id);

        $this->setOpsContext($selectedOrgId, $reviewer, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->call('approveItem', 'article', (int) $article->id);

        $workflow = EditorialReview::withoutGlobalScopes()
            ->where('content_type', 'article')
            ->where('content_id', (int) $article->id)
            ->first();

        $this->assertNotNull($workflow);
        $this->assertSame((int) $owner->id, (int) $workflow->owner_admin_user_id);
        $this->assertSame((int) $reviewer->id, (int) $workflow->reviewer_admin_user_id);
        $this->assertSame(EditorialReview::STATE_APPROVED, (string) $workflow->workflow_state);

        $audit = AuditLog::withoutGlobalScopes()
            ->where('org_id', $selectedOrgId)
            ->where('action', 'editorial_review_approved')
            ->where('target_type', 'article')
            ->where('target_id', (string) $article->id)
            ->first();

        $this->assertNotNull($audit);

        $this->withSession($this->opsSessionForOrg((int) $publisher->id, $selectedOrgId))
            ->actingAs($publisher, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertOk()
            ->assertSee('Approved')
            ->assertSee('Publish');

        $this->setOpsContext($selectedOrgId, $publisher, '/ops/content-release');

        Livewire::test(ContentReleasePage::class)
            ->assertOk()
            ->call('releaseItem', 'article', (int) $article->id);

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertTrue($article->is_public);
    }

    public function test_reassigning_reviewer_invalidates_existing_approval_until_resubmitted(): void
    {
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);
        $replacementReviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);
        $publisher = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $session = $this->opsSession((int) $owner->id);
        $selectedOrgId = (int) $session['ops_org_id'];

        $article = $this->seedArticle([
            'org_id' => $selectedOrgId,
            'title' => 'Reviewer Reassignment Article',
            'excerpt' => 'Ready excerpt',
            'content_md' => 'Ready body',
            'status' => 'draft',
            'is_public' => false,
            'published_at' => null,
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => $selectedOrgId,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Reviewer Reassignment Article SEO Title',
            'seo_description' => 'Reviewer Reassignment Article SEO Description',
            'canonical_url' => 'https://example.test/articles/reviewer-reassignment-article',
            'og_title' => 'Reviewer Reassignment Article OG Title',
            'og_description' => 'Reviewer Reassignment Article OG Description',
            'og_image_url' => 'https://example.test/images/reviewer-reassignment-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->routeRecordIntoReview($selectedOrgId, $owner, $reviewer, 'article', $article);
        $this->setOpsContext($selectedOrgId, $reviewer, '/ops/editorial-review');
        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, 'article', $article);

        $this->setOpsContext($selectedOrgId, $replacementReviewer, '/ops/editorial-review');
        EditorialReviewAudit::assignReviewer((int) $replacementReviewer->id, 'article', $article);

        $workflow = EditorialReview::withoutGlobalScopes()
            ->where('content_type', 'article')
            ->where('content_id', (int) $article->id)
            ->first();

        $this->assertNotNull($workflow);
        $this->assertSame(EditorialReviewAudit::STATE_READY, (string) $workflow->workflow_state);
        $this->assertNull($workflow->reviewed_at);
        $this->assertNull($workflow->reviewed_by_admin_user_id);
        $this->assertSame((int) $replacementReviewer->id, (int) $workflow->reviewer_admin_user_id);

        $this->withSession($this->opsSessionForOrg((int) $publisher->id, $selectedOrgId))
            ->actingAs($publisher, (string) config('admin.guard', 'admin'))
            ->get('/ops/content-release')
            ->assertOk()
            ->assertSee('Ready')
            ->assertSee('Reviewer Reassignment Article');

        $this->setOpsContext($selectedOrgId, $publisher, '/ops/content-release');

        $this->expectException(AuthorizationException::class);

        ArticleResource::releaseRecord($article);
    }

    public function test_editorial_review_cannot_approve_record_before_submission(): void
    {
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);

        $session = $this->opsSession((int) $owner->id);
        $selectedOrgId = (int) $session['ops_org_id'];

        $article = $this->seedArticle([
            'org_id' => $selectedOrgId,
            'title' => 'Premature Approval Article',
            'excerpt' => 'Ready excerpt',
            'content_md' => 'Ready body',
            'status' => 'draft',
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => $selectedOrgId,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Premature Approval Article SEO Title',
            'seo_description' => 'Premature Approval Article SEO Description',
            'canonical_url' => 'https://example.test/articles/premature-approval-article',
            'og_title' => 'Premature Approval Article OG Title',
            'og_description' => 'Premature Approval Article OG Description',
            'og_image_url' => 'https://example.test/images/premature-approval-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->setOpsContext($selectedOrgId, $owner, '/ops/editorial-review');
        EditorialReviewAudit::assignOwner((int) $owner->id, 'article', $article);

        $this->setOpsContext($selectedOrgId, $reviewer, '/ops/editorial-review');
        EditorialReviewAudit::assignReviewer((int) $reviewer->id, 'article', $article);

        $this->expectException(AuthorizationException::class);

        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, 'article', $article);
    }

    public function test_editorial_review_requires_real_seo_meta_before_marking_record_ready(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);

        $session = $this->opsSession((int) $admin->id);

        $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
            'title' => 'Seo Checklist Article',
            'excerpt' => 'Seo excerpt',
            'content_md' => 'Seo body',
            'status' => 'draft',
        ]);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Seo Checklist Article')
            ->assertSee('Needs attention')
            ->assertSee('canonical url')
            ->assertSee('og title');
    }

    public function test_editorial_review_can_request_changes_and_reject_records(): void
    {
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);

        $session = $this->opsSession((int) $owner->id);

        $changesRequested = $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
            'title' => 'Changes Requested Article',
            'excerpt' => 'Ready excerpt',
            'content_md' => 'Ready body',
            'status' => 'draft',
        ]);
        $rejected = $this->seedArticle([
            'org_id' => (int) $session['ops_org_id'],
            'title' => 'Rejected Article',
            'excerpt' => 'Ready excerpt',
            'content_md' => 'Ready body',
            'status' => 'draft',
        ]);

        foreach ([$changesRequested, $rejected] as $record) {
            ArticleSeoMeta::query()->create([
                'org_id' => (int) $session['ops_org_id'],
                'article_id' => (int) $record->id,
                'locale' => 'en',
                'seo_title' => $record->title.' SEO Title',
                'seo_description' => $record->title.' SEO Description',
                'canonical_url' => 'https://example.test/articles/'.Str::slug($record->title),
                'og_title' => $record->title.' OG Title',
                'og_description' => $record->title.' OG Description',
                'og_image_url' => 'https://example.test/images/'.Str::slug($record->title).'.png',
                'robots' => 'index,follow',
                'is_indexable' => true,
            ]);
        }

        $this->routeRecordIntoReview((int) $session['ops_org_id'], $owner, $reviewer, 'article', $changesRequested);
        $this->routeRecordIntoReview((int) $session['ops_org_id'], $owner, $reviewer, 'article', $rejected);

        $this->setOpsContext((int) $session['ops_org_id'], $reviewer, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->call('requestChangesItem', 'article', (int) $changesRequested->id)
            ->call('rejectItem', 'article', (int) $rejected->id);

        $this->withSession($session)
            ->actingAs($reviewer, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Changes requested')
            ->assertSee('Rejected');
    }

    public function test_editorial_workflow_assigns_owner_and_reviewer_then_submits_to_review(): void
    {
        $owner = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $reviewer = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
        ]);

        $session = $this->opsSession((int) $owner->id);
        $selectedOrgId = (int) $session['ops_org_id'];

        $article = $this->seedArticle([
            'org_id' => $selectedOrgId,
            'title' => 'Workflow Assignment Article',
            'excerpt' => 'Ready excerpt',
            'content_md' => 'Ready body',
            'status' => 'draft',
        ]);

        ArticleSeoMeta::query()->create([
            'org_id' => $selectedOrgId,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'seo_title' => 'Workflow Assignment Article SEO Title',
            'seo_description' => 'Workflow Assignment Article SEO Description',
            'canonical_url' => 'https://example.test/articles/workflow-assignment-article',
            'og_title' => 'Workflow Assignment Article OG Title',
            'og_description' => 'Workflow Assignment Article OG Description',
            'og_image_url' => 'https://example.test/images/workflow-assignment-article.png',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->setOpsContext($selectedOrgId, $owner, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->set('ownerAssignments.article_'.$article->id, (string) $owner->id)
            ->call('assignOwnerItem', 'article', (int) $article->id);

        $this->setOpsContext($selectedOrgId, $reviewer, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->set('reviewerAssignments.article_'.$article->id, (string) $reviewer->id)
            ->call('assignReviewerItem', 'article', (int) $article->id);

        $this->setOpsContext($selectedOrgId, $owner, '/ops/editorial-review');
        Livewire::test(EditorialReviewPage::class)
            ->assertOk()
            ->call('submitItem', 'article', (int) $article->id);

        $workflow = EditorialReview::withoutGlobalScopes()
            ->where('content_type', 'article')
            ->where('content_id', (int) $article->id)
            ->first();

        $this->assertNotNull($workflow);
        $this->assertSame((int) $owner->id, (int) $workflow->owner_admin_user_id);
        $this->assertSame((int) $reviewer->id, (int) $workflow->reviewer_admin_user_id);
        $this->assertSame(EditorialReview::STATE_IN_REVIEW, (string) $workflow->workflow_state);
        $this->assertNotNull($workflow->submitted_at);

        $this->withSession($this->opsSessionForOrg((int) $owner->id, $selectedOrgId))
            ->actingAs($owner, (string) config('admin.guard', 'admin'))
            ->get('/ops/editorial-review')
            ->assertOk()
            ->assertSee('Workflow Assignment Article')
            ->assertSee('In review')
            ->assertSee($owner->name)
            ->assertSee($reviewer->name);
    }

    public function test_article_resource_uses_public_org_while_taxonomy_resources_remain_selected_org_scoped(): void
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

        $publicArticle = Article::query()->create([
            'org_id' => 0,
            'slug' => 'public-article',
            'locale' => 'en',
            'title' => 'Public Article',
            'content_md' => 'Public article body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $selectedOrgArticle = Article::query()->create([
            'org_id' => $selectedOrg->id,
            'slug' => 'selected-org-article',
            'locale' => 'en',
            'title' => 'Selected Org Article',
            'content_md' => 'Selected org article body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $otherOrgArticle = Article::query()->create([
            'org_id' => $otherOrg->id,
            'slug' => 'other-org-article',
            'locale' => 'en',
            'title' => 'Other Org Article',
            'content_md' => 'Other org article body',
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

        $this->assertSame([(int) $publicArticle->id], $articleIds);
        $this->assertNotContains((int) $selectedOrgArticle->id, $articleIds);
        $this->assertNotContains((int) $otherOrgArticle->id, $articleIds);
        $this->assertSame([(int) $selectedCategory->id], $categoryIds);
        $this->assertNotContains((int) $otherCategory->id, $categoryIds);
        $this->assertSame([(int) $selectedTag->id], $tagIds);
        $this->assertNotContains((int) $otherTag->id, $tagIds);
    }

    public function test_article_edit_page_resolves_public_org_articles_from_tenant_ops_session(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);

        $selectedOrg = Organization::query()->create([
            'name' => 'Selected Tenant Org',
            'owner_user_id' => 9333,
            'status' => 'active',
            'domain' => 'selected-tenant.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);

        $publicArticle = Article::query()->create([
            'org_id' => 0,
            'slug' => 'public-editorial-article',
            'locale' => 'en',
            'title' => 'Public Editorial Article',
            'excerpt' => 'Visible to the public editorial workspace.',
            'content_md' => 'Public editorial body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);
        $tenantArticle = Article::query()->create([
            'org_id' => (int) $selectedOrg->id,
            'slug' => 'tenant-editorial-article',
            'locale' => 'en',
            'title' => 'Tenant Editorial Article',
            'excerpt' => 'Tenant scoped body.',
            'content_md' => 'Tenant editorial body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        $session = $this->opsSessionForOrg((int) $admin->id, (int) $selectedOrg->id);

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/'.((int) $publicArticle->id).'/edit')
            ->assertOk()
            ->assertSee('Public Editorial Article');

        $this->withSession($session)
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/articles/'.((int) $tenantArticle->id).'/edit')
            ->assertNotFound();
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
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['guard_name' => (string) config('admin.guard', 'admin')]
            );

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
            \App\Http\Middleware\SetOpsLocale::SESSION_KEY => 'en',
            \App\Http\Middleware\SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ];
    }

    private function seedCmsSurface(): void
    {
        $this->seedArticle();
        $this->seedGuide();
        $this->seedJob();
    }

    private function approveRecord(AdminUser $admin, int $orgId, string $type, object $record): void
    {
        $this->ensureSeoReady($orgId, $type, $record);

        $owner = $admin;
        if (
            ! $admin->hasPermission(PermissionNames::ADMIN_CONTENT_WRITE)
            && ! $admin->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)
            && ! $admin->hasPermission(PermissionNames::ADMIN_OWNER)
        ) {
            $owner = $this->createAdminWithPermissions([
                PermissionNames::ADMIN_CONTENT_WRITE,
            ]);
        }

        $this->routeRecordIntoReview($orgId, $owner, $admin, $type, $record);
        $this->setOpsContext($orgId, $admin, '/ops/editorial-review');
        EditorialReviewAudit::mark(EditorialReviewAudit::STATE_APPROVED, $type, $record);
    }

    private function routeRecordIntoReview(int $orgId, AdminUser $owner, AdminUser $reviewer, string $type, object $record): void
    {
        $this->ensureSeoReady($orgId, $type, $record);

        $this->setOpsContext($orgId, $owner, '/ops/editorial-review');
        EditorialReviewAudit::assignOwner((int) $owner->id, $type, $record);

        $this->setOpsContext($orgId, $reviewer, '/ops/editorial-review');
        EditorialReviewAudit::assignReviewer((int) $reviewer->id, $type, $record);

        $this->setOpsContext($orgId, $owner, '/ops/editorial-review');
        EditorialReviewAudit::submit($type, $record);
    }

    private function ensureSeoReady(int $orgId, string $type, object $record): void
    {
        $bodyField = match ($type) {
            'article' => 'content_md',
            'guide', 'job' => 'body_md',
            default => 'content_md',
        };

        $bodyHtmlField = match ($type) {
            'article' => 'content_html',
            'guide', 'job' => 'body_html',
            default => 'content_html',
        };

        if (
            ! filled(data_get($record, 'excerpt'))
            || ! filled(data_get($record, $bodyField))
        ) {
            $record->forceFill([
                'excerpt' => filled(data_get($record, 'excerpt')) ? data_get($record, 'excerpt') : 'Ready excerpt',
                $bodyField => filled(data_get($record, $bodyField)) ? data_get($record, $bodyField) : 'Ready body',
                $bodyHtmlField => filled(data_get($record, $bodyHtmlField)) ? data_get($record, $bodyHtmlField) : '<p>Ready body</p>',
            ])->save();
        }

        if ($type === 'article' && ! $record->seoMeta()->exists()) {
            ArticleSeoMeta::query()->create([
                'org_id' => $orgId,
                'article_id' => (int) data_get($record, 'id'),
                'locale' => (string) data_get($record, 'locale', 'en'),
                'seo_title' => trim((string) data_get($record, 'title', 'Article')).' SEO Title',
                'seo_description' => trim((string) data_get($record, 'excerpt', 'Article excerpt')).' SEO Description',
                'canonical_url' => 'https://example.test/articles/'.trim((string) data_get($record, 'slug', 'article')),
                'og_title' => trim((string) data_get($record, 'title', 'Article')).' OG Title',
                'og_description' => trim((string) data_get($record, 'excerpt', 'Article excerpt')).' OG Description',
                'og_image_url' => 'https://example.test/images/'.trim((string) data_get($record, 'slug', 'article')).'.png',
                'robots' => 'index,follow',
                'is_indexable' => true,
            ]);
        }

        if ($type === 'guide' && ! $record->seoMeta()->exists()) {
            CareerGuideSeoMeta::query()->create([
                'career_guide_id' => (int) data_get($record, 'id'),
                'seo_title' => trim((string) data_get($record, 'title', 'Guide')).' SEO Title',
                'seo_description' => trim((string) data_get($record, 'excerpt', 'Guide excerpt')).' SEO Description',
                'canonical_url' => 'https://example.test/guides/'.trim((string) data_get($record, 'slug', 'guide')),
                'og_title' => trim((string) data_get($record, 'title', 'Guide')).' OG Title',
                'og_description' => trim((string) data_get($record, 'excerpt', 'Guide excerpt')).' OG Description',
                'og_image_url' => 'https://example.test/images/'.trim((string) data_get($record, 'slug', 'guide')).'.png',
                'twitter_title' => trim((string) data_get($record, 'title', 'Guide')).' Twitter Title',
                'twitter_description' => trim((string) data_get($record, 'excerpt', 'Guide excerpt')).' Twitter Description',
                'twitter_image_url' => 'https://example.test/images/'.trim((string) data_get($record, 'slug', 'guide')).'-twitter.png',
                'robots' => 'index,follow',
            ]);
        }

        if ($type === 'job' && ! $record->seoMeta()->exists()) {
            CareerJobSeoMeta::query()->create([
                'job_id' => (int) data_get($record, 'id'),
                'seo_title' => trim((string) data_get($record, 'title', 'Job')).' SEO Title',
                'seo_description' => trim((string) data_get($record, 'excerpt', 'Job excerpt')).' SEO Description',
                'canonical_url' => 'https://example.test/jobs/'.trim((string) data_get($record, 'slug', 'job')),
                'og_title' => trim((string) data_get($record, 'title', 'Job')).' OG Title',
                'og_description' => trim((string) data_get($record, 'excerpt', 'Job excerpt')).' OG Description',
                'og_image_url' => 'https://example.test/images/'.trim((string) data_get($record, 'slug', 'job')).'.png',
                'twitter_title' => trim((string) data_get($record, 'title', 'Job')).' Twitter Title',
                'twitter_description' => trim((string) data_get($record, 'excerpt', 'Job excerpt')).' Twitter Description',
                'twitter_image_url' => 'https://example.test/images/'.trim((string) data_get($record, 'slug', 'job')).'-twitter.png',
                'robots' => 'index,follow',
            ]);
        }
    }

    private function setOpsContext(int $orgId, AdminUser $admin, string $path, string $method = 'POST'): void
    {
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        app()->instance('request', Request::create($path, $method));

        $context = app(OrgContext::class);
        $context->set($orgId, (int) $admin->id, 'admin');
        app()->instance(OrgContext::class, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSessionForOrg(int $adminUserId, int $orgId): array
    {
        return [
            'ops_org_id' => $orgId,
            'ops_admin_totp_verified_user_id' => $adminUserId,
            \App\Http\Middleware\SetOpsLocale::SESSION_KEY => 'en',
            \App\Http\Middleware\SetOpsLocale::EXPLICIT_SESSION_KEY => true,
        ];
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
