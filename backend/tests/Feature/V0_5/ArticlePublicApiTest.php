<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticlePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_locale_query_filters_items_and_pagination(): void
    {
        foreach (range(1, 21) as $index) {
            $this->createArticle([
                'slug' => sprintf('english-article-%02d', $index),
                'locale' => 'en',
                'title' => sprintf('English Article %02d', $index),
                'published_at' => Carbon::create(2026, 3, 10, 8, $index, 0, 'UTC'),
                'updated_at' => Carbon::create(2026, 3, 10, 9, $index, 0, 'UTC'),
            ]);
        }

        $zhArticle = $this->createArticle([
            'slug' => 'zh-only-article',
            'locale' => 'zh-CN',
            'title' => '中文文章',
            'published_at' => Carbon::create(2026, 3, 11, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 11, 9, 0, 0, 'UTC'),
        ]);

        $enPageOne = $this->getJson('/api/v0.5/articles?locale=en&page=1');

        $enPageOne->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 21)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(20, 'items')
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'article_index')
            ->assertJsonPath('landing_surface_v1.entry_type', 'content_hub');

        $this->assertSame(
            ['en'],
            array_values(array_unique(array_column($enPageOne->json('items') ?? [], 'locale')))
        );

        $this->getJson('/api/v0.5/articles?locale=en&page=2')
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.total', 21)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.locale', 'en');

        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', (string) $zhArticle->slug)
            ->assertJsonPath('items.0.locale', 'zh-CN');
    }

    public function test_article_seo_detail_returns_localized_frontend_canonical_alternates_and_jsonld_urls(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $articleEn = $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'en',
            'title' => 'MBTI Basics',
            'excerpt' => 'Learn the core concepts behind MBTI.',
        ]);
        $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'title' => 'MBTI 基础',
            'excerpt' => '了解 MBTI 的核心概念。',
        ]);

        $legacyCanonical = 'https://api.staging.fermatmind.com/articles/mbti-basics';
        $this->createSeoMeta($articleEn, [
            'seo_title' => 'MBTI Basics | FermatMind',
            'seo_description' => 'Learn the core concepts behind MBTI.',
            'canonical_url' => $legacyCanonical,
            'schema_json' => [
                '@id' => $legacyCanonical.'#article',
                'url' => $legacyCanonical,
                'mainEntityOfPage' => $legacyCanonical.'#webpage',
            ],
        ]);

        $canonical = 'https://staging.fermatmind.com/en/articles/mbti-basics';
        $zhCanonical = 'https://staging.fermatmind.com/zh/articles/mbti-basics';

        $response = $this->getJson('/api/v0.5/articles/mbti-basics/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.canonical', $canonical)
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'article_public_detail')
            ->assertJsonPath('jsonld.@type', 'Article')
            ->assertJsonPath('meta.alternates.en', $canonical)
            ->assertJsonPath('meta.alternates.zh', $zhCanonical)
            ->assertJsonPath('meta.alternates.zh-CN', $zhCanonical)
            ->assertJsonPath('jsonld.url', $canonical)
            ->assertJsonPath('jsonld.mainEntityOfPage', $canonical.'#webpage')
            ->assertJsonMissingPath('jsonld.publisher')
            ->assertJsonMissingPath('jsonld.license')
            ->assertJsonMissingPath('jsonld.distribution')
            ->assertJsonMissingPath('jsonld.downloadUrl');

        $this->assertSame($canonical.'#article', data_get($response->json(), 'jsonld.@id'));
        $this->assertStringNotContainsString($legacyCanonical, (string) $response->getContent());
    }

    public function test_article_detail_includes_landing_and_answer_surfaces(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $article = $this->createArticle([
            'slug' => 'career-fit-guide',
            'locale' => 'en',
            'title' => 'Legacy Career Fit Guide',
            'excerpt' => 'Legacy article-level insight.',
        ], [
            'title' => 'Career Fit Guide',
            'excerpt' => 'Use article-level insight to continue into tests and public hubs.',
            'content_md' => 'Revision-backed public article body.',
        ]);

        $this->createSeoMeta($article, [
            'seo_title' => 'Career Fit Guide | FermatMind',
            'seo_description' => 'Use article-level insight to continue into tests and public hubs.',
            'canonical_url' => 'https://www.fermatmind.com/en/articles/career-fit-guide',
            'schema_json' => [
                'url' => 'https://www.fermatmind.com/en/articles/career-fit-guide',
                'mainEntityOfPage' => 'https://www.fermatmind.com/en/articles/career-fit-guide',
            ],
        ]);

        $response = $this->getJson('/api/v0.5/articles/career-fit-guide?locale=en');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'article_detail')
            ->assertJsonPath('landing_surface_v1.entry_type', 'editorial_article')
            ->assertJsonPath('seo_surface_v1.canonical_url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('seo_surface_v1.alternates.en', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('seo_surface_v1.og_payload.url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.surface_type', 'article_public_detail')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.key', 'article_summary')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.title', 'Career Fit Guide')
            ->assertJsonPath('article.title', 'Career Fit Guide')
            ->assertJsonPath('article.excerpt', 'Use article-level insight to continue into tests and public hubs.')
            ->assertJsonPath('article.content_md', 'Revision-backed public article body.')
            ->assertJsonPath('article.seo_meta.canonical_url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('article.seo_meta.schema_json.url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/articles');

        $this->assertStringNotContainsString('www.fermatmind.com', (string) $response->getContent());
    }

    public function test_public_reads_require_published_revision_and_hide_human_review(): void
    {
        $visible = $this->createArticle([
            'slug' => 'visible-article',
            'locale' => 'en',
            'title' => 'Legacy visible article',
        ], [
            'title' => 'Published visible article',
        ]);

        $missingPointer = $this->createArticle([
            'slug' => 'missing-published-revision',
            'locale' => 'en',
            'title' => 'Missing published revision',
        ], [], false);

        $humanReview = $this->createArticle([
            'slug' => 'human-review-leak-guard',
            'locale' => 'en',
            'title' => 'Human review canonical',
        ], [], false);
        $humanReviewRevision = $this->createRevision($humanReview, [
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => 'Human review title must not leak',
            'published_at' => null,
        ]);
        $humanReview->forceFill(['published_revision_id' => $humanReviewRevision->id])->save();

        $response = $this->getJson('/api/v0.5/articles?locale=en&page=1');

        $response->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', (string) $visible->slug)
            ->assertJsonPath('items.0.title', 'Published visible article');

        $this->getJson('/api/v0.5/articles/'.$missingPointer->slug.'?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles/'.$humanReview->slug.'?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles/'.$humanReview->slug.'/seo?locale=en')
            ->assertNotFound();
    }

    public function test_detail_does_not_fallback_to_source_locale_when_translation_unpublished(): void
    {
        $source = $this->createArticle([
            'slug' => 'shared-translation-slug',
            'locale' => 'zh-CN',
            'title' => '中文源文 legacy',
        ], [
            'title' => '中文源文 published revision',
            'excerpt' => '中文公开摘要',
            'content_md' => '中文公开正文',
        ]);

        $translation = $this->createArticle([
            'slug' => 'shared-translation-slug',
            'locale' => 'en',
            'title' => 'English canonical human review',
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'source_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ], [], false);
        $this->createRevision($translation, [
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => 'Human review English draft',
            'published_at' => null,
        ]);

        $this->getJson('/api/v0.5/articles/shared-translation-slug?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('article.title', '中文源文 published revision');

        $this->getJson('/api/v0.5/articles/shared-translation-slug?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles?locale=en&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_public_seo_uses_published_revision_and_excludes_unpublished_alternates(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $articleEn = $this->createArticle([
            'slug' => 'revision-seo-source',
            'locale' => 'en',
            'title' => 'Legacy SEO title',
            'excerpt' => 'Legacy SEO excerpt.',
        ], [
            'title' => 'Published revision SEO title',
            'excerpt' => 'Published revision excerpt.',
            'content_md' => 'Published revision body.',
            'seo_title' => 'Published Revision SEO | FermatMind',
            'seo_description' => 'Published revision SEO description.',
        ]);
        $this->createSeoMeta($articleEn, [
            'seo_title' => 'Legacy Article SEO | FermatMind',
            'seo_description' => 'Legacy article SEO description.',
        ]);

        $this->createArticle([
            'slug' => 'revision-seo-source',
            'locale' => 'zh-CN',
            'title' => '未发布中文 sibling',
        ], [], false);

        $response = $this->getJson('/api/v0.5/articles/revision-seo-source/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.title', 'Published Revision SEO | FermatMind')
            ->assertJsonPath('meta.description', 'Published revision SEO description.')
            ->assertJsonPath('jsonld.headline', 'Published Revision SEO | FermatMind')
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/revision-seo-source');

        $this->assertNull(data_get($response->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($response->json(), 'meta.alternates.zh-CN'));
        $this->assertStringNotContainsString('Legacy Article SEO', (string) $response->getContent());
    }

    public function test_current_17_to_29_draft_and_human_review_samples_are_not_public(): void
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
                $source = $this->createArticle([
                    'id' => $id,
                    'slug' => $slug,
                    'locale' => 'zh-CN',
                    'title' => '中文源文 '.$id,
                    'status' => 'draft',
                    'is_public' => false,
                    'published_at' => null,
                    'translation_group_id' => 'article-group-'.$id,
                ], [], false);
                $this->createRevision($source, [
                    'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
                    'title' => '中文源文 revision '.$id,
                    'published_at' => null,
                ]);

                $translation = $this->createArticle([
                    'id' => $id + 7,
                    'slug' => $slug,
                    'locale' => 'en',
                    'title' => 'English human review '.$id,
                    'status' => 'draft',
                    'is_public' => false,
                    'published_at' => null,
                    'translation_group_id' => $source->translation_group_id,
                    'source_locale' => 'zh-CN',
                    'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
                    'translated_from_article_id' => $source->id,
                    'source_article_id' => $source->id,
                    'translated_from_version_hash' => $source->source_version_hash,
                ], [], false);
                $this->createRevision($translation, [
                    'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                    'title' => 'English human review revision '.$id,
                    'published_at' => null,
                ]);
            }
        });

        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');
        $this->getJson('/api/v0.5/articles?locale=en&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');

        foreach ($slugs as $slug) {
            $this->getJson('/api/v0.5/articles/'.$slug.'?locale=zh-CN')->assertNotFound();
            $this->getJson('/api/v0.5/articles/'.$slug.'?locale=en')->assertNotFound();
        }
    }

    public function test_article_seo_does_not_fake_missing_locale_alternates(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $this->createArticle([
            'slug' => 'solo-article',
            'locale' => 'en',
            'title' => 'Solo Article',
            'excerpt' => 'Only one locale exists for this article.',
        ]);

        $canonical = 'https://staging.fermatmind.com/en/articles/solo-article';
        $response = $this->getJson('/api/v0.5/articles/solo-article/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.canonical', $canonical)
            ->assertJsonPath('meta.alternates.en', $canonical)
            ->assertJsonPath('jsonld.url', $canonical)
            ->assertJsonPath('jsonld.mainEntityOfPage', $canonical);

        $this->assertNull(data_get($response->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($response->json(), 'meta.alternates.zh-CN'));
    }

    public function test_detail_and_seo_null_blocked_media_urls(): void
    {
        $article = $this->createArticle([
            'slug' => 'guarded-article',
            'locale' => 'en',
            'title' => 'Guarded article',
            'cover_image_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/article.png',
        ]);
        $this->createSeoMeta($article, [
            'og_image_url' => 'https://ci.example.test/article.png?ci-process=cover',
        ]);

        $this->getJson('/api/v0.5/articles/guarded-article?locale=en')
            ->assertOk()
            ->assertJsonPath('article.cover_image_url', null)
            ->assertJsonPath('article.seo_meta.og_image_url', null);

        $this->getJson('/api/v0.5/articles/guarded-article/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.og.image', null)
            ->assertJsonPath('meta.twitter.image', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(
        array $overrides = [],
        array $revisionOverrides = [],
        bool $withPublishedRevision = true
    ): Article {
        /** @var Article */
        $article = Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'article-slug',
            'locale' => 'en',
            'title' => 'Article Title',
            'excerpt' => 'Article excerpt.',
            'content_md' => '# Article body',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 9, 9, 0, 0, 'UTC'),
        ], $overrides));

        if ($withPublishedRevision) {
            $revision = $this->createRevision($article, $revisionOverrides);
            $article->forceFill(['published_revision_id' => $revision->id])->save();
        }

        return $article->fresh(['publishedRevision']) ?? $article;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRevision(Article $article, array $overrides = []): ArticleTranslationRevision
    {
        /** @var ArticleTranslationRevision */
        return ArticleTranslationRevision::query()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->translated_from_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash ?: $article->source_version_hash,
            'supersedes_revision_id' => null,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => null,
            'seo_description' => null,
            'published_at' => $article->published_at,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSeoMeta(Article $article, array $overrides = []): ArticleSeoMeta
    {
        /** @var ArticleSeoMeta */
        return ArticleSeoMeta::query()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) ($article->excerpt ?? ''),
            'canonical_url' => null,
            'og_title' => (string) $article->title,
            'og_description' => (string) ($article->excerpt ?? ''),
            'og_image_url' => null,
            'robots' => 'index,follow',
            'schema_json' => null,
            'is_indexable' => true,
        ], $overrides));
    }
}
