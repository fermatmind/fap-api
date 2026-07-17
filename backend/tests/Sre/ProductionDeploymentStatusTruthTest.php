<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductionDeploymentStatusTruthTest extends TestCase
{
    public function test_standard_stale_sha_fails_closed_before_the_environment_deploy_job(): void
    {
        $source = $this->workflow();
        $eligibility = $this->between($source, '  deployment-eligibility:', '  deploy-production:');
        $deploy = strstr($source, '  deploy-production:') ?: '';

        $this->assertStringNotContainsString('environment:', $eligibility);
        $this->assertStringContainsString('if [ "$DEPLOY_SHA" != "$LATEST_MAIN_SHA" ]', $eligibility);
        $this->assertStringContainsString('Manual standard production deploy refused because expected_release_sha is not latest main.', $eligibility);
        $this->assertStringContainsString('Code-only production deploy refused because expected_release_sha is not reachable from latest main.', $eligibility);
        $this->assertStringContainsString('git merge-base --is-ancestor "$DEPLOY_SHA" "$LATEST_MAIN_SHA"', $eligibility);
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
            'Record production release candidate',
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
        $this->assertStringContainsString('code-only scope refused authority path:', $eligibility);
        $this->assertStringContainsString('code-only scope refused unknown path:', $eligibility);
        $this->assertStringContainsString('generated/*', $eligibility);
        $this->assertStringNotContainsString('docs/seo/*|generated/*', $eligibility);
        $this->assertStringContainsString('docs/codex/*|generated/*)', $eligibility);
        $this->assertStringContainsString('backend/database/*', $eligibility);
        $this->assertStringContainsString('backend/app/Services/Cms/*', $eligibility);
        $this->assertStringContainsString('cumulative deployed-revision diff contains a forbidden or unknown path', $eligibility);
        $this->assertStringContainsString('RESOLVED_DEPLOY_MODE=code_only', $eligibility);
        $this->assertStringContainsString('echo "deploy_mode=$RESOLVED_DEPLOY_MODE" >> "$GITHUB_OUTPUT"', $eligibility);
        $this->assertStringContainsString('DEPLOY_MODE: ${{ needs.deployment-eligibility.outputs.deploy_mode }}', $deploy);
        $this->assertStringContainsString('DEPLOY_TASK=deploy', $deploy);
        $this->assertStringContainsString('DEPLOY_TASK=deploy:code-only', $deploy);
        $this->assertStringContainsString('-o deploy_mode="$DEPLOY_MODE"', $deploy);
        $this->assertStringContainsString("if: \${{ needs.deployment-eligibility.outputs.deploy_mode == 'standard' || needs.deployment-eligibility.outputs.deploy_mode == 'schema_only' }}", $deploy);
        $this->assertStringContainsString('${DEPLOY_MODE} deploy: auth guest POST contract probe intentionally skipped', $deploy);
        $this->assertStringContainsString('Verify mutation-limited deployed baseline before writes', $deploy);
        $this->assertStringContainsString('remote deployed REVISION does not match expected_deployed_revision', $deploy);
        $this->assertStringContainsString('main_commits_not_deployed: ${UNDEPLOYED_COUNT}', $deploy);
    }

    public function test_code_only_lane_allows_only_audited_personality_runtime_projection_services_under_cms(): void
    {
        $workflow = $this->workflow();
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $runtimeExceptions = 'backend/app/Services/Cms/PersonalityPublicAssetReadModelCache.php|backend/app/Services/Cms/PersonalityPublicContentAssetContract.php)';
        $nonRuntimeExceptions = 'backend/.env.example|backend/scripts/pr71_verify.sh)';
        $cmsAuthorityWildcard = 'backend/app/Services/Cms/*';

        $runtimeExceptionsPosition = strpos($eligibility, $runtimeExceptions);
        $nonRuntimeExceptionsPosition = strpos($eligibility, $nonRuntimeExceptions);
        $cmsAuthorityWildcardPosition = strpos($eligibility, $cmsAuthorityWildcard);

        $this->assertNotFalse($runtimeExceptionsPosition);
        $this->assertNotFalse($nonRuntimeExceptionsPosition);
        $this->assertNotFalse($cmsAuthorityWildcardPosition);
        $this->assertLessThan($cmsAuthorityWildcardPosition, $runtimeExceptionsPosition);
        $this->assertSame(1, substr_count($eligibility, $runtimeExceptions));
        $this->assertSame(1, substr_count($eligibility, $nonRuntimeExceptions));
        $this->assertStringContainsString(
            'code-only scope accepted audited personality runtime projection service: $path',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope accepted audited non-runtime release metadata or verification path: $path',
            $eligibility
        );
        $this->assertStringContainsString('code-only scope refused authority path: $path', $eligibility);
    }

    public function test_schema_only_mode_is_latest_main_exact_migration_and_read_only_authority_lane(): void
    {
        $workflow = $this->workflow();
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $deploy = strstr($workflow, '  deploy-production:') ?: '';

        foreach ([
            'schema_only requires one exact approved_migration filename.',
            'MIGRATION_PATH="backend/database/migrations/${APPROVED_MIGRATION}"',
            'cumulative diff must add exactly the approved migration and no other migration',
            'schema-only deployment refused: cumulative deployed-revision diff contains a forbidden or unknown path.',
            'I explicitly approve backend schema-only production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID} migration ${APPROVED_MIGRATION}.',
            'Manual schema_only production deploy refused because expected_release_sha is not latest main.',
        ] as $contract) {
            $this->assertStringContainsString($contract, $eligibility);
        }

        foreach ([
            'DEPLOY_TASK=deploy:schema-only',
            '-o schema_only_migration="$APPROVED_MIGRATION"',
            'Verify schema-only migration and schema state',
            'schema-only post-deploy verification found pending migrations',
            'php artisan fap:schema:verify --no-interaction --no-ansi',
            '${DEPLOY_MODE} deploy: auth guest POST contract probe intentionally skipped',
            'approved_migration: ${APPROVED_MIGRATION}',
        ] as $contract) {
            $this->assertStringContainsString($contract, $deploy);
        }

        $this->assertStringNotContainsString('landing-surfaces:import-local-baseline', $deploy);
        $this->assertStringNotContainsString('content-pages:import-local-baseline', $deploy);
        $this->assertStringNotContainsString('career:warm-public-authority-cache', $deploy);
        $this->assertStringNotContainsString('seo:warm-sitemap-source-cache', $deploy);
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
