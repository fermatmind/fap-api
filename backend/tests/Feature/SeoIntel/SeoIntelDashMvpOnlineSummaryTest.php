<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelDashMvpOnlineSummaryTest extends TestCase
{
    #[Test]
    public function artifact_locks_current_seo_dash_mvp_online_state(): void
    {
        $artifact = $this->artifact();
        $state = $artifact['current_production_state'] ?? [];
        $metabase = $artifact['metabase_state'] ?? [];

        $this->assertSame('seo-dash-mvp-online-summary.v1', $artifact['version'] ?? null);
        $this->assertSame('SEO-DASH-MVP-ONLINE-SUMMARY', $artifact['task'] ?? null);
        $this->assertSame(7, $state['seo_urls'] ?? null);
        $this->assertSame(7, $state['seo_url_entities'] ?? null);
        $this->assertSame(5, $state['seo_issue_queue'] ?? null);
        $this->assertSame(0, $state['other_checked_collector_tables'] ?? null);

        $this->assertTrue((bool) ($metabase['localhost_only'] ?? false));
        $this->assertFalse((bool) ($metabase['public_ipv4_present'] ?? true));
        $this->assertFalse((bool) ($metabase['boot_enabled'] ?? true));
        $this->assertSame('metabase_app', $metabase['application_database'] ?? null);
        $this->assertSame('seo_intel', $metabase['datasource_name'] ?? null);
        $this->assertSame('seo_intel_metabase_readonly', $metabase['datasource_account'] ?? null);
        $this->assertTrue((bool) ($metabase['read_verification_passed'] ?? false));
        $this->assertTrue((bool) ($metabase['write_deny_verification_passed'] ?? false));
    }

    #[Test]
    public function dashboard_cards_are_verified_and_limited_to_safe_seo_intel_tables(): void
    {
        $dashboard = $this->artifact()['dashboard'] ?? [];

        $this->assertSame('SEO Intelligence MVP — URL Truth & Issue Queue', $dashboard['name'] ?? null);
        $this->assertTrue((bool) ($dashboard['created'] ?? false));
        $this->assertSame(10, $dashboard['verified_cards_count'] ?? null);
        $this->assertSame(0, $dashboard['private_flow_or_forbidden_authority_count'] ?? null);

        foreach ([
            'URL Truth total count',
            'URL entity mapping total count',
            'Issue Queue total count',
            'URL Truth by page_entity_type',
            'URL Truth by locale',
            'URL Truth by source_authority',
            'URL Truth by indexability_state',
            'Issue Queue by issue_type',
            'Private-flow / forbidden authority safety count',
            'Recent issue rows, sanitized',
        ] as $card) {
            $this->assertContains($card, $dashboard['cards'] ?? []);
        }

        $this->assertSame([
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
        ], $dashboard['allowed_source_tables'] ?? []);
    }

    #[Test]
    public function access_export_and_sharing_policy_is_locked_down(): void
    {
        $policy = $this->artifact()['access_export_sharing'] ?? [];

        $this->assertTrue((bool) ($policy['verification_passed'] ?? false));
        $this->assertFalse((bool) ($policy['public_sharing_enabled'] ?? true));
        $this->assertFalse((bool) ($policy['embedding_enabled'] ?? true));
        $this->assertFalse((bool) ($policy['anonymous_links_present'] ?? true));
        $this->assertFalse((bool) ($policy['public_dashboard_or_card_tokens_present'] ?? true));
        $this->assertSame(1, $policy['admin_user_count'] ?? null);
        $this->assertSame(0, $policy['normal_operator_user_count'] ?? null);
        $this->assertSame(0, $policy['api_key_count'] ?? null);
        $this->assertFalse((bool) ($policy['exports_performed'] ?? true));
        $this->assertFalse((bool) ($policy['normal_operator_raw_sql_enabled'] ?? true));
        $this->assertTrue((bool) ($policy['future_operator_onboarding_requires_permissions_plan'] ?? false));
    }

    #[Test]
    public function forbidden_sources_fields_and_runtime_operations_remain_blocked(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'scheduler',
            'collector_writes',
            'external_search_live',
            'gsc_live',
            'baidu_live',
            'indexnow_live',
            'url_submission',
            'production_crawler_log_read',
            'research_publish',
            'pseo_generation',
            'public_metabase_access',
            'metabase_business_db_access',
        ] as $blocked) {
            $this->assertContains($blocked, $artifact['not_yet_enabled'] ?? []);
        }

        foreach ([
            'business_db',
            'tencent_rds_fap_prod',
            'node2_local_db',
            'cms_write_tables',
            'raw_orders',
            'raw_payments',
            'raw_events_detail',
            'raw_email',
            'raw_reports',
            'raw_crawler_logs',
            'provider_payloads',
            'payment_payloads',
        ] as $source) {
            $this->assertContains($source, $artifact['forbidden_sources'] ?? []);
        }

        foreach ($this->forbiddenFields() as $field) {
            $this->assertContains($field, $artifact['forbidden_fields'] ?? []);
        }

        foreach ([
            'production_operations_performed_in_this_pr',
            'metabase_operation_performed_in_this_pr',
            'runtime_code_changed_in_this_pr',
            'migration_changed_in_this_pr',
            'env_edit_in_this_pr',
            'deploy_performed_in_this_pr',
            'scheduler_enabled_in_this_pr',
            'collector_write_performed_in_this_pr',
            'external_api_live_activation',
            'url_submission_performed',
            'production_crawler_log_read',
            'research_publish_in_this_pr',
            'pseo_generation_in_this_pr',
        ] as $flag) {
            $this->assertFalse((bool) ($artifact[$flag] ?? true), $flag.' must remain false');
        }
    }

    #[Test]
    public function docs_lock_summary_next_phase_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/seo-dash-mvp-online-summary.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/seo-dash-mvp-online-summary.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'seo dash mvp online summary',
            'metabase is minimally online as a private observation layer',
            'listens only on `127.0.0.1:3000`',
            'datasource_account": "seo_intel_metabase_readonly"',
            'dashboard has 10 verified cards',
            'public sharing is disabled',
            'embedding is disabled',
            'not yet enabled',
            'research mvp',
            'search channel live readiness',
            'crawler log readiness',
            'research assets remain blocked from publish',
            'next task: `metabase-ops-access-runbook-00`',
            '"next_task": "metabase-ops-access-runbook-00"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/seo-dash-mvp-online-summary.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function forbiddenFields(): array
    {
        return [
            'password',
            'token',
            'cookie',
            'email',
            'raw_email',
            'order_no',
            'raw_order_no',
            'attempt_id',
            'raw_attempt_id',
            'payment_id',
            'raw_ip',
            'raw_user_agent',
            'raw_payload',
            'provider_payload',
            'payment_payload',
            'secret',
            'api_key',
        ];
    }
}
