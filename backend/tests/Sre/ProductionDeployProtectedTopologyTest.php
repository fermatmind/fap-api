<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductionDeployProtectedTopologyTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-production.yml');
        self::assertIsString($workflow);
        $this->workflow = $workflow;
    }

    #[Test]
    public function private_production_routing_comes_only_from_masked_environment_secrets(): void
    {
        foreach ([
            'PRODUCTION_DEPLOY_USER',
            'PRODUCTION_DEPLOY_PORT',
            'PRODUCTION_DEPLOY_HOST',
            'PRODUCTION_RETIRED_DEPLOY_HOST',
            'PRODUCTION_DEPLOY_PATH',
        ] as $secret) {
            $this->assertStringContainsString('${{ secrets.'.$secret.' }}', $this->workflow);
            $this->assertStringNotContainsString('${{ vars.'.$secret.' }}', $this->workflow);
        }

        foreach ([
            'PRODUCTION_HEALTHCHECK_URL',
            'PRODUCTION_AUTH_GUEST_CHECK_URL',
            'PRODUCTION_OPS_HOST',
        ] as $publicVariable) {
            $this->assertStringContainsString('${{ vars.'.$publicVariable.' }}', $this->workflow);
        }

        $this->assertStringNotContainsString('139.224.130.204', $this->workflow);
        $this->assertStringNotContainsString('122.152.221.126', $this->workflow);
        $this->assertStringNotContainsString('/var/www/fap-api', $this->workflow);
    }

    #[Test]
    public function topology_validation_fails_closed_before_any_ssh_or_deployer_action(): void
    {
        $guardOffset = strpos($this->workflow, '- name: Validate protected production topology');
        $sshOffset = strpos($this->workflow, '- name: Set up SSH');
        $deployerOffset = strpos($this->workflow, '- name: Deploy production with Deployer');

        $this->assertIsInt($guardOffset);
        $this->assertIsInt($sshOffset);
        $this->assertIsInt($deployerOffset);
        $this->assertLessThan($sshOffset, $guardOffset);
        $this->assertLessThan($deployerOffset, $guardOffset);
        $this->assertStringContainsString('Protected production environment variable ${name} is required.', $this->workflow);
        $this->assertStringContainsString('without disclosing values', $this->workflow);
        $this->assertStringContainsString('DEPLOY_HOST_PROD: ${{ secrets.PRODUCTION_DEPLOY_HOST }}', $this->workflow);
        $this->assertStringContainsString('RETIRED_DEPLOY_HOST: ${{ secrets.PRODUCTION_RETIRED_DEPLOY_HOST }}', $this->workflow);
        $this->assertStringContainsString('DEPLOY_PATH_PROD: ${{ secrets.PRODUCTION_DEPLOY_PATH }}', $this->workflow);
    }

    #[Test]
    public function production_queue_restart_uses_the_application_runtime_identity(): void
    {
        $this->assertStringContainsString(
            'sudo -n -u www-data -- php artisan queue:restart --ansi',
            $this->workflow,
        );
        $this->assertStringNotContainsString(
            '&& php artisan queue:restart',
            $this->workflow,
        );
    }
}
