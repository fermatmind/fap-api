<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsSeoObservationGovernanceDisplayReadinessTest extends TestCase
{
    #[Test]
    public function display_readiness_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/ops-seo-observation-governance-display-readiness.md'));
        $this->assertSame('ops-seo-observation-governance-display-readiness.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('SEO-OBS-GOV-05', $this->artifact()['task'] ?? null);
        $this->assertSame('SEO-OBS-GOV-06', $this->artifact()['next_task'] ?? null);
    }

    #[Test]
    public function ops_seo_display_is_read_only_and_not_authority(): void
    {
        $authority = $this->artifact()['display_authority'] ?? [];

        $this->assertTrue((bool) ($authority['ops_seo_is_operational_view'] ?? false));
        $this->assertFalse((bool) ($authority['ops_seo_is_truth_source'] ?? true));
        $this->assertSame('cms_backend', $authority['content_truth_source'] ?? null);
        $this->assertFalse((bool) ($authority['metabase_exposure_allowed'] ?? true));
    }

    #[Test]
    public function required_future_sections_are_locked(): void
    {
        foreach ([
            'observation_queue_summary_by_event_type',
            'observation_queue_summary_by_event_state',
            'pending_runtime_check_count',
            'awaiting_search_observation_count',
            'awaiting_crawler_observation_count',
            'needs_review_count',
            'muted_count',
            'issue_severity_distribution_p0_p1_p2_p3',
            'sla_due_overdue_counters',
            'dedupe_cluster_counters',
            'entity_key_coverage',
            'missing_translation_group_uuid_count',
            'locale_pair_coverage',
            'digital_pr_observation_only_signal_placeholders',
            'crawler_aggregate_observation_safety_counters',
        ] as $section) {
            $this->assertContains($section, $this->artifact()['required_future_sections'] ?? []);
        }
    }

    #[Test]
    public function panel_contracts_cover_observation_issue_entity_and_safe_signals(): void
    {
        $panels = $this->artifact()['panel_contracts'] ?? [];

        foreach ([
            'event_type_distribution',
            'event_state_distribution',
            'pending_runtime_checks',
            'awaiting_search_engine_observation',
            'awaiting_crawler_observation',
        ] as $item) {
            $this->assertContains($item, $panels['observation_queue'] ?? []);
        }

        foreach ([
            'p0_p1_p2_p3_distribution',
            'sla_due_count',
            'sla_overdue_count',
            'dedupe_cluster_count',
            'muted_issue_count',
        ] as $item) {
            $this->assertContains($item, $panels['issue_governance'] ?? []);
        }

        foreach ([
            'entity_key_coverage',
            'missing_translation_group_uuid_count',
            'locale_pair_coverage',
            'legacy_unpaired_count',
        ] as $item) {
            $this->assertContains($item, $panels['entity_governance'] ?? []);
        }

        foreach ([
            'digital_pr_manual_tracking_placeholder',
            'crawler_daily_aggregate_safety_counter',
            'backlink_observed_manual_or_safe_aggregate_only',
        ] as $item) {
            $this->assertContains($item, $panels['observation_only_signals'] ?? []);
        }
    }

    #[Test]
    public function hard_stops_block_write_controls_raw_data_and_metabase(): void
    {
        foreach ([
            'no_search_submit_button',
            'no_approve_retry_controls',
            'no_scheduler_controls',
            'no_collector_controls',
            'no_raw_sql',
            'no_metabase_iframe_proxy',
            'no_raw_crawler_logs',
            'no_raw_payload_display',
            'no_cms_write_controls_from_this_page',
        ] as $stop) {
            $this->assertContains($stop, $this->artifact()['hard_stops'] ?? []);
        }
    }

    #[Test]
    public function forbidden_authority_and_safety_flags_block_drift(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'frontend_fallback_authority',
            'static_sitemap_authority',
            'static_llms_authority',
            'raw_crawler_logs',
            'raw_payloads',
            'operator_raw_sql',
            'metabase_iframe_or_proxy',
            'search_engine_response_as_url_truth',
            'local_copy_authority',
            'cms_writes_from_ops_seo',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_authority_sources'] ?? []);
        }

        foreach ([
            'filament_ui_implemented',
            'service_implemented',
            'migration_added',
            'action_buttons_added',
            'write_controls_added',
            'search_submit_button_added',
            'scheduler_controls_added',
            'collector_controls_added',
            'raw_sql_displayed',
            'metabase_exposed',
            'raw_crawler_logs_displayed',
            'raw_payload_displayed',
            'cms_write_controls_added',
            'production_env_changed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact['safety_flags'][$flag] ?? true), $flag.' must be false');
        }
    }

    #[Test]
    public function docs_lock_hard_stops_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-seo-observation-governance-display-readiness.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-seo-observation-governance-display-readiness.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'observation queue summary by event_type',
            'issue severity distribution p0/p1/p2/p3',
            'missing translation_group_uuid count',
            'digital pr observation-only signal placeholders',
            'crawler aggregate observation safety counters',
            'no search submit button',
            'no approve/retry controls',
            'no scheduler controls',
            'no collector controls',
            'no raw sql',
            'no metabase iframe/proxy',
            'no raw crawler logs',
            'no raw payload display',
            'no cms write controls from this page',
            'next task: `seo-obs-gov-06`',
            '"next_task": "seo-obs-gov-06"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-seo-observation-governance-display-readiness.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
