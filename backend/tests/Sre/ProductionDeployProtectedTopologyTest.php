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
    public function production_topology_comes_only_from_protected_environment_variables(): void
    {
        foreach ([
            'PRODUCTION_DEPLOY_USER',
            'PRODUCTION_DEPLOY_PORT',
            'PRODUCTION_DEPLOY_HOST',
            'PRODUCTION_RETIRED_DEPLOY_HOST',
            'PRODUCTION_DEPLOY_PATH',
            'PRODUCTION_HEALTHCHECK_URL',
            'PRODUCTION_AUTH_GUEST_CHECK_URL',
            'PRODUCTION_OPS_HOST',
        ] as $variable) {
            $this->assertStringContainsString('${{ vars.'.$variable.' }}', $this->workflow);
        }

        $this->assertStringNotContainsString('139.224.130.204', $this->workflow);
        $this->assertStringNotContainsString('122.152.221.126', $this->workflow);
        $this->assertStringNotContainsString('/var/www/fap-api', $this->workflow);
        $this->assertStringNotContainsString('https://api.fermatmind.com', $this->workflow);
        $this->assertStringNotContainsString('ops.fermatmind.com', $this->workflow);
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
        $this->assertStringContainsString('DEPLOY_HOST_PROD: ${{ vars.PRODUCTION_DEPLOY_HOST }}', $this->workflow);
        $this->assertStringContainsString('RETIRED_DEPLOY_HOST: ${{ vars.PRODUCTION_RETIRED_DEPLOY_HOST }}', $this->workflow);
        $this->assertStringContainsString('DEPLOY_PATH_PROD: ${{ vars.PRODUCTION_DEPLOY_PATH }}', $this->workflow);
    }
}
