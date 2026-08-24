<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Detector\SearchContentLinkDetectorEvaluator;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform04SearchContentLinkDetectorTest extends TestCase
{
    #[Test]
    public function all_search_content_and_link_detectors_are_registered_and_supported(): void
    {
        $registry = (new SeoDetectorRegistry)->detectors();

        foreach (SearchContentLinkDetectorEvaluator::SUPPORTED_DETECTORS as $detectorId) {
            $this->assertArrayHasKey($detectorId, $registry);
        }

        $this->assertSame('issue', $registry['query_page_owner_conflict']['output_type']);
        $this->assertSame('opportunity', $registry['keyword_cannibalization']['output_type']);
        $this->assertSame('opportunity', $registry['high_impressions_low_ctr']['output_type']);
        $this->assertSame('opportunity', $registry['position_4_15_opportunity']['output_type']);
        $this->assertSame('opportunity', $registry['content_decay_candidate']['output_type']);
        $this->assertSame('issue', $registry['review_overdue']['output_type']);
        $this->assertSame('issue', $registry['orphan_page']['output_type']);
        $this->assertSame('opportunity', $registry['insufficient_internal_links']['output_type']);
        $this->assertSame('issue', $registry['gsc_funnel_freshness']['output_type']);
        $this->assertSame('issue', $registry['gsc_canonical_unmapped_url_truth']['output_type']);
    }

    #[Test]
    public function owner_conflict_and_growth_signals_materialize_to_the_correct_queue(): void
    {
        $queryHash = hash('sha256', 'private-query-never-output');
        $cases = [
            ['query_page_owner_conflict', ['query_hash' => $queryHash, 'current_owner_count' => 2], 'issue'],
            ['keyword_cannibalization', ['query_hash' => $queryHash, 'query_segment' => 'branded', 'gsc_quality_gate_pass' => true, 'current_public_canonical_count' => 2], 'opportunity'],
            ['high_impressions_low_ctr', ['query_segment' => 'non_branded', 'gsc_quality_gate_pass' => true, 'complete_window' => true, 'impressions' => 100, 'ctr' => 0.01, 'policy_impression_threshold' => 50, 'policy_ctr_threshold' => 0.02], 'opportunity'],
            ['position_4_15_opportunity', ['query_segment' => 'branded', 'gsc_quality_gate_pass' => true, 'complete_window' => true, 'average_position' => 8.5], 'opportunity'],
        ];

        foreach ($cases as [$detectorId, $facts, $expected]) {
            $result = $this->evaluate($detectorId, $facts);
            $this->assertSame($expected, $result['outcome'], $detectorId);
            $this->assertSame($expected, $result['queue'], $detectorId);
            $this->assertSame('direct_evidence', $result['evidence_state'], $detectorId);
        }
    }

    #[Test]
    public function gsc_quality_failure_holds_metrics_for_both_query_segments_without_fake_zero(): void
    {
        foreach (['branded', 'non_branded'] as $segment) {
            foreach (['keyword_cannibalization', 'high_impressions_low_ctr', 'position_4_15_opportunity', 'content_decay_candidate'] as $detectorId) {
                $result = $this->evaluate($detectorId, [
                    'query_segment' => $segment,
                    'gsc_quality_gate_pass' => false,
                ]);

                $this->assertSame('measurement_hold', $result['outcome'], "{$detectorId}:{$segment}");
                $this->assertSame('measurement_hold', $result['queue']);
                $this->assertSame('gsc_quality_gate_failed', $result['root_cause_or_error_code']);
                $this->assertNull($result['severity']);
                $this->assertSame($segment, $result['query_segment']);
            }
        }
    }

    #[Test]
    public function content_decay_requires_the_shared_lifecycle_definition_and_only_creates_a_candidate(): void
    {
        $facts = [
            'query_segment' => 'non_branded',
            'gsc_quality_gate_pass' => true,
            'complete_windows' => true,
            'window_days' => 28,
            'comparison_window_days' => 28,
            'consecutive_weekly_detection_count' => 2,
            'baseline_impressions' => 100,
            'policy_baseline_impression_threshold' => 50,
            'recent_28_day_impressions' => 60,
            'previous_28_day_impressions' => 100,
            'inside_new_or_major_edit_protection' => false,
            'incident_excluded' => true,
            'seasonality_excluded' => true,
        ];

        $candidate = $this->evaluate('content_decay_candidate', $facts);
        $protected = $this->evaluate('content_decay_candidate', array_replace($facts, [
            'inside_new_or_major_edit_protection' => true,
        ]));

        $this->assertSame('opportunity', $candidate['outcome']);
        $this->assertSame('opportunity', $candidate['queue']);
        $this->assertStringContainsString('no_content_rewrite_or_indexability_change', $candidate['automation_cap']);
        $this->assertSame('pass', $protected['outcome']);
        $this->assertSame('decay_candidate_excluded', $protected['root_cause_or_error_code']);
    }

    #[Test]
    public function missing_review_source_holds_and_complete_review_authority_can_prove_overdue(): void
    {
        $missing = $this->evaluate('review_overdue', ['source_state' => 'unavailable']);
        $overdue = $this->evaluate('review_overdue', [
            'days_since_review' => 91,
            'family_review_cycle_days' => 90,
        ]);

        $this->assertSame('measurement_hold', $missing['outcome']);
        $this->assertSame('source_unavailable', $missing['evidence_state']);
        $this->assertSame('issue', $overdue['outcome']);
        $this->assertSame('P2', $overdue['severity']);
    }

    #[Test]
    public function link_detectors_require_a_complete_graph_and_family_policy_threshold(): void
    {
        $orphan = $this->evaluate('orphan_page', [
            'complete_graph_snapshot' => true,
            'eligible_inbound_link_count' => 0,
        ]);
        $insufficient = $this->evaluate('insufficient_internal_links', [
            'complete_graph_snapshot' => true,
            'internal_link_threshold_source' => 'page_family_policy',
            'eligible_inbound_link_count' => 2,
            'family_minimum_internal_links' => 3,
        ]);
        $globalThreshold = $this->evaluate('insufficient_internal_links', [
            'complete_graph_snapshot' => true,
            'internal_link_threshold_source' => 'global_hardcoded',
            'eligible_inbound_link_count' => 2,
            'family_minimum_internal_links' => 3,
        ]);

        $this->assertSame('issue', $orphan['outcome']);
        $this->assertSame('opportunity', $insufficient['outcome']);
        $this->assertSame('measurement_hold', $globalThreshold['outcome']);
        $this->assertSame('detector_evidence_fields_incomplete', $globalThreshold['root_cause_or_error_code']);
    }

    #[Test]
    public function freshness_and_unmapped_canonical_are_issues_only_with_complete_direct_evidence(): void
    {
        $freshness = $this->evaluate('gsc_funnel_freshness', [
            'gsc_freshness_threshold_exceeded' => false,
            'funnel_freshness_threshold_exceeded' => true,
        ]);
        $mapping = $this->evaluate('gsc_canonical_unmapped_url_truth', [
            'normalized_gsc_canonical_hash' => hash('sha256', 'https://example.test/canonical'),
            'mapping_outcome' => 'failed',
            'mapping_root_cause' => 'current_public_authority_missing',
            'root_cause_or_error_code' => 'current_public_authority_missing',
        ]);
        $incomplete = $this->evaluate('gsc_funnel_freshness', [
            'gsc_freshness_threshold_exceeded' => false,
        ]);

        $this->assertSame('issue', $freshness['outcome']);
        $this->assertSame('issue', $mapping['outcome']);
        $this->assertSame('measurement_hold', $incomplete['outcome']);
    }

    #[Test]
    public function output_is_clustered_deduplicated_and_privacy_safe(): void
    {
        $facts = [
            'query_hash' => hash('sha256', 'sensitive query'),
            'current_owner_count' => 2,
            'root_cause_or_error_code' => 'shared_owner_conflict',
            'affected_url_count' => 40,
            'raw_query' => 'must never escape',
            'private_url' => 'https://example.test/results/private',
            'session' => 'private-session',
        ];
        $first = $this->evaluate('query_page_owner_conflict', $facts);
        $second = $this->evaluate('query_page_owner_conflict', $facts + [
            'canonical_url_hash' => hash('sha256', 'second-canonical'),
        ]);
        $newRevision = $this->evaluate('query_page_owner_conflict', $facts + [
            'authority_revision' => 'authority-r2',
        ]);

        $this->assertSame($first['cluster_uid'], $second['cluster_uid']);
        $this->assertNotSame($first['dedupe_key'], $second['dedupe_key']);
        $this->assertNotSame($first['cluster_uid'], $newRevision['cluster_uid']);
        $this->assertSame(40, $first['affected_url_count']);
        $this->assertSame(SeoDetectorRegistry::CLUSTER_KEY, $first['cluster_key_fields']);
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must never escape', $encoded);
        $this->assertStringNotContainsString('/results/private', $encoded);
        $this->assertStringNotContainsString('private-session', $encoded);
        $this->assertFalse(data_get($first, 'privacy.raw_query_stored'));
        $this->assertFalse(data_get($first, 'privacy.session_or_business_identifiers_stored'));
    }

    #[Test]
    public function indirect_high_impact_claim_holds_and_unsupported_detector_fails_closed(): void
    {
        $held = $this->evaluate('review_overdue', [
            'direct_evidence' => false,
            'verified_impact' => 'high',
            'days_since_review' => 100,
            'family_review_cycle_days' => 90,
        ]);

        $this->assertSame('measurement_hold', $held['outcome']);
        $this->assertNull($held['severity']);

        $this->expectException(InvalidArgumentException::class);
        (new SearchContentLinkDetectorEvaluator)->evaluate('unknown_detector', []);
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private function evaluate(string $detectorId, array $facts): array
    {
        return (new SearchContentLinkDetectorEvaluator)->evaluate($detectorId, $facts + [
            'source_state' => 'available',
            'evidence_complete' => true,
            'direct_evidence' => true,
            'page_family' => 'articles_topics',
            'locale' => 'en',
            'indexability_state' => 'indexable',
            'canonical_url_hash' => hash('sha256', 'canonical'),
            'authority_revision' => 'authority-r1',
            'url_truth_revision' => 'url-truth-r1',
            'policy_version' => 'seo-page-family-policy.v1',
            'affected_url_count' => 1,
        ]);
    }
}
