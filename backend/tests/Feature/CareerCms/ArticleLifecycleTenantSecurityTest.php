<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleService;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class ArticleLifecycleTenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_articles_cannot_be_published(): void
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'archived-publish-block',
            'locale' => 'en',
            'title' => 'Archived Publish Block',
            'content_md' => 'Archived content must not be republished.',
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_ARCHIVED,
            'is_public' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('archived or soft-deleted articles cannot be published.');

        app(ArticlePublishService::class)->publishArticle((int) $article->id);
    }

    public function test_publicly_readable_scope_excludes_archived_articles(): void
    {
        $active = $this->publishedArticle('active-public-article', Article::LIFECYCLE_ACTIVE);
        $this->publishedArticle('archived-public-article', Article::LIFECYCLE_ARCHIVED);

        $slugs = Article::query()
            ->withoutGlobalScopes()
            ->publiclyReadable()
            ->pluck('slug')
            ->all();

        $this->assertSame([(string) $active->slug], $slugs);
    }

    public function test_publicly_readable_scope_allows_exact_self_bound_source_revision(): void
    {
        $source = $this->publishedArticle(
            'public-source-article',
            Article::LIFECYCLE_ACTIVE,
            'zh-CN',
            ArticleTranslationRevision::STATUS_SOURCE,
        );

        $this->assertTrue(Article::query()->withoutGlobalScopes()->publiclyReadable()->whereKey($source->id)->exists());
    }

    public function test_publicly_readable_scope_rejects_non_self_bound_source_revision(): void
    {
        $source = $this->publishedArticle(
            'non-self-source-article',
            Article::LIFECYCLE_ACTIVE,
            'zh-CN',
            ArticleTranslationRevision::STATUS_SOURCE,
        );

        DB::table('article_translation_revisions')
            ->where('id', $source->published_revision_id)
            ->update(['source_article_id' => (int) $source->id + 1000]);

        $this->assertFalse(Article::query()->withoutGlobalScopes()->publiclyReadable()->whereKey($source->id)->exists());
    }

    public function test_publicly_readable_scope_rejects_translation_mislabeled_as_source(): void
    {
        $source = $this->publishedArticle(
            'translation-source-article',
            Article::LIFECYCLE_ACTIVE,
            'zh-CN',
            ArticleTranslationRevision::STATUS_SOURCE,
        );
        $translation = $this->publishedArticle(
            'mislabeled-english-translation',
            Article::LIFECYCLE_ACTIVE,
            'en',
            ArticleTranslationRevision::STATUS_SOURCE,
        );

        DB::table('articles')->where('id', $translation->id)->update([
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_locale' => 'zh-CN',
            'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
        ]);
        DB::table('article_translation_revisions')
            ->where('id', $translation->published_revision_id)
            ->update(['source_article_id' => (int) $source->id, 'source_locale' => 'zh-CN']);

        $this->assertFalse(Article::query()->withoutGlobalScopes()->publiclyReadable()->whereKey($translation->id)->exists());
    }

    public function test_publicly_readable_scope_rejects_unpublished_revision_statuses(): void
    {
        foreach ([
            ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            ArticleTranslationRevision::STATUS_APPROVED,
        ] as $index => $status) {
            $article = $this->publishedArticle(
                'unpublished-revision-'.$index,
                Article::LIFECYCLE_ACTIVE,
                'en',
                $status,
            );

            $this->assertFalse(
                Article::query()->withoutGlobalScopes()->publiclyReadable()->whereKey($article->id)->exists(),
                'Unexpectedly exposed revision status '.$status,
            );
        }
    }

    public function test_publicly_readable_scope_keeps_normal_published_revision_public(): void
    {
        $article = $this->publishedArticle('normal-published-article', Article::LIFECYCLE_ACTIVE);

        $this->assertTrue(Article::query()->withoutGlobalScopes()->publiclyReadable()->whereKey($article->id)->exists());
    }

    public function test_tenant_context_cannot_update_article_from_another_org(): void
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 7,
            'slug' => 'tenant-owned-article',
            'locale' => 'en',
            'title' => 'Tenant Owned Article',
            'content_md' => 'Original tenant article.',
            'status' => 'draft',
            'is_public' => false,
        ]);

        app(OrgContext::class)->set(8, 1001, 'admin', null, OrgContext::KIND_TENANT);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('article does not belong to the current org.');

        app(ArticleService::class)->updateArticle((int) $article->id, [
            'title' => 'Cross Tenant Update',
            'content_md' => 'This write must be rejected.',
        ]);
    }

    private function publishedArticle(
        string $slug,
        string $lifecycleState,
        string $locale = 'en',
        string $revisionStatus = ArticleTranslationRevision::STATUS_PUBLISHED,
    ): Article {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => str_replace('-', ' ', $slug),
            'content_md' => 'Published article content.',
            'status' => 'published',
            'is_public' => true,
            'lifecycle_state' => $lifecycleState,
            'published_at' => now()->subMinute(),
        ]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => $revisionStatus,
            'title' => (string) $article->title,
            'content_md' => (string) $article->content_md,
            'published_at' => now()->subMinute(),
        ]);

        $article->forceFill(['published_revision_id' => (int) $revision->id])->save();

        return $article;
    }
}
