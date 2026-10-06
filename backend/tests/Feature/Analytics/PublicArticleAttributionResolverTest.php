<?php

namespace Tests\Feature\Analytics;

use App\Models\Article;
use App\Services\Analytics\PublicArticleAttributionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PublicArticleAttributionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_current_public_article_identity_can_attribute_a_real_attempt(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            $article = Article::withoutGlobalScopes()->create([
                'org_id' => 0, 'slug' => 'public-source', 'locale' => $locale,
                'title' => 'Public source', 'content_md' => '# Public source',
                'status' => 'published', 'is_public' => true,
                'published_at' => now()->subDay(),
            ]);
            $resolver = app(PublicArticleAttributionResolver::class);
            $path = ($locale === 'en' ? '/en/' : '/zh/').'articles/public-source';
            $attempt = ['locale' => $locale, 'answers_summary_json' => json_encode(['meta' => [
                'source_page_type' => 'article_detail', 'content_id' => $article->id,
                'source_slug' => $article->slug, 'landing_path' => $path,
            ]])];
            $this->assertSame($article->id, $resolver->fromAttempt($attempt)['article_id']);
            $article->update(['published_at' => now()->addDay()]);
            $this->assertNull($resolver->fromAttempt($attempt));
            $this->assertNull($resolver->byPublicArticleId($article->id));
            $article->update(['published_at' => null]);
            $this->assertNull($resolver->fromAttempt($attempt));
            $article->update(['published_at' => now()->subDay()]);
            $article->delete();
            $this->assertDatabaseHas('articles', ['id' => $article->id]);
            $this->assertNull($resolver->fromAttempt($attempt));
            $this->assertNull($resolver->byPublicArticleId($article->id));
        }
    }
}
