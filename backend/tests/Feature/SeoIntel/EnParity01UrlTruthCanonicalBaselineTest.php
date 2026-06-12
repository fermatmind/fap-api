<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\ContentPage;
use App\Models\ResearchReport;
use App\Services\Scale\ScaleRegistry;
use App\Services\SEO\SitemapGenerator;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity01UrlTruthCanonicalBaselineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generated_baseline_artifact_records_read_only_authority_decisions(): void
    {
        $path = base_path('docs/seo/generated/en-parity-01-url-truth-canonical-baseline.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-01-url-truth-canonical-baseline.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-01', $payload['task'] ?? null);
        $this->assertTrue((bool) ($payload['no_cms_mutation_performed'] ?? false));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertSame('https://fermatmind.com/', data_get($payload, 'homepage_canonical_policy.zh_home_canonical'));
        $this->assertSame('https://fermatmind.com/en', data_get($payload, 'homepage_canonical_policy.en_home_canonical'));
        $this->assertSame('content_page', data_get($payload, 'url_truth_source_changes.content_pages.page_entity_type'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guards.no_soft_404_from_backend_authority'));
        $this->assertTrue((bool) data_get($payload, 'exposure_guards.missing_help_about_excluded_until_authority_exists'));
        $this->assertSame('EN-PARITY-02 translation group schema / read model', $payload['next_task'] ?? null);
        $this->assertContains(
            'https://fermatmind.com/en/about',
            array_column($payload['en_parity_00_p0_baseline_decisions'] ?? [], 'url')
        );
    }

    #[Test]
    public function url_truth_uses_root_as_zh_home_canonical_and_en_home_as_english_canonical(): void
    {
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $records = (new BackendAuthorityUrlTruthSource)->candidates();
        $urls = array_map(static fn ($record): string => $record->canonicalUrl, $records);

        $this->assertContains('https://fermatmind.com/', $urls);
        $this->assertContains('https://fermatmind.com/en', $urls);
        $this->assertNotContains('https://fermatmind.com/zh', $urls);

        $zhHome = collect($records)->first(
            static fn ($record): bool => $record->canonicalUrl === 'https://fermatmind.com/'
        );

        $this->assertNotNull($zhHome);
        $this->assertSame('zh-CN', $zhHome->locale);
        $this->assertSame('home', $zhHome->pageEntityType);
        $this->assertSame('backend_public_surface', $zhHome->sourceAuthority);
    }

    #[Test]
    public function content_pages_enter_url_truth_only_when_authority_backed_and_indexable(): void
    {
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $this->createContentPage([
            'slug' => 'about',
            'locale' => 'en',
            'canonical_path' => '/en/about',
            'title' => 'About FermatMind',
        ]);
        $this->createContentPage([
            'slug' => 'help-contact',
            'locale' => 'zh-CN',
            'kind' => ContentPage::KIND_HELP,
            'canonical_path' => '/help/contact',
            'title' => '联系支持',
        ]);
        $this->createContentPage([
            'slug' => 'empty-authority',
            'locale' => 'en',
            'canonical_path' => '/en/empty-authority',
            'title' => 'Empty authority',
            'content_md' => '',
            'content_html' => '',
        ]);
        $this->createContentPage([
            'slug' => 'draft-page',
            'locale' => 'zh-CN',
            'canonical_path' => '/zh/draft-page',
            'title' => 'Draft page',
            'status' => ContentPage::STATUS_DRAFT,
        ]);
        $this->createContentPage([
            'slug' => 'locale-mismatch',
            'locale' => 'en',
            'canonical_path' => '/zh/locale-mismatch',
            'title' => 'Locale mismatch',
        ]);

        $records = (new BackendAuthorityUrlTruthSource)->candidates();
        $urls = array_map(static fn ($record): string => $record->canonicalUrl, $records);

        $this->assertContains('https://fermatmind.com/en/about', $urls);
        $this->assertContains('https://fermatmind.com/zh/help/contact', $urls);
        $this->assertNotContains('https://fermatmind.com/en/help/about', $urls);
        $this->assertNotContains('https://fermatmind.com/en/empty-authority', $urls);
        $this->assertNotContains('https://fermatmind.com/zh/draft-page', $urls);
        $this->assertNotContains('https://fermatmind.com/zh/locale-mismatch', $urls);

        $about = collect($records)->first(
            static fn ($record): bool => $record->canonicalUrl === 'https://fermatmind.com/en/about'
        );

        $this->assertNotNull($about);
        $this->assertSame('content_page', $about->pageEntityType);
        $this->assertSame('backend_cms', $about->sourceAuthority);
        $this->assertSame('content_pages', $about->entitySource);
        $this->assertSame('published', $about->authorityStatus);

        $metadata = (new BackendAuthorityUrlTruthSource)->metadata();
        $this->assertFalse((bool) ($metadata['frontend_fallback_data_source'] ?? true));
        $this->assertFalse((bool) ($metadata['static_llms_fallback_graph_truth'] ?? true));
    }

    #[Test]
    public function articles_enter_url_truth_only_when_published_indexable_sitemap_and_llms_eligible(): void
    {
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $enArticle = $this->createArticle([
            'slug' => 'career-interest-test-vs-personality-test',
            'locale' => 'en',
            'title' => 'Career Interest Test vs Personality Test',
            'translation_group_id' => 'tg_article_career_interest_vs_personality_test_2026v1',
        ]);
        $this->createArticleSeoMeta($enArticle, [
            'canonical_url' => 'https://fermatmind.com/en/articles/career-interest-test-vs-personality-test',
        ]);

        $zhArticle = $this->createArticle([
            'slug' => 'career-interest-vs-personality-test-differences',
            'locale' => 'zh-CN',
            'title' => '职业兴趣测试与性格测试的区别',
            'translation_group_id' => 'tg_article_career_interest_vs_personality_test_2026v1',
            'source_locale' => 'en',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'source_article_id' => $enArticle->id,
            'translated_from_article_id' => $enArticle->id,
        ]);
        $this->createArticleSeoMeta($zhArticle, [
            'canonical_url' => 'https://fermatmind.com/zh/articles/career-interest-vs-personality-test-differences',
        ]);

        $this->createArticle([
            'slug' => 'draft-article',
            'locale' => 'en',
            'title' => 'Draft Article',
            'status' => 'draft',
            'is_public' => false,
            'published_revision_id' => null,
        ]);
        $this->createArticle([
            'slug' => 'no-llms-article',
            'locale' => 'en',
            'title' => 'No LLMS Article',
            'llms_eligible' => false,
        ]);
        $unsafeCanonical = $this->createArticle([
            'slug' => 'unsafe-canonical',
            'locale' => 'en',
            'title' => 'Unsafe Canonical',
        ]);
        $this->createArticleSeoMeta($unsafeCanonical, [
            'canonical_url' => 'https://fermatmind.com/en/results/unsafe-canonical',
        ]);

        $source = new BackendAuthorityUrlTruthSource;
        $records = $source->candidates();
        $urls = array_map(static fn ($record): string => $record->canonicalUrl, $records);

        $this->assertContains('https://fermatmind.com/en/articles/career-interest-test-vs-personality-test', $urls);
        $this->assertContains('https://fermatmind.com/zh/articles/career-interest-vs-personality-test-differences', $urls);
        $this->assertNotContains('https://fermatmind.com/en/articles/draft-article', $urls);
        $this->assertNotContains('https://fermatmind.com/en/articles/no-llms-article', $urls);
        $this->assertNotContains('https://fermatmind.com/en/results/unsafe-canonical', $urls);

        $enRecord = collect($records)->first(
            static fn ($record): bool => $record->canonicalUrl === 'https://fermatmind.com/en/articles/career-interest-test-vs-personality-test'
        );

        $this->assertNotNull($enRecord);
        $this->assertSame('article', $enRecord->pageEntityType);
        $this->assertSame((string) $enArticle->id, $enRecord->entityIdOrSlug);
        $this->assertSame('backend_cms', $enRecord->sourceAuthority);
        $this->assertSame('articles', $enRecord->entitySource);
        $this->assertSame('published_approved', $enRecord->authorityStatus);
        $this->assertSame('indexable', $enRecord->indexabilityState);
        $this->assertSame('claim_safe', $enRecord->metadata['claim_boundary_state'] ?? null);
        $this->assertTrue((bool) ($enRecord->metadata['sitemap_eligible'] ?? false));
        $this->assertTrue((bool) ($enRecord->metadata['llms_eligible'] ?? false));

        $metadata = $source->metadata();
        $this->assertTrue((bool) ($metadata['articles_attempted'] ?? false));
        $this->assertTrue((bool) ($metadata['articles_available'] ?? false));
        $this->assertNull($metadata['articles_unavailable_reason'] ?? null);
    }

    #[Test]
    public function sitemap_source_exposes_only_content_pages_with_published_authority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->app->instance(ScaleRegistry::class, $this->mockScaleRegistry());

        $this->createContentPage([
            'slug' => 'support',
            'locale' => 'en',
            'canonical_path' => '/en/support',
            'title' => 'Support & Trust Center',
        ]);
        $this->createContentPage([
            'slug' => 'method-boundaries',
            'locale' => 'zh-CN',
            'canonical_path' => '/method-boundaries',
            'title' => '测评科学与边界',
        ]);
        $this->createContentPage([
            'slug' => 'help-about',
            'locale' => 'en',
            'kind' => ContentPage::KIND_HELP,
            'canonical_path' => '/en/help/about',
            'title' => 'Help About',
            'status' => ContentPage::STATUS_DRAFT,
        ]);

        $urls = app(SitemapGenerator::class)->generateUrls();
        $locs = array_map(static fn (array $row): string => (string) ($row['loc'] ?? ''), $urls);

        $this->assertContains('https://fermatmind.com/en/support', $locs);
        $this->assertContains('https://fermatmind.com/zh/method-boundaries', $locs);
        $this->assertNotContains('https://fermatmind.com/en/help/about', $locs);
    }

    #[Test]
    public function mbti_research_apex_remains_authority_backed_when_safe_research_rows_exist(): void
    {
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $this->createResearchReport([
            'slug' => 'mbti-personality-types-salary-turnover-report',
            'locale' => 'en',
            'canonical_path' => '/en/research/mbti-personality-types-salary-turnover-report',
        ]);
        $this->createResearchReport([
            'slug' => 'mbti-personality-types-salary-turnover-report',
            'locale' => 'zh-CN',
            'canonical_path' => '/zh/research/mbti-personality-types-salary-turnover-report',
        ]);

        $records = (new BackendAuthorityUrlTruthSource)->candidates();
        $urls = array_map(static fn ($record): string => $record->canonicalUrl, $records);

        $this->assertContains('https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report', $urls);
        $this->assertContains('https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report', $urls);
        $this->assertFalse(collect($urls)->contains(
            static fn (string $url): bool => str_contains($url, 'https://www.fermatmind.com')
        ));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createContentPage(array $overrides = []): ContentPage
    {
        return ContentPage::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'about',
            'path' => '/about',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'about',
            'title' => 'About FermatMind',
            'summary' => 'Authority-backed content page.',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'en',
            'translation_group_id' => 'content-page-about',
            'source_locale' => 'zh-CN',
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'legal_review_required' => false,
            'science_review_required' => false,
            'content_md' => '## Overview'.PHP_EOL.'Authority-backed body.',
            'content_html' => '',
            'seo_title' => 'About FermatMind',
            'seo_description' => 'Authority-backed content page.',
            'meta_description' => 'Authority-backed content page.',
            'canonical_path' => '/en/about',
            'schema_enabled' => false,
            'publish_allowed' => true,
            'operator_approval_required' => false,
            'operator_approved_at' => now()->subDay(),
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'faq_schema_eligible' => false,
            'status' => ContentPage::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createResearchReport(array $overrides = []): ResearchReport
    {
        return ResearchReport::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'safe-research-report',
            'locale' => 'en',
            'title' => 'Safe Research Report',
            'executive_summary' => 'Directional research summary.',
            'body_md' => 'Research body.',
            'research_type' => 'salary_turnover',
            'methodology' => 'Modeled index methodology.',
            'sample_disclaimer' => 'Exploratory, non-diagnostic, not hiring advice.',
            'claim_boundary' => 'No salary guarantee or individual prediction.',
            'author_name' => 'FermatMind Research',
            'reviewer_name' => 'FermatMind Review',
            'references' => [['title' => 'Reference', 'url' => 'https://example.com/reference']],
            'downloadable_asset_placeholder' => 'Dataset schema blocked for first publish.',
            'status' => ResearchReport::STATUS_PUBLISHED,
            'review_state' => ResearchReport::REVIEW_APPROVED,
            'is_public' => true,
            'is_indexable' => true,
            'last_reviewed_at' => now()->subDay(),
            'published_at' => now()->subHour(),
            'seo_title' => 'Safe Research Report',
            'seo_description' => 'Safe Research Report description.',
            'canonical_path' => '/en/research/safe-research-report',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        $article = Article::query()->create($overrides + [
            'org_id' => 0,
            'slug' => 'safe-article',
            'locale' => 'en',
            'translation_group_id' => 'tg-safe-article',
            'source_locale' => $overrides['locale'] ?? 'en',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Safe Article',
            'excerpt' => 'Authority-backed article excerpt.',
            'content_md' => '## Overview'.PHP_EOL.'Authority-backed article body.',
            'content_html' => '',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now()->subHour(),
        ]);

        if ($article->published_revision_id === null) {
            $revision = ArticleTranslationRevision::query()->create([
                'org_id' => (int) $article->org_id,
                'article_id' => (int) $article->id,
                'source_article_id' => (int) ($article->source_article_id ?: $article->id),
                'translation_group_id' => (string) $article->translation_group_id,
                'locale' => (string) $article->locale,
                'source_locale' => (string) ($article->source_locale ?: $article->locale),
                'revision_number' => 1,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'title' => (string) $article->title,
                'excerpt' => (string) $article->excerpt,
                'content_md' => (string) $article->content_md,
                'seo_title' => (string) $article->title,
                'seo_description' => (string) $article->excerpt,
                'published_at' => now()->subHour(),
            ]);

            $article->forceFill([
                'working_revision_id' => $revision->id,
                'published_revision_id' => $revision->id,
            ])->save();
        }

        return $article->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticleSeoMeta(Article $article, array $overrides = []): ArticleSeoMeta
    {
        return ArticleSeoMeta::query()->create($overrides + [
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => 'https://fermatmind.com/'.$this->articleLocaleSegment((string) $article->locale).'/articles/'.$article->slug,
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);
    }

    private function articleLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    private function mockScaleRegistry(): ScaleRegistry
    {
        $registry = \Mockery::mock(ScaleRegistry::class);
        $registry->shouldReceive('listActivePublic')->andReturn([]);

        return $registry;
    }
}
