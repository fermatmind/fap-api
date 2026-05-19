<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelResearchSeoGeoSearchChannelContractTest extends TestCase
{
    #[Test]
    public function artifact_defines_research_report_authority_boundary(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('research-seo-geo-search-channel-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('PR-RESEARCH-03', $artifact['task'] ?? null);
        $this->assertSame('research_report', $artifact['page_entity_type'] ?? null);
        $this->assertSame('backend_cms', $artifact['authority_model']['research_authority'] ?? null);
        $this->assertSame('fap_web_backend_payload_only', $artifact['authority_model']['runtime_authority'] ?? null);
        $this->assertSame('seo_intel_url_truth', $artifact['authority_model']['observation_authority'] ?? null);

        foreach ([
            'frontend_fallback',
            'static_sitemap_fallback',
            'static_llms_fallback',
            'node2_local_db',
            'business_db_raw_tables',
            'production_crawler_logs',
        ] as $forbidden) {
            $this->assertContains($forbidden, $artifact['forbidden_authority_inputs'] ?? []);
        }
    }

    #[Test]
    public function sitemap_llms_and_search_channel_gates_are_explicit(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'backend_cms_source_exists',
            'page_entity_type_research_report',
            'status_published',
            'review_state_approved',
            'is_public_true',
            'is_indexable_true',
            'canonical_path_present',
            'url_truth_supports_research_report',
            'claim_boundary_review_passed',
            'no_private_flow',
            'no_raw_pii',
        ] as $gate) {
            $this->assertContains($gate, $artifact['sitemap_eligibility_gates'] ?? []);
        }

        foreach ([
            'sitemap_eligibility_passed',
            'sanitized_summary',
            'claim_boundary_preserved',
            'no_raw_evidence',
            'no_private_payload',
            'no_frontend_or_static_fallback_enumeration',
        ] as $gate) {
            $this->assertContains($gate, $artifact['llms_eligibility_gates'] ?? []);
        }

        foreach ([
            'sitemap_eligibility_passed',
            'url_truth_supports_research_report',
            'source_authority_backend_cms',
            'indexability_explicit_indexable',
            'claim_boundary_review_passed',
            'search_channel_queue_accepts_research_report',
            'later_channel_credentials_quota_owner_and_live_operation_approval',
        ] as $gate) {
            $this->assertContains($gate, $artifact['search_channel_queue_eligibility_gates'] ?? []);
        }
    }

    #[Test]
    public function url_truth_support_is_read_only_and_research_scoped(): void
    {
        $support = $this->artifact()['url_truth_support'] ?? [];

        $this->assertSame('research_report', $support['page_entity_type'] ?? null);
        $this->assertSame('backend_cms', $support['source_authority'] ?? null);

        foreach (['seo_urls', 'seo_url_entities', 'seo_issue_queue'] as $table) {
            $this->assertContains($table, $support['safe_observed_tables'] ?? []);
        }

        foreach ([
            'canonical_present',
            'locale_explicit',
            'indexability_explicit',
            'private_flow_absent',
            'forbidden_authority_absent',
        ] as $state) {
            $this->assertContains($state, $support['required_states'] ?? []);
        }
    }

    #[Test]
    public function claim_boundary_and_dataset_schema_remain_constrained(): void
    {
        $artifact = $this->artifact();
        $claimBoundary = $artifact['claim_boundary'] ?? [];
        $datasetPolicy = $artifact['dataset_schema_policy'] ?? [];

        foreach ([
            'diagnosis',
            'treatment',
            'cure',
            'hiring_fit',
            'job_competency',
            'exact_iq',
            'guaranteed_salary',
            'guaranteed_turnover_prediction',
            'guaranteed_career_outcome',
            'ai_career_planning_authority',
            'full_career_recommendation',
        ] as $claim) {
            $this->assertContains($claim, $claimBoundary['forbidden_claim_classes'] ?? []);
        }

        $this->assertFalse((bool) ($datasetPolicy['enabled_in_this_pr'] ?? true));

        foreach ([
            'versioned_downloadable_asset',
            'stable_public_file',
            'checksum_or_immutable_version',
            'license_or_usage_note',
            'dataset_ready_backend_contract',
        ] as $gate) {
            $this->assertContains($gate, $datasetPolicy['blocked_until'] ?? []);
        }
    }

    #[Test]
    public function production_publish_and_exposure_flags_remain_false(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'research_publish_in_this_pr',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
            'search_channel_queue_inserted_in_this_pr',
            'url_submission_performed',
            'external_api_live_activation',
            'scheduler_enabled_in_this_pr',
            'collector_write_executed_in_this_pr',
            'production_crawler_log_read',
            'env_edit_in_this_pr',
            'deployment_performed_in_this_pr',
            'dataset_schema_enabled_in_this_pr',
            'pseo_generation_in_this_pr',
            'frontend_fallback_authority_enabled',
            'node2_source_used',
            'metabase_operation_performed',
            'business_db_connected',
            'tencent_rds_connected',
            'node2_db_connected',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_publish_no_search_submission_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/research-seo-geo-search-channel-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/research-seo-geo-search-channel-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'page_entity_type = research_report',
            'sitemap eligibility gate',
            'llms.txt eligibility gate',
            'search channel queue eligibility gate',
            'dataset schema remains blocked',
            'no research content published',
            'no url submitted to search engines',
            'next task: `research-publish-readiness-00`',
            '"next_task": "research-publish-readiness-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/research-seo-geo-search-channel-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
