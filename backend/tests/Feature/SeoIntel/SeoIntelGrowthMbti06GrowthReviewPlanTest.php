<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti06GrowthReviewPlanTest extends TestCase
{
    #[Test]
    public function growth_review_plan_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-06-7-14-28-day-growth-review-plan.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-06-7-14-28-day-growth-review-plan.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-06', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-07', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function t_plus_one_review_inputs_are_locked(): void
    {
        foreach (['public_url_status', 'url_truth_candidate_matrix', 'claim_lint_pass_fail', 'search_channel_dry_run_eligibility', 'funnel_event_contract_completeness', 'issue_queue_p0_p1_check'] as $input) {
            $this->assertContains($input, $this->artifact()['review_windows']['T+1'] ?? []);
        }
    }

    #[Test]
    public function t_plus_seven_fourteen_and_twenty_eight_windows_are_locked(): void
    {
        $windows = $this->artifact()['review_windows'] ?? [];

        foreach (['ops_seo_deltas', 'crawler_aggregate_trends', 'digital_pr_human_send_status'] as $input) {
            $this->assertContains($input, $windows['T+7'] ?? []);
        }
        foreach (['human_only_test_starts_results_unlocks_orders', 'claim_lint_regressions', 'brand_lift_proxy_if_data_exists'] as $input) {
            $this->assertContains($input, $windows['T+14'] ?? []);
        }
        foreach (['revenue_and_report_access_deltas', 'scale_no_scale_decision', 'next_entity_candidate'] as $input) {
            $this->assertContains($input, $windows['T+28'] ?? []);
        }
    }

    #[Test]
    public function decision_outcomes_and_allowed_inputs_are_explicit(): void
    {
        foreach (['scale', 'adjust', 'pause', 'rollback', 'replicate', 'insufficient_data'] as $outcome) {
            $this->assertContains($outcome, $this->artifact()['decision_outcomes'] ?? []);
        }
        foreach (['ops_seo_read_only_view', 'url_truth', 'issue_queue', 'crawler_aggregate_observation', 'human_only_funnel_telemetry', 'claim_lint', 'internal_link_graph_dry_run'] as $input) {
            $this->assertContains($input, $this->artifact()['allowed_inputs'] ?? []);
        }
    }

    #[Test]
    public function scale_safety_checks_block_unsafe_growth(): void
    {
        foreach (['no_claim_unsafe_public_pages', 'no_private_flow_leaks', 'no_forbidden_authority_url_truth', 'no_uncontrolled_search_channel_live_gates', 'no_bulk_outreach', 'no_pseo'] as $check) {
            $this->assertContains($check, $this->artifact()['required_safety_checks_before_scale'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prevent_live_review_and_mutation(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_live_review_no_queries_no_mutation_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-06-7-14-28-day-growth-review-plan.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-06-7-14-28-day-growth-review-plan.v1.json')));

        foreach (['does not execute a live review', 'does not query production systems', 'does not mutate search channel', 'does not send digital pr', 'does not mutate cms', 'seo-growth-mbti-07'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-06-7-14-28-day-growth-review-plan.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
