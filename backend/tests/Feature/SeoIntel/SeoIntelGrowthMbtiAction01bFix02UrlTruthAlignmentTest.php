<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ResearchReport;
use App\Services\Scale\ScaleRegistry;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbtiAction01bFix02UrlTruthAlignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function mbti_and_research_candidates_align_to_apex_and_emit_zh_public_paths(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $this->app->instance(ScaleRegistry::class, $this->mockScaleRegistry());

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
        $this->createResearchReport([
            'slug' => 'mbti-personality-types-salary-turnover-rate-report',
            'locale' => 'en',
            'canonical_path' => '/en/research/mbti-personality-types-salary-turnover-rate-report',
        ]);
        $this->createResearchReport([
            'slug' => 'article-fallback-report',
            'locale' => 'en',
            'canonical_path' => '/en/articles/article-fallback-report',
        ]);
        $this->createResearchReport([
            'slug' => 'reports-fallback-report',
            'locale' => 'zh-CN',
            'canonical_path' => '/zh/reports/reports-fallback-report',
        ]);

        $source = new BackendAuthorityUrlTruthSource;
        $records = $source->candidates();
        $canonicalUrls = array_map(static fn ($record): string => $record->canonicalUrl, $records);

        $this->assertContains('https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types', $canonicalUrls);
        $this->assertContains('https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types', $canonicalUrls);
        $this->assertContains('https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report', $canonicalUrls);
        $this->assertContains('https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report', $canonicalUrls);
        $this->assertNotContains('https://www.fermatmind.com/en/tests/mbti-personality-test-16-personality-types', $canonicalUrls);
        $this->assertNotContains('https://www.fermatmind.com/zh/tests/mbti-personality-test-16-personality-types', $canonicalUrls);
        $this->assertFalse(collect($canonicalUrls)->contains(static fn (string $url): bool => str_contains($url, 'https://www.fermatmind.com')));
        $this->assertFalse(collect($canonicalUrls)->contains(static fn (string $url): bool => str_contains($url, 'turnover-rate-report')));
        $this->assertFalse(collect($canonicalUrls)->contains(static fn (string $url): bool => str_contains($url, '/articles/')));
        $this->assertFalse(collect($canonicalUrls)->contains(static fn (string $url): bool => str_contains($url, '/reports/')));
        $this->assertFalse(collect($canonicalUrls)->contains(static fn (string $url): bool => str_contains($url, '/zh-CN/')));
        $this->assertFalse(collect($canonicalUrls)->contains(static fn (string $url): bool => str_contains($url, '/take')));

        $zhMbti = collect($records)->first(
            static fn ($record): bool => $record->canonicalUrl === 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types'
        );
        $enResearch = collect($records)->first(
            static fn ($record): bool => $record->canonicalUrl === 'https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report'
        );
        $zhResearch = collect($records)->first(
            static fn ($record): bool => $record->canonicalUrl === 'https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report'
        );

        $this->assertNotNull($zhMbti);
        $this->assertSame('zh-CN', $zhMbti->locale);
        $this->assertSame('test_detail', $zhMbti->pageEntityType);
        $this->assertSame('scale_catalog', $zhMbti->sourceAuthority);
        $this->assertSame('indexable', $zhMbti->indexabilityState);
        $this->assertFalse($zhMbti->isPrivateFlow);

        $this->assertNotNull($enResearch);
        $this->assertSame('research_report', $enResearch->pageEntityType);
        $this->assertSame('backend_cms', $enResearch->sourceAuthority);
        $this->assertSame('research_reports', $enResearch->entitySource);
        $this->assertFalse($enResearch->isPrivateFlow);

        $this->assertNotNull($zhResearch);
        $this->assertSame('zh-CN', $zhResearch->locale);
        $this->assertSame('research_report', $zhResearch->pageEntityType);
        $this->assertSame('backend_cms', $zhResearch->sourceAuthority);

        $this->assertContains('test_detail', config('seo_intel.url_truth_inventory.allowed_page_entity_types', []));
        $this->assertContains('research_report', config('seo_intel.url_truth_inventory.allowed_page_entity_types', []));
        $this->assertContains('scale_catalog', config('seo_intel.url_truth_inventory.allowed_source_authorities', []));
        $this->assertContains('backend_cms', config('seo_intel.url_truth_inventory.allowed_source_authorities', []));

        $metadata = $source->metadata();
        $this->assertFalse((bool) ($metadata['frontend_fallback_data_source'] ?? true));
        $this->assertFalse((bool) ($metadata['static_llms_fallback_graph_truth'] ?? true));
    }

    private function mockScaleRegistry(): ScaleRegistry
    {
        $registry = \Mockery::mock(ScaleRegistry::class);
        $registry->shouldReceive('listActivePublic')->once()->with(0)->andReturn([
            [
                'code' => 'MBTI',
                'primary_slug' => 'mbti-personality-test-16-personality-types',
                'updated_at' => now()->toISOString(),
                'content_i18n_json' => [
                    'en' => [
                        'catalog' => [
                            'questions_count' => 93,
                            'time_minutes' => 12,
                        ],
                    ],
                    'zh' => [
                        'catalog' => [
                            'questions_count' => 93,
                            'time_minutes' => 12,
                        ],
                    ],
                ],
            ],
        ]);

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createResearchReport(array $overrides = []): ResearchReport
    {
        $slug = (string) ($overrides['slug'] ?? 'safe-research-report');
        $locale = (string) ($overrides['locale'] ?? 'en');
        $localeSegment = $locale === 'zh-CN' ? 'zh' : $locale;

        return ResearchReport::query()->create($overrides + [
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
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
            'canonical_path' => '/'.$localeSegment.'/research/'.$slug,
        ]);
    }
}
