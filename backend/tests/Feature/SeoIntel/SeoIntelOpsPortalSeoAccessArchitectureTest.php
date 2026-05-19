<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsPortalSeoAccessArchitectureTest extends TestCase
{
    #[Test]
    public function artifact_locks_fap_api_ops_portal_route_ownership(): void
    {
        $artifact = $this->artifact();
        $route = $artifact['route_contract'] ?? [];

        $this->assertSame('ops-portal-seo-access-architecture.v1', $artifact['version'] ?? null);
        $this->assertSame('OPS-PORTAL-SEO-01', $artifact['task'] ?? null);
        $this->assertSame('https://ops.fermatmind.com/ops/seo', $route['target_url'] ?? null);
        $this->assertSame('fap-api', $route['owner_repo'] ?? null);
        $this->assertSame('fap-api', $route['runtime_owner'] ?? null);
        $this->assertSame('Laravel Filament Ops panel', $route['surface'] ?? null);
        $this->assertSame('/ops', $route['panel_path'] ?? null);
        $this->assertSame('authenticated_ops_portal_entry', $route['route_kind'] ?? null);
    }

    #[Test]
    public function metabase_remains_private_and_is_not_embedded_or_proxied(): void
    {
        $artifact = $this->artifact();
        $route = $artifact['route_contract'] ?? [];
        $metabase = $artifact['metabase_boundary'] ?? [];

        $this->assertFalse((bool) ($route['metabase_public_exposure_allowed'] ?? true));
        $this->assertFalse((bool) ($route['metabase_iframe_allowed'] ?? true));
        $this->assertFalse((bool) ($route['metabase_reverse_proxy_allowed_in_this_pr'] ?? true));
        $this->assertTrue((bool) ($metabase['localhost_only'] ?? false));
        $this->assertSame('127.0.0.1', $metabase['listen_host'] ?? null);
        $this->assertSame(3000, $metabase['listen_port'] ?? null);
        $this->assertFalse((bool) ($metabase['public_ipv4_allowed'] ?? true));
        $this->assertFalse((bool) ($metabase['public_security_group_ingress_allowed'] ?? true));
        $this->assertFalse((bool) ($metabase['dns_cdn_openresty_nginx_change_allowed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($metabase['public_sharing_allowed'] ?? true));
        $this->assertFalse((bool) ($metabase['anonymous_links_allowed'] ?? true));
        $this->assertFalse((bool) ($metabase['public_embedding_allowed'] ?? true));
    }

    #[Test]
    public function safe_mvp_page_only_shows_status_and_private_access_instructions(): void
    {
        $model = $this->artifact()['safe_mvp_access_model'] ?? [];

        $this->assertTrue((bool) ($model['page_shows_status_summary'] ?? false));
        $this->assertTrue((bool) ($model['page_shows_dashboard_name'] ?? false));
        $this->assertTrue((bool) ($model['page_shows_owner_assignments'] ?? false));
        $this->assertTrue((bool) ($model['page_shows_private_access_instructions'] ?? false));
        $this->assertFalse((bool) ($model['live_metabase_api_calls_allowed_in_this_pr'] ?? true));
        $this->assertFalse((bool) ($model['live_seo_intel_queries_allowed_in_this_pr'] ?? true));

        foreach (['workbench', 'bastion', 'vpn', 'owner_controlled_private_channel'] as $channel) {
            $this->assertContains($channel, $model['private_access_channels'] ?? []);
        }
    }

    #[Test]
    public function current_status_summary_matches_verified_seo_dash_mvp_state(): void
    {
        $state = $this->artifact()['current_seo_dash_state'] ?? [];

        $this->assertSame(7, $state['seo_urls'] ?? null);
        $this->assertSame(7, $state['seo_url_entities'] ?? null);
        $this->assertSame(5, $state['seo_issue_queue'] ?? null);
        $this->assertSame(1, $state['datasource_count'] ?? null);
        $this->assertSame('seo_intel', $state['datasource_name'] ?? null);
        $this->assertSame('seo_intel_metabase_readonly', $state['datasource_account'] ?? null);
        $this->assertSame('SEO Intelligence MVP - URL Truth & Issue Queue', $state['dashboard_name'] ?? null);
        $this->assertSame(10, $state['verified_cards_count'] ?? null);
        $this->assertTrue((bool) ($state['read_verification_passed'] ?? false));
        $this->assertTrue((bool) ($state['write_deny_verification_passed'] ?? false));
    }

    #[Test]
    public function normal_operator_sql_datasource_management_and_exports_remain_blocked(): void
    {
        $operator = $this->artifact()['operator_boundary'] ?? [];

        $this->assertFalse((bool) ($operator['normal_operator_unrestricted_sql_allowed'] ?? true));
        $this->assertFalse((bool) ($operator['datasource_management_for_normal_operators_allowed'] ?? true));
        $this->assertTrue((bool) ($operator['exports_owner_controlled_only'] ?? false));
        $this->assertTrue((bool) ($operator['future_operator_onboarding_requires_permissions_plan'] ?? false));
    }

    #[Test]
    public function forbidden_sources_and_operations_are_locked_out(): void
    {
        $artifact = $this->artifact();

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

        foreach ([
            'deploy',
            'env_edit',
            'dns_change',
            'cdn_change',
            'openresty_or_nginx_change',
            'public_port_open',
            'ecs_security_group_change',
            'rds_whitelist_change',
            'metabase_public_exposure',
            'metabase_bind_0_0_0_0',
            'metabase_iframe',
            'metabase_reverse_proxy',
            'metabase_datasource_addition',
            'metabase_dashboard_creation',
            'scheduler_enablement',
            'collector_write',
            'live_search_api_connection',
            'url_submission',
            'production_crawler_log_read',
            'research_publish',
            'pseo_generation',
        ] as $operation) {
            $this->assertContains($operation, $artifact['forbidden_operations'] ?? []);
        }
    }

    #[Test]
    public function this_pr_does_not_perform_runtime_production_network_or_metabase_operations(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_operations_performed_in_this_pr',
            'runtime_route_code_changed_in_this_pr',
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
    public function docs_lock_architecture_terms_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-portal-seo-access-architecture.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-portal-seo-access-architecture.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'ops portal seo access architecture contract',
            'authenticated ops portal entry',
            'fap-api owns `/ops/seo`',
            'filament ops panel',
            'must not iframe metabase',
            'must not reverse-proxy metabase',
            'metabase remains private',
            '127.0.0.1:3000',
            'workbench',
            'bastion',
            'vpn',
            'no unrestricted sql',
            'next task: `ops-portal-seo-02`',
            '"next_task": "ops-portal-seo-02"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-portal-seo-access-architecture.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
