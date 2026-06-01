<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpsApiInternalResolveProofTest extends TestCase
{
    #[Test]
    public function generated_artifact_records_node1_api_path_proof_and_boundaries(): void
    {
        $payload = $this->artifact();

        $this->assertSame('ops-api-internal-resolve-proof.v1', $payload['schema_version'] ?? null);
        $this->assertSame('OPS-API-INTERNAL-RESOLVE-PROOF', $payload['task'] ?? null);
        $this->assertSame('ops_api_internal_resolve_proof_completed_with_sidecars', $payload['final_decision'] ?? null);
        $this->assertTrue((bool) ($payload['read_only_ssh_verification'] ?? false));

        $this->assertSame('fap-web-node1', data_get($payload, 'node1_host.ssh_alias'));
        $this->assertSame('VM-4-7-ubuntu', data_get($payload, 'node1_host.hostname'));
        $this->assertSame(5, data_get($payload, 'node1_direct_dns_check.attempt_count'));
        $this->assertSame(5, data_get($payload, 'node1_direct_dns_check.success_count'));
        $this->assertTrue((bool) data_get($payload, 'node1_direct_dns_check.stable'));

        $this->assertSame(5, data_get($payload, 'node1_node_https_check.attempt_count'));
        $this->assertSame(5, data_get($payload, 'node1_node_https_check.success_count'));
        $this->assertTrue((bool) data_get($payload, 'node1_node_https_check.stable'));

        $this->assertSame(3, data_get($payload, 'node1_apex_same_origin_check.success_count'));
        $this->assertTrue((bool) data_get($payload, 'node1_apex_same_origin_check.stable'));
        $this->assertSame(5, data_get($payload, 'node2_direct_check.success_count'));
        $this->assertSame(3, data_get($payload, 'api_host_direct_check.success_count'));

        $this->assertFalse((bool) data_get($payload, 'legacy_internal_resolve_sidecar.stable'));
        $this->assertSame('timeout', data_get($payload, 'legacy_internal_resolve_sidecar.result'));
        $this->assertTrue((bool) data_get($payload, 'proof_summary.node1_fap_web_to_api_current_path_stable'));
        $this->assertFalse((bool) data_get($payload, 'proof_summary.legacy_198_18_14_180_forced_path_stable'));
        $this->assertTrue((bool) data_get($payload, 'proof_summary.response_hash_consistent_across_successful_checks'));

        foreach ([
            'no_production_mutation',
            'no_deploy',
            'no_cms_mutation',
            'no_search_channel_action',
            'no_url_submission',
            'no_external_search_api_call',
            'no_env_dns_nginx_edit',
            'no_service_restart',
            'no_raw_log_access',
        ] as $flag) {
            $this->assertTrue((bool) data_get($payload, "safety_boundaries.$flag"), $flag);
        }

        $this->assertNotEmpty($payload['next_task'] ?? null);
    }

    #[Test]
    public function report_contains_required_sections(): void
    {
        $reportPath = base_path('docs/seo/ops-api-internal-resolve-proof.md');

        $this->assertFileExists($reportPath);

        $report = (string) file_get_contents($reportPath);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Scope And Safety',
            '## 3. Local Direct API Check',
            '## 4. Node1 Direct DNS / HTTPS Proof',
            '## 5. Node1 Node.js Runtime Proof',
            '## 6. Apex Same-Origin API Proof',
            '## 7. Node2 And API Host Proof',
            '## 8. Forced Resolve Sidecar',
            '## 9. PM2 Sidecar',
            '## 10. What Was Not Done',
            '## 11. Final Decision',
            '## 12. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $path = base_path('docs/seo/generated/ops-api-internal-resolve-proof.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
