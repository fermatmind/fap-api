<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelSeoOpsApprovalGatesNoGoProtocolsTest extends TestCase
{
    #[Test]
    public function approval_gate_contract_exists_and_parses(): void
    {
        $this->assertFileExists(base_path('docs/seo/seo-ops-approval-gates-no-go-protocols.md'));

        $artifact = $this->artifact();

        $this->assertSame('seo-ops-approval-gates-no-go-protocols.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01D', $artifact['task'] ?? null);
        $this->assertSame('SEO-OPS-SOP-PR-TRAIN-01', $artifact['train'] ?? null);
        $this->assertSame('docs_generated_test_only', $artifact['type'] ?? null);
        $this->assertSame('SEO-OPS-SOP-01E', $artifact['next_task'] ?? null);
    }

    #[Test]
    public function human_approval_required_actions_are_locked(): void
    {
        $actions = $this->artifact()['human_approval_required_for'] ?? [];

        foreach ([
            'cms_publish',
            'cms_content_mutation',
            'search_channel_enqueue',
            'search_channel_live_submission',
            'gsc_baidu_indexnow_bing_360_sogou_shenma_calls',
            'crawler_log_production_canary',
            'scheduler_activation',
            'production_migration',
            'backend_deploy',
            'public_metabase_exposure',
            'digital_pr_send_or_follow_up',
            'claim_override',
            'internal_link_mutation',
            'pseo_generation',
            'bulk_content_generation',
            'production_env_edit',
        ] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    #[Test]
    public function no_go_rules_block_truth_drift_and_automation(): void
    {
        $rules = $this->artifact()['no_go_rules'] ?? [];

        foreach ([
            'no_draft_private_noindex_or_claim_unsafe_url_submission',
            'no_public_metabase_exposure',
            'no_scheduler_without_approval',
            'no_raw_production_crawler_log_read_without_exact_approval',
            'no_crawler_log_as_url_truth',
            'no_frontend_fallback_static_sitemap_static_llms_search_response_local_copy_or_digital_pr_mention_as_url_truth',
            'no_auto_publish',
            'no_auto_fix_cms_content',
            'no_auto_rewrite_claims',
            'no_auto_create_internal_links',
            'no_bulk_digital_pr_outreach',
            'no_paid_backlinks',
            'no_riasec_big_five_career_graph_precise_recommender_claim',
            'no_search_channel_retry_without_gate',
        ] as $rule) {
            $this->assertContains($rule, $rules);
        }
    }

    #[Test]
    public function p0_triggers_cover_claim_private_search_metabase_scheduler_and_authority_failures(): void
    {
        $triggers = $this->artifact()['p0_triggers'] ?? [];

        foreach ([
            'claim_unsafe_public_indexable_page',
            'private_flow_leak_into_public_or_search_surface',
            'submitted_private_noindex_non_canonical_draft_or_claim_unsafe_url',
            'metabase_public_exposure',
            'scheduler_unexpectedly_enabled',
            'raw_crawler_logs_persisted',
            'frontend_fallback_becomes_canonical_authority',
            'business_db_leak_into_seo_intel',
            'search_channel_live_gate_left_open_after_canary',
        ] as $trigger) {
            $this->assertContains($trigger, $triggers);
        }
    }

    #[Test]
    public function exact_approval_phrase_templates_are_present(): void
    {
        $templates = $this->artifact()['approval_phrase_templates'] ?? [];

        foreach ([
            'search_channel_live_submission',
            'crawler_log_production_canary',
            'backend_deploy',
            'digital_pr_send',
            'cms_publish',
            'production_migration',
            'scheduler_activation',
        ] as $key) {
            $this->assertArrayHasKey($key, $templates);
            $this->assertStringContainsString('I approve', $templates[$key]);
        }
    }

    #[Test]
    public function safety_flags_confirm_no_gate_implementation_or_production_action(): void
    {
        foreach ($this->artifact()['safety_flags'] ?? [] as $flag => $value) {
            $this->assertFalse((bool) $value, $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_approval_and_no_go_boundaries(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-ops-approval-gates-no-go-protocols.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-ops-approval-gates-no-go-protocols.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'cms publish',
            'search channel live submission',
            'gsc, baidu, indexnow, bing, 360, sogou, or shenma calls',
            'crawler log production canary',
            'public metabase exposure',
            'digital pr send or follow-up',
            'claim override',
            'internal link mutation',
            'submit draft, private, noindex, or claim-unsafe urls',
            'treat crawler log as url truth',
            'auto-rewrite claims',
            'buy backlinks',
            'approval must be exact, scoped, current, and human-provided',
            'next task after this pr: `seo-ops-sop-01e`',
            '"next_task": "seo-ops-sop-01e"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-ops-approval-gates-no-go-protocols.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
