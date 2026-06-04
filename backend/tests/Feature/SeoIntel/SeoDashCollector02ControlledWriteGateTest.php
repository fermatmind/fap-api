<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoDashCollector02ControlledWriteGateTest extends TestCase
{
    #[Test]
    public function artifact_is_docs_contract_only_and_reconciles_smoke_pr(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('seo-dash-collector-02-controlled-write-gate.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-DASH-COLLECTOR-02', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($artifact['docs_contract_only'] ?? false));
        $this->assertContains('SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE', $artifact['source_documents'] ?? []);
        $this->assertContains('SEO-DASH-PROD-03A', $artifact['source_documents'] ?? []);

        $reconciled = $artifact['reconciled_pr'] ?? [];
        $this->assertSame('SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE', $reconciled['id'] ?? null);
        $this->assertSame('https://github.com/fermatmind/fap-api/pull/1882', $reconciled['pr_url'] ?? null);
        $this->assertSame('1b88323eb05d579e847d37b0e89ed24464b1924c', $reconciled['merge_commit'] ?? null);
        $this->assertSame('merged', $reconciled['state'] ?? null);
    }

    #[Test]
    public function this_pr_does_not_enable_runtime_write_or_external_boundaries(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'runtime_changed',
            'scheduler_enabled_in_this_pr',
            'collector_write_enabled_in_this_pr',
            'production_write_executed_in_this_pr',
            'external_api_enabled_in_this_pr',
            'external_api_calls_executed_in_this_pr',
            'cms_mutation_allowed',
            'search_submission_allowed',
            'deployment_allowed',
            'production_env_edit_allowed',
            'fap_web_modified',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function first_controlled_write_candidate_is_only_url_truth_inventory(): void
    {
        $artifact = $this->artifact();
        $tiers = $artifact['write_tiers'] ?? [];

        $this->assertSame('url_truth_inventory', $artifact['first_controlled_write_candidate'] ?? null);
        $this->assertSame(['url_truth_inventory'], $tiers['tier_1_first_controlled_write_candidate'] ?? []);
        $this->assertTrue((bool) ($artifact['first_canary_requires_separate_exact_approval'] ?? false));
        $this->assertStringContainsString('--collector=url_truth_inventory', $artifact['first_canary_command_template'] ?? '');
        $this->assertStringContainsString('--canary', $artifact['first_canary_command_template'] ?? '');
        $this->assertStringNotContainsString('--dry-run', $artifact['first_canary_command_template'] ?? '');
        $this->assertStringNotContainsString('--no-write', $artifact['first_canary_command_template'] ?? '');

        foreach ([
            'gsc_foundation',
            'baidu_foundation',
            'indexnow_foundation',
            'so360_foundation',
            'sogou_foundation',
            'shenma_foundation',
        ] as $collector) {
            $this->assertContains($collector, $tiers['tier_3_blocked_until_live_external_api_approval'] ?? []);
        }
    }

    #[Test]
    public function batch_limits_and_approval_phrase_are_locked(): void
    {
        $artifact = $this->artifact();
        $limits = $artifact['batch_limits'] ?? [];

        $this->assertTrue((bool) ($limits['first_canary_requires_canary'] ?? false));
        $this->assertSame(10, $limits['default_canary_limit'] ?? null);
        $this->assertSame(50, $limits['hard_max_limit'] ?? null);
        $this->assertTrue((bool) ($limits['unbounded_write_forbidden'] ?? false));
        $this->assertTrue((bool) ($limits['all_collectors_loop_forbidden'] ?? false));
        $this->assertTrue((bool) ($limits['scheduler_trigger_forbidden'] ?? false));
        $this->assertTrue((bool) ($limits['queue_worker_trigger_forbidden'] ?? false));

        foreach ([
            'exact_backend_sha',
            'collector=url_truth_inventory',
            '--canary',
            'no_scheduler',
            'no_external_api',
            'no_cms_mutation',
            'no_search_submission',
            'no_deployment',
            'no_production_env_file_edit',
        ] as $required) {
            $this->assertContains($required, $artifact['future_approval_phrase_must_include'] ?? []);
        }
    }

    #[Test]
    public function verification_and_rollback_policy_protect_scope(): void
    {
        $artifact = $this->artifact();

        $this->assertSame(['seo_urls', 'seo_url_entities'], $artifact['target_tables_for_first_canary'] ?? []);
        $this->assertContains('business_db_tables', $artifact['forbidden_target_tables_for_first_canary'] ?? []);
        $this->assertContains('cms_tables', $artifact['forbidden_target_tables_for_first_canary'] ?? []);
        $this->assertContains('search_submission_tables', $artifact['forbidden_target_tables_for_first_canary'] ?? []);

        foreach ([
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_ip',
            'raw_user_agent',
            'token',
            'secret',
        ] as $field) {
            $this->assertContains($field, $artifact['forbidden_fields'] ?? []);
        }

        $this->assertContains('only_seo_urls_and_seo_url_entities_changed', $artifact['verification_after_write'] ?? []);
        $this->assertContains('no_external_api_calls', $artifact['verification_after_write'] ?? []);
        $this->assertContains('scheduler_still_disabled', $artifact['verification_after_write'] ?? []);

        $rollback = $artifact['rollback_policy'] ?? [];
        $this->assertSame('set_SEO_INTEL_WRITE_ENABLED_false', $rollback['first_action'] ?? null);
        $this->assertFalse((bool) ($rollback['schema_rollback_allowed'] ?? true));
        $this->assertTrue((bool) ($rollback['destructive_delete_requires_separate_approval'] ?? false));
    }

    #[Test]
    public function docs_state_no_production_write_is_approved(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-dash-collector-02-controlled-write-gate.md')));

        foreach ([
            'this pr is docs and contract only',
            'this command is not approved by this pr',
            'no production write is approved by this pr',
            'scheduler remains disabled',
            'external api',
            'cms mutation',
            'search submission',
            'first rollback action: set `seo_intel_write_enabled=false`',
        ] as $required) {
            $this->assertStringContainsString($required, $doc);
        }

        $this->assertSame(
            'approval-gated url_truth_inventory controlled write canary preflight',
            $this->artifact()['next_task'] ?? null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-collector-02-controlled-write-gate.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
