<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelMetabaseOpsAccessRunbookTest extends TestCase
{
    #[Test]
    public function artifact_locks_private_localhost_only_access_model(): void
    {
        $artifact = $this->artifact();
        $access = $artifact['access_model'] ?? [];

        $this->assertSame('metabase-ops-access-runbook.v1', $artifact['version'] ?? null);
        $this->assertSame('METABASE-OPS-ACCESS-RUNBOOK-00', $artifact['task'] ?? null);
        $this->assertTrue((bool) ($access['localhost_only'] ?? false));
        $this->assertSame('127.0.0.1', $access['listen_host'] ?? null);
        $this->assertSame(3000, $access['listen_port'] ?? null);
        $this->assertFalse((bool) ($access['public_ipv4_allowed'] ?? true));
        $this->assertFalse((bool) ($access['public_security_group_ingress_allowed'] ?? true));
        $this->assertFalse((bool) ($access['public_dns_or_cdn_allowed'] ?? true));
        $this->assertFalse((bool) ($access['anonymous_links_allowed'] ?? true));
        $this->assertFalse((bool) ($access['public_sharing_allowed'] ?? true));
        $this->assertFalse((bool) ($access['public_embedding_allowed'] ?? true));
    }

    #[Test]
    public function service_policy_keeps_boot_disabled_and_documents_safe_commands(): void
    {
        $service = $this->artifact()['service_policy'] ?? [];

        $this->assertSame('metabase', $service['service_name'] ?? null);
        $this->assertFalse((bool) ($service['boot_enabled_policy'] ?? true));
        $this->assertTrue((bool) ($service['boot_policy_change_requires_separate_approval'] ?? false));

        foreach ([
            'systemctl status metabase --no-pager',
            'systemctl is-active metabase',
            'systemctl is-enabled metabase',
            "ss -lntp | grep ':3000' || true",
            'curl -sS http://127.0.0.1:3000/api/health',
        ] as $command) {
            $this->assertContains($command, $service['status_commands'] ?? []);
        }

        foreach ([
            'systemctl start metabase',
            'systemctl stop metabase',
            'systemctl restart metabase',
        ] as $command) {
            $this->assertContains($command, $service['allowed_control_commands'] ?? []);
        }
    }

    #[Test]
    public function emergency_revoke_and_rotation_do_not_open_public_or_use_writer_credentials(): void
    {
        $artifact = $this->artifact();
        $revoke = $artifact['emergency_revoke'] ?? [];
        $rotation = $artifact['password_rotation'] ?? [];

        foreach ([
            'metabase_binds_0_0_0_0',
            'ecs_public_ipv4_or_eip_present',
            'public_security_group_ingress_present',
            'public_sharing_enabled',
            'embedding_enabled',
            'unexpected_datasource_present',
            'normal_operator_without_permissions_plan',
            'business_or_raw_datasource_present',
        ] as $trigger) {
            $this->assertContains($trigger, $revoke['triggers'] ?? []);
        }

        foreach ([
            'systemctl stop metabase',
            'systemctl disable metabase',
            'confirm_no_public_or_port_3000_listener',
            'confirm_rds_public_endpoint_and_whitelist_remain_private',
        ] as $step) {
            $this->assertContains($step, $revoke['steps'] ?? []);
        }

        $this->assertTrue((bool) ($rotation['secrets_must_not_be_printed'] ?? false));

        foreach ([
            'seo_intel_writer',
            'seo_intel_migrator',
            'business_db_user',
            'tencent_rds_user',
            'node2_local_db_user',
        ] as $account) {
            $this->assertContains($account, $rotation['forbidden_rotation_accounts'] ?? []);
        }
    }

    #[Test]
    public function export_and_operator_onboarding_policies_are_blocked_until_permissions_plan(): void
    {
        $artifact = $this->artifact();
        $export = $artifact['export_policy'] ?? [];
        $operator = $artifact['operator_onboarding'] ?? [];

        $this->assertFalse((bool) ($export['exports_performed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($export['normal_operator_exports_allowed'] ?? true));
        $this->assertTrue((bool) ($export['owner_approval_required'] ?? false));

        foreach ([
            'sanitized_url_truth_aggregates',
            'sanitized_issue_queue_summaries',
            'safe_dashboard_screenshots_without_raw_pii',
        ] as $scope) {
            $this->assertContains($scope, $export['allowed_export_scope'] ?? []);
        }

        foreach ($this->forbiddenExportFields() as $field) {
            $this->assertContains($field, $export['forbidden_export_fields'] ?? []);
        }

        $this->assertTrue((bool) ($operator['blocked_until_permissions_plan'] ?? false));
        $this->assertFalse((bool) ($operator['normal_operator_users_allowed_now'] ?? true));
        $this->assertFalse((bool) ($operator['unrestricted_native_sql_allowed_for_normal_users'] ?? true));
    }

    #[Test]
    public function datasource_boundary_allows_only_seo_intel_and_forbids_business_raw_sources(): void
    {
        $boundary = $this->artifact()['datasource_boundary'] ?? [];

        $this->assertSame('seo_intel', $boundary['allowed_database'] ?? null);
        $this->assertSame('seo_intel_metabase_readonly', $boundary['allowed_datasource_user'] ?? null);
        $this->assertSame([
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
        ], $boundary['allowed_tables'] ?? []);

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
            $this->assertContains($source, $boundary['forbidden_sources'] ?? []);
        }
    }

    #[Test]
    public function no_runtime_production_or_research_publish_operation_is_authorized(): void
    {
        $artifact = $this->artifact();

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
    public function docs_lock_private_access_export_policy_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/metabase-ops-access-runbook.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/metabase-ops-access-runbook.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'metabase ops access runbook',
            'localhost-only',
            'public sharing: disabled',
            'anonymous links: disabled',
            'boot enablement: disabled',
            'emergency revoke',
            'password rotation',
            'exports are owner-controlled only',
            'operator onboarding is blocked',
            'metabase may read only',
            'business db',
            'node2 local db',
            'next task: `pr-research-01`',
            '"next_task": "pr-research-01"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/metabase-ops-access-runbook.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function forbiddenExportFields(): array
    {
        return [
            'email',
            'raw_email',
            'order_no',
            'raw_order_no',
            'attempt_id',
            'raw_attempt_id',
            'payment_id',
            'cookie',
            'raw_ip',
            'raw_user_agent',
            'token',
            'raw_payload',
            'provider_payload',
            'payment_payload',
            'secret',
            'api_key',
        ];
    }
}
