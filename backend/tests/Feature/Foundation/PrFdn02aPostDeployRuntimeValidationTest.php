<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PrFdn02aPostDeployRuntimeValidationTest extends TestCase
{
    #[Test]
    public function generated_runtime_validation_artifact_locks_read_only_boundaries(): void
    {
        $reportPath = base_path('docs/seo/pr-fdn-02a-post-deploy-runtime-validation.md');
        $generatedPath = base_path('docs/seo/generated/pr-fdn-02a-post-deploy-runtime-validation.v1.json');

        $this->assertFileExists($reportPath);
        $this->assertFileExists($generatedPath);

        $payload = json_decode((string) file_get_contents($generatedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('PR-FDN-02A-POST-DEPLOY-RUNTIME-VALIDATION', $payload['task'] ?? null);
        $this->assertSame(
            'pr_fdn_02a_post_deploy_runtime_validation_completed_with_ops_sidecar',
            $payload['final_decision'] ?? null
        );
        $this->assertSame(1758, $payload['backend_mvp_pr']['number'] ?? null);
        $this->assertSame(
            'dc51d31168027a105b644da5e6e85338cbbb4277',
            $payload['backend_mvp_pr']['merge_commit'] ?? null
        );

        $this->assertTrue($payload['route_contract']['registered'] ?? false);
        $this->assertSame(200, $payload['runtime_state']['apex_same_origin']['records_endpoint']['status'] ?? null);
        $this->assertSame(200, $payload['runtime_state']['direct_api_node_https']['records_endpoint']['status'] ?? null);
        $this->assertSame(
            'ops_sidecar_ssl_error_syscall',
            $payload['runtime_state']['direct_api_curl']['status'] ?? null
        );

        $this->assertTrue($payload['privacy_boundary_state']['private_fields_excluded_by_contract'] ?? false);
        $this->assertContains('proof_private_path', $payload['privacy_boundary_state']['private_fields'] ?? []);
        $this->assertTrue($payload['publication_gate_state']['planned_records_excluded'] ?? false);
        $this->assertTrue($payload['publication_gate_state']['voided_records_excluded'] ?? false);
        $this->assertTrue($payload['publication_gate_state']['non_public_records_excluded'] ?? false);

        $this->assertTrue($payload['no_production_mutation'] ?? false);
        $this->assertTrue($payload['no_cms_mutation'] ?? false);
        $this->assertTrue($payload['no_deploy'] ?? false);
        $this->assertTrue($payload['no_search_channel_action'] ?? false);
        $this->assertTrue($payload['no_url_submission'] ?? false);
        $this->assertTrue($payload['no_external_api_call'] ?? false);
        $this->assertTrue($payload['no_credentials_handled'] ?? false);
        $this->assertTrue($payload['no_env_dns_nginx_edit'] ?? false);
        $this->assertNotEmpty($payload['next_task'] ?? null);
    }
}
