<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelResearchAssetReadinessTest extends TestCase
{
    #[Test]
    public function artifact_recommends_research_route_and_keeps_reports_separate(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('research-asset-readiness.v1', $artifact['version'] ?? null);
        $this->assertContains('SEO-DASH-PROD-04B', $artifact['source_documents'] ?? []);
        $this->assertSame('/research', $artifact['recommended_public_route_family'] ?? null);
        $this->assertSame('/reports', $artifact['reserved_private_or_product_route_family'] ?? null);
        $this->assertSame('research_report', $artifact['proposed_page_entity_type'] ?? null);
        $this->assertSame('backend_cms_authority', $artifact['source_authority_required'] ?? null);
        $this->assertTrue((bool) ($artifact['url_truth_support_required_before_indexing'] ?? false));
        $this->assertFalse((bool) ($artifact['route_added_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($artifact['published_research_content_in_this_pr'] ?? true));
    }

    #[Test]
    public function backend_cms_and_fap_web_requirements_preserve_authority_boundaries(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'title',
            'slug',
            'locale',
            'methodology_summary',
            'evidence_grade',
            'publication_state',
            'claim_boundary_status',
            'canonical_url',
            'page_entity_type',
            'indexability_state',
            'citation_metadata',
        ] as $field) {
            $this->assertContains($field, $artifact['backend_cms_fields_required'] ?? []);
        }

        foreach ([
            'fetch_from_backend_cms_api',
            'render_empty_or_unavailable_state_when_unpublished',
            'no_local_editorial_fallback',
            'no_local_sitemap_or_llms_enumeration',
            'keep_reports_private_product_semantics_separate',
            'drafts_and_unreviewed_assets_remain_noindex',
        ] as $requirement) {
            $this->assertContains($requirement, $artifact['fap_web_runtime_requirements'] ?? []);
        }
    }

    #[Test]
    public function seo_geo_search_eligibility_requires_url_truth_and_claim_review(): void
    {
        $gates = $this->artifact()['seo_geo_search_eligibility_gates'] ?? [];

        foreach ([
            'backend_cms_source_exists',
            'published_state_explicit',
            'url_truth_supports_research_report',
            'canonical_url_present',
            'indexability_explicit_indexable',
            'claim_boundary_review_passed',
            'no_private_flow_or_user_specific_data',
            'no_raw_pii_or_raw_evidence',
            'search_channel_queue_accepts_page_type',
        ] as $gate) {
            $this->assertContains($gate, $gates);
        }
    }

    #[Test]
    public function runtime_publish_sitemap_llms_and_pseo_remain_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'research_runtime_route',
            'cms_runtime_code',
            'fap_web_route_code',
            'sitemap_behavior_change',
            'llms_behavior_change',
            'search_channel_queue_submission',
            'research_publish',
            'pseo_generation',
        ] as $blocked) {
            $this->assertContains($blocked, $artifact['blocked_until_later_pr'] ?? []);
        }

        foreach ([
            'metabase_deployed_in_this_pr',
            'production_write_execution',
            'scheduler_enabled_in_this_pr',
            'external_api_live_activation',
            'url_submission_performed',
            'production_crawler_log_read',
            'env_edit_in_this_pr',
            'sitemap_changed_in_this_pr',
            'llms_changed_in_this_pr',
            'research_publish_in_this_pr',
            'pseo_generation_in_this_pr',
            'claim_boundary_expanded_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function claim_boundary_forbids_overclaiming_and_lists_safer_wording(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'diagnosis',
            'treatment',
            'cure',
            'full_career_recommendation',
            'hiring_fit',
            'job_competency',
            'exact_iq',
            'guaranteed_career_outcome',
            'ai_career_planning_authority',
        ] as $claim) {
            $this->assertContains($claim, $artifact['forbidden_claim_classes'] ?? []);
        }

        foreach ([
            'self_assessment',
            'non_diagnostic',
            'for_reference_only',
            'online_estimate',
            'confidence_interval',
            'career_direction_reference',
            'exploration_suggestion',
            'interest_signal',
            'work_style_tendency',
            'snapshot_based_support',
        ] as $wording) {
            $this->assertContains($wording, $artifact['allowed_safer_wording'] ?? []);
        }
    }

    #[Test]
    public function docs_lock_no_runtime_no_publish_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/research-asset-readiness.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/research-asset-readiness.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'this pr does not implement a research runtime route',
            'use `/research` as the future public research asset hub',
            'reserve `/reports` for user/product report flows',
            'page_entity_type`: `research_report`',
            'must not enter sitemap',
            'must not expand fermatmind claim boundaries',
            'no fap-web runtime file is changed in this pr',
            'next task: search-channel-queue-00',
            '"next_task": "search-channel-queue-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/research-asset-readiness.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
