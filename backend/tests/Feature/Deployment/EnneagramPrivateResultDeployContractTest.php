<?php

declare(strict_types=1);

namespace Tests\Feature\Deployment;

use Tests\TestCase;

final class EnneagramPrivateResultDeployContractTest extends TestCase
{
    public function test_standard_deploy_publishes_and_can_restore_the_previous_canonical_release(): void
    {
        $deploy = (string) file_get_contents(base_path('../deploy.php'));

        $this->assertStringContainsString("task('enneagram:publish-private-result-authority'", $deploy);
        $this->assertStringContainsString("deployShellArg('ENNEAGRAM_PRIVATE_RESULT')", $deploy);
        $this->assertStringContainsString('packs2:publish --pack=%s --pack-version=v2 --activate=1', $deploy);
        $this->assertStringContainsString("after('riasec:publish-private-result-authority', 'enneagram:publish-private-result-authority')", $deploy);
        $this->assertStringContainsString("after('deploy:failed', 'enneagram:rollback-private-result-authority-on-failure')", $deploy);
        $this->assertStringContainsString('packs2:rollback --pack=ENNEAGRAM_PRIVATE_RESULT --pack-version=v2', $deploy);
    }
}
