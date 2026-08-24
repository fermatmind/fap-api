<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\Detector\TechnicalAuthorityDetectorEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform04TechnicalAuthorityDetectorTest extends TestCase
{
    #[Test]
    public function all_technical_and_authority_detectors_are_registered_and_supported(): void
    {
        $registry = (new SeoDetectorRegistry)->detectors();

        foreach (TechnicalAuthorityDetectorEvaluator::SUPPORTED_DETECTORS as $detectorId) {
            $this->assertArrayHasKey($detectorId, $registry);
            $this->assertSame('issue', $registry[$detectorId]['output_type']);
        }
    }

    #[Test]
    public function status_redirect_indexability_and_authority_rules_return_direct_issues(): void
    {
        $hashA = hash('sha256', 'a');
        $hashB = hash('sha256', 'b');
        $cases = [
            ['http_404', ['observed_status' => 404]],
            ['http_5xx', ['observed_status' => 503, 'consecutive_observation_count' => 2]],
            ['redirect_chain', ['redirect_hop_count' => 2]],
            ['redirect_loop', ['redirect_loop_detected' => true]],
            ['redirect_wrong_target', ['observed_terminal_url_hash' => $hashA, 'expected_canonical_url_hash' => $hashB]],
            ['false_noindex', ['authority_indexable' => true, 'observed_noindex' => true]],
            ['canonical_authority_drift', ['observed_canonical_url_hash' => $hashA, 'authority_canonical_url_hash' => $hashB]],
            ['hreflang_locale_counterpart_drift', ['policy_requires_locale_pair' => true, 'counterpart_authority_exists' => true, 'observed_counterpart_url_hash' => $hashA, 'expected_counterpart_url_hash' => $hashB]],
            ['jsonld_visible_content_mismatch', ['field_diff_count' => 1]],
        ];

        foreach ($cases as [$detectorId, $facts]) {
            $result = $this->evaluate($detectorId, $facts);
            $this->assertSame('issue', $result['outcome'], $detectorId);
            $this->assertSame('direct_evidence', $result['evidence_state'], $detectorId);
            $this->assertSame('P2', $result['severity'], $detectorId);
        }
    }

    #[Test]
    public function expected_retirement_and_unrequired_locale_pair_pass(): void
    {
        $gone = $this->evaluate('http_410', [
            'indexability_state' => 'retired',
            'observed_status' => 410,
            'retirement_authority_matches' => true,
        ]);
        $hreflang = $this->evaluate('hreflang_locale_counterpart_drift', ['policy_requires_locale_pair' => false]);

        $this->assertSame('pass', $gone['outcome']);
        $this->assertSame('expected_retirement', $gone['root_cause_or_error_code']);
        $this->assertSame('pass', $hreflang['outcome']);
        $this->assertSame('locale_pair_not_required', $hreflang['root_cause_or_error_code']);
    }

    #[Test]
    public function collection_cms_sync_pagination_and_mapping_failures_are_detected(): void
    {
        $cases = [
            ['public_collection_split', ['same_revision_snapshots' => true, 'collection_set_diff_count' => 3]],
            ['cms_published_shell', ['authority_published' => true, 'empty_body_count' => 0, 'missing_required_module_count' => 1, 'missing_metadata_count' => 0]],
            ['data_sync_stale', ['freshness_threshold_exceeded' => true]],
            ['pagination_incomplete', ['termination_condition_valid' => false, 'rows_seen' => 20, 'rows_accounted' => 20]],
            ['pagination_incomplete', ['termination_condition_valid' => true, 'rows_seen' => 20, 'rows_accounted' => 19]],
            ['url_mapping_failure', ['mapping_outcome' => 'failed', 'root_cause_or_error_code' => 'authority_binding_missing']],
        ];

        foreach ($cases as [$detectorId, $facts]) {
            $result = $this->evaluate($detectorId, $facts);
            $this->assertSame('issue', $result['outcome'], $detectorId);
        }
    }

    #[Test]
    public function cwv_and_incomparable_collection_evidence_hold_without_fake_health(): void
    {
        $cwvDisconnected = $this->evaluate('runtime_performance_degradation', [
            'field_cwv_connected' => false,
            'bounded_runtime_observation_count' => 0,
            'performance_threshold_breached' => false,
        ]);
        $fakeLab = $this->evaluate('runtime_performance_degradation', [
            'field_cwv_connected' => false,
            'bounded_runtime_observation_count' => 10,
            'evidence_kind' => 'lighthouse_lab',
            'performance_threshold_breached' => true,
        ]);
        $collection = $this->evaluate('public_collection_split', [
            'same_revision_snapshots' => false,
            'collection_set_diff_count' => 10,
        ]);

        foreach ([$cwvDisconnected, $fakeLab, $collection] as $result) {
            $this->assertSame('measurement_hold', $result['outcome']);
            $this->assertNull($result['severity']);
        }
    }

    #[Test]
    public function missing_or_indirect_evidence_holds_and_single_timeout_stays_low_severity(): void
    {
        $sourceUnavailable = $this->evaluate('http_404', ['source_state' => 'unavailable']);
        $inferred = $this->evaluate('http_404', ['direct_evidence' => false, 'observed_status' => 404]);
        $timeout = $this->evaluate('runtime_api_timeout', ['timed_out' => true, 'consecutive_observation_count' => 1]);

        $this->assertSame('measurement_hold', $sourceUnavailable['outcome']);
        $this->assertSame('source_unavailable', $sourceUnavailable['evidence_state']);
        $this->assertSame('measurement_hold', $inferred['outcome']);
        $this->assertSame('insufficient_evidence', $inferred['evidence_state']);
        $this->assertSame('issue', $timeout['outcome']);
        $this->assertSame('P3', $timeout['severity']);
        $this->assertFalse($timeout['human_intervention_required']);
    }

    #[Test]
    public function claimed_complete_but_malformed_detector_evidence_cannot_return_false_pass(): void
    {
        $malformedCanonical = $this->evaluate('canonical_authority_drift', [
            'observed_canonical_url_hash' => 'not-a-hash',
            'authority_canonical_url_hash' => hash('sha256', 'authority'),
        ]);
        $missingCmsCounts = $this->evaluate('cms_published_shell', [
            'authority_published' => true,
            'missing_required_module_count' => 0,
        ]);

        foreach ([$malformedCanonical, $missingCmsCounts] as $result) {
            $this->assertSame('measurement_hold', $result['outcome']);
            $this->assertSame('insufficient_evidence', $result['evidence_state']);
            $this->assertSame('detector_evidence_fields_incomplete', $result['root_cause_or_error_code']);
        }
    }

    #[Test]
    public function directly_proven_private_leak_uses_verified_impact_and_never_returns_private_data(): void
    {
        $result = $this->evaluate('private_url_public_collection_leak', [
            'indexability_state' => 'private',
            'private_negative_set_match' => true,
            'direct_public_collection_membership' => true,
            'verified_impact' => 'critical',
            'affected_url_count' => 7,
            'private_url' => 'https://example.test/results/private-identity',
            'raw_response' => ['secret' => 'must-not-escape'],
        ]);

        $this->assertSame('issue', $result['outcome']);
        $this->assertSame('P0', $result['severity']);
        $this->assertTrue($result['human_intervention_required']);
        $this->assertSame(7, $result['affected_url_count']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-identity', $encoded);
        $this->assertStringNotContainsString('must-not-escape', $encoded);
        $this->assertFalse(data_get($result, 'privacy.raw_urls_stored'));
        $this->assertFalse(data_get($result, 'privacy.raw_response_stored'));
    }

    #[Test]
    public function shared_root_cause_clusters_many_urls_once_and_revisions_reopen_a_new_cluster(): void
    {
        $first = $this->evaluate('http_5xx', [
            'canonical_url_hash' => hash('sha256', 'one'),
            'observed_status' => 500,
            'root_cause_or_error_code' => 'shared_api_failure',
            'affected_url_count' => 300,
        ]);
        $second = $this->evaluate('http_5xx', [
            'canonical_url_hash' => hash('sha256', 'two'),
            'observed_status' => 500,
            'root_cause_or_error_code' => 'shared_api_failure',
            'affected_url_count' => 300,
        ]);
        $newRevision = $this->evaluate('http_5xx', [
            'authority_revision' => 'authority-r2',
            'observed_status' => 500,
            'root_cause_or_error_code' => 'shared_api_failure',
            'affected_url_count' => 300,
        ]);

        $this->assertSame($first['cluster_uid'], $second['cluster_uid']);
        $this->assertNotSame($first['dedupe_key'], $second['dedupe_key']);
        $this->assertNotSame($first['cluster_uid'], $newRevision['cluster_uid']);
        $this->assertSame(300, $first['affected_url_count']);
        $this->assertSame(SeoDetectorRegistry::CLUSTER_KEY, $first['cluster_key_fields']);
    }

    #[Test]
    public function unsupported_detector_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TechnicalAuthorityDetectorEvaluator)->evaluate('unknown_detector', []);
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function evaluate(string $detectorId, array $facts): array
    {
        return (new TechnicalAuthorityDetectorEvaluator)->evaluate($detectorId, $facts + [
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
