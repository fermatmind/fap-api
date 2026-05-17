<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Collectors;

use App\Services\SeoIntel\DomesticIndexSampleNormalizer;
use App\Services\SeoIntel\DomesticSearchEngineAdapterContract;
use App\Services\SeoIntel\DomesticSearchSubmissionStatusNormalizer;
use App\Services\SeoIntel\DomesticSearchUrlEligibilityValidator;
use App\Services\SeoIntel\SeoIntelCollector;
use App\Services\SeoIntel\SeoIntelCollectorResult;

abstract class DomesticSearchFoundationCollector implements DomesticSearchEngineAdapterContract, SeoIntelCollector
{
    public function __construct(
        private readonly DomesticSearchUrlEligibilityValidator $validator,
        private readonly DomesticSearchSubmissionStatusNormalizer $statusNormalizer,
        private readonly DomesticIndexSampleNormalizer $indexSampleNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function collect(array $options = []): SeoIntelCollectorResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $results = array_map(
            fn (array $candidate): array => $this->validator->validate($candidate, $this->engine(), $this->sourceEngine()),
            $this->fixtureCandidates(),
        );
        $eligible = array_values(array_filter($results, static fn (array $result): bool => (bool) $result['eligible']));
        $indexSamples = array_map(
            fn (array $sample): array => $this->indexSampleNormalizer->normalize($sample + ['engine' => $this->engine()]),
            $this->fixtureIndexSamples(),
        );

        return new SeoIntelCollectorResult(
            collector: $this->collectorName(),
            status: 'success',
            dryRun: $dryRun,
            writesAttempted: false,
            writesCommitted: false,
            externalCallsAttempted: false,
            itemsSeen: count($results),
            issues: [
                'fixture_only_no_live_'.$this->engine().'_api_call',
                'real_url_submission_blocked',
                'engine_specific_page_generation_blocked',
                ...$this->issues($results),
            ],
            metadata: [
                'engine' => $this->engine(),
                'source_engine' => $this->sourceEngine(),
                'urls_seen' => count($results),
                'urls_validated' => count($eligible),
                'submissions_attempted' => false,
                'verification_status_rows_seen' => 1,
                'index_samples_seen' => count($indexSamples),
                $this->engine().'_enabled' => (bool) config('seo_intel.'.$this->engine().'_enabled', false),
                $this->engine().'_live_api_enabled' => $this->liveApiEnabled(),
                'credentials_required' => false,
                'external_api_calls_allowed' => false,
                'external_calls_attempted' => false,
                'real_url_submission_allowed' => false,
                'draft_url_submission_allowed' => false,
                'private_flow_submission_allowed' => false,
                'noindex_url_submission_allowed' => false,
                'engine_specific_page_generation_allowed' => false,
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

    public function name(): string
    {
        return $this->collectorName();
    }

    public function liveApiEnabled(): bool
    {
        return (bool) config('seo_intel.'.$this->engine().'_live_api_enabled', false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureCandidates(): array
    {
        return [
            [
                'canonical_url' => 'https://example.invalid/zh/tests/mbti-personality-test-16-personality-types',
                'locale' => 'zh-CN',
                'page_entity_type' => 'test_detail',
                'indexability_state' => 'indexable',
                'controlled_published' => true,
                'public_runtime_verified' => true,
                'claim_safe' => true,
            ],
            [
                'canonical_url' => 'https://example.invalid/zh/articles/personality-test',
                'locale' => 'zh-CN',
                'page_entity_type' => 'article',
                'indexability_state' => 'indexable',
                'controlled_published' => true,
                'public_runtime_verified' => true,
                'claim_safe' => true,
            ],
            [
                'canonical_url' => 'https://example.invalid/zh/drafts/unpublished',
                'locale' => 'zh-CN',
                'page_entity_type' => 'article',
                'indexability_state' => 'noindex',
                'is_draft' => true,
            ],
            [
                'canonical_url' => 'https://example.invalid/zh/result/private',
                'locale' => 'zh-CN',
                'page_entity_type' => 'result',
                'indexability_state' => 'indexable',
                'is_private_flow' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureIndexSamples(): array
    {
        return [
            [
                'canonical_url' => 'https://example.invalid/zh/tests/mbti-personality-test-16-personality-types',
                'locale' => 'zh-CN',
                'index_status' => 'unknown',
                'title' => 'Fixture title',
                'snippet' => 'Fixture snippet',
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
