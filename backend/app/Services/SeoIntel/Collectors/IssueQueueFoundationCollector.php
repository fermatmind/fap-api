<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;
use App\Services\SeoIntel\SeoIssueQueueProducer;
use App\Services\SeoIntel\SeoIssueSummaryService;

final class IssueQueueFoundationCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly SeoIssueQueueProducer $producer,
        private readonly SeoIssueSummaryService $summaryService,
    ) {}

    public function name(): string
    {
        return 'issue_queue_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $canary = (bool) ($options['canary'] ?? false);
        $limit = $this->boundedLimit($options['limit'] ?? null, $canary);
        $produced = $this->producer->produce();
        $summary = $this->summaryService->summarize($produced['issues']);

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: $summary['issue_count'],
            issues: [
                'fixture_only_no_live_data_read',
                'cms_mutation_blocked',
                'auto_publish_blocked',
                'auto_pseo_blocked',
                'raw_pii_blocked',
            ],
            metadata: [
                'issue_queue_enabled' => (bool) config('seo_intel.issue_queue_enabled', false),
                'issue_summary_api_enabled' => (bool) config('seo_intel.issue_summary_api_enabled', false),
                'writes_allowed' => (bool) ($options['writes_allowed'] ?? false),
                'canary' => $canary,
                'limit' => $limit,
                'write_requires_bound' => true,
                'scheduler_enabled' => false,
                'queue_worker_enabled' => false,
                'external_api_calls_allowed' => false,
                'cms_summary_read_only' => true,
                'cms_mutation_attempted' => false,
                'auto_publish_attempted' => false,
                'auto_pseo_attempted' => false,
                'issue_count' => $summary['issue_count'],
                'issue_types' => array_keys($summary['issue_type_counts']),
                'severity_counts' => $summary['severity_counts'],
                'lifecycle_counts' => $summary['lifecycle_counts'],
                'raw_evidence_included' => false,
                'metabase_data_source' => 'seo_intel_only',
                'search_channel_purchase_attribution_allowed' => false,
                'node2_local_laravel_data_source' => false,
            ],
        );
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
}
