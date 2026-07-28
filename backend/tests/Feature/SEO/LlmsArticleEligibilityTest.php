<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class LlmsArticleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_llms_surfaces_project_only_publicly_llms_eligible_articles(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->createPublishedArticle('included-article', 'zh-CN', true);
        $this->createPublishedArticle('excluded-from-llms', 'zh-CN', false);
        $this->createPublishedArticle('included-english-article', 'en', true);
        $this->createPublishedArticle('private-article', 'en', true, false);
        $careerCache = app(PublicCareerAuthorityResponseCache::class);
        $careerCache->publishDirectoryReadModel('en', ['items' => []]);
        $careerCache->publishDirectoryReadModel('zh-CN', ['items' => []]);

        $generator = app(SitemapGenerator::class);
        $sitemapLocations = collect($generator->generateUrls())->pluck('loc')->all();
        $llmsRows = $generator->generateLlmsUrls();
        $llmsLocations = collect($llmsRows)->pluck('loc')->all();

        $this->assertContains('https://fermatmind.com/zh/articles/excluded-from-llms', $sitemapLocations);
        $this->assertNotContains('https://fermatmind.com/zh/articles/excluded-from-llms', $llmsLocations);
        $this->assertContains('https://fermatmind.com/zh/articles/included-article', $llmsLocations);
        $this->assertNotContains('https://fermatmind.com/en/articles/private-article', $llmsLocations);
        $this->assertSame([], array_values(array_filter(
            $llmsRows,
            static fn (array $row): bool => array_key_exists('__llms_eligible', $row),
        )));

        foreach (['/llms.txt', '/llms-full.txt'] as $path) {
            Cache::forget('seo:llms-txt:v1:body');
            Cache::forget('seo:llms-full-txt:v1:body');
            $body = (string) $this->get($path)
                ->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
                ->getContent();

            $this->assertSame(1, substr_count($body, 'https://fermatmind.com/zh/articles/included-article'));
            $this->assertSame(1, substr_count($body, 'https://fermatmind.com/en/articles/included-english-article'));
            $this->assertStringContainsString('https://fermatmind.com/zh/articles', $body);
            $this->assertStringContainsString('https://fermatmind.com/en/articles', $body);
            $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/excluded-from-llms', $body);
            $this->assertStringNotContainsString('https://fermatmind.com/en/articles/private-article', $body);
        }
    }

    private function createPublishedArticle(
        string $slug,
        string $locale,
        bool $llmsEligible,
        bool $isPublic = true,
    ): void {
        $publishedAt = Carbon::create(2026, 7, 28, 12, 0, 0, 'UTC');
        $article = Article::query()->create([
            'org_id' => 0,
            'slug' => $slug,
            'translation_group_id' => 'test-'.$locale.'-'.$slug,
            'locale' => $locale,
            'source_locale' => $locale,
            'title' => 'Article '.$slug,
            'excerpt' => 'Eligibility fixture.',
            'content_md' => '# Article',
            'status' => 'published',
            'is_public' => $isPublic,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => $llmsEligible,
            'published_at' => $publishedAt,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'published_at' => $publishedAt,
        ]);

        $article->forceFill(['published_revision_id' => $revision->id])->save();
    }
}
