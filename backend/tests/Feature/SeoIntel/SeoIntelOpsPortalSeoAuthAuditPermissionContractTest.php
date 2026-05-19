<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsPortalSeoAuthAuditPermissionContractTest extends TestCase
{
    #[Test]
    public function artifact_requires_existing_ops_auth_stack(): void
    {
        $artifact = $this->artifact();
        $auth = $artifact['auth_stack'] ?? [];

        $this->assertSame('ops-portal-seo-auth-audit-permission-contract.v1', $artifact['version'] ?? null);
        $this->assertSame('OPS-PORTAL-SEO-02', $artifact['task'] ?? null);
        $this->assertSame('fap-api Filament Ops panel', $auth['surface'] ?? null);
        $this->assertFalse((bool) ($auth['public_route_outside_ops_panel_allowed'] ?? true));
        $this->assertTrue((bool) ($auth['admin_guard_required'] ?? false));
        $this->assertTrue((bool) ($auth['filament_ops_auth_required'] ?? false));
        $this->assertTrue((bool) ($auth['session_required'] ?? false));
        $this->assertTrue((bool) ($auth['csrf_required'] ?? false));
        $this->assertTrue((bool) ($auth['totp_required_when_configured'] ?? false));
        $this->assertTrue((bool) ($auth['org_context_required_when_panel_requires_it'] ?? false));
        $this->assertTrue((bool) ($auth['ops_access_control_required'] ?? false));
        $this->assertTrue((bool) ($auth['host_allowlist_respected'] ?? false));
        $this->assertTrue((bool) ($auth['ip_allowlist_respected'] ?? false));
        $this->assertFalse((bool) ($auth['fail_open_allowed'] ?? true));
    }

    #[Test]
    public function permissions_allow_owner_or_ops_read_and_reserve_narrow_future_permission(): void
    {
        $permissions = $this->artifact()['permission_decision'] ?? [];

        $this->assertContains('admin.owner', $permissions['initial_allowed_permissions'] ?? []);
        $this->assertContains('admin.ops.read', $permissions['initial_allowed_permissions'] ?? []);
        $this->assertSame('admin.seo_intel.read', $permissions['future_narrow_permission'] ?? null);
        $this->assertFalse((bool) ($permissions['normal_operator_unrestricted_sql_allowed'] ?? true));
        $this->assertFalse((bool) ($permissions['normal_operator_datasource_management_allowed'] ?? true));
        $this->assertFalse((bool) ($permissions['normal_operator_export_allowed_by_default'] ?? true));
    }

    #[Test]
    public function owners_and_audit_events_are_explicit(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'metabase_admin_owner',
            'dashboard_owner',
            'db_access_owner',
            'export_policy_owner',
            'emergency_revoke_owner',
        ] as $owner) {
            $this->assertContains($owner, $artifact['owners'] ?? []);
        }

        foreach ([
            'ops_seo_page_access',
            'permission_change',
            'owner_assignment_change',
            'export_approval_decision',
            'emergency_revoke_action',
            'future_bridge_or_proxy_access_event',
        ] as $event) {
            $this->assertContains($event, $artifact['audit_expectations'] ?? []);
        }
    }

    #[Test]
    public function audit_contract_forbids_secrets_and_raw_operational_identifiers(): void
    {
        foreach ([
            'password',
            'token',
            'cookie',
            'raw_ip_payload',
            'order_id',
            'payment_id',
            'attempt_id',
            'raw_email',
            'provider_payload',
            'payment_payload',
            'secret',
            'api_key',
        ] as $field) {
            $this->assertContains($field, $this->artifact()['audit_forbidden_fields'] ?? []);
        }
    }

    #[Test]
    public function normal_operator_onboarding_remains_blocked_until_permissions_plan(): void
    {
        $operator = $this->artifact()['operator_onboarding'] ?? [];

        $this->assertTrue((bool) ($operator['blocked_until_permissions_plan'] ?? false));
        $this->assertFalse((bool) ($operator['normal_operator_users_allowed_now'] ?? true));
        $this->assertFalse((bool) ($operator['unrestricted_native_sql_allowed'] ?? true));
        $this->assertFalse((bool) ($operator['native_query_access_default'] ?? true));
        $this->assertFalse((bool) ($operator['datasource_management_allowed'] ?? true));
        $this->assertFalse((bool) ($operator['exports_allowed_by_default'] ?? true));
        $this->assertFalse((bool) ($operator['public_sharing_allowed'] ?? true));
        $this->assertFalse((bool) ($operator['public_embedding_allowed'] ?? true));
        $this->assertFalse((bool) ($operator['anonymous_link_creation_allowed'] ?? true));
    }

    #[Test]
    public function revoke_triggers_and_steps_cover_public_metabase_and_forbidden_sources(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'metabase_binds_0_0_0_0',
            'metabase_public_ipv4_or_eip_present',
            'public_security_group_ingress_present',
            'public_sharing_enabled',
            'anonymous_links_created',
            'public_embedding_enabled',
            'unexpected_datasource_present',
            'business_or_raw_datasource_present',
            'normal_operator_unrestricted_sql_enabled',
        ] as $trigger) {
            $this->assertContains($trigger, $artifact['emergency_revoke_triggers'] ?? []);
        }

        foreach ([
            'stop_public_access',
            'disable_unsafe_settings',
            'confirm_localhost_only_metabase',
            'review_datasource_inventory',
            'record_owner_action',
        ] as $step) {
            $this->assertContains($step, $artifact['emergency_revoke_steps'] ?? []);
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
    }

    #[Test]
    public function this_pr_does_not_add_runtime_permission_code_route_shell_or_operations(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_operations_performed_in_this_pr',
            'runtime_permission_code_changed_in_this_pr',
            'route_shell_added_in_this_pr',
            'metabase_operation_performed_in_this_pr',
            'network_change_performed_in_this_pr',
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
    public function docs_lock_auth_audit_permission_contract_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-portal-seo-auth-audit-permission-contract.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-portal-seo-auth-audit-permission-contract.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'ops portal seo auth audit permission contract',
            'admin guard',
            'filament ops authentication',
            'csrf protection',
            'totp',
            'org context',
            'ops access control',
            'admin.ops.read',
            'admin.seo_intel.read',
            'metabase admin owner',
            'emergency revoke owner',
            'normal operators are blocked',
            'unrestricted sql',
            'next task: `ops-portal-seo-03`',
            '"next_task": "ops-portal-seo-03"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-portal-seo-auth-audit-permission-contract.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
