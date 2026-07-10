<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OpsApiInternalResolveProofTest extends TestCase
{
    #[Test]
    public function generated_artifact_keeps_aggregate_proof_without_operational_identifiers(): void
    {
        $payload = $this->artifact();

        $this->assertSame('ops-api-internal-resolve-proof.v1', $payload['schema_version'] ?? null);
        $this->assertSame('OPS-API-INTERNAL-RESOLVE-PROOF', $payload['task'] ?? null);
        $this->assertSame('ops_api_internal_resolve_proof_completed_with_sidecars', $payload['final_decision'] ?? null);
        $this->assertTrue((bool) ($payload['read_only_verification'] ?? false));
        $this->assertSame(5, data_get($payload, 'checks.public_api_direct.attempt_count'));
        $this->assertSame(5, data_get($payload, 'checks.public_api_direct.success_count'));
        $this->assertTrue((bool) data_get($payload, 'checks.server_runtime_https.stable'));
        $this->assertTrue((bool) data_get($payload, 'checks.same_origin_api.stable'));
        $this->assertFalse((bool) data_get($payload, 'legacy_route_sidecar.stable'));
        $this->assertSame('timeout', data_get($payload, 'legacy_route_sidecar.result'));
        $this->assertTrue((bool) data_get($payload, 'proof_summary.public_api_path_stable'));
        $this->assertFalse((bool) data_get($payload, 'proof_summary.retired_route_stable'));

        foreach ((array) ($payload['redaction'] ?? []) as $flag => $value) {
            $this->assertTrue((bool) $value, (string) $flag);
        }

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['"ssh_alias":', '"hostname":', '"remote_ips":', '"dns_resolution":', '"forced_resolve":', '"restart_time_per_instance":', '"body_sha256":'] as $field) {
            $this->assertStringNotContainsString($field, $serialized, $field);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $serialized);

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
            '## 3. Aggregate Public API Check',
            '## 4. Aggregate Server Runtime Check',
            '## 5. Aggregate Same-Origin Check',
            '## 6. Independent Network Observations',
            '## 7. Retired Route Sidecar',
            '## 8. Process Health Sidecar',
            '## 9. Repository Redaction Boundary',
            '## 10. What Was Not Done',
            '## 11. Final Decision',
            '## 12. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        foreach (['fap-web-node1', 'fap-node2', 'fap-api-prod', 'VM-4-', 'ssh alias:', 'forced resolve to'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $report);
        }
        $this->assertDoesNotMatchRegularExpression('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $report);
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
