<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSeoOpsWeeklyMonthlyReviewRunbookTest extends TestCase
{
    #[Test]
    public function weekly_monthly_runbook_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-ops-weekly-monthly-review-runbook.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-ops-weekly-monthly-review-runbook.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01C', $artifact['task'] ?? null);
        $this->assertSame('SEO-OPS-SOP-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01D', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function weekly_review_items_cover_required_system_surfaces(): void
    {
        $items = $this->artifact()['weekly_review_items'] ?? [];

        foreach ([
            'search_channel_queue_backlog',
            'search_channel_approval_candidates',
            'crawler_aggregate_trend_no_raw_logs',
            'content_publish_rehearsal_blockers',
            'internal_link_graph_coverage',
            'chinese_claim_lint_backlog',
            'research_url_observation',
            'digital_pr_response_referral_mention_tracking',
            'mbti_cluster_url_truth_and_issue_trend',
            'approved_search_performance_feedback_if_available',
            'repair_backlog_decisions',
        ] as $item) {
            $this->assertContains($item, $items);
        }
    }

    #[Test]
    public function monthly_review_items_cover_growth_and_governance(): void
    {
        $items = $this->artifact()['monthly_review_items'] ?? [];

        foreach ([
            'entity_cluster_performance',
            'content_decay_and_repair_queue',
            'internal_link_graph_coverage_by_entity_family',
            'claim_safety_audit',
            'digital_pr_outcome_and_next_wave_decision',
            'search_channel_submission_audit',
            'crawler_aggregate_observation_review',
            'revenue_funnel_review_where_backend_truth_exists',
            'mbti_growth_loop_7_14_28_day_review',
            'next_entity_selection',
        ] as $item) {
            $this->assertContains($item, $items);
        }
    }

    #[Test]
    public function observation_only_signals_cannot_become_truth(): void
    {
        $signals = $this->artifact()['observation_only_signals'] ?? [];

        foreach ([
            'search_performance_feedback',
            'crawler_aggregate_behavior',
            'referral_traffic',
            'digital_pr_response_mention_or_backlink_observation',
            'frontend_runtime_observations',
            'static_sitemap_or_llms_output',
        ] as $signal) {
            $this->assertContains($signal, $signals);
        }
    }

    #[Test]
    public function human_approval_boundaries_are_locked(): void
    {
        $gates = $this->artifact()['human_approval_required_for'] ?? [];

        foreach ([
            'cms_publish_or_content_mutation',
            'search_channel_enqueue',
            'search_channel_live_submission',
            'crawler_log_production_canary',
            'scheduler_activation',
            'production_migration',
            'backend_deploy',
            'public_metabase_exposure',
            'digital_pr_send_or_follow_up',
            'claim_override',
            'internal_link_mutation',
            'pseo_generation',
        ] as $gate) {
            $this->assertContains($gate, $gates);
        }
    }

    #[Test]
    public function safety_flags_confirm_review_runbook_is_non_mutating(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_weekly_monthly_review_boundaries(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-ops-weekly-monthly-review-runbook.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-ops-weekly-monthly-review-runbook.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'search channel queue backlog',
            'crawler aggregate trend, with no raw logs',
            'digital pr response, referral, and mention tracking',
            'mbti cluster url truth and issue trend',
            'entity cluster performance',
            'mbti growth loop 7/14/28-day review',
            'search performance feedback',
            'static sitemap or llms output',
            'cms publish or content mutation',
            'search channel live submission',
            'public metabase exposure',
            'next task after this pr: `seo-ops-sop-01d`',
            '"next_task": "seo-ops-sop-01d"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-ops-weekly-monthly-review-runbook.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
