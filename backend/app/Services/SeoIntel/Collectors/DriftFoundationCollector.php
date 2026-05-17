<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\Drift\HtmlSnapshotParser;
use App\Services\SeoIntel\Drift\MetadataDriftComparator;
use App\Services\SeoIntel\Drift\SitemapLlmsParityComparator;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class DriftFoundationCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly HtmlSnapshotParser $htmlParser,
        private readonly MetadataDriftComparator $metadataComparator,
        private readonly SitemapLlmsParityComparator $parityComparator,
    ) {}

    public function name(): string
    {
        return 'drift_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $writesAllowed = (bool) ($options['writes_allowed'] ?? false);
        $allowExternalApiCalls = (bool) ($options['allow_external_api_calls'] ?? false);
        $allowProductionCrawl = (bool) ($options['allow_production_crawl'] ?? false);
        $snapshot = $this->htmlParser->parse($this->fixtureHtml(), 200);
        $metadataComparison = $this->metadataComparator->compare($this->expectedMetadata(), $snapshot);
        $parity = $this->parityComparator->compare(
            inventoryUrls: [
                'https://fermatmind.com/zh/articles/drift-fixture',
                'https://fermatmind.com/zh/topics/search-intelligence',
            ],
            sitemapUrls: [
                'https://fermatmind.com/zh/articles/drift-fixture',
                'https://fermatmind.com/zh/result/private-flow',
            ],
            llmsUrls: [
                'https://fermatmind.com/zh/topics/search-intelligence',
            ],
            privateFlowUrls: [
                'https://fermatmind.com/zh/result/private-flow',
            ],
            sourceAuthoritiesByUrl: [
                'https://fermatmind.com/zh/articles/drift-fixture' => 'cms_article',
            ],
        );

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: 1,
            issues: $this->warningsFrom($metadataComparison, $parity),
            metadata: [
                'writes_allowed' => $writesAllowed,
                'external_api_calls_allowed' => $allowExternalApiCalls,
                'production_crawl_allowed' => $allowProductionCrawl,
                'production_log_read_allowed' => false,
                'fetches_public_html' => false,
                'performs_drift_detection' => false,
                'modifies_sitemap_llms' => false,
                'modifies_cms' => false,
                'node2_local_laravel_data_source' => false,
                'snapshot_summary' => [
                    'status_code' => $snapshot['status_code'],
                    'canonical_hash' => $snapshot['canonical'] === null ? null : hash('sha256', $snapshot['canonical']),
                    'title_hash' => $snapshot['title'] === null ? null : hash('sha256', $snapshot['title']),
                    'description_hash' => $snapshot['description'] === null ? null : hash('sha256', $snapshot['description']),
                    'robots_hash' => $snapshot['robots'] === null ? null : hash('sha256', $snapshot['robots']),
                    'jsonld_count' => $snapshot['jsonld_count'],
                    'jsonld_types' => $snapshot['jsonld_types'],
                    'hreflang_count' => count($snapshot['hreflang']),
                ],
                'metadata_drift_issues' => $metadataComparison,
                'sitemap_llms_parity' => $parity,
            ],
        );
    }

    private function fixtureHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="zh-CN">
<head>
<link rel="canonical" href="https://fermatmind.com/zh/articles/drift-fixture">
<link rel="alternate" hreflang="en" href="https://fermatmind.com/en/articles/drift-fixture">
<title>Drift Fixture</title>
<meta name="description" content="Safe fixture for drift parsing">
<meta name="robots" content="index,follow">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>
</head>
<body>fixture only</body>
</html>
HTML;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedMetadata(): array
    {
        return [
            'canonical_url' => 'https://fermatmind.com/zh/articles/drift-fixture',
            'title' => 'Drift Fixture',
            'description' => 'Safe fixture for drift parsing',
            'robots' => 'index,follow',
            'jsonld_types' => ['Article'],
            'jsonld_count' => 1,
            'hreflang' => [
                [
                    'hreflang' => 'en',
                    'href_hash' => hash('sha256', 'https://fermatmind.com/en/articles/drift-fixture'),
                ],
            ],
        ];
    }

    /**
     * @param  list<array{status: string, issue_type: string, expected_hash: string|null, observed_hash: string|null}>  $metadataComparison
     * @param  array<string, list<string>>  $parity
     * @return list<string>
     */
    private function warningsFrom(array $metadataComparison, array $parity): array
    {
        $warnings = [];

        foreach ($metadataComparison as $issue) {
            if (($issue['status'] ?? 'pass') !== 'pass') {
                $warnings[] = (string) $issue['issue_type'];
            }
        }

        foreach ($parity as $issueType => $hashes) {
            if ($hashes !== []) {
                $warnings[] = $issueType;
            }
        }

        return array_values(array_unique($warnings));
    }
}
