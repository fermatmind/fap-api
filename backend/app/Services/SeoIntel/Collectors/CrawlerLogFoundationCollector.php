<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\Drift\CrawlerLogLineParser;
use App\Services\SeoIntel\Drift\CrawlerUserAgentClassifier;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class CrawlerLogFoundationCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly CrawlerUserAgentClassifier $classifier,
        private readonly CrawlerLogLineParser $logParser,
    ) {}

    public function name(): string
    {
        return 'crawler_log_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $writesAllowed = (bool) ($options['writes_allowed'] ?? false);
        $allowProductionLogRead = (bool) ($options['allow_production_log_read'] ?? false);
        $parsed = $this->logParser->parse($this->fixtureLogLine());

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: 1,
            issues: [],
            metadata: [
                'writes_allowed' => $writesAllowed,
                'external_api_calls_allowed' => false,
                'production_crawl_allowed' => false,
                'production_log_read_allowed' => $allowProductionLogRead,
                'reads_production_logs' => false,
                'supported_bot_families' => $this->classifier->supportedFamilies(),
                'sample_log_result' => $parsed,
                'parser_outputs_pii' => false,
                'node2_local_laravel_data_source' => false,
            ],
        );
    }

    private function fixtureLogLine(): string
    {
        return '198.51.100.10 - - [17/May/2026:05:00:00 +0000] "GET /zh/articles/drift-fixture?utm_source=fixture HTTP/1.1" 200 123 "-" "Googlebot/2.1 (+http://www.google.com/bot.html)" request_time=0.123';
    }
}
