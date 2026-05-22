<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti00BaselineSnapshotTelemetryContractTest extends TestCase
{
    #[Test]
    public function baseline_snapshot_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-00-baseline-snapshot-telemetry-contract.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-00-baseline-snapshot-telemetry-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-00', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-01', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function growth_loop_and_baseline_scope_are_locked(): void
    {
        $artifact = $this->artifact();

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
        ], $artifact['growth_loop'] ?? []);

        foreach ([
            'mbti_test_page',
            'mbti_research_report',
            'mbti_topic_hub',
            'mbti_personality_type_pages',
            'mbti_articles',
            'mbti_take_result_report_paywall_private_flows',
            'digital_pr_hrzone_hrec_state',
            'search_channel_readiness_state',
            'internal_link_readiness_state',
            'claim_lint_readiness_state',
            'funnel_revenue_telemetry_readiness_state',
        ] as $scope) {
            $this->assertContains($scope, $artifact['baseline_scope'] ?? []);
        }
    }

    #[Test]
    public function candidate_urls_deferred_surfaces_and_private_exclusions_are_locked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            '/en/tests/mbti-personality-test-16-personality-types',
            '/zh/tests/mbti-personality-test-16-personality-types',
            '/en/research/mbti-personality-types-salary-turnover-report',
            '/zh/research/mbti-personality-types-salary-turnover-report',
        ] as $url) {
            $this->assertContains($url, $artifact['current_candidate_urls'] ?? []);
        }

        foreach ([
            'mbti_topic_hub_until_backend_cms_topic_authority_explicit',
            'mbti_personality_type_pages_until_backend_personality_cms_authority_explicit',
            'mbti_articles_until_backend_cms_article_rows_verified',
        ] as $surface) {
            $this->assertContains($surface, $artifact['deferred_surfaces'] ?? []);
        }

        foreach (['take', 'result', 'report', 'paywall', 'order', 'pdf', 'history'] as $privateFlow) {
            $this->assertContains($privateFlow, $artifact['private_noindex_excluded'] ?? []);
        }
    }

    #[Test]
    public function telemetry_split_preserves_observation_truth_bot_and_pii_boundaries(): void
    {
        $telemetry = $this->artifact()['telemetry_contract'] ?? [];

        foreach ([
            'landing_view',
            'test_cta_click',
            'test_start_click',
            'report_preview_view',
            'unlock_click',
            'checkout_button_click',
            'email_form_view',
        ] as $event) {
            $this->assertContains($event, $telemetry['frontend_observation_events'] ?? []);
        }

        foreach ([
            'attempt_created',
            'attempt_submitted',
            'result_generated',
            'email_captured',
            'order_created',
            'payment_success',
            'benefit_granted',
            'report_access_granted',
            'pdf_generated',
        ] as $event) {
            $this->assertContains($event, $telemetry['backend_truth_events'] ?? []);
        }

        foreach ([
            'frontend_observation_not_backend_truth',
            'backend_payment_order_report_access_is_truth',
            'bot_crawler_excluded_from_conversion_formulas',
            'crawler_only_enters_crawler_aggregate_observation',
            'email_not_in_public_html_search_analytics_payloads_urls_or_digital_pr_artifacts',
        ] as $rule) {
            $this->assertContains($rule, $telemetry['rules'] ?? []);
        }
    }

    #[Test]
    public function observation_inputs_are_allowed_without_becoming_truth_sources(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'backend_authoritative_url_truth_candidates',
            'claim_lint_states',
            'search_channel_dry_run_eligibility_states',
            'issue_queue_observations',
            'crawler_aggregate_observations',
            'ops_seo_read_only_views',
            'digital_pr_manual_tracking_state',
            'human_only_funnel_telemetry_contracts',
        ] as $input) {
            $this->assertContains($input, $artifact['baseline_observation_inputs'] ?? []);
        }

        foreach ([
            'frontend_fallback',
            'static_sitemap',
            'static_llms',
            'crawler_logs',
            'search_engine_responses',
            'digital_pr_mentions',
            'local_copies',
            'ga4_gsc_referral_signals',
        ] as $source) {
            $this->assertContains($source, $artifact['not_truth_sources'] ?? []);
        }
    }

    #[Test]
    public function measurable_now_and_not_yet_measurable_items_are_explicit(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'contract_completeness',
            'candidate_url_families',
            'private_noindex_exclusion_boundary',
            'telemetry_event_taxonomy',
            'claim_gate_requirements',
            'search_channel_preconditions',
            'internal_link_dry_run_output_shape',
            'digital_pr_manual_tracking_fields',
        ] as $item) {
            $this->assertContains($item, $artifact['measurable_now'] ?? []);
        }

        foreach ([
            'complete_backend_authoritative_url_truth_rows',
            'verified_production_cms_topic_personality_article_rows',
            'live_search_channel_outcomes',
            'live_digital_pr_response_outcomes',
            'complete_human_only_revenue_conversion_formulas',
            'production_claim_lint_pass_fail_across_all_mbti_surfaces',
        ] as $item) {
            $this->assertContains($item, $artifact['not_measurable_yet'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_keep_this_contract_non_mutating(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_authority_boundaries_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-00-baseline-snapshot-telemetry-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-00-baseline-snapshot-telemetry-contract.v1.json')));
        $combined = $doc.'
'.$artifactJson;

        foreach ([
            'frontend fallback',
            'static sitemap',
            'static llms',
            'search engine responses',
            'digital pr mentions',
            'frontend observation != backend truth',
            'backend payment/order/report access is truth',
            'bot/crawler traffic is excluded from conversion formulas',
            'email must not enter public html',
            'seo-growth-mbti-01',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-00-baseline-snapshot-telemetry-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
