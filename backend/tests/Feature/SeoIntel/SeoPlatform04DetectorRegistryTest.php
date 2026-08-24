<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform04DetectorRegistryTest extends TestCase
{
    #[Test]
    public function registry_is_versioned_complete_valid_and_hash_stable(): void
    {
        $registry = new SeoDetectorRegistry;
        $registry->assertValid();

        $this->assertSame(SeoDetectorRegistry::VERSION, 'seo-detector-registry.v1');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $registry->registryHash());
        $this->assertSame($registry->registryHash(), (new SeoDetectorRegistry)->registryHash());

        foreach ($registry->detectors() as $id => $detector) {
            $this->assertSame($id, $detector['detector_id']);
            foreach (SeoDetectorRegistry::REQUIRED_FIELDS as $field) {
                $this->assertArrayHasKey($field, $detector, "{$id} missing {$field}");
            }
        }
    }

    #[Test]
    public function every_requested_technical_search_content_and_link_detector_is_registered(): void
    {
        $this->assertSame([
            'http_404',
            'http_410',
            'http_5xx',
            'redirect_chain',
            'redirect_loop',
            'redirect_wrong_target',
            'false_noindex',
            'canonical_authority_drift',
            'hreflang_locale_counterpart_drift',
            'jsonld_visible_content_mismatch',
            'public_collection_split',
            'cms_published_shell',
            'runtime_api_timeout',
            'runtime_performance_degradation',
            'private_url_public_collection_leak',
            'data_sync_stale',
            'pagination_incomplete',
            'url_mapping_failure',
            'query_page_owner_conflict',
            'keyword_cannibalization',
            'high_impressions_low_ctr',
            'position_4_15_opportunity',
            'content_decay_candidate',
            'review_overdue',
            'orphan_page',
            'insufficient_internal_links',
            'gsc_funnel_freshness',
            'gsc_canonical_unmapped_url_truth',
        ], array_keys((new SeoDetectorRegistry)->detectors()));
    }

    #[Test]
    public function outputs_clusters_and_agent_caps_are_fail_closed(): void
    {
        foreach ((new SeoDetectorRegistry)->detectors() as $detector) {
            $this->assertContains('pass', $detector['allowed_outputs']);
            $this->assertContains('measurement_hold', $detector['allowed_outputs']);
            $this->assertContains($detector['output_type'], ['issue', 'opportunity']);
            $this->assertSame(SeoDetectorRegistry::CLUSTER_KEY, $detector['root_cause_cluster_key']);
            $this->assertSame('direct_evidence', data_get($detector, 'severity_policy.p0_p1_requires'));
            $this->assertSame('P2', data_get($detector, 'severity_policy.inference_or_single_transient_max'));
            $this->assertSame('measurement_hold', data_get($detector, 'severity_policy.missing_data_outcome'));
            $this->assertNotSame('forbidden', $detector['max_agent_risk_level']);
            $this->assertStringNotContainsString('publish', (string) $detector['automation_cap']);
        }
    }

    #[Test]
    public function registry_binds_page_family_policy_revisions_and_private_negative_set(): void
    {
        $publicFamilies = PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS;

        foreach ((new SeoDetectorRegistry)->detectors() as $detector) {
            $this->assertSame($publicFamilies, data_get($detector, 'applicability.page_families'));
            $this->assertSame(['zh-CN', 'en'], data_get($detector, 'applicability.locales'));
            $this->assertSame('current', data_get($detector, 'required_revisions.url_truth_revision'));
            $this->assertSame('current', data_get($detector, 'required_revisions.authority_revision'));
            $this->assertTrue(data_get($detector, 'privacy_constraints.private_negative_set_required'));
            $this->assertFalse(data_get($detector, 'privacy_constraints.private_urls_may_be_persisted'));
            $this->assertFalse(data_get($detector, 'privacy_constraints.raw_sensitive_fields_allowed'));
        }
    }

    #[Test]
    public function special_evidence_boundaries_prevent_false_health_and_false_incidents(): void
    {
        $detectors = (new SeoDetectorRegistry)->detectors();

        $this->assertStringContainsString('explicit_retirement_authority', data_get($detectors, 'http_410.severity_policy.direct_evidence_rule'));
        $this->assertStringContainsString('single_transient_failure', data_get($detectors, 'http_5xx.severity_policy.direct_evidence_rule'));
        $this->assertSame('measurement_hold', data_get($detectors, 'runtime_performance_degradation.source_unavailable_outcome'));
        $this->assertStringContainsString('lighthouse_lab_substitution_forbidden', data_get($detectors, 'runtime_performance_degradation.measurement_hold_reason'));
        $this->assertStringContainsString('overdue_must_not_be_inferred', data_get($detectors, 'review_overdue.measurement_hold_reason'));
        $this->assertContains('private', data_get($detectors, 'private_url_public_collection_leak.applicability.indexability_states'));
        $this->assertContains('noindex', data_get($detectors, 'private_url_public_collection_leak.applicability.indexability_states'));
        $this->assertContains('two_complete_28_day_windows', data_get($detectors, 'content_decay_candidate.minimum_evidence'));
        $this->assertContains('two_consecutive_weekly_detections', data_get($detectors, 'content_decay_candidate.minimum_evidence'));
        $this->assertContains('family_specific_internal_link_policy', data_get($detectors, 'insufficient_internal_links.minimum_evidence'));
        foreach (['keyword_cannibalization', 'high_impressions_low_ctr', 'position_4_15_opportunity', 'content_decay_candidate'] as $detectorId) {
            $this->assertSame('measurement_hold', data_get($detectors, "{$detectorId}.quality_gate_failure_outcome"));
            $this->assertSame(['branded', 'non_branded'], data_get($detectors, "{$detectorId}.metric_segmentation"));
        }
    }

    #[Test]
    public function registry_contains_no_historical_counts_or_search_submission_authority(): void
    {
        $encoded = json_encode((new SeoDetectorRegistry)->detectors(), JSON_THROW_ON_ERROR);

        foreach (['2623', '77', '20', '4675'] as $historicalCount) {
            $this->assertStringNotContainsString($historicalCount, $encoded);
        }
        $this->assertStringNotContainsString('request_indexing', strtolower($encoded));
        $this->assertStringNotContainsString('search_submission_allowed', strtolower($encoded));
    }
}
