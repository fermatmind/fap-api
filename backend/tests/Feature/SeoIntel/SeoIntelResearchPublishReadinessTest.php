<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelResearchPublishReadinessTest extends TestCase
{
    #[Test]
    public function artifact_locks_first_candidate_as_frozen_draft(): void
    {
        $artifact = $this->artifact();
        $candidate = $artifact['candidate'] ?? [];

        $this->assertSame('research-publish-readiness.v1', $artifact['version'] ?? null);
        $this->assertSame('RESEARCH-PUBLISH-READINESS-00', $artifact['task'] ?? null);
        $this->assertSame('MBTI Salary & Turnover Report', $candidate['name'] ?? null);
        $this->assertSame('Research Candidate Frozen Draft', $candidate['status'] ?? null);
        $this->assertSame('research_report', $candidate['page_entity_type'] ?? null);
        $this->assertSame('backend_cms_research_asset', $candidate['authority'] ?? null);
        $this->assertSame('/research/{slug}', $candidate['public_route_family'] ?? null);
        $this->assertFalse((bool) ($candidate['published_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($candidate['indexable_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($candidate['queued_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($candidate['submitted_in_this_pr'] ?? true));
    }

    #[Test]
    public function publish_prerequisites_cover_backend_runtime_seo_truth_dashboard_and_claim_review(): void
    {
        $prerequisites = $this->artifact()['publish_prerequisites'] ?? [];

        foreach ([
            'research_backend_cms_mvp_merged',
            'fap_web_runtime_mvp_merged',
            'research_seo_geo_search_channel_contract_merged',
            'url_truth_observation_supports_research_report',
            'metabase_dashboard_visible_to_owner',
            'claim_linter_passed',
            'methodology_present',
            'sample_disclaimer_present',
            'references_present',
            'author_present',
            'reviewer_present',
            'last_reviewed_at_present',
        ] as $gate) {
            $this->assertContains($gate, $prerequisites);
        }
    }

    #[Test]
    public function completed_prerequisite_evidence_references_merged_research_prs(): void
    {
        $evidence = $this->artifact()['completed_prerequisite_evidence'] ?? [];

        $this->assertSame('merged', $evidence['research_backend_cms_mvp']['status'] ?? null);
        $this->assertSame('https://github.com/fermatmind/fap-api/pull/1481', $evidence['research_backend_cms_mvp']['pr_url'] ?? null);
        $this->assertSame('merged', $evidence['fap_web_runtime_mvp']['status'] ?? null);
        $this->assertSame('https://github.com/fermatmind/fap-web/pull/848', $evidence['fap_web_runtime_mvp']['pr_url'] ?? null);
        $this->assertSame('merged', $evidence['research_seo_geo_search_channel_contract']['status'] ?? null);
        $this->assertSame('https://github.com/fermatmind/fap-api/pull/1482', $evidence['research_seo_geo_search_channel_contract']['pr_url'] ?? null);
        $this->assertSame('minimally_online', $evidence['seo_dash_mvp']['status'] ?? null);
        $this->assertFalse((bool) ($evidence['seo_dash_mvp']['metabase_public_exposure'] ?? true));
    }

    #[Test]
    public function candidate_requirements_and_forbidden_fields_are_sanitized(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'executive_summary',
            'methodology',
            'sample_disclaimer',
            'claim_boundary_statement',
            'author',
            'reviewer',
            'references',
            'last_reviewed_at',
        ] as $field) {
            $this->assertContains($field, $artifact['candidate_content_requirements'] ?? []);
        }

        foreach ([
            'raw_pii',
            'raw_orders',
            'raw_payments',
            'raw_events',
            'raw_email',
            'raw_crawler_logs',
            'cookies',
            'raw_ips',
            'provider_payloads',
            'payment_payloads',
            'user_specific_private_report_evidence',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_candidate_fields'] ?? []);
        }
    }

    #[Test]
    public function claim_boundary_and_later_publication_gate_remain_strict(): void
    {
        $artifact = $this->artifact();

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
            $this->assertContains($claim, $artifact['forbidden_claim_classes'] ?? []);
        }

        foreach ([
            'cms_record_approved',
            'cms_record_published',
            'cms_record_public',
            'cms_record_indexable',
            'url_truth_records_research_url',
            'seo_geo_search_channel_gates_satisfied',
            'no_private_noindex_or_draft_exposure',
        ] as $gate) {
            $this->assertContains($gate, $artifact['later_publication_gate'] ?? []);
        }
    }

    #[Test]
    public function publish_sitemap_llms_queue_digital_pr_and_pseo_remain_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'research_publish',
            'sitemap_inclusion',
            'llms_inclusion',
            'search_channel_queue_insertion',
            'url_submission',
            'digital_pr',
            'dataset_schema_if_no_versioned_asset',
            'pseo_generation',
        ] as $blocked) {
            $this->assertContains($blocked, $artifact['blocked_until_later_task'] ?? []);
        }

        foreach ([
            'research_publish_in_this_pr',
            'research_content_imported_in_this_pr',
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
            'digital_pr_started_in_this_pr',
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
    public function docs_lock_no_publish_no_search_submission_no_digital_pr(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/research-publish-readiness.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/research-publish-readiness.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'mbti salary & turnover report',
            'research candidate frozen draft',
            'no research content published',
            'no sitemap behavior changed',
            'no `llms.txt` behavior changed',
            'no search channel queue insertion performed',
            'no url submitted to search engines',
            'no digital pr started',
            'no pseo created',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/research-publish-readiness.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
