<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\ArticleTranslationOpsPage;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportArticle;
use App\Services\Cms\CmsTranslationWorkflowException;
use App\Services\Cms\DisabledCmsMachineTranslationProvider;
use App\Services\Cms\SiblingTranslationWorkflowService;
use App\Services\Ops\CmsTranslationOpsService;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class CmsTranslationBackboneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_unified_translation_ops_page_lists_multiple_content_types(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_READ]);
        $selectedOrg = $this->createOrganization($admin);

        $this->createPublishedArticleGroup('ops-article');
        $this->createSourceSupportArticle('support-faq');
        $this->createSourceInterpretationGuide('guide-reading');
        $this->createSourceContentPage('company-charter', '/charter');

        $this->withSession($this->opsSession($admin, $selectedOrg))
            ->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(ArticleTranslationOpsPage::class)
            ->assertOk()
            ->assertSee('Unified Translation Ops Console')
            ->assertSee('Support Articles')
            ->assertSee('Interpretation Guides')
            ->assertSee('Content Pages')
            ->assertSee('Create translation draft disabled')
            ->assertSet('metrics.translation_groups', 4)
            ->assertSet('metrics.missing_translation_count', 3)
            ->assertSet('coverageMatrix.0.cells.zh-CN.state', 'source')
            ->assertSet('summaryCards.1.value', '3')
            ->set('contentTypeFilter', 'support_article')
            ->assertSee('support-faq')
            ->assertDontSee('ops-article');
    }

    public function test_row_backed_translation_workflow_publishes_with_invalidation_signals(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/invalidate',
        ]);
        config()->set('ops.content_release_observability.broadcast_webhook', '');

        Http::fake([
            'https://cache.example.test/invalidate' => Http::response(['ok' => true], 202),
        ]);

        $workflow = app(SiblingTranslationWorkflowService::class);

        foreach (['support_article', 'interpretation_guide', 'content_page'] as $contentType) {
            $source = match ($contentType) {
                'support_article' => $this->createSourceSupportArticle('support-'.$contentType),
                'interpretation_guide' => $this->createSourceInterpretationGuide('guide-'.$contentType),
                'content_page' => $this->createSourceContentPage('page-'.$contentType, '/'.$contentType),
            };

            $target = $this->createTargetTranslation($contentType, $source, 'en');

            $workflow->promoteToHumanReview($contentType, $target);
            $workflow->approveTranslation($contentType, $target->fresh());
            $published = $workflow->publishTranslation($contentType, $target->fresh());

            $this->assertSame('published', (string) $published->translation_status);
            $this->assertSame((int) $source->id, (int) $published->source_content_id);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'content_release_publish',
                'target_type' => $contentType,
                'target_id' => (string) $published->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'content_release_cache_signal',
                'target_type' => $contentType,
                'target_id' => (string) $published->id,
                'result' => 'success',
            ]);
        }
    }

    public function test_row_backed_translation_preflight_blocks_stale_publish(): void
    {
        $workflow = app(SiblingTranslationWorkflowService::class);
        $source = $this->createSourceSupportArticle('stale-support');
        $target = $this->createTargetTranslation('support_article', $source, 'en');

        $source->forceFill([
            'title' => 'Updated source title',
        ])->save();
        $target->forceFill([
            'translation_status' => SupportArticle::TRANSLATION_STATUS_APPROVED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
        ])->save();

        $this->expectException(CmsTranslationWorkflowException::class);
        $this->expectExceptionMessage('Translation publish preflight failed.');

        $workflow->publishTranslation('support_article', $target->fresh());
    }

    public function test_unified_translation_ops_localizes_blockers_and_provider_reasons(): void
    {
        app()->setLocale('zh_CN');
        config()->set('services.cms_translation.providers.support_article', DisabledCmsMachineTranslationProvider::class);

        $source = $this->createSourceSupportArticle('localized-blockers');
        $target = $this->createTargetTranslation('support_article', $source, 'en');
        $target->forceFill([
            'body_md' => '',
            'body_html' => '',
            'seo_description' => '',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_APPROVED,
        ])->save();

        $missingSource = $this->createSourceSupportArticle('localized-provider-reason');

        $dashboard = app(CmsTranslationOpsService::class)->dashboard(['content_type' => 'support_article']);
        $blockedGroup = collect($dashboard['groups'])->firstWhere('translation_group_id', $source->translation_group_id);
        $this->assertIsArray($blockedGroup);
        $targetLocale = collect($blockedGroup['locales'])->firstWhere('locale', 'en');
        $this->assertIsArray($targetLocale);

        $this->assertContains('缺少正文', $targetLocale['preflight']['blockers']);
        $this->assertContains('缺少 SEO 描述', $targetLocale['preflight']['blockers']);

        $missingGroup = collect($dashboard['groups'])->firstWhere('translation_group_id', $missingSource->translation_group_id);
        $this->assertIsArray($missingGroup);
        $disabledReasons = collect($missingGroup['group_actions'])->pluck('reason')->filter()->values()->all();

        $this->assertTrue(
            collect($disabledReasons)->contains(fn (string $reason): bool => str_contains($reason, '尚未配置机器翻译 provider')),
            'Expected at least one disabled reason to be localized for the missing provider.',
        );
        $this->assertNotContains('body missing', $targetLocale['preflight']['blockers']);
        $this->assertNotContains('seo description missing', $targetLocale['preflight']['blockers']);
    }

    private function createPublishedArticleGroup(string $slug): void
    {
        $source = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '中文 '.$slug,
            'excerpt' => '摘要',
            'content_md' => "## 参考文献\n\n- [1] source citation",
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $sourceRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $source->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
            'source_version_hash' => 'source-'.$slug,
            'translated_from_version_hash' => 'source-'.$slug,
            'title' => $source->title,
            'excerpt' => $source->excerpt,
            'content_md' => $source->content_md,
        ]);
        $source->forceFill([
            'working_revision_id' => (int) $sourceRevision->id,
            'published_revision_id' => (int) $sourceRevision->id,
            'source_version_hash' => 'source-'.$slug,
        ])->saveQuietly();

        $translation = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'en',
            'translation_group_id' => 'article-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
            'translated_from_version_hash' => 'source-'.$slug,
            'title' => 'EN '.$slug,
            'excerpt' => 'excerpt',
            'content_md' => "## References\n\n- [1] source citation",
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $translationRevision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $translation->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => 'source-'.$slug,
            'translated_from_version_hash' => 'source-'.$slug,
            'title' => $translation->title,
            'excerpt' => $translation->excerpt,
            'content_md' => $translation->content_md,
        ]);
        $translation->forceFill([
            'working_revision_id' => (int) $translationRevision->id,
            'published_revision_id' => (int) $translationRevision->id,
            'source_version_hash' => 'source-'.$slug,
        ])->saveQuietly();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $translation->id,
            'locale' => 'en',
            'seo_title' => 'SEO '.$slug,
            'seo_description' => 'SEO description',
            'is_indexable' => true,
        ]);
    }

    private function createSourceSupportArticle(string $slug): SupportArticle
    {
        return SupportArticle::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'title' => '中文 support',
            'summary' => 'support summary',
            'body_md' => 'support body',
            'body_html' => '<p>support body</p>',
            'support_category' => SupportArticle::CATEGORIES[0],
            'support_intent' => SupportArticle::INTENTS[0],
            'locale' => 'zh-CN',
            'translation_group_id' => 'support-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => SupportArticle::TRANSLATION_STATUS_SOURCE,
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'support seo',
            'seo_description' => 'support seo description',
            'canonical_path' => '/support/articles/'.$slug,
        ]);
    }

    private function createSourceInterpretationGuide(string $slug): InterpretationGuide
    {
        return InterpretationGuide::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'title' => '中文 guide',
            'summary' => 'guide summary',
            'body_md' => 'guide body',
            'body_html' => '<p>guide body</p>',
            'test_family' => InterpretationGuide::TEST_FAMILIES[0],
            'result_context' => InterpretationGuide::RESULT_CONTEXTS[0],
            'audience' => 'general',
            'locale' => 'zh-CN',
            'translation_group_id' => 'interpretation-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => InterpretationGuide::TRANSLATION_STATUS_SOURCE,
            'status' => InterpretationGuide::STATUS_PUBLISHED,
            'review_state' => InterpretationGuide::REVIEW_APPROVED,
            'published_at' => now(),
            'seo_title' => 'guide seo',
            'seo_description' => 'guide seo description',
            'canonical_path' => '/support/guides/'.$slug,
        ]);
    }

    private function createSourceContentPage(string $slug, string $path): ContentPage
    {
        return ContentPage::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'path' => $path,
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => ContentPage::PAGE_TYPES[0],
            'title' => '中文 page',
            'summary' => 'page summary',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'translation_group_id' => 'content-page-'.$slug,
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'content_md' => 'page body',
            'content_html' => '<p>page body</p>',
            'seo_title' => 'page seo',
            'seo_description' => 'page seo description',
            'meta_description' => 'page meta',
            'canonical_path' => $path,
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
    }

    private function createTargetTranslation(string $contentType, object $source, string $targetLocale): object
    {
        return match ($contentType) {
            'support_article' => SupportArticle::query()->create([
                'org_id' => 0,
                'slug' => $source->slug,
                'title' => 'EN support',
                'summary' => 'EN summary',
                'body_md' => 'EN body',
                'body_html' => '<p>EN body</p>',
                'support_category' => $source->support_category,
                'support_intent' => $source->support_intent,
                'locale' => $targetLocale,
                'translation_group_id' => $source->translation_group_id,
                'source_locale' => 'zh-CN',
                'translation_status' => SupportArticle::TRANSLATION_STATUS_DRAFT,
                'source_content_id' => $source->id,
                'translated_from_version_hash' => $source->source_version_hash,
                'status' => SupportArticle::STATUS_DRAFT,
                'review_state' => SupportArticle::REVIEW_DRAFT,
                'seo_title' => 'EN support seo',
                'seo_description' => 'EN support seo description',
                'canonical_path' => '/support/articles/'.$source->slug,
            ]),
            'interpretation_guide' => InterpretationGuide::query()->create([
                'org_id' => 0,
                'slug' => $source->slug,
                'title' => 'EN guide',
                'summary' => 'EN summary',
                'body_md' => 'EN body',
                'body_html' => '<p>EN body</p>',
                'test_family' => $source->test_family,
                'result_context' => $source->result_context,
                'audience' => $source->audience,
                'locale' => $targetLocale,
                'translation_group_id' => $source->translation_group_id,
                'source_locale' => 'zh-CN',
                'translation_status' => InterpretationGuide::TRANSLATION_STATUS_DRAFT,
                'source_content_id' => $source->id,
                'translated_from_version_hash' => $source->source_version_hash,
                'status' => InterpretationGuide::STATUS_DRAFT,
                'review_state' => InterpretationGuide::REVIEW_DRAFT,
                'seo_title' => 'EN guide seo',
                'seo_description' => 'EN guide seo description',
                'canonical_path' => '/support/guides/'.$source->slug,
            ]),
            'content_page' => ContentPage::query()->create([
                'org_id' => 0,
                'slug' => $source->slug,
                'path' => $source->path,
                'kind' => $source->kind,
                'page_type' => $source->page_type,
                'title' => 'EN page',
                'summary' => 'EN summary',
                'template' => $source->template,
                'animation_profile' => $source->animation_profile,
                'locale' => $targetLocale,
                'translation_group_id' => $source->translation_group_id,
                'source_locale' => 'zh-CN',
                'translation_status' => ContentPage::TRANSLATION_STATUS_DRAFT,
                'source_content_id' => $source->id,
                'translated_from_version_hash' => $source->source_version_hash,
                'content_md' => 'EN body',
                'content_html' => '<p>EN body</p>',
                'seo_title' => 'EN page seo',
                'seo_description' => 'EN page seo description',
                'meta_description' => 'EN meta',
                'canonical_path' => $source->path,
                'status' => ContentPage::STATUS_DRAFT,
                'review_state' => 'draft',
                'is_public' => false,
                'is_indexable' => true,
            ]),
        };
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdminWithPermissions(array $permissions): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'Ops Tester '.uniqid('', true),
            'guard_name' => (string) config('admin.guard', 'admin'),
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => (string) config('admin.guard', 'admin'),
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin = AdminUser::query()->create([
            'name' => 'Ops Tester',
            'email' => 'ops-tester-'.uniqid().'@example.test',
            'password' => bcrypt('password'),
        ]);
        $admin->roles()->sync([$role->id]);

        return $admin;
    }

    private function createOrganization(AdminUser $admin): Organization
    {
        return Organization::query()->create([
            'name' => 'CMS Translation Org '.uniqid(),
            'slug' => 'cms-translation-org-'.uniqid(),
            'owner_user_id' => (int) $admin->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSession(AdminUser $admin, Organization $organization): array
    {
        return [
            'ops_org_id' => (int) $organization->id,
            'ops_org_name' => (string) $organization->name,
            'ops_org_slug' => (string) $organization->slug,
            'ops_actor_admin_id' => (int) $admin->id,
        ];
    }
}
