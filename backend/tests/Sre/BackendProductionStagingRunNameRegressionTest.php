<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackendProductionStagingRunNameRegressionTest extends TestCase
{
    #[Test]
    public function custom_staging_run_titles_do_not_replace_exact_workflow_path_evidence(): void
    {
        $automatic = $this->workflow('backend-production-auto-deploy.yml');
        $production = $this->workflow('deploy-production.yml');

        $this->assertStringNotContainsString("staging.name !== 'Deploy Application'", $automatic);
        $this->assertStringContainsString("staging.path !== '.github/workflows/deploy.yml'", $automatic);

        $this->assertStringNotContainsString('.name == "Deploy Application"', $production);
        $this->assertStringContainsString('.path == ".github/workflows/deploy.yml"', $production);
        $this->assertStringContainsString('gh run list --workflow deploy.yml', $production);
    }

    private function workflow(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/'.$name);
    }
}
