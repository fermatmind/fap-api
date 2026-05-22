<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSeoOpsMbtiGrowthLoopHandoffTest extends TestCase
{
    #[Test]
    public function mbti_growth_handoff_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-ops-mbti-growth-loop-handoff.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-ops-mbti-growth-loop-handoff.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01E', $artifact['task'] ?? null);
        $this->assertSame('SEO-OPS-SOP-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-00', $artifact['next_phase'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01F', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function handoff_artifacts_cover_baseline_telemetry_waves_and_scale_decision(): void
    {
        $artifacts = $this->artifact()['required_handoff_artifacts'] ?? [];

        foreach ([
            'baseline_snapshot_requirements',
            'telemetry_contract_requirements',
            'entity_map_requirements',
            'url_truth_review',
            'content_internal_link_wave_1',
            'claim_lint_gate',
            'search_channel_canary_wave',
            'digital_pr_wave',
            'human_only_funnel_review',
            '7_14_28_day_review',
            'scale_decision',
        ] as $artifact) {
            $this->assertContains($artifact, $artifacts);
        }
    }

    #[Test]
    public function core_growth_loop_path_is_locked(): void
    {
        $this->assertSame([
            'search',
            'content',
            'test',
            'result',
            'report',
            'revenue',
            'observation',
            'repair',
            'next_action',
        ], $this->artifact()['growth_loop_core_path'] ?? []);
    }

    #[Test]
    public function telemetry_constraints_preserve_backend_truth_and_observation_boundaries(): void
    {
        $constraints = $this->artifact()['telemetry_constraints'] ?? [];

        foreach ([
            'frontend_observation_events_are_not_backend_truth',
            'backend_payment_order_report_access_events_are_truth',
            'bot_and_crawler_traffic_excluded_from_product_conversion_funnel',
            'entity_key_independent_from_url_slug',
            'brand_lift_proxy_tracks_unlinked_mentions_and_branded_query_lift_when_data_exists',
            'digital_pr_mention_is_not_backlink_proof',
            'crawler_and_search_data_are_observation_only',
        ] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }
    }

    #[Test]
    public function mbti_first_experiment_scope_is_explicit(): void
    {
        $scope = $this->artifact()['mbti_first_experiment_scope'] ?? [];

        foreach ([
            'mbti_test_page',
            'mbti_topic_hub_if_available',
            'mbti_research_page',
            'sixteen_type_entity_pages_where_governed',
            'mbti_result_report_paywall_path',
            'digital_pr_hrzone_hrec_state',
            'search_channel_canary_state',
            'ops_seo_review_cadence',
        ] as $item) {
            $this->assertContains($item, $scope);
        }
    }

    #[Test]
    public function scale_guards_prevent_pseo_bulk_actions_and_recommender_overclaims(): void
    {
        $guards = $this->artifact()['scale_guards'] ?? [];

        foreach ([
            'do_not_scale_to_big_five_riasec_or_career_until_mbti_loop_reviewed',
            'do_not_overclaim_big_five_riasec_or_career_recommender_depth',
            'do_not_generate_pseo',
            'do_not_bulk_submit_urls',
            'do_not_bulk_outreach',
            'do_not_use_riasec_big_five_or_career_graph_as_precise_career_recommender_authority',
        ] as $guard) {
            $this->assertContains($guard, $guards);
        }
    }

    #[Test]
    public function safety_flags_confirm_handoff_is_non_mutating(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_mbti_handoff_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-ops-mbti-growth-loop-handoff.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-ops-mbti-growth-loop-handoff.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'seo-growth-mbti-00',
            'the first governed growth loop is mbti only',
            'baseline snapshot requirements',
            'telemetry contract requirements',
            'search -> content -> test -> result -> report -> revenue -> observation -> repair -> next action',
            'frontend observation events are not backend truth',
            'backend payment, order, and report access events are truth',
            'digital pr mention is not backlink proof',
            'mbti test page',
            'digital pr hrzone/hrec state',
            'do not generate pseo',
            'do not bulk submit urls',
            'do not bulk outreach',
            '28-day review',
            'next task after this pr: `seo-ops-sop-01f`',
            '"next_task": "seo-ops-sop-01f"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-ops-mbti-growth-loop-handoff.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
