<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelOpsPortalSeoPrivateAccessBridgeTest extends TestCase
{
    #[Test]
    public function artifact_allows_only_private_owner_controlled_mvp_access_paths(): void
    {
        $artifact = $this->artifact();

        $this->assertSame('ops-portal-seo-private-metabase-access-bridge.v1', $artifact['version'] ?? null);
        $this->assertSame('OPS-PORTAL-SEO-04', $artifact['task'] ?? null);

        foreach ([
            'workbench',
            'ssh_tunnel_through_approved_bastion',
            'vpn_after_separate_approval',
            'owner_controlled_private_channel',
        ] as $path) {
            $this->assertContains($path, $artifact['approved_mvp_access_paths'] ?? []);
        }
    }

    #[Test]
    public function public_metabase_iframe_proxy_and_operator_sql_are_not_mvp_paths(): void
    {
        foreach ([
            'raw_public_metabase',
            'public_dns_to_metabase',
            'public_cdn_path_to_metabase',
            'iframe_embedding',
            'reverse_proxy_through_ops_portal',
            'public_sharing_links',
            'anonymous_links',
            'public_embeds',
            'normal_operator_unrestricted_sql',
        ] as $path) {
            $this->assertContains($path, $this->artifact()['not_mvp_access_paths'] ?? []);
        }
    }

    #[Test]
    public function workbench_requires_no_network_or_rds_changes(): void
    {
        $workbench = $this->artifact()['bridge_options']['workbench'] ?? [];

        $this->assertTrue((bool) ($workbench['allowed_for_mvp'] ?? false));
        $this->assertFalse((bool) ($workbench['requires_dns_change'] ?? true));
        $this->assertFalse((bool) ($workbench['requires_public_port'] ?? true));
        $this->assertFalse((bool) ($workbench['requires_security_group_change'] ?? true));
        $this->assertFalse((bool) ($workbench['requires_rds_whitelist_change'] ?? true));
    }

    #[Test]
    public function reverse_proxy_is_future_only_and_requires_preflight(): void
    {
        $proxy = $this->artifact()['bridge_options']['reverse_proxy_behind_ops_auth'] ?? [];

        $this->assertFalse((bool) ($proxy['allowed_in_this_pr'] ?? true));
        $this->assertTrue((bool) ($proxy['requires_production_preflight'] ?? false));
        $this->assertTrue((bool) ($proxy['requires_auth_session_csrf_cookie_review'] ?? false));
        $this->assertTrue((bool) ($proxy['requires_audit_logging_plan'] ?? false));
        $this->assertTrue((bool) ($proxy['requires_rate_limit_and_revoke_plan'] ?? false));
        $this->assertTrue((bool) ($proxy['requires_dns_openresty_nginx_review_if_routing_changes'] ?? false));
    }

    #[Test]
    public function production_gates_block_public_network_and_forbidden_db_access(): void
    {
        foreach ([
            'no_metabase_bind_0_0_0_0',
            'no_public_ecs_port',
            'no_ecs_public_ipv4_or_eip',
            'no_security_group_broadening',
            'no_rds_whitelist_broadening',
            'no_wildcard_percent',
            'no_0_0_0_0_0',
            'no_broad_cidr',
            'no_public_rds_endpoint',
            'no_business_db_access',
            'no_tencent_rds_access',
            'no_node2_access',
            'no_dns_cdn_openresty_nginx_change_without_production_approval',
        ] as $gate) {
            $this->assertContains($gate, $this->artifact()['production_gates'] ?? []);
        }
    }

    #[Test]
    public function this_pr_does_not_implement_proxy_network_or_metabase_changes(): void
    {
        $artifact = $this->artifact();

        foreach ([
            'production_operations_performed_in_this_pr',
            'proxy_implemented_in_this_pr',
            'metabase_operation_performed_in_this_pr',
            'network_change_performed_in_this_pr',
            'security_group_change_performed_in_this_pr',
            'rds_whitelist_change_performed_in_this_pr',
            'dns_cdn_openresty_nginx_changed_in_this_pr',
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
    public function docs_lock_private_bridge_policy_and_next_task(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/ops-portal-seo-private-metabase-access-bridge.md')));
        $artifactJson = strtolower((string) file_get_contents(base_path('docs/seo/generated/ops-portal-seo-private-metabase-access-bridge.v1.json')));
        $combined = $doc."\n".$artifactJson;

        foreach ([
            'private metabase access bridge contract',
            'workbench access',
            'ssh tunnel',
            'bastion',
            'vpn',
            'raw public metabase',
            'iframe embedding',
            'reverse proxy behind ops auth is not authorized by this pr',
            'no security group broadening',
            'no rds whitelist broadening',
            'no business db',
            'next task: `ops-portal-seo-05`',
            '"next_task": "ops-portal-seo-05"',
        ] as $required) {
            $this->assertStringContainsString($required, $combined);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-portal-seo-private-metabase-access-bridge.v1.json');

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
