<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Drift\CrawlerLogLineParser;
use App\Services\SeoIntel\Drift\CrawlerUserAgentClassifier;
use App\Services\SeoIntel\Drift\HtmlSnapshotParser;
use App\Services\SeoIntel\Drift\MetadataDriftComparator;
use App\Services\SeoIntel\Drift\SitemapLlmsParityComparator;
use App\Services\SeoIntel\SeoIntelCollectorManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelDriftCollectorFoundationTest extends TestCase
{
    #[Test]
    public function drift_and_crawler_foundation_collectors_are_registered_and_dry_run_safe(): void
    {
        $this->assertContains('drift_foundation', config('seo_intel.allowed_collectors'));
        $this->assertContains('crawler_log_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
        $this->assertFalse((bool) config('seo_intel.allow_production_crawl'));
        $this->assertFalse((bool) config('seo_intel.allow_production_log_read'));

        $drift = (new SeoIntelCollectorManager)->collect('drift_foundation', ['dry_run' => true]);
        $crawler = (new SeoIntelCollectorManager)->collect('crawler_log_foundation', ['dry_run' => true]);

        foreach ([$drift, $crawler] as $result) {
            $this->assertSame('success', $result->status);
            $this->assertTrue($result->dryRun);
            $this->assertFalse($result->writesAttempted);
            $this->assertFalse($result->writesCommitted);
            $this->assertFalse($result->externalCallsAttempted);
            $this->assertFalse((bool) ($result->metadata['node2_local_laravel_data_source'] ?? true));
        }

        $this->assertFalse((bool) ($drift->metadata['fetches_public_html'] ?? true));
        $this->assertFalse((bool) ($drift->metadata['modifies_sitemap_llms'] ?? true));
        $this->assertFalse((bool) ($drift->metadata['modifies_cms'] ?? true));
        $this->assertFalse((bool) ($crawler->metadata['reads_production_logs'] ?? true));
    }

    #[Test]
    public function default_write_is_blocked_when_collectors_are_disabled(): void
    {
        $result = (new SeoIntelCollectorManager)->collect('drift_foundation', [
            'dry_run' => false,
            'no_write' => false,
        ]);

        $this->assertSame('blocked', $result->status);
        $this->assertContains('collectors_disabled', $result->issues);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
    }

    #[Test]
    public function drift_foundation_accepts_canary_and_limit_and_caps_large_limits(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        $this->seedUrlTruthRows();
        config(['seo_intel.drift_foundation.canary_max_limit' => 5]);

        $canaryExitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'drift_foundation',
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--canary' => true,
        ]);
        $canaryOutput = json_decode(trim(Artisan::output()), true);

        $limitExitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'drift_foundation',
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
            '--limit' => 500,
        ]);
        $limitOutput = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $canaryExitCode);
        $this->assertSame(0, $limitExitCode);
        $this->assertTrue((bool) data_get($canaryOutput, 'metadata.canary'));
        $this->assertGreaterThan(0, (int) data_get($canaryOutput, 'metadata.candidate_count'));
        $this->assertSame(5, data_get($limitOutput, 'metadata.limit'));
        $this->assertLessThanOrEqual(5, (int) data_get($limitOutput, 'metadata.planned_issue_count'));
        $this->assertSame(['seo_urls', 'seo_url_entities'], data_get($canaryOutput, 'metadata.source_tables'));
        $this->assertSame(['seo_issue_queue'], data_get($canaryOutput, 'metadata.target_tables'));
        $this->assertFalse((bool) data_get($canaryOutput, 'metadata.external_api_calls_attempted', true));
        $this->assertFalse((bool) data_get($canaryOutput, 'metadata.production_log_read_attempted', true));
    }

    #[Test]
    public function drift_foundation_write_mode_requires_canary_or_limit(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        $this->seedUrlTruthRows();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.collectors_enabled' => true,
            'seo_intel.write_enabled' => true,
            'seo_intel.drift_foundation.issue_queue_target_enabled' => true,
        ]);

        $result = (new SeoIntelCollectorManager)->collect('drift_foundation', [
            'dry_run' => false,
            'no_write' => false,
        ]);

        $this->assertSame('blocked', $result->status);
        $this->assertContains('drift_foundation_write_requires_bound', $result->issues);
        $this->assertFalse($result->writesAttempted);
        $this->assertFalse($result->writesCommitted);
    }

    #[Test]
    public function bounded_drift_canary_writes_sanitized_issue_queue_rows_idempotently(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        $this->seedUrlTruthRows();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.collectors_enabled' => true,
            'seo_intel.write_enabled' => true,
            'seo_intel.drift_foundation.issue_queue_target_enabled' => true,
        ]);

        $first = (new SeoIntelCollectorManager)->collect('drift_foundation', [
            'dry_run' => false,
            'no_write' => false,
            'canary' => true,
        ]);
        $second = (new SeoIntelCollectorManager)->collect('drift_foundation', [
            'dry_run' => false,
            'no_write' => false,
            'limit' => 5,
        ]);

        $this->assertSame('success', $first->status);
        $this->assertTrue($first->writesAttempted);
        $this->assertTrue($first->writesCommitted);
        $this->assertSame(['seo_issue_queue'], $first->metadata['target_tables'] ?? null);
        $this->assertTrue((bool) ($first->metadata['pii_safe'] ?? false));
        $this->assertFalse((bool) ($first->metadata['raw_evidence_included'] ?? true));
        $this->assertSame('success', $second->status);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_issue_queue')->count());

        $row = (array) DB::connection('seo_intel')->table('seo_issue_queue')->first();
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);

        $this->assertSame('drift_foundation', $row['source_system'] ?? null);
        $this->assertNull($row['canonical_url'] ?? null);
        foreach (['email', 'order_no', 'attempt_id', 'payment_id', 'provider_event_id', 'cookie', 'raw_ip', 'token', 'api_key', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    #[Test]
    public function drift_foundation_rejects_forbidden_source_authority_without_using_node2_source(): void
    {
        $this->prepareSeoIntelSqliteConnection();
        $this->seedUrlTruthRows(sourceAuthority: 'node2_local_db');

        $result = (new SeoIntelCollectorManager)->collect('drift_foundation', [
            'dry_run' => true,
            'no_write' => true,
            'canary' => true,
        ]);
        $encoded = json_encode($result->metadata, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $result->status);
        $this->assertContains('forbidden_source_authority_detected', array_keys($result->metadata['issue_type_breakdown'] ?? []));
        $this->assertStringNotContainsString('"source_authority":"node2_local_db"', $encoded);
        $this->assertFalse((bool) ($result->metadata['node2_local_db_data_source'] ?? true));
        $this->assertFalse((bool) ($result->metadata['business_db_raw_source_used'] ?? true));
    }

    #[Test]
    public function html_snapshot_parser_extracts_safe_metadata(): void
    {
        $snapshot = (new HtmlSnapshotParser)->parse(<<<'HTML'
<html><head>
<link rel="canonical" href="https://fermatmind.com/zh/articles/fixture">
<link rel="alternate" hreflang="en" href="https://fermatmind.com/en/articles/fixture">
<title>Fixture Title</title>
<meta name="description" content="Fixture description">
<meta name="robots" content="index,follow">
<script type="application/ld+json">{"@type":"Article"}</script>
</head></html>
HTML, 200);

        $this->assertSame(200, $snapshot['status_code']);
        $this->assertSame('https://fermatmind.com/zh/articles/fixture', $snapshot['canonical']);
        $this->assertSame('Fixture Title', $snapshot['title']);
        $this->assertSame('Fixture description', $snapshot['description']);
        $this->assertSame('index,follow', $snapshot['robots']);
        $this->assertSame(1, $snapshot['jsonld_count']);
        $this->assertSame(['Article'], $snapshot['jsonld_types']);
        $this->assertSame('en', $snapshot['hreflang'][0]['hreflang'] ?? null);
        $this->assertArrayHasKey('href_hash', $snapshot['hreflang'][0] ?? []);
    }

    #[Test]
    public function metadata_drift_comparator_reports_mismatches_with_hashes_only(): void
    {
        $issues = (new MetadataDriftComparator)->compare(
            ['canonical_url' => 'https://fermatmind.com/a', 'title' => 'Expected'],
            ['canonical' => 'https://fermatmind.com/b', 'title' => 'Observed']
        );

        $this->assertContains('canonical_url_mismatch', array_column($issues, 'issue_type'));
        $this->assertContains('title_mismatch', array_column($issues, 'issue_type'));

        foreach ($issues as $issue) {
            $this->assertArrayHasKey('expected_hash', $issue);
            $this->assertArrayHasKey('observed_hash', $issue);
            $this->assertStringNotContainsString('Expected', json_encode($issue, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('Observed', json_encode($issue, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function sitemap_llms_parity_comparator_detects_extra_missing_and_private_flow_exposure(): void
    {
        $result = (new SitemapLlmsParityComparator)->compare(
            inventoryUrls: ['https://fermatmind.com/a', 'https://fermatmind.com/b'],
            sitemapUrls: ['https://fermatmind.com/a', 'https://fermatmind.com/private/result'],
            llmsUrls: ['https://fermatmind.com/b', 'https://fermatmind.com/extra'],
            privateFlowUrls: ['https://fermatmind.com/private/result'],
            sourceAuthoritiesByUrl: ['https://fermatmind.com/extra' => 'frontend_fallback'],
        );

        $this->assertNotEmpty($result['missing_in_sitemap']);
        $this->assertNotEmpty($result['extra_in_sitemap']);
        $this->assertNotEmpty($result['missing_in_llms']);
        $this->assertNotEmpty($result['extra_in_llms']);
        $this->assertNotEmpty($result['private_flow_exposure_warning']);
        $this->assertNotEmpty($result['source_authority_mismatch']);
    }

    #[Test]
    public function crawler_user_agent_classifier_detects_required_families(): void
    {
        $classifier = new CrawlerUserAgentClassifier;

        $this->assertSame('googlebot', $classifier->classify('Googlebot/2.1'));
        $this->assertSame('bingbot', $classifier->classify('bingbot/2.0'));
        $this->assertSame('baiduspider', $classifier->classify('Baiduspider/2.0'));
        $this->assertSame('360spider', $classifier->classify('360Spider'));
        $this->assertSame('sogou', $classifier->classify('Sogou web spider'));
        $this->assertSame('shenma_yisou', $classifier->classify('YisouSpider'));
        $this->assertSame('bytespider', $classifier->classify('Bytespider'));
        $this->assertSame('ai_crawler', $classifier->classify('GPTBot'));
        $this->assertSame('unknown_bot', $classifier->classify('ExampleCrawler'));
        $this->assertSame('human_or_unknown', $classifier->classify('Mozilla/5.0'));
    }

    #[Test]
    public function crawler_log_parser_does_not_expose_raw_ip_or_cookies(): void
    {
        $parsed = (new CrawlerLogLineParser(new CrawlerUserAgentClassifier))->parse(
            '203.0.113.9 - - [17/May/2026:05:00:00 +0000] "GET /zh/articles/fixture?cookie=secret HTTP/1.1" 200 123 "-" "Baiduspider/2.0" request_time=0.045'
        );
        $encoded = json_encode($parsed, JSON_THROW_ON_ERROR);

        $this->assertSame('baiduspider', $parsed['bot_family']);
        $this->assertSame('/zh/articles/fixture', $parsed['path']);
        $this->assertSame(200, $parsed['status_code']);
        $this->assertSame(45, $parsed['response_time_ms']);
        $this->assertSame('GET', $parsed['method']);
        $this->assertArrayHasKey('user_agent_hash', $parsed);
        $this->assertStringNotContainsString('203.0.113.9', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertFalse($parsed['exposes_raw_ip']);
        $this->assertFalse($parsed['exposes_cookies']);
    }

    #[Test]
    public function drift_and_crawler_commands_output_safe_dry_run_json(): void
    {
        foreach (['drift_foundation', 'crawler_log_foundation'] as $collector) {
            $exitCode = Artisan::call('seo-intel:collect', [
                '--collector' => $collector,
                '--dry-run' => true,
                '--json' => true,
            ]);

            $output = trim(Artisan::output());
            $decoded = json_decode($output, true);

            $this->assertSame(0, $exitCode);
            $this->assertIsArray($decoded);
            $this->assertSame($collector, $decoded['collector'] ?? null);
            $this->assertSame('success', $decoded['status'] ?? null);
            $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
            $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
            $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
            $this->assertStringNotContainsString('203.0.113', $output);
            $this->assertStringNotContainsString('198.51.100', $output);
            $this->assertStringNotContainsString('order_no', $output);
            $this->assertStringNotContainsString('attempt_id', $output);
            $this->assertStringNotContainsString('payment_id', $output);
        }
    }

    #[Test]
    public function generated_artifact_locks_drift_foundation_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-02A', $artifact['source_documents'] ?? []);
        $this->assertSame(['drift_foundation', 'crawler_log_foundation'], $artifact['collectors'] ?? []);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_crawl_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_log_read_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['modifies_sitemap_llms'] ?? true));
        $this->assertFalse((bool) ($artifact['modifies_cms'] ?? true));
        $this->assertFalse((bool) ($artifact['node2_local_laravel_data_source'] ?? true));
        $this->assertFalse((bool) ($artifact['parser_outputs_pii'] ?? true));
        $this->assertContains('baiduspider', $artifact['supported_bot_families'] ?? []);
        $this->assertSame('SEO-DASH-03A', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function bounded_drift_issue_canary_artifact_locks_next_preflight_task(): void
    {
        $path = base_path('docs/seo/generated/bounded-drift-issue-canary.v1.json');

        $this->assertFileExists($path);

        $artifact = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($artifact);
        $this->assertSame('drift_foundation.bounded_issue_canary.v1', $artifact['version'] ?? null);
        $this->assertSame('drift_foundation', $artifact['collector'] ?? null);
        $this->assertSame('seo_issue_queue', $artifact['target_table'] ?? null);
        $this->assertSame(['seo_urls', 'seo_url_entities'], $artifact['source_tables'] ?? null);
        $this->assertTrue((bool) ($artifact['bounded_canary_supported'] ?? false));
        $this->assertTrue((bool) ($artifact['write_requires_bound'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_log_read_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['scheduler_enabled_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['metabase_deployed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['production_write_executed_in_this_pr'] ?? true));
        $this->assertContains('email', $artifact['forbidden_fields'] ?? []);
        $this->assertContains('node2_local_db', $artifact['forbidden_sources'] ?? []);
        $this->assertSame('SEO-DASH-PROD-03D-PREFLIGHT-R2', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function drift_foundation_does_not_add_scheduler_activation(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('drift_foundation', $bootstrap);
        $this->assertStringNotContainsString('crawler_log_foundation', $bootstrap);
        $this->assertStringNotContainsString('DriftFoundationCollector', $bootstrap);
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-intel-drift-collector-foundation.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function prepareSeoIntelSqliteConnection(): void
    {
        config([
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('seo_intel');

        Schema::connection('seo_intel')->create('seo_urls', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->string('lastmod_source', 64)->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale']);
        });

        Schema::connection('seo_intel')->create('seo_url_entities', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();
        });

        Schema::connection('seo_intel')->create('seo_issue_queue', function ($table): void {
            $table->id();
            $table->string('issue_uid', 128)->unique();
            $table->string('issue_type', 64);
            $table->string('severity', 32)->default('info');
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 64)->nullable();
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('status', 32)->default('open');
            $table->string('lifecycle_state', 32)->default('open');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    private function seedUrlTruthRows(string $sourceAuthority = 'backend_public_surface'): void
    {
        $now = now();
        $rows = [
            [
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/en'),
                'canonical_url' => 'https://fermatmind.com/en',
                'locale' => 'en',
                'page_entity_type' => 'home',
                'entity_id_or_slug' => 'home:en',
                'cluster' => null,
                'source_authority' => $sourceAuthority,
                'indexability_state' => 'indexable',
            ],
            [
                'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types'),
                'canonical_url' => 'https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types',
                'locale' => 'en',
                'page_entity_type' => 'test_detail',
                'entity_id_or_slug' => 'mbti-personality-test-16-personality-types',
                'cluster' => 'personality',
                'source_authority' => $sourceAuthority,
                'indexability_state' => 'indexable',
            ],
        ];

        foreach ($rows as $row) {
            DB::connection('seo_intel')->table('seo_urls')->insert($row + [
                'lastmod_at' => null,
                'lastmod_source' => null,
                'is_private_flow' => false,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'metadata_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::connection('seo_intel')->table('seo_url_entities')->insert([
                'canonical_url_hash' => $row['canonical_url_hash'],
                'locale' => $row['locale'],
                'page_entity_type' => $row['page_entity_type'],
                'entity_id_or_slug' => $row['entity_id_or_slug'],
                'entity_source' => 'backend_authority',
                'authority_status' => 'authoritative',
                'source_updated_at' => null,
                'attributes_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
