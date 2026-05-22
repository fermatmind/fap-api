<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSeoOpsDailyRunbookTest extends TestCase
{
    #[Test]
    public function daily_runbook_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-ops-daily-runbook.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-ops-daily-runbook.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01B', $artifact['task'] ?? null);
        $this->assertSame('SEO-OPS-SOP-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01C', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function daily_checklist_covers_required_operator_surfaces(): void
    {
        $checks = $this->artifact()['daily_checklist'] ?? [];

        foreach ([
            'open_ops_seo',
            'review_overview_heartbeat',
            'review_safety_heartbeat',
            'check_url_truth_counts_and_forbidden_authority_counters',
            'check_issue_queue_p0_p1_only',
            'check_search_channel_queue_approval_execution_states',
            'confirm_live_search_gates_closed_unless_explicitly_approved',
            'check_crawler_aggregate_safety_counters_only',
            'check_claim_lint_blocked_needs_review_counts',
            'check_internal_link_missing_entity_key',
            'check_internal_link_legacy_unpaired',
            'check_internal_link_unsafe_fallback_sources',
            'check_content_publish_rehearsal_blockers',
            'check_digital_pr_hrzone_response_status',
            'check_mbti_growth_loop_status_if_active',
            'confirm_no_uncontrolled_scheduler_collector_write_or_search_submission',
        ] as $check) {
            $this->assertContains($check, $checks);
        }
    }

    #[Test]
    public function p0_escalation_rules_lock_same_day_human_review_cases(): void
    {
        $rules = $this->artifact()['p0_escalation_rules'] ?? [];

        foreach ([
            'claim_unsafe_public_indexable_page',
            'private_flow_leak_into_public_or_search_surface',
            'non_canonical_private_draft_noindex_or_claim_unsafe_url_in_search_channel',
            'scheduler_unexpectedly_enabled',
            'metabase_public_exposure',
            'raw_crawler_log_persistence',
            'frontend_fallback_becomes_canonical_authority',
            'business_db_data_leaks_into_seo_intel',
            'search_channel_live_gate_left_open_after_canary',
        ] as $rule) {
            $this->assertContains($rule, $rules);
        }
    }

    #[Test]
    public function daily_forbidden_actions_prevent_mutation_and_truth_drift(): void
    {
        $forbidden = $this->artifact()['daily_forbidden_actions'] ?? [];

        foreach ([
            'cms_content_change_from_observation_dashboard',
            'url_submission_from_daily_checklist',
            'digital_pr_follow_up_without_exact_approval',
            'raw_crawler_log_read',
            'crawler_search_referral_backlink_or_mention_as_truth',
            'metabase_exposure',
            'scheduler_activation',
            'collector_write',
            'claim_auto_fix',
            'internal_link_auto_creation',
            'pseo_creation',
        ] as $action) {
            $this->assertContains($action, $forbidden);
        }
    }

    #[Test]
    public function safety_flags_confirm_daily_runbook_is_non_mutating(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_daily_sop_boundaries(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-ops-daily-runbook.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-ops-daily-runbook.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'open `/ops/seo`',
            'issue queue p0/p1',
            'live search gates are closed',
            'crawler aggregate safety counters only',
            'claim lint `blocked` and `needs_review`',
            'missing entity key',
            'legacy_unpaired',
            'unsafe fallback sources',
            'digital pr response status for hrzone',
            'no uncontrolled scheduler',
            'do not read raw crawler logs',
            'do not treat crawler, search, referral, backlink, or mention data as truth',
            'next task after this pr: `seo-ops-sop-01c`',
            '"next_task": "seo-ops-sop-01c"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-ops-daily-runbook.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
