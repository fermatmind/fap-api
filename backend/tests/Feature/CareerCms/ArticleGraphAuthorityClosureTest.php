<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use App\Models\CareerGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleGraphAuthorityClosureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const SIX_EDITORIAL_ARTICLES = [
        'how-personality-shapes-attitude-toward-ai',
        'which-love-script-fits-you-best',
        'are-infj-men-rare-or-socially-silenced',
        'best-valentines-date-by-personality-and-relationship-science',
        'how-16-personality-types-talk-to-an-ai-coach',
        'childhood-dream-job-still-shapes-career-choice',
    ];

    public function test_six_editorial_articles_are_reachable_from_backend_graph_authorities(): void
    {
        $this->importArticleBaseline();

        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--source-dir' => '../content_baselines/topics',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--guide' => ['how-to-find-right-career-direction'],
            '--source-dir' => '../content_baselines/career_guides',
        ])->assertExitCode(0);

        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/landing_surfaces',
        ])->assertExitCode(0);

        $this->assertSixArticlesArePublishedAndIndexable();
        $this->assertMbtiTopicArticleGraph();
        $this->assertBigFiveTopicArticleGraph();
        $this->assertCareerGuideArticleGraph();
        $this->assertHomepageRecommendedArticlesRemainBackendReferences();
        $this->assertSensitiveTestsDoNotReceiveEditorialArticleLinks();
    }

    private function importArticleBaseline(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])->assertExitCode(0);
    }

    private function assertSixArticlesArePublishedAndIndexable(): void
    {
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->whereIn('slug', self::SIX_EDITORIAL_ARTICLES)
            ->get()
            ->keyBy('slug');

        $this->assertSame(
            collect(self::SIX_EDITORIAL_ARTICLES)->sort()->values()->all(),
            $articles->keys()->sort()->values()->all()
        );

        foreach (self::SIX_EDITORIAL_ARTICLES as $slug) {
            /** @var Article $article */
            $article = $articles->get($slug);
            $this->assertSame('published', (string) $article->status, $slug);
            $this->assertTrue((bool) $article->is_public, $slug);
            $this->assertTrue((bool) $article->is_indexable, $slug);
            $this->assertNotNull($article->published_revision_id, $slug);
            $this->assertSame('editorial', (string) $article->voice, $slug);
        }
    }

    private function assertMbtiTopicArticleGraph(): void
    {
        $response = $this->getJson('/api/v0.5/topics/mbti?locale=zh-CN&org_id=0');
        $response->assertOk();

        $articleKeys = collect($response->json('entry_groups.articles', []))
            ->pluck('target_key')
            ->values()
            ->all();

        $this->assertSame([
            'how-personality-shapes-attitude-toward-ai',
            'how-16-personality-types-talk-to-an-ai-coach',
            'which-love-script-fits-you-best',
            'best-valentines-date-by-personality-and-relationship-science',
            'are-infj-men-rare-or-socially-silenced',
        ], $articleKeys);

        $this->assertSame('/zh/articles/how-personality-shapes-attitude-toward-ai', $response->json('entry_groups.articles.0.url'));
    }

    private function assertBigFiveTopicArticleGraph(): void
    {
        $response = $this->getJson('/api/v0.5/topics/big-five?locale=zh-CN&org_id=0');
        $response->assertOk();

        $articleKeys = collect($response->json('entry_groups.articles', []))
            ->pluck('target_key')
            ->values()
            ->all();

        $this->assertSame([
            'how-personality-shapes-attitude-toward-ai',
            'childhood-dream-job-still-shapes-career-choice',
        ], $articleKeys);
    }

    private function assertCareerGuideArticleGraph(): void
    {
        $guide = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'how-to-find-right-career-direction')
            ->firstOrFail();

        $this->assertSame(
            ['childhood-dream-job-still-shapes-career-choice'],
            $guide->relatedArticles()->withoutGlobalScopes()->pluck('articles.slug')->all()
        );

        $this->getJson('/api/v0.5/career-guides/how-to-find-right-career-direction?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('related_articles.0.slug', 'childhood-dream-job-still-shapes-career-choice');
    }

    private function assertHomepageRecommendedArticlesRemainBackendReferences(): void
    {
        $response = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0');
        $response->assertOk();

        $recommendedArticleSlugs = collect($response->json('surface.page_blocks', []))
            ->firstWhere('block_key', 'recommended_articles')['payload_json']['items'] ?? [];
        $recommendedArticleSlugs = collect($recommendedArticleSlugs)
            ->map(static fn (array $item): string => (string) data_get($item, 'article.slug'))
            ->values()
            ->all();

        foreach (self::SIX_EDITORIAL_ARTICLES as $slug) {
            $this->assertContains($slug, $recommendedArticleSlugs);
        }
    }

    private function assertSensitiveTestsDoNotReceiveEditorialArticleLinks(): void
    {
        $sensitiveTestSlugs = [
            'depression-screening-test-standard-edition',
            'clinical-depression-anxiety-assessment-professional-edition',
            'iq-test-intelligence-quotient-assessment',
        ];

        $relatedTestSlugs = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->whereIn('slug', self::SIX_EDITORIAL_ARTICLES)
            ->pluck('related_test_slug')
            ->filter()
            ->values()
            ->all();

        foreach ($sensitiveTestSlugs as $testSlug) {
            $this->assertNotContains($testSlug, $relatedTestSlugs);
        }
    }
}
