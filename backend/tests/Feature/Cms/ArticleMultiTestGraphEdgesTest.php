<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTestEdge;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticleMultiTestGraphEdgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_article_list_filter_matches_visible_multi_test_edges(): void
    {
        $article = $this->publishedArticle('multi-signal-article', 'en', null);

        ArticleTestEdge::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'test_slug' => 'big-five-personality-test',
            'role' => ArticleTestEdge::ROLE_SECONDARY,
            'sort_order' => 20,
            'safety_level' => ArticleTestEdge::SAFETY_NORMAL,
            'visibility' => ArticleTestEdge::VISIBILITY_PUBLIC,
            'source' => 'test',
        ]);

        $this->getJson('/api/v0.5/articles?locale=en&org_id=0&related_test_slug=big-five-personality-test')
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'multi-signal-article')
            ->assertJsonPath('items.0.related_test_slug', null)
            ->assertJsonPath('items.0.related_test_slugs.0', 'big-five-personality-test')
            ->assertJsonPath('items.0.test_edges.0.test_slug', 'big-five-personality-test')
            ->assertJsonPath('items.0.test_edges.0.role', ArticleTestEdge::ROLE_SECONDARY);
    }

    public function test_legacy_related_test_slug_still_filters_articles_without_edges(): void
    {
        $this->publishedArticle(
            'legacy-primary-article',
            'zh-CN',
            'mbti-personality-test-16-personality-types'
        );

        $this->getJson('/api/v0.5/articles?locale=zh-CN&org_id=0&related_test_slug=mbti-personality-test-16-personality-types')
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'legacy-primary-article')
            ->assertJsonPath('items.0.related_test_slug', 'mbti-personality-test-16-personality-types')
            ->assertJsonPath('items.0.related_test_slugs.0', 'mbti-personality-test-16-personality-types');
    }

    public function test_editorial_package_import_persists_multiple_test_edges_with_sensitive_guard(): void
    {
        $path = $this->writePackage([
            'package_version' => 'editorial_package.v1',
            'title' => 'What is an MBTI and Big Five combined guide?',
            'slug' => 'multi-test-editorial-draft',
            'locale' => 'en',
            'author' => 'Fermat Institute',
            'intended_status' => 'draft',
            'body_markdown' => "# Multi-test guide\n\n## What is this guide?\n\nIt defines the concept.\n\n## Methodology and theory\n\nIt explains dimensions and method.\n\n## FAQ\n\n### Is this diagnostic?\n\nNo.\n\n## Conclusion\n\nUse it as one input.",
            'references' => ['John et al. (2008). Big Five taxonomy.'],
            'seo_title' => 'What is an MBTI and Big Five combined guide?',
            'meta_description' => 'A careful guide to multiple personality signals.',
            'excerpt' => 'A careful guide to multiple personality signals.',
            'canonical' => '',
            'indexability' => true,
            'content_track' => 'evergreen_knowledge',
            'category' => 'Personality Psychology',
            'tags' => ['MBTI', 'Big Five'],
            'topic_cluster' => 'mbti',
            'content_series' => '',
            'audience_intent' => 'self_understanding',
            'commercial_priority' => 'medium',
            'signal_source' => 'MBTI',
            'signal_type' => 'identity',
            'decision_domains' => ['self'],
            'target_tests' => [
                'mbti-personality-test-16-personality-types',
                'big-five-personality-test',
                'depression-screening-test-standard-edition',
            ],
            'target_topics' => ['mbti'],
            'target_personality_pages' => [],
            'target_career_pages' => [],
            'target_reports' => [],
            'next_action' => 'start_mbti_test',
            'internal_links' => ['/en/tests/mbti-personality-test-16-personality-types', '/en/topics/mbti'],
            'graph_edges' => [
                'from_article_to_test' => [
                    [
                        'test_slug' => 'holland-career-interest-test-riasec',
                        'role' => ArticleTestEdge::ROLE_CONTEXTUAL,
                        'sort_order' => 40,
                    ],
                ],
                'from_article_to_topic' => ['mbti'],
            ],
            'recommended_reverse_links' => [],
            'cover_image' => 'https://api.fermatmind.com/static/articles/covers/multi-test-editorial-draft.svg',
            'cover_image_alt' => 'Abstract personality map.',
            'cover_image_prompt' => 'Academic editorial abstract personality map.',
            'cover_image_style_tag' => 'academic-editorial',
            'answer_surface_policy' => 'none',
            'answer_surface_v1' => [],
            'answer_surface_visibility' => 'disabled',
            'cta_slots' => [
                ['position' => 'after_summary', 'label' => 'Start MBTI', 'href' => '/en/tests/mbti-personality-test-16-personality-types'],
            ],
            'primary_cta' => 'Start MBTI',
            'secondary_cta' => '',
            'freemium_entry' => '',
            'report_upsell_allowed' => false,
            'claim_boundary_notes' => 'No diagnosis or prediction.',
            'claim_level' => 'evidence_supported',
            'sensitivity_level' => 'normal',
            'medical_disclaimer_required' => false,
            'ability_disclaimer_required' => false,
            'external_references_required' => true,
            'review_required_by' => ['editor'],
        ]);

        $this->artisan('articles:import-editorial-package', [
            '--file' => $path,
            '--locale' => 'en',
        ])
            ->expectsOutputToContain('errors_count=0')
            ->assertExitCode(0);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->with('testEdges')
            ->where('slug', 'multi-test-editorial-draft')
            ->firstOrFail();

        $this->assertSame('mbti-personality-test-16-personality-types', (string) $article->related_test_slug);
        $this->assertSame(
            [
                'mbti-personality-test-16-personality-types',
                'big-five-personality-test',
                'depression-screening-test-standard-edition',
                'holland-career-interest-test-riasec',
            ],
            $article->testEdges->pluck('test_slug')->values()->all()
        );
        $this->assertSame(ArticleTestEdge::ROLE_PRIMARY, (string) $article->testEdges[0]->role);
        $this->assertSame(
            ArticleTestEdge::SAFETY_SENSITIVE,
            (string) $article->testEdges->firstWhere('test_slug', 'depression-screening-test-standard-edition')?->safety_level
        );
    }

    private function publishedArticle(string $slug, string $locale, ?string $relatedTestSlug): Article
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'name' => 'Personality Psychology',
            'slug' => 'personality-psychology',
        ]);

        /** @var Article $article */
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'Fermat Institute',
            'reading_minutes' => 4,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Related article',
            'excerpt' => 'Related article excerpt.',
            'content_md' => "# Related article\n\nBody.",
            'related_test_slug' => $relatedTestSlug,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::parse('2026-05-15 00:00:00', 'UTC'),
        ]);

        /** @var ArticleTranslationRevision $revision */
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => $locale,
            'source_locale' => $locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'published_at' => Carbon::parse('2026-05-15 00:00:00', 'UTC'),
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        return $article->fresh() ?? $article;
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = storage_path('framework/testing/editorial-package-'.uniqid('', true).'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
