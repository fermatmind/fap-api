<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGrowthMbti07ScaleDecisionEntityPlaybookCloseoutTest extends TestCase
{
    #[Test]
    public function closeout_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-growth-mbti-07-scale-decision-entity-playbook-closeout.md'));
        $artifact = $this->artifact();

        $this->assertSame('seo-growth-mbti-07-scale-decision-entity-playbook-closeout.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-07', $artifact['task'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_ledger_only', $artifact['type'] ?? null);
    }

    #[Test]
    public function completed_train_items_are_recorded(): void
    {
        foreach (['SEO-GROWTH-MBTI-00', 'SEO-GROWTH-MBTI-01', 'SEO-GROWTH-MBTI-02', 'SEO-GROWTH-MBTI-03A', 'SEO-GROWTH-MBTI-03B', 'SEO-GROWTH-MBTI-04', 'SEO-GROWTH-MBTI-05', 'SEO-GROWTH-MBTI-06'] as $item) {
            $this->assertContains($item, $this->artifact()['completed_train_items'] ?? []);
        }
    }

    #[Test]
    public function entity_playbook_template_covers_required_rule_families(): void
    {
        foreach (['entity_key_rules', 'url_truth_rules', 'content_internal_link_rules', 'claim_lint_rules', 'search_channel_rules', 'digital_pr_rules', 'funnel_telemetry_rules', 'bot_human_separation', 'brand_lift_proxy', 'ops_seo_review_cadence', 'repair_action_rules', 'replication_checklist'] as $rule) {
            $this->assertContains($rule, $this->artifact()['entity_playbook_template'] ?? []);
        }
    }

    #[Test]
    public function scale_criteria_and_replication_order_are_locked(): void
    {
        foreach (['mbti_review_window_produces_clear_scale_decision', 'no_claim_unsafe_public_page', 'no_private_flow_leak', 'no_forbidden_authority_url_truth', 'no_uncontrolled_search_channel_live_gate', 'no_bulk_outreach', 'no_pseo'] as $criteria) {
            $this->assertContains($criteria, $this->artifact()['scale_no_scale_criteria'] ?? []);
        }

        $this->assertSame(['MBTI', 'Big Five', 'Enneagram', 'RIASEC', 'Career Guides', 'Research Hub', 'Topic Clusters', 'Multi-language'], $this->artifact()['replication_order'] ?? []);
    }

    #[Test]
    public function hard_boundaries_prevent_overclaiming_bulk_actions_and_automation(): void
    {
        foreach (['do_not_replicate_until_mbti_review_window_produces_clear_scale_decision', 'do_not_overclaim_big_five_riasec_career_or_mbti_as_precise_recommender', 'do_not_bulk_submit_urls', 'do_not_bulk_outreach', 'do_not_generate_pseo', 'do_not_auto_publish', 'do_not_auto_link'] as $boundary) {
            $this->assertContains($boundary, $this->artifact()['hard_boundaries'] ?? []);
        }
    }

    #[Test]
    public function sidecars_not_done_and_safety_flags_are_explicit(): void
    {
        $artifact = $this->artifact();
        foreach (['translation_group_uuid_missing_globally', 'fap_web_fallback_authority_risk', 'bot_human_funnel_separation_partially_proven', 'search_channel_live_gates_must_remain_closed_except_exact_approved_live_canary'] as $sidecar) {
            $this->assertContains($sidecar, $artifact['sidecar_issues'] ?? []);
        }
        foreach (['live_growth_actions', 'content_publish', 'search_channel_enqueue', 'url_submission', 'digital_pr_send', 'cms_mutation', 'url_truth_write', 'internal_link_creation', 'fap_web_modification'] as $notDone) {
            $this->assertContains($notDone, $artifact['not_done'] ?? []);
        }
        foreach ($artifact['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function final_decision_and_next_task_are_locked(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo_growth_mbti_planning_completed_ready_for_first_human_approved_growth_action', $artifact['final_decision'] ?? null);
        $this->assertSame('SEO-GROWTH-MBTI-ACTION-01', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function docs_lock_no_runtime_or_growth_execution(): void
    {
        $combined = strtolower((string) file_get_contents(base_path('docs/seo/seo-growth-mbti-07-scale-decision-entity-playbook-closeout.md')).'
'.(string) file_get_contents(base_path('docs/seo/generated/seo-growth-mbti-07-scale-decision-entity-playbook-closeout.v1.json')));

        foreach (['does not implement runtime code', 'does not run migrations', 'does not perform production operations', 'does not mutate cms', 'does not modify fap-web', 'does not mutate search channel', 'does not send digital pr', 'seo-growth-mbti-action-01'] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /** @return array<string, mixed> */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-growth-mbti-07-scale-decision-entity-playbook-closeout.v1.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
