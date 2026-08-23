<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\Drift\HtmlSnapshotParser;
use App\Services\SeoIntel\Drift\MetadataDriftComparator;
use App\Services\SeoIntel\Drift\SitemapLlmsParityComparator;
use App\Services\SeoIntel\DriftIssueCandidate;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;
use App\Services\SeoIntel\UrlTruthDriftIssueCandidateSource;
use Illuminate\Support\Facades\DB;

final class DriftFoundationCollector implements SeoIntelCollector
{
    private readonly UrlTruthDriftIssueCandidateSource $candidateSource;

    public function __construct(
        private readonly HtmlSnapshotParser $htmlParser,
        private readonly MetadataDriftComparator $metadataComparator,
        private readonly SitemapLlmsParityComparator $parityComparator,
        ?UrlTruthDriftIssueCandidateSource $candidateSource = null,
    ) {
        $this->candidateSource = $candidateSource ?? new UrlTruthDriftIssueCandidateSource;
    }

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
        $canary = (bool) ($options['canary'] ?? false);
        $limit = $this->boundedLimit($options['limit'] ?? null, $canary);
        $boundProvided = $canary || (($options['limit'] ?? null) !== null && ($options['limit'] ?? '') !== '');
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
        $candidateResult = $this->candidateSource->candidates($limit);
        $candidates = $candidateResult['candidates'];
        $issues = array_merge(
            $this->warningsFrom($metadataComparison, $parity),
            $candidateResult['issues'],
        );

        if (
            $writesAllowed
            && ! $dryRun
            && (bool) config('seo_intel.drift_foundation.write_requires_bound', true)
            && ! $boundProvided
        ) {
            $issues[] = 'drift_foundation_write_requires_bound';

            return $this->result(
                dryRun: $dryRun,
                writesAllowed: $writesAllowed,
                writesAttempted: false,
                writesCommitted: false,
                issues: $issues,
                snapshot: $snapshot,
                metadataComparison: $metadataComparison,
                parity: $parity,
                candidateResult: $candidateResult,
                candidates: $candidates,
                canary: $canary,
                limit: $limit,
                status: 'blocked',
            );
        }

        if (
            $writesAllowed
            && ! $dryRun
            && ! (bool) config('seo_intel.drift_foundation.issue_queue_target_enabled', false)
        ) {
            $issues[] = 'drift_issue_queue_target_disabled';

            return $this->result(
                dryRun: $dryRun,
                writesAllowed: $writesAllowed,
                writesAttempted: false,
                writesCommitted: false,
                issues: $issues,
                snapshot: $snapshot,
                metadataComparison: $metadataComparison,
                parity: $parity,
                candidateResult: $candidateResult,
                candidates: $candidates,
                canary: $canary,
                limit: $limit,
                status: 'blocked',
            );
        }

        $writesAttempted = $writesAllowed && ! $dryRun && $candidates !== [];
        $writesCommitted = false;

        if ($writesAttempted) {
            $this->writeIssueCandidates($candidates);
            $writesCommitted = true;
        }

        return $this->result(
            dryRun: $dryRun,
            writesAllowed: $writesAllowed,
            writesAttempted: $writesAttempted,
            writesCommitted: $writesCommitted,
            issues: $issues,
            snapshot: $snapshot,
            metadataComparison: $metadataComparison,
            parity: $parity,
            candidateResult: $candidateResult,
            candidates: $candidates,
            canary: $canary,
            limit: $limit,
        );
    }

    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{status: string, issue_type: string, expected_hash: string|null, observed_hash: string|null}>  $metadataComparison
     * @param  array<string, list<string>>  $parity
     * @param  array{candidates: list<DriftIssueCandidate>, metadata: array<string, mixed>, issues: list<string>}  $candidateResult
     * @param  list<DriftIssueCandidate>  $candidates
     */
    private function result(
        bool $dryRun,
        bool $writesAllowed,
        bool $writesAttempted,
        bool $writesCommitted,
        array $issues,
        array $snapshot,
        array $metadataComparison,
        array $parity,
        array $candidateResult,
        array $candidates,
        bool $canary,
        ?int $limit,
        string $status = 'success',
    ): SeoIntelCollectorResult {
        $targetTables = ['seo_issue_queue'];

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: $status,
            dryRun: $dryRun,
            writesAttempted: $writesAttempted,
            writesCommitted: $writesCommitted,
            externalCallsAttempted: false,
            itemsSeen: (int) ($candidateResult['metadata']['url_rows_seen'] ?? 0),
            issues: array_values(array_unique($issues)),
            metadata: [
                'writes_allowed' => $writesAllowed,
                'external_api_calls_allowed' => false,
                'external_api_calls_attempted' => false,
                'production_crawl_allowed' => false,
                'production_log_read_allowed' => false,
                'source_tables' => ['seo_urls', 'seo_url_entities'],
                'target_tables' => $targetTables,
                'candidate_count' => count($candidates),
                'planned_issue_count' => count($candidates),
                'written_issue_count' => $writesCommitted ? count($candidates) : 0,
                'limit' => $limit,
                'canary' => $canary,
                'write_requires_bound' => (bool) config('seo_intel.drift_foundation.write_requires_bound', true),
                'issue_queue_target_enabled' => (bool) config('seo_intel.drift_foundation.issue_queue_target_enabled', false),
                'issue_type_breakdown' => $this->issueTypeBreakdown($candidates),
                'pii_safe' => true,
                'raw_evidence_included' => false,
                'production_log_read_attempted' => false,
                'public_html_crawl_attempted' => false,
                'search_submission_attempted' => false,
                'cms_mutation_attempted' => false,
                'auto_publish_attempted' => false,
                'auto_pseo_attempted' => false,
                'fetches_public_html' => false,
                'performs_drift_detection' => false,
                'modifies_sitemap_llms' => false,
                'modifies_cms' => false,
                'node2_local_laravel_data_source' => false,
                'node2_local_db_data_source' => false,
                'business_db_raw_source_used' => false,
                'source_authority_breakdown' => $candidateResult['metadata']['source_authority_breakdown'] ?? [],
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

    /**
     * @param  list<DriftIssueCandidate>  $candidates
     */
    private function writeIssueCandidates(array $candidates): void
    {
        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();

        foreach ($candidates as $candidate) {
            $connection->table('seo_issue_queue')->updateOrInsert(
                ['issue_uid' => $candidate->issueUid()],
                [
                    'issue_type' => $candidate->issueType,
                    'severity' => $candidate->severity,
                    'source_system' => $this->name(),
                    'source_engine' => null,
                    'canonical_url_hash' => $candidate->canonicalUrlHash,
                    'canonical_url' => null,
                    'locale' => $candidate->locale,
                    'page_entity_type' => $candidate->pageEntityType,
                    'entity_id_or_slug' => $candidate->entityIdOrSlug,
                    'cluster' => $candidate->cluster,
                    'status' => 'open',
                    'lifecycle_state' => 'open',
                    'detected_at' => $now,
                    'summary' => $candidate->summary,
                    'recommendation' => $candidate->recommendation,
                    'evidence_hash' => $candidate->evidenceHash(),
                    'metadata_json' => json_encode($candidate->metadata, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
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

    private function boundedLimit(mixed $rawLimit, bool $canary): ?int
    {
        $max = max(1, (int) config('seo_intel.drift_foundation.canary_max_limit', 50));

        if ($rawLimit !== null && $rawLimit !== '') {
            return min($max, max(1, (int) $rawLimit));
        }

        if ($canary) {
            $default = max(1, (int) config('seo_intel.drift_foundation.canary_default_limit', 5));

            return min($max, $default);
        }

        return null;
    }

    /**
     * @param  list<DriftIssueCandidate>  $candidates
     * @return array<string, int>
     */
    private function issueTypeBreakdown(array $candidates): array
    {
        $breakdown = [];

        foreach ($candidates as $candidate) {
            $breakdown[$candidate->issueType] = ($breakdown[$candidate->issueType] ?? 0) + 1;
        }

        ksort($breakdown);

        return $breakdown;
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
