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
        $this->assertStringNotContainsString('steps.latest_main_guard.outputs.eligible', $deploy);
    }

    public function test_auto_mode_is_fail_closed_to_a_cumulative_code_only_lane(): void
    {
        $workflow = $this->workflow();
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $deploy = strstr($workflow, '  deploy-production:') ?: '';

        $this->assertStringContainsString('fetch-depth: 0', $eligibility);
        $this->assertStringContainsString('expected_deployed_revision as a lowercase 40-character deployed REVISION', $eligibility);
        $this->assertStringContainsString('git merge-base --is-ancestor "$EXPECTED_DEPLOYED_REVISION" "$DEPLOY_SHA"', $eligibility);
        $this->assertStringContainsString('git diff --no-renames --name-only "$EXPECTED_DEPLOYED_REVISION" "$DEPLOY_SHA"', $eligibility);
        $this->assertStringContainsString('code-only scope refused authority or generated path:', $eligibility);
        $this->assertStringContainsString('code-only scope refused unknown path:', $eligibility);
        $this->assertStringContainsString('generated/*', $eligibility);
        $this->assertStringContainsString('backend/database/*', $eligibility);
        $this->assertStringContainsString('backend/app/Services/Cms/*', $eligibility);
        $this->assertStringContainsString('cumulative deployed-revision diff contains a forbidden or unknown path', $eligibility);
        $this->assertStringContainsString('RESOLVED_DEPLOY_MODE=code_only', $eligibility);
        $this->assertStringContainsString('echo "deploy_mode=$RESOLVED_DEPLOY_MODE" >> "$GITHUB_OUTPUT"', $eligibility);
        $this->assertStringContainsString('DEPLOY_MODE: ${{ needs.deployment-eligibility.outputs.deploy_mode }}', $deploy);
        $this->assertStringContainsString('DEPLOY_TASK=deploy', $deploy);
        $this->assertStringContainsString('DEPLOY_TASK=deploy:code-only', $deploy);
        $this->assertStringContainsString('-o deploy_mode="$DEPLOY_MODE"', $deploy);
        $this->assertStringContainsString("if: \${{ needs.deployment-eligibility.outputs.deploy_mode == 'standard' }}", $deploy);
        $this->assertStringContainsString('code-only deploy: auth guest POST contract probe intentionally skipped', $deploy);
        $this->assertStringContainsString('Verify code-only deployed baseline before writes', $deploy);
        $this->assertStringContainsString('remote deployed REVISION does not match expected_deployed_revision', $deploy);
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
        return (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-production.yml');
    }

    private function between(string $source, string $start, string $end): string
    {
        $offset = strpos($source, $start);
        $length = strpos($source, $end) - $offset;

        return substr($source, $offset, $length);
    }
}
