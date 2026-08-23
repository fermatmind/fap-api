<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoOpportunityQueueReadService;
use App\Services\SeoIntel\OpsDashboard\SeoTechnicalAuditReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoTechnicalAuditOpportunityReadModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-23 12:00:00 UTC');
        config([
            'database.connections.seo_task7_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'seo_intel.connection' => 'seo_task7_test',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.search_channel_queue.allowed_page_entity_types' => ['article'],
            'seo_intel.search_channel_queue.approved_source_authorities' => ['cms'],
            'seo_intel.search_channel_queue.forbidden_page_entity_types' => [],
            'seo_intel.search_channel_queue.forbidden_source_authorities' => [],
            'seo_intel.gsc_data_quality.min_rows' => 1,
        ]);
        DB::purge('seo_task7_test');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function technical_audits_expose_scope_evidence_and_real_source_states(): void
    {
        $this->seedUrl('page-one');
        DB::connection('seo_task7_test')->table('seo_issue_queue')->insert([
            $this->issue('canonical-single', 'canonical_mismatch', 'https://fermatmind.com/en/articles/page-one', ['root_cause' => 'canonical_mismatch']),
            $this->issue('robots-template', 'robots_noindex', null, ['root_cause' => 'robots_template_default', 'template' => 'article-detail']),
            $this->issue('sitemap-site', 'sitemap_inventory_gap', null, ['root_cause' => 'sitemap_inventory_gap', 'affected_scope' => 'site']),
            $this->issue('external-cwv', 'field_cwv_disconnected', null, ['root_cause' => 'crux_not_connected', 'affected_scope' => 'external_disconnected'], 'external_connector'),
        ]);

        $result = (new SeoTechnicalAuditReadService('seo_task7_test'))->read();

        $this->assertSame('connected', $result['state']);
        $this->assertSame(1, $result['summary']['single_url']);
        $this->assertSame(1, $result['summary']['template']);
        $this->assertSame(1, $result['summary']['site']);
        $this->assertSame(1, $result['summary']['external_disconnected']);
        $this->assertSame('/en/articles/page-one', collect($result['rows'])->firstWhere('issue_uid', 'canonical-single')['canonical_path']);
        $this->assertSame('disconnected', data_get($result, 'sources.field_cwv.state'));
        $this->assertNull(data_get($result, 'sources.field_cwv.metrics'));
        $this->assertFalse(data_get($result, 'sources.field_cwv.lighthouse_lab_substitution_allowed'));
        $this->assertSame('disconnected', collect($result['checks'])->firstWhere('check', 'public_html')['state']);
        $this->assertNull(collect($result['checks'])->firstWhere('check', 'public_html')['issue_count']);
        $this->assertFalse($result['boundaries']['writes_attempted']);
    }

    #[Test]
    public function opportunities_cover_real_query_page_patterns_without_synthetic_metrics(): void
    {
        $pageOne = $this->seedUrl('page-one');
        $pageTwo = $this->seedUrl('page-two');
        $latest = CarbonImmutable::now('UTC')->subDays(3)->toDateString();
        $prior = CarbonImmutable::now('UTC')->subDays(18)->toDateString();
        $queryHash = hash('sha256', 'career fit');

        $this->gscRow($pageOne, $queryHash, $prior, 2, 100, 8000);
        $this->gscRow($pageOne, $queryHash, $latest, 0, 40, 9000);
        $this->gscRow($pageTwo, $queryHash, $latest, 1, 60, 12000);
        $this->gscRow(hash('sha256', 'https://fermatmind.com/en/articles/unmapped'), hash('sha256', 'missing topic'), $latest, 0, 100, 30000);

        $result = (new SeoOpportunityQueueReadService('seo_task7_test'))->read(100);
        $types = collect($result['recent_rows'])->flatMap(fn (array $row): array => $row['opportunity_types'])->unique()->all();

        $this->assertSame('connected', $result['state']);
        $this->assertContains('high_impressions_low_ctr', $types);
        $this->assertContains('ranking_4_20', $types);
        $this->assertContains('content_decay', $types);
        $this->assertContains('keyword_cannibalization', $types);
        $this->assertContains('no_content_match', $types);
        $this->assertTrue(collect($result['recent_rows'])->every(fn (array $row): bool => data_get($row, 'evidence.query_page_bound') === true));
        $this->assertTrue(collect($result['recent_rows'])->every(fn (array $row): bool => ($row['human_review_boundary'] ?? null) === 'human_review_required_before_cms_or_search_action'));
        $this->assertSame('withheld_without_real_query_page_evidence', data_get($result, 'scoring_contract.post_publish_data_blindspot'));
        $this->assertFalse($result['boundaries']['writes_attempted']);
    }

    #[Test]
    public function disconnected_opportunity_source_returns_no_zero_or_mock_candidates(): void
    {
        $result = (new SeoOpportunityQueueReadService('seo_task7_test'))->read();

        $this->assertSame('disconnected', $result['state']);
        $this->assertSame([], $result['recent_rows']);
        $this->assertSame(0, $result['total_count']);
        $this->assertFalse($result['boundaries']['external_calls_attempted']);
    }

    private function seedUrl(string $slug): string
    {
        $url = 'https://fermatmind.com/en/articles/'.$slug;
        $hash = hash('sha256', $url);
        DB::connection('seo_task7_test')->table('seo_urls')->insert([
            'canonical_url_hash' => $hash,
            'canonical_url' => $url,
            'locale' => 'en',
            'page_entity_type' => 'article',
            'source_authority' => 'cms',
            'indexability_state' => 'indexable',
            'is_private_flow' => false,
            'metadata_json' => json_encode(['claim_boundary_state' => 'claim_safe'], JSON_THROW_ON_ERROR),
        ]);

        return $hash;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function issue(string $uid, string $type, ?string $url, array $metadata, string $source = 'cms'): array
    {
        return [
            'issue_uid' => $uid,
            'issue_type' => $type,
            'severity' => 'high',
            'source_system' => $source,
            'source_engine' => null,
            'canonical_url_hash' => $url === null ? null : hash('sha256', $url),
            'canonical_url' => $url,
            'locale' => 'en',
            'page_entity_type' => 'article',
            'status' => 'open',
            'lifecycle_state' => 'open',
            'summary' => $type,
            'recommendation' => 'review_source_evidence',
            'evidence_hash' => hash('sha256', $uid),
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'detected_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function gscRow(string $urlHash, string $queryHash, string $date, int $clicks, int $impressions, int $positionMilli): void
    {
        DB::connection('seo_task7_test')->table('seo_gsc_daily')->insert([
            'report_date' => $date,
            'canonical_url_hash' => $urlHash,
            'query_hash' => $queryHash,
            'query_display_masked' => 'c********t',
            'locale' => 'en',
            'source_engine' => 'google',
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr_ppm' => $impressions > 0 ? (int) floor(($clicks / $impressions) * 1_000_000) : null,
            'average_position_milli' => $positionMilli,
            'is_brand_query' => false,
            'query_type' => 'non_brand',
            'metadata_json' => json_encode(['data_origin' => 'live_gsc_api'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('seo_task7_test');
        $schema->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->boolean('is_private_flow')->default(false);
            $table->json('metadata_json')->nullable();
        });
        $schema->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid');
            $table->string('issue_type');
            $table->string('severity');
            $table->string('source_system');
            $table->string('source_engine')->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale')->nullable();
            $table->string('page_entity_type')->nullable();
            $table->string('status');
            $table->string('lifecycle_state');
            $table->string('summary')->nullable();
            $table->string('recommendation')->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
        $schema->create('seo_gsc_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->char('canonical_url_hash', 64);
            $table->char('query_hash', 64);
            $table->string('query_display_masked')->nullable();
            $table->string('locale')->nullable();
            $table->string('source_engine');
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->unsignedInteger('ctr_ppm')->nullable();
            $table->unsignedInteger('average_position_milli')->nullable();
            $table->boolean('is_brand_query');
            $table->string('query_type');
            $table->json('metadata_json');
        });
        $schema->create('seo_crawler_log_daily_aggregates', function (Blueprint $table): void {
            $table->id();
        });
    }
}
