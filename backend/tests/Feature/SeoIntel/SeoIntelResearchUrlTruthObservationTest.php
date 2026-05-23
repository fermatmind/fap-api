<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ResearchReport;
use App\Services\SeoIntel\SeoIntelCollectorManager;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelResearchUrlTruthObservationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function published_claim_safe_research_report_emits_url_truth_candidate(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $this->createResearchReport([
            'slug' => 'mbti-personality-types-salary-turnover-report',
            'locale' => 'en',
            'canonical_path' => '/en/research/mbti-personality-types-salary-turnover-report',
        ]);

        $source = new BackendAuthorityUrlTruthSource;
        $records = array_values(array_filter(
            $source->candidates(),
            static fn ($record): bool => $record->pageEntityType === ResearchReport::PAGE_ENTITY_TYPE
        ));

        $this->assertCount(1, $records);

        $record = $records[0];

        $this->assertSame('https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report', $record->canonicalUrl);
        $this->assertSame('en', $record->locale);
        $this->assertSame('research_report', $record->pageEntityType);
        $this->assertSame('mbti-personality-types-salary-turnover-report', $record->entityIdOrSlug);
        $this->assertSame('backend_cms', $record->sourceAuthority);
        $this->assertSame('indexable', $record->indexabilityState);
        $this->assertSame('research_reports', $record->entitySource);
        $this->assertSame('published_approved', $record->authorityStatus);
        $this->assertFalse($record->isPrivateFlow);
        $this->assertSame(true, $record->attributes['claim_safe'] ?? null);

        $metadata = $source->metadata();
        $this->assertTrue((bool) ($metadata['research_reports_attempted'] ?? false));
        $this->assertTrue((bool) ($metadata['research_reports_available'] ?? false));
        $this->assertFalse((bool) ($metadata['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($metadata['frontend_fallback_data_source'] ?? true));
        $this->assertFalse((bool) ($metadata['node2_local_db_data_source'] ?? true));
    }

    #[Test]
    public function research_url_truth_skips_draft_private_noindex_unapproved_and_unsafe_routes(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);

        $this->createResearchReport([
            'slug' => 'valid-research-report',
            'locale' => 'zh-CN',
            'canonical_path' => '/zh/research/valid-research-report',
        ]);
        $this->createResearchReport(['slug' => 'draft-report', 'status' => ResearchReport::STATUS_DRAFT]);
        $this->createResearchReport(['slug' => 'private-report', 'is_public' => false]);
        $this->createResearchReport(['slug' => 'noindex-report', 'is_indexable' => false]);
        $this->createResearchReport(['slug' => 'claim-unsafe-report', 'review_state' => ResearchReport::REVIEW_CLAIM]);
        $this->createResearchReport(['slug' => 'mbti-personality-types-salary-turnover-rate-report']);
        $this->createResearchReport([
            'slug' => 'article-route-report',
            'canonical_path' => '/en/articles/article-route-report',
        ]);
        $this->createResearchReport([
            'slug' => 'missing-methodology-report',
            'methodology' => '',
        ]);

        $result = (new SeoIntelCollectorManager)->collect('url_truth_inventory', [
            'dry_run' => true,
            'limit' => 20,
            'page_type' => ResearchReport::PAGE_ENTITY_TYPE,
        ]);

        $this->assertSame('success', $result->status);
        $this->assertTrue($result->dryRun);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertSame(1, $result->metadata['planned_url_count'] ?? null);
        $this->assertSame(1, $result->metadata['planned_entity_count'] ?? null);
        $this->assertSame(['seo_urls', 'seo_url_entities'], $result->metadata['target_tables'] ?? null);
        $this->assertSame(['backend_cms' => 1], $result->metadata['source_authority_breakdown'] ?? null);
        $this->assertSame('research_report', $result->metadata['page_type_filter'] ?? null);
        $this->assertFalse((bool) ($result->metadata['external_api_calls'] ?? true));
        $this->assertFalse((bool) ($result->metadata['search_url_submission'] ?? true));
        $this->assertFalse((bool) ($result->metadata['source']['node2_local_db_data_source'] ?? true));
    }

    #[Test]
    public function command_page_type_filter_observes_research_report_without_writes(): void
    {
        $this->createResearchReport([
            'slug' => 'mbti-personality-types-salary-turnover-report',
            'locale' => 'en',
            'canonical_path' => '/en/research/mbti-personality-types-salary-turnover-report',
        ]);

        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'url_truth_inventory',
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 20,
            '--page-type' => 'research_report',
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('url_truth_inventory', $decoded['collector'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertSame('research_report', data_get($decoded, 'metadata.page_type_filter'));
        $this->assertGreaterThan(0, (int) data_get($decoded, 'metadata.planned_url_count'));
        $this->assertSame(['seo_urls', 'seo_url_entities'], data_get($decoded, 'metadata.target_tables'));
        $this->assertFalse((bool) data_get($decoded, 'metadata.source.external_api_calls', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.source.static_llms_fallback_graph_truth', true));
    }

    #[Test]
    public function generated_artifact_locks_research_url_truth_observation_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('research-url-truth-observation.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('url_truth_inventory', $artifact['collector'] ?? null);
        $this->assertSame('research_report', $artifact['page_entity_type'] ?? null);
        $this->assertContains('backend_cms', $artifact['source_authority_allowlist'] ?? []);
        $this->assertSame(['seo_urls', 'seo_url_entities'], $artifact['target_tables'] ?? null);
        $this->assertTrue((bool) ($artifact['no_external_api'] ?? false));
        $this->assertTrue((bool) ($artifact['no_search_submission'] ?? false));
        $this->assertTrue((bool) ($artifact['no_crawler_log_read'] ?? false));
        $this->assertSame('RESEARCH-PUBLISH-02-RERUN', $artifact['next_task'] ?? null);

        foreach ([
            'draft',
            'private',
            'noindex',
            'unapproved',
            'claim_unsafe',
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'node2_local_source',
            'articles_route',
            'reports_route',
        ] as $forbidden) {
            $this->assertContains($forbidden, $artifact['forbidden_states'] ?? []);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/research-url-truth-observation.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
