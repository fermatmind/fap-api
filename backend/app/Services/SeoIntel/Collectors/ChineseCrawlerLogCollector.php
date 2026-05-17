<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\ChineseCrawlerUserAgentClassifier;
use App\Services\SeoIntel\CrawlerLogDailyAggregator;
use App\Services\SeoIntel\CrawlerLogLineParser;
use App\Services\SeoIntel\CrawlerLogPrivacySanitizer;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class ChineseCrawlerLogCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly ChineseCrawlerUserAgentClassifier $classifier,
        private readonly CrawlerLogLineParser $parser,
        private readonly CrawlerLogPrivacySanitizer $sanitizer,
        private readonly CrawlerLogDailyAggregator $aggregator,
    ) {}

    public function name(): string
    {
        return 'chinese_crawler_log_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $rows = array_map(fn (string $line): array => $this->safeRow($line), $this->fixtureLines());
        $aggregate = $this->aggregator->aggregate($rows);

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: count($rows),
            issues: [
                'fixture_only_no_production_log_read',
                'raw_ip_storage_blocked',
                'raw_cookie_storage_blocked',
                'raw_user_agent_storage_blocked',
                ...$this->warnings($aggregate),
            ],
            metadata: [
                'chinese_crawler_logs_enabled' => (bool) config('seo_intel.chinese_crawler_logs_enabled', false),
                'chinese_crawler_live_log_read_enabled' => (bool) config('seo_intel.chinese_crawler_live_log_read_enabled', false),
                'external_api_calls_allowed' => false,
                'external_calls_attempted' => false,
                'production_log_read_allowed' => false,
                'production_log_read_attempted' => false,
                'writes_allowed' => (bool) ($options['writes_allowed'] ?? false),
                'scheduler_enabled' => false,
                'queue_worker_enabled' => false,
                'lines_seen' => $aggregate['lines_seen'],
                'lines_parsed' => $aggregate['lines_parsed'],
                'bot_family_counts' => $aggregate['bot_family_counts'],
                'source_engine_counts' => $aggregate['source_engine_counts'],
                'private_flow_hits' => $aggregate['private_flow_hits'],
                'noindex_hits' => $aggregate['noindex_hits'],
                'daily_rows_seen' => count($aggregate['daily_rows']),
                'supported_bot_families' => $this->classifier->supportedFamilies(),
                'raw_ip_storage_allowed' => false,
                'raw_cookie_storage_allowed' => false,
                'raw_user_agent_storage_allowed' => false,
                'parser_outputs_pii' => false,
                'crawler_logs_are_seo_truth' => false,
                'crawler_hit_grants_indexability' => false,
                'search_channel_purchase_attribution_allowed' => false,
                'node2_local_laravel_data_source' => false,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRow(string $line): array
    {
        $parsed = $this->parser->parse($line);
        $path = $this->sanitizer->normalizePath($parsed['path']);

        return [
            'report_date' => '2026-05-17',
            'canonical_url_hash' => null,
            'path_hash' => $this->sanitizer->pathHash($path),
            'path_display_masked' => $this->sanitizer->pathDisplayMasked($path),
            'locale' => str_starts_with((string) $path, '/zh') ? 'zh-CN' : null,
            'page_entity_type' => $this->pageEntityType($path),
            'source_engine' => $parsed['source_engine'],
            'bot_family' => $parsed['bot_family'],
            'user_agent_hash' => $parsed['user_agent_hash'],
            'method' => $parsed['method'],
            'status_code' => $parsed['status_code'],
            'response_time_bucket' => $this->sanitizer->responseTimeBucket($parsed['response_time_ms']),
            'robots_allowed' => ! str_contains((string) $path, '/blocked-by-robots'),
            'blocked_by_robots' => str_contains((string) $path, '/blocked-by-robots'),
            'private_flow_hit' => $this->sanitizer->isPrivateFlowPath($path),
            'noindex_hit' => str_contains((string) $path, '/noindex'),
            'metadata_json' => [
                'fixture_only' => true,
                'raw_line_stored' => false,
                'raw_ip_stored' => false,
                'raw_cookie_stored' => false,
                'raw_user_agent_stored' => false,
            ],
        ];
    }

    private function pageEntityType(?string $path): ?string
    {
        return match (true) {
            $path === null => null,
            str_contains($path, '/articles/') => 'article',
            str_contains($path, '/topics/') => 'topic',
            str_contains($path, '/personality/') => 'personality',
            str_contains($path, '/career/jobs') => 'career_job',
            str_contains($path, '/tests/') => 'test_detail',
            default => null,
        };
    }

    /**
     * @param  array{private_flow_hits: int, noindex_hits: int}  $aggregate
     * @return list<string>
     */
    private function warnings(array $aggregate): array
    {
        $warnings = [];

        if ($aggregate['private_flow_hits'] > 0) {
            $warnings[] = 'private_flow_crawler_hit_warning';
        }

        if ($aggregate['noindex_hits'] > 0) {
            $warnings[] = 'noindex_crawler_hit_warning';
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function fixtureLines(): array
    {
        return [
            '198.51.100.10 - - [17/May/2026:05:00:00 +0000] "GET /zh/articles/fixture?utm_source=baidu HTTP/1.1" 200 123 "-" "Baiduspider/2.0" request_time=0.045',
            '203.0.113.20 - - [17/May/2026:05:01:00 +0000] "GET /zh/topics/career HTTP/1.1" 200 123 "-" "360Spider" request_time=0.101',
            'cdn-edge status=200 method=GET path=/zh/tests/mbti-personality-test-16-personality-types?token=secret duration_ms=250 ua="Sogou web spider/4.0"',
            '203.0.113.30 - - [17/May/2026:05:02:00 +0000] "GET /zh/result/private?attempt_id=secret HTTP/1.1" 404 123 "-" "YisouSpider" request_time=0.300',
            '203.0.113.40 - - [17/May/2026:05:03:00 +0000] "GET /zh/noindex/blocked-by-robots HTTP/1.1" 403 123 "-" "Bytespider" request_time=1.250',
        ];
    }
}
