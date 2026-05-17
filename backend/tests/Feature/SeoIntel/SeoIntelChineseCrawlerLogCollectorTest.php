<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\ChineseCrawlerUserAgentClassifier;
use App\Services\SeoIntel\CrawlerLogLineParser;
use App\Services\SeoIntel\CrawlerLogPrivacySanitizer;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelChineseCrawlerLogCollectorTest extends TestCase
{
    #[Test]
    public function crawler_log_collector_is_registered_and_disabled_by_default(): void
    {
        $this->assertContains('chinese_crawler_log_foundation', config('seo_intel.allowed_collectors'));
        $this->assertFalse((bool) config('seo_intel.collectors_enabled'));
        $this->assertFalse((bool) config('seo_intel.write_enabled'));
        $this->assertFalse((bool) config('seo_intel.allow_external_api_calls'));
        $this->assertFalse((bool) config('seo_intel.allow_production_log_read'));
        $this->assertFalse((bool) config('seo_intel.allow_raw_ip_storage'));
        $this->assertFalse((bool) config('seo_intel.allow_raw_user_agent_storage'));
        $this->assertFalse((bool) config('seo_intel.allow_raw_cookie_storage'));
        $this->assertFalse((bool) config('seo_intel.chinese_crawler_logs_enabled'));
        $this->assertFalse((bool) config('seo_intel.chinese_crawler_live_log_read_enabled'));
        $this->assertNull(config('seo_intel.crawler_log_source'));
    }

    #[Test]
    public function crawler_log_daily_migration_does_not_include_forbidden_columns(): void
    {
        $paths = glob(base_path('database/migrations/*seo_crawler_logs_daily*'));

        $this->assertCount(1, $paths);

        $contents = strtolower((string) file_get_contents($paths[0]));

        foreach ($this->forbiddenColumns() as $column) {
            $this->assertStringNotContainsString("'".$column."'", $contents, 'migration must not define '.$column);
            $this->assertStringNotContainsString('"'.$column.'"', $contents, 'migration must not define '.$column);
        }
    }

    #[Test]
    public function dry_run_command_outputs_safe_json_without_live_logs_external_calls_or_raw_identifiers(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'chinese_crawler_log_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('chinese_crawler_log_foundation', $decoded['collector'] ?? null);
        $this->assertSame('success', $decoded['status'] ?? null);
        $this->assertTrue((bool) ($decoded['dry_run'] ?? false));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['production_log_read_attempted'] ?? true));
        $this->assertSame(5, $decoded['metadata']['lines_seen'] ?? null);
        $this->assertSame(5, $decoded['metadata']['lines_parsed'] ?? null);
        $this->assertGreaterThanOrEqual(1, $decoded['metadata']['private_flow_hits'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $decoded['metadata']['noindex_hits'] ?? 0);
        $this->assertFalse((bool) ($decoded['metadata']['crawler_hit_grants_indexability'] ?? true));
        $this->assertFalse((bool) ($decoded['metadata']['search_channel_purchase_attribution_allowed'] ?? true));

        foreach (['198.51.100', '203.0.113', 'Baiduspider', '360Spider', 'Sogou', 'YisouSpider', 'Bytespider', 'token=secret', 'attempt_id=secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output);
        }
    }

    #[Test]
    public function user_agent_classifier_detects_required_families_and_source_engines(): void
    {
        $classifier = new ChineseCrawlerUserAgentClassifier;

        $cases = [
            'Googlebot/2.1' => ['googlebot', 'google'],
            'bingbot/2.0' => ['bingbot', 'bing_indexnow'],
            'Baiduspider/2.0' => ['baiduspider', 'baidu'],
            '360Spider' => ['so360_spider', 'so360'],
            'Sogou web spider' => ['sogou_spider', 'sogou'],
            'YisouSpider' => ['shenma_yisou', 'shenma'],
            'Bytespider' => ['bytespider', 'ai_search'],
            'GPTBot' => ['ai_crawler', 'ai_search'],
            'ExampleCrawler' => ['unknown_bot', 'unknown'],
        ];

        foreach ($cases as $userAgent => [$family, $sourceEngine]) {
            $this->assertSame($family, $classifier->classify($userAgent));
            $this->assertSame($sourceEngine, $classifier->sourceEngineFor($family));
        }
    }

    #[Test]
    public function parser_and_sanitizer_strip_queries_and_do_not_expose_raw_ip_cookie_or_user_agent(): void
    {
        $parser = new CrawlerLogLineParser(new ChineseCrawlerUserAgentClassifier);
        $sanitizer = new CrawlerLogPrivacySanitizer;
        $parsed = $parser->parse(
            '203.0.113.9 - - [17/May/2026:05:00:00 +0000] "GET /zh/result/private?cookie=secret&attempt_id=hidden HTTP/1.1" 200 123 "-" "Baiduspider/2.0" request_time=0.045'
        );
        $path = $sanitizer->normalizePath($parsed['path']);
        $encoded = json_encode([$parsed, 'path' => $path], JSON_THROW_ON_ERROR);

        $this->assertSame('baiduspider', $parsed['bot_family']);
        $this->assertSame('baidu', $parsed['source_engine']);
        $this->assertSame('/zh/result/private', $path);
        $this->assertSame('GET', $parsed['method']);
        $this->assertSame(200, $parsed['status_code']);
        $this->assertSame(45, $parsed['response_time_ms']);
        $this->assertArrayHasKey('user_agent_hash', $parsed);
        $this->assertArrayNotHasKey('user_agent', $parsed);
        $this->assertTrue($sanitizer->isPrivateFlowPath($path));
        $this->assertStringNotContainsString('203.0.113.9', $encoded);
        $this->assertStringNotContainsString('cookie=secret', $encoded);
        $this->assertStringNotContainsString('attempt_id=hidden', $encoded);
        $this->assertStringNotContainsString('Baiduspider/2.0', $encoded);
    }

    #[Test]
    public function generated_artifact_locks_chinese_crawler_log_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(1, $artifact['version'] ?? null);
        $this->assertContains('CHINA-SEARCH-03', $artifact['source_documents'] ?? []);
        $this->assertSame('chinese_crawler_log_foundation', $artifact['collector'] ?? null);
        $this->assertFalse((bool) ($artifact['enabled_by_default'] ?? true));
        $this->assertFalse((bool) ($artifact['write_enabled_by_default'] ?? true));
        $this->assertTrue((bool) ($artifact['dry_run_default'] ?? false));
        $this->assertFalse((bool) ($artifact['external_api_calls_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['production_log_read_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['raw_ip_storage_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['raw_cookie_storage_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['raw_user_agent_storage_allowed'] ?? true));
        $this->assertContains('baiduspider', $artifact['supported_bot_families'] ?? []);
        $this->assertSame('baidu', $artifact['source_engine_mapping']['baiduspider'] ?? null);
        $this->assertTrue((bool) ($artifact['private_flow_warning_enabled'] ?? false));
        $this->assertFalse((bool) ($artifact['crawler_hit_grants_indexability'] ?? true));
        $this->assertFalse((bool) ($artifact['search_channel_purchase_attribution_allowed'] ?? true));
        $this->assertTrue((bool) ($artifact['pii_forbidden'] ?? false));
        $this->assertSame('SEO-DASH-05', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function crawler_log_foundation_does_not_enable_scheduler_or_mutate_runtime_configs(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('chinese_crawler_log_foundation', $bootstrap);
        $this->assertStringNotContainsString('ChineseCrawlerLogCollector', $bootstrap);
        $this->assertFalse((bool) config('seo_intel.chinese_crawler_live_log_read_enabled'));
        $this->assertFalse((bool) config('seo_intel.chinese_crawler_log_foundation.crawler_hit_grants_indexability'));
        $this->assertFalse((bool) config('seo_intel.chinese_crawler_log_foundation.search_channel_purchase_attribution_allowed'));
    }

    /**
     * @return list<string>
     */
    private function forbiddenColumns(): array
    {
        return [
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_cookie',
            'raw_payload',
            'payment_payload',
            'raw_email',
            'raw_ip',
            'ip_address',
            'raw_user_agent',
            'user_agent',
            'token',
            'api_key',
            'secret',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/chinese-crawler-log-collector.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
