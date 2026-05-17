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
}
