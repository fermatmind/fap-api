<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticlePublishingOpsPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\EditorialReview;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ArticlePublishingOpsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_article_publishing_ops_page_requires_content_read_permission(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_OPS_READ,
        ]);
        $org = $this->createOrganization('Article Publishing Ops');

        $this->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/article-publishing-ops')
            ->assertForbidden();
    }

    public function test_article_publishing_ops_page_renders_queue_health_without_sensitive_data(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $org = $this->createOrganization('Article Publishing Ops');

        $zhDraft = $this->createArticle('daily-zh-draft', 'zh-CN', '今日中文草稿', [
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $zhDraft->id,
            'locale' => 'zh-CN',
            'seo_title' => '今日中文草稿 SEO',
            'seo_description' => '今日中文草稿 description',
            'canonical_url' => 'https://example.test/zh/articles/daily-zh-draft',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $enDraft = $this->createArticle('daily-en-draft', 'en', 'Today English Draft', [
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $publishReady = $this->createArticle('publish-ready-draft', 'en', 'Publish Ready Draft', [
            'created_at' => Carbon::now()->subDay(),
            'updated_at' => Carbon::now()->subDay(),
        ]);
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $publishReady->id,
            'locale' => 'en',
            'seo_title' => 'Publish Ready SEO',
            'seo_description' => 'Publish Ready description',
            'canonical_url' => 'https://example.test/en/articles/publish-ready-draft',
            'og_title' => 'Publish Ready OG',
            'og_description' => 'Publish Ready OG description',
            'og_image_url' => 'https://example.test/cover.svg',
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);
        $this->createApprovedRevision($publishReady, $admin);
        EditorialReview::query()->withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'content_type' => 'article',
            'content_id' => (int) $publishReady->id,
            'workflow_state' => EditorialReview::STATE_APPROVED,
            'last_transition_at' => Carbon::now(),
            'reviewed_at' => Carbon::now(),
        ]);

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $zhDraft->id,
            'slug' => 'daily-zh-draft',
            'locale' => 'zh-CN',
            'title' => '今日中文草稿',
            'content_track' => 'editorial_journal',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'draft',
            'claim_result_json' => ['status' => 'passed', 'matches' => []],
            'references_json' => ['status' => 'complete', 'count' => 2],
            'media_json' => ['status' => 'complete'],
            'graph_json' => ['status' => 'complete', 'target_topics' => ['mbti']],
            'answer_surface_json' => ['policy' => 'none'],
            'body_hash' => str_repeat('a', 64),
            'heading_sequence_json' => ['1:标题', '2:执行摘要'],
            'references_count' => 2,
        ]);
        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => null,
            'slug' => 'claim-blocked-draft',
            'locale' => 'zh-CN',
            'title' => 'Claim blocked draft',
            'content_track' => 'editorial_journal',
            'status' => ArticleEditorialPackageImport::STATUS_BLOCKED,
            'intended_status' => 'draft',
            'claim_result_json' => ['status' => 'blocked', 'matches' => [['code' => 'claim_boundary_forbidden_phrase']]],
            'references_json' => ['status' => 'missing', 'count' => 0],
            'media_json' => ['status' => 'missing'],
            'graph_json' => ['status' => 'missing'],
            'answer_surface_json' => ['policy' => 'none'],
            'body_hash' => str_repeat('b', 64),
            'heading_sequence_json' => ['1:标题'],
            'references_count' => 0,
            'blocked_reasons_json' => [['code' => 'claim_boundary_forbidden_phrase']],
        ]);
        AuditLog::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $org->id,
            'action' => 'content_release_cache_signal',
            'target_type' => 'article',
            'target_id' => (string) $zhDraft->id,
            'meta_json' => ['title' => '今日中文草稿'],
            'result' => 'failed',
            'created_at' => Carbon::now(),
        ]);
        $publishedDue = $this->createArticle('seven-day-review', 'zh-CN', '七天复盘文章', [
            'status' => 'published',
            'is_public' => true,
            'published_at' => Carbon::now()->subDays(7),
            'created_at' => Carbon::now()->subDays(7),
            'updated_at' => Carbon::now()->subDays(7),
        ]);
        $this->assertNotNull($publishedDue->id);

        $this->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticlePublishingOpsPage::class)
            ->assertOk()
            ->assertSet('dailyHealthFields.0.value', '1')
            ->assertSet('dailyHealthFields.1.value', '2')
            ->assertSet('dailyHealthFields.2.value', '1')
            ->assertSet('dailyHealthFields.3.value', '1')
            ->assertSet('dailyHealthFields.4.value', '1')
            ->assertSet('dailyHealthFields.5.value', '1')
            ->assertSet('dailyHealthFields.6.value', '1')
            ->assertSet('queueFields.2.value', '1')
            ->assertSet('queueFields.3.value', '1')
            ->assertSee('Article Publishing Ops')
            ->assertSee('今日中文草稿')
            ->assertSee('claim-blocked-draft')
            ->assertSee('七天复盘文章')
            ->assertDontSee('report_json')
            ->assertDontSee('PaymentEvent')
            ->assertDontSee('answers_json');
    }

    public function test_article_publishing_ops_release_failures_are_scoped_to_selected_org(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
        ]);
        $selectedOrg = $this->createOrganization('Article Publishing Selected Org');
        $foreignOrg = $this->createOrganization('Article Publishing Foreign Org');

        AuditLog::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $selectedOrg->id,
            'action' => 'content_release_failure_alert',
            'target_type' => 'article',
            'target_id' => 'selected-article',
            'meta_json' => ['title' => 'Selected org release failure'],
            'result' => 'failed',
            'created_at' => Carbon::now(),
        ]);
        AuditLog::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $foreignOrg->id,
            'action' => 'content_release_failure_alert',
            'target_type' => 'article',
            'target_id' => 'foreign-article',
            'meta_json' => ['title' => 'Foreign org release failure'],
            'result' => 'failed',
            'created_at' => Carbon::now(),
        ]);

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticlePublishingOpsPage::class)
            ->assertOk()
            ->assertSet('dailyHealthFields.6.value', '1')
            ->assertSet('releaseRows.0.title', 'Selected org release failure')
            ->assertSee('Selected org release failure')
            ->assertDontSee('Foreign org release failure');
    }

    public function test_drafts_precede_published_metadata_gaps_and_links_keep_selected_article(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization('CMS queue priority');
        $draft = $this->createArticle('old-draft', 'en', 'Old actionable draft', [
            'updated_at' => now()->subYear(),
        ]);
        for ($i = 0; $i < 13; $i++) {
            $this->createArticle('published-'.$i, 'en', 'Published '.$i, [
                'status' => 'published', 'is_public' => true,
            ]);
        }
        $this->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));
        Livewire::test(ArticlePublishingOpsPage::class)
            ->assertSet('queueRows.0.title', 'Old actionable draft')
            ->assertSet('queueRows.0.review_status', 'Review not recorded')
            ->assertSet('queueRows.0.edit_url', null)
            ->assertSet('queueRows.0.workspace_url', url('/ops/seo-operations').'?workspace=content&article='.$draft->id)
            ->assertSee('Old actionable draft')
            ->assertSet('queueFields.3.value', '0');
    }

    public function test_article_approval_cannot_make_an_unapproved_working_revision_ready(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization('CMS revision review');
        $article = $this->createArticle('revision-review', 'en', 'Revision review');
        ArticleSeoMeta::query()->create([
            'org_id' => 0, 'article_id' => $article->id, 'locale' => 'en',
            'seo_title' => 'Old SEO', 'seo_description' => 'Old description',
            'canonical_url' => 'https://example.test/en/articles/revision-review',
            'robots' => 'index,follow', 'og_title' => 'OG', 'og_description' => 'OG description',
            'og_image_url' => 'https://example.test/cover.svg',
        ]);
        $revision = $this->createApprovedRevision($article, $admin);
        EditorialReview::query()->create([
            'id' => (string) Str::uuid(), 'org_id' => 0, 'content_type' => 'article',
            'content_id' => $article->id, 'workflow_state' => EditorialReview::STATE_APPROVED,
            'last_transition_at' => now()->addMinute(), 'reviewed_at' => now(),
        ]);
        $this->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));
        Livewire::test(ArticlePublishingOpsPage::class)->assertSet('queueFields.3.value', '1');
        $revision->update(['revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT]);
        Livewire::test(ArticlePublishingOpsPage::class)->assertSet('queueFields.3.value', '0')
            ->assertSet('queueRows.0.next_action', 'Submit review')
            ->assertSet('queueRows.0.revision_status', 'machine_draft')
            ->assertSee('Article workflow: Approved');
        $revision->update(['revision_status' => ArticleTranslationRevision::STATUS_APPROVED, 'excerpt' => '', 'seo_title' => '']);
        Livewire::test(ArticlePublishingOpsPage::class)->assertSet('dailyHealthFields.2.value', '1')
            ->assertSet('queueRows.0.next_action', 'Complete metadata')
            ->assertSet('queueFields.3.value', '0');
        $article->seoMeta->update(['seo_title' => null, 'seo_description' => null]);
        $revision->update(['excerpt' => 'Current excerpt', 'seo_title' => 'Current title', 'seo_description' => 'Current description']);
        Livewire::test(ArticlePublishingOpsPage::class)->assertSet('dailyHealthFields.2.value', '0')
            ->assertSet('queueFields.3.value', '1');
        $revision->update(['content_md' => '']);
        Livewire::test(ArticlePublishingOpsPage::class)->assertSet('queueFields.3.value', '0');
        $this->assertNotEmpty($article->fresh()->content_md);
        $this->assertNull($article->seoMeta->fresh()->seo_title);
    }

    public function test_published_article_with_pending_revision_precedes_old_published_gaps(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $org = $this->createOrganization('CMS published update');
        for ($i = 0; $i < 13; $i++) {
            $this->createArticle('old-gap-'.$i, 'en', 'Old published gap '.$i, ['status' => 'published']);
        }
        $article = $this->createArticle('pending-update', 'en', 'Pending published update', ['status' => 'published']);
        $published = $this->createApprovedRevision($article, $admin);
        $working = $published->replicate();
        $working->revision_number = 2;
        $working->revision_status = ArticleTranslationRevision::STATUS_MACHINE_DRAFT;
        $working->save();
        $article->update(['published_revision_id' => $published->id, 'working_revision_id' => $working->id, 'updated_at' => now()->subYear()]);
        $this->withSession($this->opsSession($admin, $org))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));
        Livewire::test(ArticlePublishingOpsPage::class)
            ->assertSet('queueRows.0.title', 'Pending published update')
            ->assertSet('queueRows.0.revision_id', $working->id);
    }

    private function createApprovedRevision(Article $article, AdminUser $admin): ArticleTranslationRevision
    {
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
            'translation_group_id' => $article->translation_group_id, 'locale' => $article->locale,
            'source_locale' => $article->locale, 'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'title' => $article->title, 'excerpt' => $article->excerpt, 'content_md' => $article->content_md,
            'seo_title' => 'Current SEO', 'seo_description' => 'Current description',
            'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'approved_at' => now(),
        ]);
        $article->update(['working_revision_id' => $revision->id]);

        return $revision;
    }

    private function createArticle(string $slug, string $locale, string $title, array $overrides = []): Article
    {
        return Article::query()->withoutGlobalScopes()->create(array_replace([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'excerpt' => $title.' excerpt',
            'content_md' => '# '.$title."\n\nBody",
            'cover_image_url' => 'https://example.test/'.$slug.'.svg',
            'cover_image_alt' => $title.' cover',
            'cover_image_variants' => [
                'editorial_package_v1' => [
                    'content_track' => 'editorial_journal',
                    'references' => ['Reference'],
                    'target_topics' => ['mbti'],
                ],
            ],
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
        ], $overrides));
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
     * @return array{ops_org_id:int,ops_admin_totp_verified_user_id:int,ops_locale:string,ops_locale_explicit:bool}
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
