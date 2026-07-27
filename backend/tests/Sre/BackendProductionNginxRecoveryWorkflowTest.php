<?php

namespace Tests\Sre;

use Tests\TestCase;

class BackendProductionNginxRecoveryWorkflowTest extends TestCase
{
    public function test_workflow_is_exact_preflight_bound_and_limits_the_mutation_to_starting_nginx(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/backend-production-nginx-recovery.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('verify_only', $workflow);
        $this->assertStringContainsString('recover_and_verify', $workflow);
        $this->assertStringContainsString('preflight_run_id:', $workflow);
        $this->assertStringContainsString('expected_config_set_sha256:', $workflow);
        $this->assertStringContainsString('backend.production.nginx-recovery.v1', $workflow);
        $this->assertStringContainsString(
            'I explicitly approve production fap-api nginx recovery from preflight run ${PREFLIGHT_RUN_ID} with control-plane SHA ${GITHUB_SHA} active SHA ${EXPECTED_ACTIVE_SHA} nginx config-set SHA256 ${EXPECTED_CONFIG_SET_SHA256}; start only the failed nginx service after exact config and runtime revalidation, no deploy/symlink/migration/CMS/database-authority/cache/queue/publication/sitemap/llms/search/PR23.',
            $workflow,
        );
        $this->assertStringContainsString('sudo nginx -T 2>&1', $workflow);
        $this->assertStringContainsString('sudo nginx -t >/dev/null 2>&1', $workflow);
        $this->assertStringContainsString('timeout 30 sudo systemctl start nginx', $workflow);
        $this->assertStringNotContainsString('systemctl restart nginx', $workflow);
        $this->assertStringNotContainsString('systemctl reload nginx', $workflow);
        $this->assertStringNotContainsString('vendor/bin/dep deploy', $workflow);
        $this->assertStringNotContainsString('php artisan migrate', $workflow);
        $this->assertStringNotContainsString('queue:restart', $workflow);
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);
        $this->assertStringContainsString('secrets.PRODUCTION_DEPLOY_USER', $workflow);
        $this->assertStringContainsString('secrets.PRODUCTION_DEPLOY_PORT', $workflow);
        $this->assertStringContainsString('secrets.PRODUCTION_DEPLOY_HOST', $workflow);
    }

    public function test_preflight_is_read_only_and_recovery_revalidates_public_evidence(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/backend-production-nginx-recovery.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('and .mutation_attempted == false', $workflow);
        $this->assertStringContainsString('test "$mutation_attempted" = false', $workflow);
        $this->assertStringContainsString('test "$health_status" = 404', $workflow);
        $this->assertStringContainsString('test "$flags_status" = 200', $workflow);
        $this->assertStringContainsString('test "$bigfive_status" = 200', $workflow);
        $this->assertStringContainsString('test "$bigfive_contract" = true', $workflow);
        $this->assertStringContainsString('nginx_config_set_sha256', $workflow);
        $this->assertStringContainsString('nginx_master_count', $workflow);
        $this->assertStringContainsString('public_bigfive_contract_passed', $workflow);
        $this->assertStringContainsString('Backend nginx recovery receipt generated without routing metadata.', $workflow);
    }
}
