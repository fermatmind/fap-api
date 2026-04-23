<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Resources\ArticleResource\Pages\EditArticle;
use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ArticleTranslationRevisionContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_revision_backfill_creates_source_and_human_review_revisions(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        ArticleSeoMeta::query()->create([
            'article_id' => $source->id,
            'seo_title' => '中文 SEO 标题',
            'seo_description' => '中文 SEO 描述',
        ]);

        $translation = $this->createArticle(1, 'en', 'Reviewed English draft', [
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->runRevisionBackfill();

        $source->refresh();
        $translation->refresh();

        $this->assertNull($source->source_article_id);
        $this->assertNotNull($source->working_revision_id);
        $this->assertNull($source->published_revision_id);
        $this->assertSame($source->id, $translation->source_article_id);
        $this->assertNotNull($translation->working_revision_id);
        $this->assertNull($translation->published_revision_id);

        $sourceRevision = $source->workingRevision;
        $translationRevision = $translation->workingRevision;

        $this->assertInstanceOf(ArticleTranslationRevision::class, $sourceRevision);
        $this->assertSame($source->id, $sourceRevision->article_id);
        $this->assertSame($source->id, $sourceRevision->source_article_id);
        $this->assertSame(1, $sourceRevision->revision_number);
        $this->assertSame(ArticleTranslationRevision::STATUS_SOURCE, $sourceRevision->revision_status);
        $this->assertSame($source->source_version_hash, $sourceRevision->source_version_hash);
        $this->assertSame($source->source_version_hash, $sourceRevision->translated_from_version_hash);
        $this->assertSame('中文 SEO 标题', $sourceRevision->seo_title);
        $this->assertSame('中文 SEO 描述', $sourceRevision->seo_description);

        $this->assertInstanceOf(ArticleTranslationRevision::class, $translationRevision);
        $this->assertSame($translation->id, $translationRevision->article_id);
        $this->assertSame($source->id, $translationRevision->source_article_id);
        $this->assertSame($source->translation_group_id, $translationRevision->translation_group_id);
        $this->assertSame('en', $translationRevision->locale);
        $this->assertSame('zh-CN', $translationRevision->source_locale);
        $this->assertSame(1, $translationRevision->revision_number);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $translationRevision->revision_status);
        $this->assertSame($source->source_version_hash, $translationRevision->source_version_hash);
        $this->assertSame($source->source_version_hash, $translationRevision->translated_from_version_hash);
        $this->assertNull($translationRevision->reviewed_at);
        $this->assertNull($translationRevision->approved_at);
        $this->assertFalse($translationRevision->isStale($source));
    }

    public function test_published_articles_backfill_published_revision_pointer(): void
    {
        $published = $this->createArticle(1, 'en', 'Published source', [
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->runRevisionBackfill();

        $published->refresh();

        $this->assertNotNull($published->working_revision_id);
        $this->assertSame($published->working_revision_id, $published->published_revision_id);
        $this->assertNotNull($published->publishedRevision?->published_at);
    }

    public function test_same_locale_can_create_new_revision_without_new_article_row(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        $translation = $this->createArticle(1, 'en', 'Reviewed English draft', [
            'slug' => 'stable-article-slug',
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->runRevisionBackfill();
        $translation->refresh();

        ArticleTranslationRevision::query()->create([
            'org_id' => $translation->org_id,
            'article_id' => $translation->id,
            'source_article_id' => $source->id,
            'translation_group_id' => $translation->translation_group_id,
            'locale' => $translation->locale,
            'source_locale' => $translation->source_locale,
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => $source->source_version_hash,
            'translated_from_version_hash' => $source->source_version_hash,
            'supersedes_revision_id' => $translation->working_revision_id,
            'title' => 'Second English machine draft',
            'excerpt' => 'Second draft excerpt',
            'content_md' => 'Second draft body',
        ]);

        $this->assertSame(1, Article::query()
            ->where('org_id', 1)
            ->where('locale', 'en')
            ->where('slug', 'stable-article-slug')
            ->count());
        $this->assertSame(2, $translation->translationRevisions()->count());
    }

    public function test_backfill_smoke_covers_current_source_and_en_translation_id_window(): void
    {
        $slugs = [
            17 => 'how-personality-shapes-attitude-toward-ai',
            18 => 'which-love-script-fits-you-best',
            19 => 'are-infj-men-rare-or-socially-silenced',
            20 => 'best-valentines-date-by-personality-and-relationship-science',
            21 => 'how-16-personality-types-talk-to-an-ai-coach',
            22 => 'childhood-dream-job-still-shapes-career-choice',
        ];

        Article::unguarded(function () use ($slugs): void {
            foreach ($slugs as $id => $slug) {
                $source = $this->createArticle(1, 'zh-CN', '中文源文 '.$id, [
                    'id' => $id,
                    'slug' => $slug,
                    'translation_group_id' => 'article-group-'.$id,
                ]);

                $this->createArticle(1, 'en', 'Reviewed English draft '.$id, [
                    'id' => $id + 7,
                    'slug' => $slug,
                    'translation_group_id' => $source->translation_group_id,
                    'source_locale' => $source->source_locale,
                    'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
                    'translated_from_article_id' => $source->id,
                    'translated_from_version_hash' => $source->source_version_hash,
                ]);
            }
        });

        $this->runRevisionBackfill();

        $articleIds = [17, 18, 19, 20, 21, 22, 24, 25, 26, 27, 28, 29];

        $this->assertSame(12, Article::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $articleIds)
            ->whereNotNull('working_revision_id')
            ->count());
        $this->assertSame(12, ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereIn('article_id', $articleIds)
            ->where('revision_number', 1)
            ->count());
        $this->assertSame(6, ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereIn('article_id', [17, 18, 19, 20, 21, 22])
            ->where('revision_status', ArticleTranslationRevision::STATUS_SOURCE)
            ->count());
        $this->assertSame(6, ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereIn('article_id', [24, 25, 26, 27, 28, 29])
            ->where('revision_status', ArticleTranslationRevision::STATUS_HUMAN_REVIEW)
            ->count());
    }

    public function test_revision_stale_and_supersedes_relationship_are_available(): void
    {
        $source = $this->createArticle(1, 'zh-CN', '中文源文');
        $translation = $this->createArticle(1, 'en', 'Reviewed English draft', [
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => $source->source_locale,
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ]);

        $this->runRevisionBackfill();

        $source->forceFill(['title' => '中文源文已更新'])->save();
        $translation->refresh();
        $oldRevision = $translation->workingRevision;

        $this->assertTrue($oldRevision->isStale($source));

        $newRevision = ArticleTranslationRevision::query()->create([
            'org_id' => $translation->org_id,
            'article_id' => $translation->id,
            'source_article_id' => $source->id,
            'translation_group_id' => $translation->translation_group_id,
            'locale' => $translation->locale,
            'source_locale' => $translation->source_locale,
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'source_version_hash' => $source->source_version_hash,
            'translated_from_version_hash' => $source->source_version_hash,
            'supersedes_revision_id' => $oldRevision->id,
            'title' => 'Resynced English machine draft',
            'excerpt' => 'Resync excerpt',
            'content_md' => 'Resync body',
        ]);

        $translation->forceFill(['working_revision_id' => $newRevision->id])->save();

        $this->assertFalse($newRevision->isStale($source));
        $this->assertTrue($newRevision->supersedes->is($oldRevision));
        $this->assertTrue($oldRevision->supersededBy->contains($newRevision));
        $this->assertSame(Article::TRANSLATION_STATUS_HUMAN_REVIEW, $translation->refresh()->translation_status);
    }

    public function test_editor_cutover_reconciliation_preserves_newer_canonical_content(): void
    {
        $article = $this->createArticle(1, 'en', 'Initial canonical title');
        ArticleSeoMeta::query()->create([
            'article_id' => $article->id,
            'seo_title' => 'Initial SEO title',
            'seo_description' => 'Initial SEO description',
        ]);

        $this->runRevisionBackfill();

        $article->forceFill([
            'title' => 'Latest human reviewed title',
            'excerpt' => 'Latest human reviewed excerpt',
            'content_md' => 'Latest human reviewed body with references intact',
        ])->save();
        $article->seoMeta()->update([
            'seo_title' => 'Latest SEO title',
            'seo_description' => 'Latest SEO description',
        ]);

        $this->runEditorCutoverReconciliation();

        $article->refresh();
        $revision = $article->workingRevision;

        $this->assertInstanceOf(ArticleTranslationRevision::class, $revision);
        $this->assertSame('Latest human reviewed title', $revision->title);
        $this->assertSame('Latest human reviewed excerpt', $revision->excerpt);
        $this->assertSame('Latest human reviewed body with references intact', $revision->content_md);
        $this->assertSame('Latest SEO title', $revision->seo_title);
        $this->assertSame('Latest SEO description', $revision->seo_description);
        $this->assertSame($article->source_version_hash, $revision->source_version_hash);
    }

    public function test_workspace_save_writes_working_revision_without_overwriting_canonical_body(): void
    {
        $article = $this->createArticle(1, 'en', 'Canonical title');
        $this->runRevisionBackfill();

        $workspace = app(ArticleTranslationRevisionWorkspace::class);
        $workspace->saveWorkingRevision($article, [
            'title' => 'Revision title',
            'excerpt' => 'Revision excerpt',
            'content_md' => 'Revision body',
            'seo_title' => 'Revision SEO title',
            'seo_description' => 'Revision SEO description',
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
        ]);

        $article->refresh();
        $revision = $article->workingRevision;

        $this->assertSame('Canonical title', $article->title);
        $this->assertSame('Translation revision excerpt', $article->excerpt);
        $this->assertSame('Translation revision body', $article->content_md);
        $this->assertSame('Revision title', $revision?->title);
        $this->assertSame('Revision excerpt', $revision?->excerpt);
        $this->assertSame('Revision body', $revision?->content_md);
        $this->assertSame('Revision SEO title', $revision?->seo_title);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $revision?->revision_status);
    }

    public function test_runtime_resolver_does_not_overwrite_working_revision_after_canonical_metadata_changes(): void
    {
        $article = $this->createArticle(1, 'en', 'Canonical title');
        $this->runRevisionBackfill();

        $workspace = app(ArticleTranslationRevisionWorkspace::class);
        $workspace->saveWorkingRevision($article, [
            'title' => 'Protected revision title',
            'excerpt' => 'Protected revision excerpt',
            'content_md' => 'Protected revision body',
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
        ]);

        $article->refresh();
        $article->forceFill([
            'reviewer_name' => 'Metadata Reviewer',
        ])->save();

        $revision = $workspace->resolveWorkingRevision($article->refresh());

        $this->assertSame('Canonical title', $article->title);
        $this->assertSame('Protected revision title', $revision->title);
        $this->assertSame('Protected revision excerpt', $revision->excerpt);
        $this->assertSame('Protected revision body', $revision->content_md);
    }

    public function test_filament_article_editor_saves_working_revision_not_canonical_body(): void
    {
        $admin = $this->createAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);
        $org = $this->createOrganization();
        app(OrgContext::class)->set((int) $org->id, (int) $admin->id, 'admin');

        $article = $this->createArticle((int) $org->id, 'en', 'Canonical editor title');
        $this->runRevisionBackfill();
        $article->refresh();

        session($this->opsSession($admin, $org));
        $this->actingAs($admin, (string) config('admin.guard', 'admin'));

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm([
                'title' => 'Editor revision title',
                'slug' => $article->slug,
                'excerpt' => 'Editor revision excerpt',
                'content_md' => 'Editor revision body',
                'status' => 'draft',
                'is_public' => false,
                'is_indexable' => true,
                'locale' => 'en',
                'seo_title' => 'Editor revision SEO title',
                'seo_description' => 'Editor revision SEO description',
                'canonical_url' => 'https://example.test/articles/canonical-editor-title',
                'og_title' => 'Compatibility OG title',
                'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $article->refresh();
        $revision = $article->workingRevision;

        $this->assertSame('Canonical editor title', $article->title);
        $this->assertSame('Translation revision excerpt', $article->excerpt);
        $this->assertSame('Translation revision body', $article->content_md);
        $this->assertSame('Editor revision title', $revision?->title);
        $this->assertSame('Editor revision excerpt', $revision?->excerpt);
        $this->assertSame('Editor revision body', $revision?->content_md);
        $this->assertSame('Editor revision SEO title', $revision?->seo_title);
        $this->assertSame(ArticleTranslationRevision::STATUS_HUMAN_REVIEW, $revision?->revision_status);
        $this->assertSame('https://example.test/articles/canonical-editor-title', $article->seoMeta?->canonical_url);
        $this->assertSame('Compatibility OG title', $article->seoMeta?->og_title);
        $this->assertNull($article->seoMeta?->seo_title);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(int $orgId, string $locale, string $title, array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'org_id' => $orgId,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'locale' => $locale,
            'title' => $title,
            'excerpt' => 'Translation revision excerpt',
            'content_md' => 'Translation revision body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ], $overrides));
    }

    private function runRevisionBackfill(): void
    {
        $migration = require database_path('migrations/2026_04_23_010000_create_article_translation_revisions_table.php');
        $migration->up();
    }

    private function runEditorCutoverReconciliation(): void
    {
        $migration = require database_path('migrations/2026_04_23_020000_reconcile_article_working_revisions_for_editor_cutover.php');
        $migration->up();
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
            'name' => 'article_revision_cutover_'.Str::lower(Str::random(6)),
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

    private function createOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Article Revision Cutover Org',
            'owner_user_id' => 9101,
            'status' => 'active',
            'domain' => 'article-revision-cutover.example.test',
            'timezone' => 'Asia/Shanghai',
            'locale' => 'en',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opsSession(AdminUser $admin, Organization $org): array
    {
        return [
            'ops_org_id' => $org->id,
            'ops_locale' => 'en',
            'ops_admin_totp_verified_user_id' => $admin->id,
        ];
    }
}
