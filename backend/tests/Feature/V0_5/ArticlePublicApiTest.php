<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    public function test_list_orders_newest_articles_before_legacy_voice_order(): void
    {
        $this->createArticle([
            'slug' => 'older-voice-ordered-article',
            'locale' => 'zh-CN',
            'title' => 'Older voice ordered article',
            'voice_order' => 1,
            'published_at' => Carbon::create(2026, 4, 18, 8, 0, 0, 'UTC'),
        ]);

        $this->createArticle([
            'slug' => 'newest-uploaded-article',
            'locale' => 'zh-CN',
            'title' => 'Newest uploaded article',
            'voice_order' => null,
            'published_at' => Carbon::create(2026, 5, 14, 8, 0, 0, 'UTC'),
        ]);

        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'newest-uploaded-article')
            ->assertJsonPath('items.1.slug', 'older-voice-ordered-article');
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

    public function test_article_seo_alternates_use_translation_group_id_for_different_slugs(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $groupId = 'article_mbti_vs_holland_career_choice_v1';
        $this->createArticle([
            'slug' => 'mbti-vs-holland-code-career-choice',
            'locale' => 'en',
            'title' => 'MBTI vs Holland Code career choice',
            'translation_group_id' => $groupId,
        ]);
        $this->createArticle([
            'slug' => 'mbti-vs-holland-career-choice',
            'locale' => 'zh-CN',
            'title' => 'MBTI vs Holland career choice',
            'translation_group_id' => $groupId,
        ]);

        $response = $this->getJson('/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/mbti-vs-holland-code-career-choice')
            ->assertJsonPath('meta.alternates.zh', 'https://staging.fermatmind.com/zh/articles/mbti-vs-holland-career-choice')
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/articles/mbti-vs-holland-career-choice');

        $this->assertNull(data_get($response->json(), 'meta.alternates.x-default'));
    }

    public function test_article_seo_alternates_exclude_draft_and_noindex_siblings(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $draftGroup = 'article_draft_sibling_exclusion_v1';
        $this->createArticle([
            'slug' => 'draft-sibling-source',
            'locale' => 'en',
            'translation_group_id' => $draftGroup,
        ]);
        $this->createArticle([
            'slug' => 'draft-sibling-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => $draftGroup,
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
        ], [], false);

        $draftResponse = $this->getJson('/api/v0.5/articles/draft-sibling-source/seo?locale=en');
        $draftResponse->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/draft-sibling-source');
        $this->assertNull(data_get($draftResponse->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($draftResponse->json(), 'meta.alternates.zh-CN'));

        $noindexGroup = 'article_noindex_sibling_exclusion_v1';
        $this->createArticle([
            'slug' => 'noindex-sibling-source',
            'locale' => 'en',
            'translation_group_id' => $noindexGroup,
        ]);
        $this->createArticle([
            'slug' => 'noindex-sibling-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => $noindexGroup,
            'is_indexable' => false,
        ]);

        $noindexResponse = $this->getJson('/api/v0.5/articles/noindex-sibling-source/seo?locale=en');
        $noindexResponse->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/noindex-sibling-source');
        $this->assertNull(data_get($noindexResponse->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($noindexResponse->json(), 'meta.alternates.zh-CN'));
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

    public function test_article_detail_projects_cms_cta_slots_and_visible_faq_without_private_targets(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $editorialPackage = [
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_visibility' => 'below_intro',
            'answer_surface_v1' => [
                'faq_items' => [
                    ['key' => 'visible_faq', 'question' => 'Visible FAQ?', 'answer' => 'Visible answer.'],
                    ['key' => 'hidden_faq', 'question' => 'Hidden FAQ?', 'answer' => 'Hidden answer.', 'visibility' => 'hidden'],
                ],
            ],
            'cta_slots' => [
                ['slot_id' => 'primary_riasec', 'label' => 'Start RIASEC', 'href' => '/en/tests/holland-career-interest-test-riasec'],
                ['slot_id' => 'secondary_mbti', 'label' => 'Start MBTI', 'href' => '/en/tests/mbti-personality-test-16-personality-types'],
                ['slot_id' => 'tertiary_big_five', 'label' => 'Start Big Five', 'href' => '/en/tests/big-five-personality-test'],
                ['slot_id' => 'private_result', 'label' => 'Private result', 'href' => '/en/result/private-attempt'],
            ],
        ];

        $article = $this->createArticle([
            'slug' => 'cms-cta-faq-article',
            'locale' => 'en',
            'title' => 'CMS CTA FAQ Article',
            'cover_image_variants' => ['editorial_package_v1' => $editorialPackage],
            'related_test_slug' => 'mbti-personality-test-16-personality-types',
        ]);
        $this->createSeoMeta($article, [
            'schema_json' => ['editorial_package_v1' => $editorialPackage],
        ]);

        $detail = $this->getJson('/api/v0.5/articles/cms-cta-faq-article?locale=en');

        $detail->assertOk()
            ->assertJsonPath('landing_surface_v1.start_test_target', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.key', 'primary_riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.href', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.1.key', 'secondary_mbti')
            ->assertJsonPath('landing_surface_v1.cta_bundle.2.key', 'tertiary_big_five')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.key', 'visible_faq')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'Visible FAQ?')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/tests/holland-career-interest-test-riasec');

        $this->assertContains('FAQPage', $detail->json('seo_surface_v1.structured_data_keys'));

        $this->assertStringNotContainsString('private-attempt', (string) $detail->getContent());
        $this->assertStringNotContainsString('Hidden FAQ?', (string) $detail->getContent());

        $seo = $this->getJson('/api/v0.5/articles/cms-cta-faq-article/seo?locale=en');
        $seo->assertOk()
            ->assertJsonPath('jsonld.hasPart.0.@type', 'FAQPage')
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.0.name', 'Visible FAQ?');

        $this->assertStringNotContainsString('Hidden FAQ?', (string) $seo->getContent());
    }

    public function test_article_detail_uses_locale_specific_cms_faq_blocks(): void
    {
        $groupId = 'article_locale_specific_faq_v1';
        $enPackage = [
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_visibility' => 'below_intro',
            'answer_surface_v1' => [
                'faq_items' => [
                    ['key' => 'en_visible_faq', 'question' => 'English visible FAQ?', 'answer' => 'English visible answer.'],
                ],
            ],
        ];
        $zhPackage = [
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_visibility' => 'below_intro',
            'answer_surface_v1' => [
                'faq_items' => [
                    ['key' => 'zh_visible_faq', 'question' => 'Chinese visible FAQ?', 'answer' => 'Chinese visible answer.'],
                ],
            ],
        ];

        $enArticle = $this->createArticle([
            'slug' => 'locale-specific-faq-en',
            'locale' => 'en',
            'translation_group_id' => $groupId,
            'cover_image_variants' => ['editorial_package_v1' => $enPackage],
        ]);
        $this->createSeoMeta($enArticle, [
            'schema_json' => ['editorial_package_v1' => $enPackage],
        ]);

        $zhArticle = $this->createArticle([
            'slug' => 'locale-specific-faq-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => $groupId,
            'cover_image_variants' => ['editorial_package_v1' => $zhPackage],
        ]);
        $this->createSeoMeta($zhArticle, [
            'schema_json' => ['editorial_package_v1' => $zhPackage],
        ]);

        $enDetail = $this->getJson('/api/v0.5/articles/locale-specific-faq-en?locale=en');
        $enDetail->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'English visible FAQ?');
        $this->assertStringNotContainsString('Chinese visible FAQ?', (string) $enDetail->getContent());

        $zhDetail = $this->getJson('/api/v0.5/articles/locale-specific-faq-zh?locale=zh-CN');
        $zhDetail->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'Chinese visible FAQ?');
        $this->assertStringNotContainsString('English visible FAQ?', (string) $zhDetail->getContent());

        $enSeo = $this->getJson('/api/v0.5/articles/locale-specific-faq-en/seo?locale=en');
        $enSeo->assertOk()
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.0.name', 'English visible FAQ?');
        $this->assertStringNotContainsString('Chinese visible FAQ?', (string) $enSeo->getContent());

        $zhSeo = $this->getJson('/api/v0.5/articles/locale-specific-faq-zh/seo?locale=zh-CN');
        $zhSeo->assertOk()
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.0.name', 'Chinese visible FAQ?');
        $this->assertStringNotContainsString('English visible FAQ?', (string) $zhSeo->getContent());
    }

    public function test_article_detail_fallback_uses_related_public_test_before_mbti_default(): void
    {
        $this->createArticle([
            'slug' => 'riasec-fallback-article',
            'locale' => 'en',
            'related_test_slug' => 'holland-career-interest-test-riasec',
        ]);

        $response = $this->getJson('/api/v0.5/articles/riasec-fallback-article?locale=en');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.start_test_target', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.2.href', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.2.href', '/en/tests/holland-career-interest-test-riasec');
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

    public function test_sitemap_source_uses_public_readable_and_indexable_article_gate(): void
    {
        Cache::flush();
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $this->createArticle([
            'slug' => 'sitemap-visible-article',
            'locale' => 'en',
        ]);
        $this->createArticle([
            'slug' => 'sitemap-draft-article',
            'locale' => 'en',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
        ], [], false);
        $this->createArticle([
            'slug' => 'sitemap-noindex-article',
            'locale' => 'en',
            'is_indexable' => false,
        ]);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk();
        $locations = collect($response->json('items'))->pluck('loc')->all();
        $this->assertContains('https://staging.fermatmind.com/en/articles/sitemap-visible-article', $locations);
        $this->assertNotContains('https://staging.fermatmind.com/en/articles/sitemap-draft-article', $locations);
        $this->assertNotContains('https://staging.fermatmind.com/en/articles/sitemap-noindex-article', $locations);
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
