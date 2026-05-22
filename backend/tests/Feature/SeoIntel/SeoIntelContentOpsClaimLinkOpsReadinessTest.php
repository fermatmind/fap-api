<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelContentOpsClaimLinkOpsReadinessTest extends TestCase
{
    #[Test]
    public function readiness_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/content-ops-claim-link-ops-readiness.md'));

        $artifact = $this->artifact();

        $this->assertSame('content-ops-claim-link-ops-readiness.v1', $artifact['version'] ?? null);
        $this->assertSame('CONTENT-OPS-CLAIM-LINK-OPS-READINESS', $artifact['task'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('CONTENT-OPS-CLAIM-LINK-CLOSEOUT', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function future_sections_cover_content_link_claim_and_sidecars(): void
    {
        $sections = $this->artifact()['future_sections'] ?? [];

        foreach ([
            'content_publish_rehearsal_summary',
            'planned_observation_event_counts',
            'draft_blocked_from_sitemap_llms_search_counters',
            'internal_link_graph_coverage',
            'missing_entity_key_count',
            'legacy_unpaired_count',
            'unsafe_fallback_link_source_count',
            'claim_lint_safe_needs_review_blocked_counts',
            'claim_issue_severity_p0_p1_p2_p3_summary',
            'content_ops_sidecar_warnings',
        ] as $section) {
            $this->assertContains($section, $sections);
        }
    }

    #[Test]
    public function allowed_inputs_are_read_only_summaries(): void
    {
        $inputs = $this->artifact()['allowed_read_only_inputs'] ?? [];

        foreach ([
            'content_publish_rehearsal_dry_run_output',
            'internal_link_graph_dry_run_output',
            'chinese_claim_linter_fixture_or_candidate_package_output',
            'observation_governance_planned_event_summary',
            'entity_key_translation_group_coverage_summary',
        ] as $input) {
            $this->assertContains($input, $inputs);
        }
    }

    #[Test]
    public function hard_stops_block_write_actions_raw_data_and_metabase(): void
    {
        $stops = $this->artifact()['hard_stops'] ?? [];

        foreach ([
            'no_publish_button',
            'no_rewrite_button',
            'no_internal_link_creation_button',
            'no_search_channel_enqueue_button',
            'no_submit_url_button',
            'no_scheduler_controls',
            'no_collector_controls',
            'no_raw_sql',
            'no_metabase_iframe_or_proxy',
            'no_raw_payload_display',
            'no_raw_crawler_logs',
            'no_cms_write_controls',
        ] as $stop) {
            $this->assertContains($stop, $stops);
        }
    }

    #[Test]
    public function authority_boundary_and_safety_flags_keep_ops_seo_read_only(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('content_metadata_publish_canonical_robots_claim_truth', $artifact['authority_boundary']['cms_backend'] ?? null);
        $this->assertSame('backend_cms_authoritative', $artifact['authority_boundary']['internal_link_graph'] ?? null);
        $this->assertSame('deterministic_renderer_not_fallback_authority', $artifact['authority_boundary']['fap_web'] ?? null);
        $this->assertSame('operational_view_only', $artifact['authority_boundary']['ops_seo'] ?? null);
        $this->assertSame('private_not_exposed', $artifact['authority_boundary']['metabase'] ?? null);

        foreach ([
            'filament_ui_implemented',
            'action_buttons_added',
            'write_controls_added',
            'cms_mutation_enabled',
            'internal_link_creation_enabled',
            'auto_rewrite_enabled',
            'search_channel_enqueue_enabled',
            'url_submission_enabled',
            'metabase_exposed',
            'migration_added',
            'scheduler_enabled',
            'collector_enabled',
            'fap_web_modified',
            'deployment_performed',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact['safety_flags'][$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_display_readiness_without_ui_implementation(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/content-ops-claim-link-ops-readiness.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/content-ops-claim-link-ops-readiness.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'docs/generated/test only',
            'does not implement filament ui',
            'content publish rehearsal summary',
            'internal link graph coverage',
            'claim lint `safe` / `needs_review` / `blocked` counts',
            'p0 / p1 / p2 / p3 claim issue summary',
            'no publish button',
            'no rewrite button',
            'no internal link creation button',
            'no search channel enqueue button',
            'no metabase iframe or proxy',
            'operational view only',
            'next task: `content-ops-claim-link-closeout`',
            '"next_task": "content-ops-claim-link-closeout"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('docs/seo/generated/content-ops-claim-link-ops-readiness.v1.json')), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
