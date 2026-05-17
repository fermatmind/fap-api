<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\IndexNowPayloadValidator;
use App\Services\SeoIntel\SearchChannelSubmissionStatusNormalizer;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

final class IndexNowFoundationCollector implements SeoIntelCollector
{
    public function __construct(
        private readonly IndexNowPayloadValidator $validator,
        private readonly SearchChannelSubmissionStatusNormalizer $statusNormalizer,
    ) {}

    public function name(): string
    {
        return 'indexnow_foundation';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $results = array_map(fn (array $candidate): array => $this->validator->validate($candidate), $this->fixtureCandidates());
        $eligible = array_values(array_filter($results, static fn (array $result): bool => (bool) $result['eligible']));
        $issues = $this->issues($results);

        return new SeoIntelCollectorResult(
            collector: $this->name(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: count($results),
            issues: [
                'fixture_only_no_live_indexnow_api_call',
                'real_url_submission_blocked',
                ...$issues,
            ],
            metadata: [
                'urls_seen' => count($results),
                'urls_validated' => count($eligible),
                'submissions_attempted' => false,
                'source_engine' => 'bing_indexnow',
                'indexnow_enabled' => (bool) config('seo_intel.indexnow_enabled', false),
                'indexnow_live_api_enabled' => (bool) config('seo_intel.indexnow_live_api_enabled', false),
                'credentials_required' => false,
                'external_api_calls_allowed' => false,
                'external_calls_attempted' => false,
                'real_url_submission_allowed' => false,
                'draft_url_submission_allowed' => false,
                'scheduler_enabled' => false,
                'queue_worker_enabled' => false,
                'search_channel_adapter_only' => true,
                'seo_truth_source' => false,
                'search_channel_purchase_attribution_allowed' => false,
                'purchase_truth_source' => 'backend_orders_payment_benefits',
                'node2_local_laravel_data_source' => false,
                'sample_url_hashes' => array_map(
                    static fn (array $result): ?string => $result['normalized']['canonical_url_hash'] ?? null,
                    $eligible,
                ),
                'dry_run_submission_status' => $this->statusNormalizer->normalize('dry_run'),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureCandidates(): array
    {
        return [
            [
                'canonical_url' => 'https://example.invalid/zh/tests/mbti-personality-test-16-personality-types',
                'indexability_state' => 'indexable',
            ],
            [
                'canonical_url' => 'https://example.invalid/en/career/jobs/software-engineer',
                'indexability_state' => 'indexable',
            ],
            [
                'canonical_url' => 'https://example.invalid/zh/result/private',
                'indexability_state' => 'indexable',
                'is_private_flow' => true,
            ],
        ];
    }

    /**
     * @param  list<array{eligible: bool, issues: list<string>, normalized: array<string, mixed>}>  $results
     * @return list<string>
     */
    private function issues(array $results): array
    {
        $issues = [];

        foreach ($results as $result) {
            foreach ($result['issues'] as $issue) {
                $issues[] = $issue;
            }
        }

        return array_values(array_unique($issues));
    }
}
