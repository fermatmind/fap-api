<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Cms\ArticleTranslationWorkflowException;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Ops\ArticleTranslationOpsService;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ArticleTranslationOpsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $fixtureSlugs = [
        'how-personality-shapes-attitude-toward-ai',
        'which-love-script-fits-you-best',
        'are-infj-men-rare-or-socially-silenced',
        'best-valentines-date-by-personality-and-relationship-science',
        'how-16-personality-types-talk-to-an-ai-coach',
        'childhood-dream-job-still-shapes-career-choice',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_translation_ops_page_lists_current_six_translation_groups(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $selectedOrg = $this->createOrganization();

        foreach ($this->fixtureSlugs as $slug) {
            $this->createPublishedTranslationGroup($slug);
        }

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->get('/ops/article-translation-ops')
            ->assertOk()
            ->assertSee('Translation Ops Console')
            ->assertSee('how-personality-shapes-attitude-toward-ai')
            ->assertSee('childhood-dream-job-still-shapes-career-choice');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticleTranslationOpsPage::class)
            ->assertOk()
            ->assertSet('metrics.translation_groups', 6)
            ->assertSet('metrics.stale_groups', 0)
            ->assertSet('metrics.ownership_mismatch_groups', 0)
            ->assertSee('how-16-personality-types-talk-to-an-ai-coach')
            ->assertSee('en published')
            ->assertSee('zh-CN source')
            ->assertSee('Create translation draft disabled')
            ->assertSee('Re-sync from source disabled');
    }

    public function test_translation_ops_page_localizes_visible_chinese_console_copy(): void
    {
        app()->setLocale('zh_CN');

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->createOrganization();
        $this->createPublishedTranslationGroup('localized-console-fixture');

        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticleTranslationOpsPage::class)
            ->assertOk()
            ->assertSee('统一翻译运营控制台')
            ->assertSee('翻译健康度')
            ->assertSee('内容类型')
            ->assertSee('源文')
            ->assertSee('已发布')
            ->assertSee('归属正常')
            ->assertSee('未配置机器翻译 provider')
            ->assertDontSee('Unified Translation Ops Console')
            ->assertDontSee('Translation health')
            ->assertDontSee('Content type');
    }

    public function test_translation_ops_service_flags_stale_translation_and_source_update_alert(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('stale-translation-fixture');
        $group['source']->forceFill([
            'source_version_hash' => 'source-hash-new',
        ])->saveQuietly();
        $group['sourceRevision']->forceFill([
            'source_version_hash' => 'source-hash-new',
            'translated_from_version_hash' => 'source-hash-new',
        ])->save();
        $group['translationRevision']->forceFill([
            'translated_from_version_hash' => 'source-hash-old',
        ])->save();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'stale' => 'stale',
        ]);

        $this->assertSame(1, $dashboard['metrics']['stale_groups']);
        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame(1, $dashboard['groups'][0]['stale_locales_count']);
        $this->assertContains('source updated after target review', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_service_detects_missing_target_locale(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->createSourceOnlyGroup('missing-en-fixture');
        $this->createPublishedTranslationGroup('has-en-fixture');

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'target_locale' => 'en',
            'missing_locale' => true,
        ]);

        $this->assertSame(1, $dashboard['metrics']['missing_target_locale']);
        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame('missing-en-fixture', $dashboard['groups'][0]['slug']);
        $this->assertContains('missing en locale', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_service_detects_revision_ownership_mismatch(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('ownership-mismatch-fixture');
        $group['translation']->forceFill([
            'org_id' => 2,
        ])->saveQuietly();
        $group['translationRevision']->forceFill([
            'org_id' => 2,
        ])->save();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'ownership' => 'mismatch',
        ]);

        $this->assertSame(1, $dashboard['metrics']['ownership_mismatch_groups']);
        $this->assertCount(1, $dashboard['groups']);
        $this->assertFalse($dashboard['groups'][0]['ownership_ok']);
        $this->assertContains('article org mismatch', $dashboard['groups'][0]['ownership_issues']);
        $this->assertContains('published revision org mismatch', $dashboard['groups'][0]['ownership_issues']);
    }

    public function test_translation_ops_service_prefers_public_source_when_duplicate_source_exists(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('duplicate-source-fixture');
        $duplicateSource = Article::query()->create([
            'org_id' => 2,
            'slug' => 'duplicate-source-fixture',
            'locale' => 'zh-CN',
            'translation_group_id' => $group['source']->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '重复中文源文',
            'excerpt' => '重复摘要',
            'content_md' => '重复正文',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $duplicateRevision = $this->createRevision($duplicateSource, [
            'source_article_id' => (int) $duplicateSource->id,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
        ]);
        $duplicateSource->forceFill([
            'working_revision_id' => (int) $duplicateRevision->id,
        ])->saveQuietly();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'slug' => 'duplicate-source-fixture',
        ]);

        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame((int) $group['source']->id, $dashboard['groups'][0]['source_article_id']);
        $this->assertFalse($dashboard['groups'][0]['canonical_ok']);
        $this->assertContains('canonical/source split risk', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_service_flags_published_article_missing_published_revision(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $group = $this->createPublishedTranslationGroup('missing-published-revision-fixture');
        $group['translation']->forceFill([
            'published_revision_id' => null,
        ])->saveQuietly();

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'slug' => 'missing-published-revision-fixture',
        ]);

        $this->assertCount(1, $dashboard['groups']);
        $this->assertContains('missing published revision', collect($dashboard['groups'][0]['alerts'])->pluck('label')->all());
    }

    public function test_translation_ops_published_filter_respects_selected_target_locale(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->createSourceOnlyGroup('source-only-published-fixture');
        $this->createPublishedTranslationGroup('target-published-fixture');

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'target_locale' => 'en',
            'published' => 'unpublished',
        ]);

        $this->assertCount(1, $dashboard['groups']);
        $this->assertSame('source-only-published-fixture', $dashboard['groups'][0]['slug']);
    }

    public function test_translation_workflow_creates_machine_draft_with_public_ownership_and_audit(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->app->instance(ArticleMachineTranslationProvider::class, new FakeArticleMachineTranslationProvider);

        $source = $this->createSourceOnlyGroup('create-machine-draft-fixture');

        $result = app(ArticleTranslationWorkflowService::class)->createMachineDraft($source, 'en', (int) $admin->id);

        $translation = $result['article']->fresh(['workingRevision', 'seoMeta']);
        $revision = $translation->workingRevision;
        $seoMeta = $translation->seoMeta;

        $this->assertSame(0, (int) $translation->org_id);
        $this->assertSame('en', (string) $translation->locale);
        $this->assertSame((int) $source->id, (int) $translation->source_article_id);
        $this->assertSame((string) $source->translation_group_id, (string) $translation->translation_group_id);
        $this->assertSame(Article::TRANSLATION_STATUS_MACHINE_DRAFT, (string) $translation->translation_status);
        $this->assertSame('draft', (string) $translation->status);
        $this->assertFalse((bool) $translation->is_public);
        $this->assertInstanceOf(ArticleTranslationRevision::class, $revision);
        $this->assertSame(0, (int) $revision->org_id);
        $this->assertSame(ArticleTranslationRevision::STATUS_MACHINE_DRAFT, (string) $revision->revision_status);
        $this->assertSame((string) $source->source_version_hash, (string) $revision->translated_from_version_hash);
        $this->assertInstanceOf(ArticleSeoMeta::class, $seoMeta);
        $this->assertSame(0, (int) $seoMeta->org_id);
        $this->assertSame('en', (string) $seoMeta->locale);
        $this->assertTrue(AuditLog::query()->where('action', 'article_translation_draft_created')->exists());
    }

    public function test_translation_ops_page_create_draft_action_uses_workflow_service(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->app->instance(ArticleMachineTranslationProvider::class, new FakeArticleMachineTranslationProvider);

        $source = $this->createSourceOnlyGroup('livewire-create-machine-draft-fixture');

        Livewire::test(ArticleTranslationOpsPage::class)
            ->call('createTranslationDraft', (int) $source->id, 'en')
            ->assertOk();

        $translation = Article::query()
            ->withoutGlobalScopes()
            ->where('translation_group_id', (string) $source->translation_group_id)
            ->where('locale', 'en')
            ->with(['workingRevision', 'seoMeta'])
            ->first();

        $this->assertInstanceOf(Article::class, $translation);
        $this->assertSame(0, (int) $translation->org_id);
        $this->assertSame(Article::TRANSLATION_STATUS_MACHINE_DRAFT, (string) $translation->translation_status);
        $this->assertSame(0, (int) $translation->workingRevision->org_id);
        $this->assertSame(0, (int) $translation->seoMeta->org_id);
    }

    public function test_translation_workflow_resyncs_stale_translation_under_same_canonical_article(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->app->instance(ArticleMachineTranslationProvider::class, new FakeArticleMachineTranslationProvider('resynced'));

        $group = $this->createPublishedTranslationGroup('resync-stale-fixture');
        $translationId = (int) $group['translation']->id;
        $publishedRevisionId = (int) $group['translation']->published_revision_id;

        $group['source']->forceFill([
            'content_md' => "更新中文正文\n\n参考文献：https://example.test/new",
        ])->save();
        $newSourceHash = (string) $group['source']->fresh()->source_version_hash;
        $group['sourceRevision']->forceFill([
            'source_version_hash' => $newSourceHash,
            'translated_from_version_hash' => $newSourceHash,
        ])->save();

        $result = app(ArticleTranslationWorkflowService::class)->resyncFromSource($group['translation'], (int) $admin->id);

        $translation = $result['article']->fresh(['workingRevision', 'publishedRevision']);
        $revision = $translation->workingRevision;

        $this->assertSame($translationId, (int) $translation->id);
        $this->assertSame($publishedRevisionId, (int) $translation->published_revision_id);
        $this->assertInstanceOf(ArticleTranslationRevision::class, $revision);
        $this->assertNotSame($publishedRevisionId, (int) $revision->id);
        $this->assertSame($publishedRevisionId, (int) $revision->supersedes_revision_id);
        $this->assertSame(2, (int) $revision->revision_number);
        $this->assertSame(ArticleTranslationRevision::STATUS_MACHINE_DRAFT, (string) $revision->revision_status);
        $this->assertSame($newSourceHash, (string) $revision->translated_from_version_hash);
        $this->assertSame(1, Article::query()
            ->withoutGlobalScopes()
            ->where('translation_group_id', (string) $group['source']->translation_group_id)
            ->where('locale', 'en')
            ->count());
        $this->assertTrue(AuditLog::query()->where('action', 'article_translation_resynced')->exists());
    }

    public function test_translation_publish_guardrails_block_missing_reference_markers(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $source = $this->createSourceOnlyGroup('preflight-reference-fixture');
        $source->forceFill([
            'content_md' => "中文正文\n\n参考文献：https://example.test/source",
        ])->save();
        $sourceHash = (string) $source->fresh()->source_version_hash;
        $source->workingRevision->forceFill([
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'content_md' => $source->content_md,
        ])->save();

        $translation = Article::query()->create([
            'org_id' => 0,
            'slug' => (string) $source->slug,
            'locale' => 'en',
            'translation_group_id' => (string) $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translated_from_version_hash' => $sourceHash,
            'title' => 'English preflight fixture',
            'excerpt' => 'English excerpt',
            'content_md' => 'English body without markers',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        $revision = $this->createRevision($translation, [
            'source_article_id' => (int) $source->id,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'content_md' => 'English body without markers',
        ]);
        $translation->forceFill([
            'working_revision_id' => (int) $revision->id,
        ])->saveQuietly();

        $preflight = app(ArticleTranslationWorkflowService::class)->preflight($translation->fresh(['workingRevision', 'sourceCanonical.workingRevision']));

        $this->assertFalse($preflight['ok']);
        $this->assertContains('references/citations presence check failed', $preflight['blockers']);
        $this->expectException(ArticleTranslationWorkflowException::class);

        app(ArticleTranslationWorkflowService::class)->approveTranslation($translation);
    }

    public function test_translation_workflow_promotes_approves_and_publishes_with_guardrails(): void
    {
        $admin = $this->createAdminWithPermissions([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
        ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));
        $this->app->instance(ArticleMachineTranslationProvider::class, new FakeArticleMachineTranslationProvider);

        $source = $this->createSourceOnlyGroup('publish-translation-fixture');
        $workflow = app(ArticleTranslationWorkflowService::class);
        $draft = $workflow->createMachineDraft($source, 'en', (int) $admin->id)['article'];
        $workflow->promoteToHumanReview($draft);
        $workflow->approveTranslation($draft);
        $publishedRevision = $workflow->publishTranslation($draft);
        $translation = $draft->fresh(['workingRevision', 'publishedRevision']);

        $this->assertSame('published', (string) $translation->status);
        $this->assertTrue((bool) $translation->is_public);
        $this->assertNotNull($translation->published_at);
        $this->assertSame((int) $publishedRevision->id, (int) $translation->published_revision_id);
        $this->assertSame(Article::TRANSLATION_STATUS_PUBLISHED, (string) $translation->translation_status);
        $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, (string) $publishedRevision->revision_status);
        $this->assertTrue(AuditLog::query()->where('action', 'article_translation_published')->exists());
    }

    public function test_translation_ops_console_exposes_coverage_compare_and_preflight_summary(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->createPublishedTranslationGroup('console-summary-fixture');

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'slug' => 'console-summary-fixture',
        ]);

        $group = $dashboard['groups'][0];
        $targetLocale = collect($group['locales'])->first(fn (array $locale): bool => $locale['locale'] === 'en');

        $this->assertSame(['zh-CN', 'en'], $group['coverage']['existing_locales']);
        $this->assertSame(['zh-CN', 'en'], $group['coverage']['published_locales']);
        $this->assertSame([], $group['coverage']['missing_target_locales']);
        $this->assertContains('source/target hash current', $targetLocale['compare_summary']);
        $this->assertTrue($targetLocale['preflight']['ok']);
    }

    public function test_translation_ops_console_uses_configured_target_locale_coverage(): void
    {
        config()->set('services.article_translation.target_locales', ['en', 'ja']);

        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        $this->createPublishedTranslationGroup('configured-target-locale-fixture');

        $dashboard = app(ArticleTranslationOpsService::class)->dashboard([
            'slug' => 'configured-target-locale-fixture',
        ]);
        $group = $dashboard['groups'][0];

        $this->assertSame(['en', 'ja'], $group['coverage']['target_locales']);
        $this->assertSame(['ja'], $group['coverage']['missing_target_locales']);
        $this->assertContains('missing ja locale', collect($group['alerts'])->pluck('label')->all());
    }

    /**
     * @return array{source:Article,translation:Article,sourceRevision:ArticleTranslationRevision,translationRevision:ArticleTranslationRevision}
     */
    private function createPublishedTranslationGroup(string $slug): array
    {
        $source = $this->createSourceOnlyGroup($slug);
        $sourceHash = (string) $source->source_version_hash;

        $translation = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'en',
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'translated_from_article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translated_from_version_hash' => $sourceHash,
            'title' => 'English '.$slug,
            'excerpt' => 'English excerpt',
            'content_md' => 'English body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $translationRevision = $this->createRevision($translation, [
            'source_article_id' => (int) $source->id,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'published_at' => now(),
        ]);
        $translation->forceFill([
            'working_revision_id' => (int) $translationRevision->id,
            'published_revision_id' => (int) $translationRevision->id,
        ])->saveQuietly();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'locale' => 'en',
            'seo_title' => 'English SEO '.$slug,
            'seo_description' => 'English SEO description',
            'is_indexable' => true,
        ]);

        return [
            'source' => $source,
            'translation' => $translation,
            'sourceRevision' => $source->workingRevision,
            'translationRevision' => $translationRevision,
        ];
    }

    private function createSourceOnlyGroup(string $slug): Article
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '中文 '.$slug,
            'excerpt' => '中文摘要',
            'content_md' => '中文正文',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $sourceHash = (string) $source->source_version_hash;
        $sourceRevision = $this->createRevision($source, [
            'source_article_id' => (int) $source->id,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => $sourceHash,
            'translated_from_version_hash' => $sourceHash,
            'published_at' => now(),
        ]);
        $source->forceFill([
            'working_revision_id' => (int) $sourceRevision->id,
            'published_revision_id' => (int) $sourceRevision->id,
        ])->saveQuietly();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $source->id,
            'locale' => 'zh-CN',
            'seo_title' => '中文 SEO '.$slug,
            'seo_description' => '中文 SEO 描述',
            'is_indexable' => true,
        ]);

        return $source->fresh(['workingRevision', 'publishedRevision', 'seoMeta']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRevision(Article $article, array $overrides = []): ArticleTranslationRevision
    {
        return ArticleTranslationRevision::query()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash ?: $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
        ], $overrides));
    }

    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'translation_ops_'.Str::lower(Str::random(6)),
            'email' => 'translation_ops_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'translation_ops_role_'.Str::lower(Str::random(8)),
            'description' => null,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Translation Ops Org',
            'owner_user_id' => 5101,
            'status' => 'active',
            'domain' => 'translation-ops.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array{ops_org_id:int,ops_locale:string,ops_admin_totp_verified_user_id:int}
     */
    private function opsSession(AdminUser $admin, Organization $org): array
    {
        return [
            'ops_org_id' => (int) $org->id,
            'ops_locale' => 'en',
            'ops_admin_totp_verified_user_id' => (int) $admin->id,
        ];
    }
}

final class FakeArticleMachineTranslationProvider implements ArticleMachineTranslationProvider
{
    public function __construct(private readonly string $suffix = 'translated') {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /**
     * @return array{title:string,excerpt:string|null,content_md:string,seo_title:string|null,seo_description:string|null}
     */
    public function translate(Article $source, string $targetLocale): array
    {
        return [
            'title' => 'English '.$this->suffix.' '.$source->slug,
            'excerpt' => 'English excerpt for '.$source->slug,
            'content_md' => "English body for {$source->slug}.\n\nReferences: https://example.test/reference",
            'seo_title' => 'SEO '.$source->slug,
            'seo_description' => 'SEO description '.$source->slug,
        ];
    }
}
