<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti05HumanOnlyFunnelRevenueReviewContractTest extends TestCase
{
    #[Test]
    public function funnel_revenue_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-05-human-only-funnel-revenue-review-contract.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-05-human-only-funnel-revenue-review-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-05', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-06', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function frontend_observation_and_backend_truth_events_are_separated(): void
    {
        $artifact = $this->artifact();
        foreach (['landing_view', 'test_cta_click', 'test_start_click', 'report_preview_view', 'unlock_click', 'checkout_button_click', 'email_form_view'] as $event) {
            $this->assertContains($event, $artifact['frontend_observation_events'] ?? []);
        }
        foreach (['attempt_created', 'attempt_submitted', 'result_generated', 'email_captured', 'order_created', 'payment_success', 'benefit_granted', 'report_access_granted', 'pdf_generated'] as $event) {
            $this->assertContains($event, $artifact['backend_truth_events'] ?? []);
        }
    }

    #[Test]
    public function human_only_bot_exclusion_and_pii_rules_are_locked(): void
    {
        foreach (['conversion_formulas_use_human_only_traffic', 'known_bot_suspected_bot_and_crawler_excluded', 'internal_and_qa_traffic_excluded_where_identifiable', 'crawler_traffic_only_enters_crawler_aggregate_observation', 'email_is_pii_and_must_not_enter_public_html_search_analytics_url_or_digital_pr'] as $rule) {
            $this->assertContains($rule, $this->artifact()['rules'] ?? []);
        }
    }

    #[Test]
    public function backend_payment_and_report_access_are_truth(): void
    {
        foreach (['backend_payment_success_is_revenue_truth', 'backend_report_access_granted_is_access_truth', 'frontend_unlock_click_is_observation_only'] as $rule) {
            $this->assertContains($rule, $this->artifact()['rules'] ?? []);
        }
    }

    #[Test]
    public function funnel_metrics_dedupe_and_table_families_are_contract_only(): void
    {
        $artifact = $this->artifact();
        foreach (['human_landing_views', 'human_test_starts', 'human_payment_successes', 'human_report_access_grants'] as $metric) {
            $this->assertContains($metric, $artifact['funnel_metrics'] ?? []);
        }
        foreach (['attempt_id', 'order_id', 'email_hash', 'report_id', 'session_id', 'date_grain'] as $key) {
            $this->assertContains($key, $artifact['dedupe_concepts'] ?? []);
        }
        foreach (['attempts', 'orders', 'payments', 'benefits', 'report_access'] as $family) {
            $this->assertContains($family, $artifact['source_of_truth_table_families_contract_only'] ?? []);
        }
    }

    #[Test]
    public function safety_flags_prevent_production_queries_pii_and_runtime_work(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_no_business_db_no_pii_no_runtime_and_next_task(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-05-human-only-funnel-revenue-review-contract.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-05-human-only-funnel-revenue-review-contract.v1.json')));

        foreach (['does not query production databases', 'does not touch the business db', 'does not expose pii', 'does not implement runtime telemetry', 'seo-growth-mbti-06'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-05-human-only-funnel-revenue-review-contract.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
