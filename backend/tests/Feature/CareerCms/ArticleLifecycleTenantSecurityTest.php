<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ArticleService;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function publishedArticle(string $slug, string $lifecycleState): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => 'en',
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
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => (string) $article->title,
            'content_md' => (string) $article->content_md,
            'published_at' => now()->subMinute(),
        ]);

        $article->forceFill(['published_revision_id' => (int) $revision->id])->save();

        return $article;
    }
}
