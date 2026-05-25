<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

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
            'content_md' => '## Overview'.PHP_EOL.'Authority-backed body.',
            'content_html' => '',
            'seo_title' => 'About FermatMind',
            'seo_description' => 'Authority-backed content page.',
            'meta_description' => 'Authority-backed content page.',
            'canonical_path' => '/en/about',
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

    private function mockScaleRegistry(): ScaleRegistry
    {
        $registry = \Mockery::mock(ScaleRegistry::class);
        $registry->shouldReceive('listActivePublic')->andReturn([]);

        return $registry;
    }
}
