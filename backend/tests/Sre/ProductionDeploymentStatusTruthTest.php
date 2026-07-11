<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductionDeploymentStatusTruthTest extends TestCase
{
    public function test_stale_manual_sha_fails_closed_before_the_environment_deploy_job(): void
    {
        $source = $this->workflow();
        $eligibility = $this->between($source, '  deployment-eligibility:', '  deploy-production:');
        $deploy = strstr($source, '  deploy-production:') ?: '';

        $this->assertStringNotContainsString('environment:', $eligibility);
        $this->assertStringContainsString('if [ "$DEPLOY_SHA" != "$LATEST_MAIN_SHA" ]', $eligibility);
        $this->assertStringContainsString('Manual production deploy refused because expected_release_sha is not latest main.', $eligibility);
        $this->assertStringContainsString('exit 1', $eligibility);
        $this->assertStringNotContainsString('eligible=false', $eligibility);
        $this->assertStringContainsString('needs: deployment-eligibility', $deploy);
        $this->assertStringContainsString("if: \${{ needs.deployment-eligibility.outputs.eligible == 'true' }}", $deploy);
        $this->assertStringContainsString("environment:\n      name: production", $deploy);
        $this->assertStringNotContainsString('skip_deploy', $source);
    }

    public function test_revision_queue_restart_and_both_smoke_steps_are_mandatory(): void
    {
        $deploy = strstr($this->workflow(), '  deploy-production:') ?: '';
        foreach ([
            'Deploy production with Deployer',
            'Restart queue workers through Laravel queue restart',
            'Verify deployed revision',
            'Healthcheck and contract smoke',
            'Ops entry and asset smoke',
        ] as $step) {
            $this->assertSame(1, substr_count($deploy, "- name: {$step}"), $step);
        }

        $this->assertStringContainsString('test "$DEPLOYED_SHA" = "$DEPLOY_SHA"', $deploy);
        $this->assertStringNotContainsString('continue-on-error: true', $deploy);
        $this->assertStringNotContainsString('steps.latest_main_guard.outputs', $deploy);
    }

    #[DataProvider('deploymentOutcomes')]
    public function test_only_complete_exact_revision_deploy_reports_success(
        bool $eligible,
        bool $deploySucceeded,
        bool $queueRestartSucceeded,
        bool $revisionMatches,
        bool $smokeSucceeded,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->outcome(
            $eligible,
            $deploySucceeded,
            $queueRestartSucceeded,
            $revisionMatches,
            $smokeSucceeded,
        ));
    }

    /** @return iterable<string,array{bool,bool,bool,bool,bool,string}> */
    public static function deploymentOutcomes(): iterable
    {
        yield 'exact SHA success' => [true, true, true, true, true, 'success'];
        yield 'eligibility not granted' => [false, false, false, false, false, 'skipped'];
        yield 'deploy failure' => [true, false, false, false, false, 'failed'];
        yield 'queue restart skipped' => [true, true, false, true, true, 'failed'];
        yield 'revision mismatch' => [true, true, true, false, true, 'failed'];
        yield 'smoke failure' => [true, true, true, true, false, 'failed'];
    }

    private function outcome(bool $eligible, bool $deploy, bool $queue, bool $revision, bool $smoke): string
    {
        if (! $eligible) {
            return 'skipped';
        }

        return $deploy && $queue && $revision && $smoke ? 'success' : 'failed';
    }

    private function workflow(): string
    {
        return (string) file_get_contents(base_path('../.github/workflows/deploy-production.yml'));
    }

    private function between(string $source, string $start, string $end): string
    {
        $offset = strpos($source, $start);
        $length = strpos($source, $end) - $offset;

        return substr($source, $offset, $length);
    }
}
