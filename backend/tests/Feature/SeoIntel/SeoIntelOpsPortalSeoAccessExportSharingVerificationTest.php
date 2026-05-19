<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsPortalSeoAccessExportSharingVerificationTest extends TestCase
{
    #[Test]
    public function artifact_requires_ops_auth_and_forbids_metabase_exposure_from_ops_route(): void
    {
        $route = $this->artifact()['ops_route_verification'] ?? [];

        $this->assertSame('ops-portal-seo-access-export-sharing-verification.v1', $this->artifact()['version'] ?? null);
        $this->assertSame('OPS-PORTAL-SEO-05', $this->artifact()['task'] ?? null);
        $this->assertSame('/ops/seo', $route['path'] ?? null);
        $this->assertTrue((bool) ($route['requires_ops_admin_auth'] ?? false));
        $this->assertFalse((bool) ($route['public_metabase_url_exposed'] ?? true));
        $this->assertFalse((bool) ($route['metabase_iframe_present'] ?? true));
        $this->assertFalse((bool) ($route['metabase_reverse_proxy_present'] ?? true));
    }

    #[Test]
    public function metabase_privacy_and_sharing_verification_stays_locked_down(): void
    {
        $privacy = $this->artifact()['metabase_privacy_verification'] ?? [];
        $sharing = $this->artifact()['sharing_verification'] ?? [];

        $this->assertTrue((bool) ($privacy['private_only'] ?? false));
        $this->assertTrue((bool) ($privacy['localhost_bound'] ?? false));
        $this->assertFalse((bool) ($privacy['public_ipv4_allowed'] ?? true));
        $this->assertFalse((bool) ($privacy['public_port_allowed'] ?? true));
        $this->assertFalse((bool) ($privacy['security_group_change_allowed'] ?? true));
        $this->assertFalse((bool) ($privacy['rds_whitelist_change_allowed'] ?? true));
        $this->assertFalse((bool) ($sharing['public_sharing_enabled'] ?? true));
        $this->assertFalse((bool) ($sharing['anonymous_links_present'] ?? true));
        $this->assertFalse((bool) ($sharing['public_embeds_enabled'] ?? true));
        $this->assertFalse((bool) ($sharing['public_dashboard_or_card_tokens_present'] ?? true));
    }

    #[Test]
    public function datasource_boundary_allows_only_seo_intel_readonly_source(): void
    {
        $datasource = $this->artifact()['datasource_verification'] ?? [];

        $this->assertSame(1, $datasource['expected_datasource_count'] ?? null);
        $this->assertSame('seo_intel', $datasource['expected_datasource_name'] ?? null);
        $this->assertSame('seo_intel_metabase_readonly', $datasource['expected_datasource_account'] ?? null);
        $this->assertFalse((bool) ($datasource['sample_database_allowed'] ?? true));

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
            $this->assertContains($source, $datasource['forbidden_sources'] ?? []);
        }
    }

    #[Test]
    public function dashboard_tables_and_export_fields_are_safe_only(): void
    {
        $dashboard = $this->artifact()['dashboard_verification'] ?? [];
        $export = $this->artifact()['export_policy'] ?? [];

        $this->assertSame('SEO Intelligence MVP - URL Truth & Issue Queue', $dashboard['dashboard_name'] ?? null);
        $this->assertSame([
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
        ], $dashboard['allowed_tables'] ?? []);
        $this->assertFalse((bool) ($dashboard['unsafe_fields_allowed'] ?? true));
        $this->assertFalse((bool) ($export['normal_operator_exports_allowed_by_default'] ?? true));
        $this->assertTrue((bool) ($export['owner_approval_required'] ?? false));

        foreach ([
            'password',
            'token',
            'cookie',
            'email',
            'order_id',
            'payment_id',
            'attempt_id',
            'raw_ip',
            'user_agent',
            'raw_payload',
            'provider_payload',
            'payment_payload',
            'raw_evidence',
            'raw_crawler_logs',
            'business_db_rows',
        ] as $field) {
            $this->assertContains($field, $export['forbidden_export_fields'] ?? []);
        }
    }

    #[Test]
    public function raw_sql_audit_owner_and_revoke_policies_are_required(): void
    {
        $sql = $this->artifact()['raw_sql_policy'] ?? [];
        $revoke = $this->artifact()['audit_owner_revoke_verification'] ?? [];

        $this->assertFalse((bool) ($sql['normal_operator_unrestricted_sql_allowed'] ?? true));
        $this->assertTrue((bool) ($sql['admin_native_sql_remains_privileged'] ?? false));
        $this->assertTrue((bool) ($sql['future_operator_onboarding_requires_permission_setup'] ?? false));

        foreach ([
            'metabase_admin_owner_required',
            'dashboard_owner_required',
            'db_access_owner_required',
            'export_policy_owner_required',
            'emergency_revoke_owner_required',
            'audit_path_required',
            'revoke_path_required',
        ] as $flag) {
            $this->assertTrue((bool) ($revoke[$flag] ?? false), $flag.' must be true');
        }
    }

    #[Test]
    public function this_pr_does_not_operate_metabase_or_production_systems(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_operations_performed_in_this_pr',
            'metabase_api_operation_performed_in_this_pr',
            'datasource_added_in_this_pr',
            'dashboard_created_in_this_pr',
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
    public function docs_lock_verification_policy_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-portal-seo-access-export-sharing-verification.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-portal-seo-access-export-sharing-verification.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'access export sharing verification contract',
            '/ops/seo',
            'requires existing ops/admin authentication',
            'does not expose a public metabase url',
            'public sharing is disabled',
            'anonymous links are absent',
            'datasource count is exactly 1',
            'seo_intel_metabase_readonly',
            'seo_urls',
            'seo_url_entities',
            'seo_issue_queue',
            'no pii export',
            'normal operators have no unrestricted sql',
            'emergency revoke',
            'next task: `ops-portal-seo-prod-01`',
            '"next_task": "ops-portal-seo-prod-01"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-portal-seo-access-export-sharing-verification.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
