<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Tests\TestCase;

final class Eq60PrivateResultDeployContractTest extends TestCase
{
    public function test_standard_deploy_bootstraps_lkg_then_cas_activates_and_can_restore_it(): void
    {
        $deploy = (string) file_get_contents(base_path('../deploy.php'));

        $this->assertStringContainsString("task('eq60:publish-private-result-authority'", $deploy);
        $this->assertStringContainsString('--pack=%s --pack-version=v1 --activate=1 --compile=0 --compare-and-swap=1', $deploy);
        $this->assertStringContainsString('release_revision="$(tr -d \'\\r\\n\' < ../REVISION)"', $deploy);
        $this->assertStringContainsString('--force-new-release=1 --compare-and-swap=1 --expected-previous-release-id="$previous" --source_commit="$release_revision"', $deploy);
        $this->assertStringContainsString("after('enneagram:publish-private-result-authority', 'eq60:publish-private-result-authority')", $deploy);
        $this->assertStringContainsString("after('deploy:failed', 'eq60:rollback-private-result-authority-on-failure')", $deploy);
        $this->assertStringContainsString('packs2:rollback --pack=EQ_60 --pack-version=v1', $deploy);
    }
}
