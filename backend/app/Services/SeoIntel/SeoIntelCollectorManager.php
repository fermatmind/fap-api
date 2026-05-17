<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\Collectors\CrawlerLogFoundationCollector;
use App\Services\SeoIntel\Collectors\DriftFoundationCollector;
use App\Services\SeoIntel\Collectors\NoopSeoIntelCollector;
use App\Services\SeoIntel\Collectors\UrlTruthInventoryCollector;
use App\Services\SeoIntel\Drift\CrawlerLogLineParser;
use App\Services\SeoIntel\Drift\CrawlerUserAgentClassifier;
use App\Services\SeoIntel\Drift\HtmlSnapshotParser;
use App\Services\SeoIntel\Drift\MetadataDriftComparator;
use App\Services\SeoIntel\Drift\SitemapLlmsParityComparator;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;

final class SeoIntelCollectorManager
{
    /**
     * @return list<string>
     */
    public function allowedCollectors(): array
    {
        $allowed = config('seo_intel.allowed_collectors', ['noop']);

        if (! is_array($allowed)) {
            return ['noop'];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $collector): string => (string) $collector, $allowed),
            static fn (string $collector): bool => $collector !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(?string $collector = null, array $options = []): SeoIntelCollectorResult
    {
        $collectorName = $collector ?: (string) config('seo_intel.default_collector', 'noop');
        $dryRun = array_key_exists('dry_run', $options)
            ? (bool) $options['dry_run']
            : (bool) config('seo_intel.dry_run_default', true);

        if (! in_array($collectorName, $this->allowedCollectors(), true)) {
            return $this->blocked($collectorName, $dryRun, 'unknown_collector');
        }

        if (! $this->externalApiCallsAllowed()) {
            $options['allow_external_api_calls'] = false;
        }

        if (! $this->productionCrawlAllowed()) {
            $options['allow_production_crawl'] = false;
        }

        if (! $this->productionLogReadAllowed()) {
            $options['allow_production_log_read'] = false;
        }

        $collectorsEnabled = (bool) config('seo_intel.collectors_enabled', false);
        $safeDryRunAllowed = $dryRun;

        if (! $collectorsEnabled && ! $safeDryRunAllowed) {
            return $this->blocked($collectorName, $dryRun, 'collectors_disabled');
        }

        if (! $this->writesAllowed($dryRun, (bool) ($options['no_write'] ?? false))) {
            $options['writes_allowed'] = false;
        }

        return $this->resolve($collectorName)->collect($options + ['dry_run' => $dryRun]);
    }

    private function resolve(string $collector): SeoIntelCollector
    {
        if ($collector === 'noop') {
            return new NoopSeoIntelCollector;
        }

        if ($collector === 'url_truth_inventory') {
            return new UrlTruthInventoryCollector(new BackendAuthorityUrlTruthSource);
        }

        if ($collector === 'drift_foundation') {
            return new DriftFoundationCollector(
                new HtmlSnapshotParser,
                new MetadataDriftComparator,
                new SitemapLlmsParityComparator,
            );
        }

        if ($collector === 'crawler_log_foundation') {
            return new CrawlerLogFoundationCollector(
                new CrawlerUserAgentClassifier,
                new CrawlerLogLineParser(new CrawlerUserAgentClassifier),
            );
        }

        return new NoopSeoIntelCollector;
    }

    private function writesAllowed(bool $dryRun, bool $noWrite): bool
    {
        return ! $dryRun
            && ! $noWrite
            && (bool) config('seo_intel.enabled', false)
            && (bool) config('seo_intel.write_enabled', false);
    }

    private function externalApiCallsAllowed(): bool
    {
        return (bool) config('seo_intel.allow_external_api_calls', false);
    }

    private function productionCrawlAllowed(): bool
    {
        return (bool) config('seo_intel.allow_production_crawl', false);
    }

    private function productionLogReadAllowed(): bool
    {
        return (bool) config('seo_intel.allow_production_log_read', false);
    }

    private function blocked(string $collector, bool $dryRun, string $reason): SeoIntelCollectorResult
    {
        return new SeoIntelCollectorResult(
            collector: $collector,
            status: 'blocked',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: 0,
            issues: [$reason],
            metadata: [
                'collectors_enabled' => (bool) config('seo_intel.collectors_enabled', false),
                'write_enabled' => (bool) config('seo_intel.write_enabled', false),
                'external_api_calls_allowed' => $this->externalApiCallsAllowed(),
                'production_crawl_allowed' => $this->productionCrawlAllowed(),
                'production_log_read_allowed' => $this->productionLogReadAllowed(),
            ],
        );
    }
}
