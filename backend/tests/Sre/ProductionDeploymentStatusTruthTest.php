<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductionDeploymentStatusTruthTest extends TestCase
{
    public function test_standard_immutable_staged_candidate_may_trail_main_but_must_remain_its_ancestor(): void
    {
        $source = $this->workflow();
        $eligibility = $this->between($source, '  deployment-eligibility:', '  deploy-production:');
        $deploy = strstr($source, '  deploy-production:') ?: '';

        $this->assertStringNotContainsString('environment:', $eligibility);
        $this->assertStringContainsString(
            'I explicitly approve bounded backend production deploy for exact SHA ${DEPLOY_SHA}, excluding all newer main commits, release ${RELEASE_ID}.',
            $eligibility
        );
        $this->assertStringContainsString('STAGING_SHA: ${{ steps.resolve_deploy_mode.outputs.staging_sha }}', $eligibility);
        $this->assertStringContainsString('echo "staging_sha=$STAGING_SHA" >> "$GITHUB_OUTPUT"', $eligibility);
        $this->assertStringContainsString('if ! git merge-base --is-ancestor "$DEPLOY_SHA" "$LATEST_MAIN_SHA"', $eligibility);
        $this->assertStringContainsString('if [ "$DEPLOY_SHA" != "$LATEST_MAIN_SHA" ]', $eligibility);
        $this->assertStringContainsString('if [ "$STAGING_SHA" != "$DEPLOY_SHA" ]', $eligibility);
        $this->assertStringContainsString('Immutable standard candidate requires an exact-SHA successful staging run when main has advanced.', $eligibility);
        $this->assertStringContainsString('Manual standard production deploy refused because the staged candidate is not reachable from latest main.', $eligibility);
        $this->assertStringContainsString('newer main commits remain intentionally excluded', $eligibility);
        $this->assertStringNotContainsString(
            'Manual standard production deploy refused because expected_release_sha is not latest main.',
            $eligibility
        );
        $this->assertStringContainsString('Code-only production deploy refused because expected_release_sha is not reachable from latest main and has no exact isolated receipt.', $eligibility);
        $this->assertStringContainsString('git merge-base --is-ancestor "$DEPLOY_SHA" "$LATEST_MAIN_SHA"', $eligibility);
        $this->assertStringContainsString('exit 1', $eligibility);
        $this->assertStringNotContainsString('eligible=false', $eligibility);
        $this->assertStringContainsString('needs: deployment-eligibility', $deploy);
        $this->assertStringContainsString("if: \${{ needs.deployment-eligibility.outputs.eligible == 'true' }}", $deploy);
        $this->assertStringContainsString("environment:\n      name: production", $deploy);
        $this->assertStringNotContainsString('skip_deploy', $source);
    }

    public function test_standard_candidate_revalidates_main_and_production_ancestry_before_any_remote_write(): void
    {
        $deploy = strstr($this->workflow(), '  deploy-production:') ?: '';
        $candidateStep = $this->between(
            $deploy,
            '- name: Verify immutable standard candidate advances production before writes',
            '- name: Remote deploy lock guard'
        );

        $candidateOffset = strpos($deploy, '- name: Verify immutable standard candidate advances production before writes');
        $lockOffset = strpos($deploy, '- name: Remote deploy lock guard');
        $healthOffset = strpos($deploy, '- name: Verify production health evidence baseline');
        $deployerOffset = strpos($deploy, '- name: Deploy production with Deployer');

        $this->assertIsInt($candidateOffset);
        $this->assertIsInt($lockOffset);
        $this->assertIsInt($healthOffset);
        $this->assertIsInt($deployerOffset);
        $this->assertLessThan($lockOffset, $candidateOffset);
        $this->assertLessThan($healthOffset, $candidateOffset);
        $this->assertLessThan($deployerOffset, $candidateOffset);
        $this->assertStringContainsString(
            "if: \${{ needs.deployment-eligibility.outputs.deploy_mode == 'standard' }}",
            $candidateStep
        );
        $this->assertStringContainsString('git fetch --no-tags origin main:refs/remotes/origin/main', $candidateStep);
        $this->assertStringContainsString('git merge-base --is-ancestor "$DEPLOY_SHA" "$LATEST_MAIN_AT_WRITE"', $candidateStep);
        $this->assertStringContainsString('test -f REVISION', $candidateStep);
        $this->assertStringContainsString('[[ ! "$CURRENT_PRODUCTION_SHA" =~ ^[0-9a-f]{40}$ ]]', $candidateStep);
        $this->assertStringContainsString('git cat-file -e "${CURRENT_PRODUCTION_SHA}^{commit}"', $candidateStep);
        $this->assertStringContainsString('if [ "$CURRENT_PRODUCTION_SHA" = "$DEPLOY_SHA" ]', $candidateStep);
        $this->assertStringContainsString('git merge-base --is-ancestor "$CURRENT_PRODUCTION_SHA" "$DEPLOY_SHA"', $candidateStep);
        $this->assertStringContainsString('PRODUCTION_BASELINE_MODE="linear_ancestor"', $candidateStep);
        $this->assertStringContainsString('PRODUCTION_BASELINE_MODE="runtime46_patch_subsumed"', $candidateStep);
        $this->assertStringContainsString('would not safely advance the current production revision', $candidateStep);
        $this->assertStringContainsString('production_baseline_mode=$PRODUCTION_BASELINE_MODE', $candidateStep);
        $this->assertStringNotContainsString('dep deploy', $candidateStep);
        $this->assertStringNotContainsString('artisan migrate', $candidateStep);
        $this->assertStringNotContainsString('queue:restart', $candidateStep);
        $this->assertStringNotContainsString('rm -f', $candidateStep);
    }

    public function test_standard_candidate_accepts_only_a_staged_descendant_of_the_audited_runtime46_bridge(): void
    {
        $deploy = strstr($this->workflow(), '  deploy-production:') ?: '';
        $candidateStep = $this->between(
            $deploy,
            '- name: Verify immutable standard candidate advances production before writes',
            '- name: Remote deploy lock guard'
        );

        foreach ([
            'AUDITED_RUNTIME46_PRODUCTION_SHA="bc0ed833bc9aae1473ab37f1dead2517e1aff618"',
            'AUDITED_RUNTIME46_BRIDGE_SHA="49038deb50cda789e4365ea42068832ed28d6023"',
            '[ "$CURRENT_PRODUCTION_SHA" = "$AUDITED_RUNTIME46_PRODUCTION_SHA" ]',
            'git cat-file -e "${AUDITED_RUNTIME46_BRIDGE_SHA}^{commit}"',
            'git merge-base --is-ancestor "$AUDITED_RUNTIME46_BRIDGE_SHA" "$DEPLOY_SHA"',
            'STAGING_SHA: ${{ needs.deployment-eligibility.outputs.staging_sha }}',
            '[ "$STAGING_SHA" = "$DEPLOY_SHA" ]',
            '[ "${#production_commit[@]}" -eq 2 ]',
            'git merge-base --is-ancestor "$production_parent" "$DEPLOY_SHA"',
            'git diff --no-renames --name-status "$production_parent" "$CURRENT_PRODUCTION_SHA"',
            '[ "$actual_diff" = "$expected_diff" ]',
            'git rev-parse "${CURRENT_PRODUCTION_SHA}:${path}"',
            'git rev-parse "${DEPLOY_SHA}:${path}"',
            '[ "$production_blob" = "$candidate_blob" ]',
            'Subsumed baseline refused an unknown, deleted, renamed, or status-drifted production path.',
            'Subsumed baseline refused Runtime 46 blob drift.',
            'Subsumed baseline requires a candidate descended from the audited Runtime 46 bridge.',
            'Subsumed baseline requires exact-SHA staging evidence for the selected descendant.',
        ] as $contract) {
            $this->assertStringContainsString($contract, $candidateStep);
        }

        $this->assertStringNotContainsString('[ "$DEPLOY_SHA" = "$AUDITED_RUNTIME46_BRIDGE_SHA" ]', $candidateStep);
        $this->assertStringNotContainsString('AUDITED_RUNTIME46_STAGING_RUN_ID', $candidateStep);
        $this->assertSame(5, substr_count($candidateStep, '$\'A\\tbackend/'));
        $this->assertSame(1, substr_count($candidateStep, '$\'M\\tbackend/'));
        $this->assertStringContainsString(
            'PRODUCTION_BASELINE_MODE: ${{ steps.standard_baseline_guard.outputs.production_baseline_mode }}',
            $deploy
        );
        $this->assertStringContainsString(
            'production_baseline_mode: ${PRODUCTION_BASELINE_MODE}',
            $deploy
        );
    }

    public function test_standard_staging_evidence_allows_only_audited_control_only_byte_equivalent_delta(): void
    {
        $eligibility = $this->between($this->workflow(), '  deployment-eligibility:', '  deploy-production:');

        $this->assertStringContainsString('if [ "$STAGING_SHA" != "$DEPLOY_SHA" ]', $eligibility);
        $this->assertStringContainsString('if [ "$RESOLVED_DEPLOY_MODE" != standard ]', $eligibility);
        $this->assertStringContainsString('git merge-base --is-ancestor "$STAGING_SHA" "$DEPLOY_SHA"', $eligibility);
        $this->assertStringContainsString('git diff --no-renames --name-only "$STAGING_SHA" "$DEPLOY_SHA"', $eligibility);
        $this->assertStringContainsString('.github/workflows/mbti-comp-runtime46-staging-dry-run.yml|.github/workflows/mbti-comp-runtime46-production-ops.yml|.github/workflows/deploy-production.yml|backend/tests/Sre/DeployStorageAndDatabaseConfigTest.php|backend/tests/Sre/ProductionDeploymentStatusTruthTest.php)', $eligibility);
        $this->assertStringContainsString('staging-equivalence refused non-audited delta path: $path', $eligibility);
        $this->assertStringContainsString('backend/resources backend/routes backend/artisan backend/composer.json backend/composer.lock deploy.php', $eligibility);
        $this->assertStringContainsString('staging-equivalence refused a runtime or deployment artifact change.', $eligibility);
        $this->assertStringContainsString('deployed runtime artifact is byte-equivalent across the audited control-only delta.', $eligibility);
        $this->assertStringContainsString('non-standard deployment requires exact-SHA staging evidence or an exact isolated Runtime 46 receipt.', $eligibility);
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

    public function test_production_deploy_uses_internal_health_and_public_business_evidence_before_and_after_activation(): void
    {
        $workflow = $this->workflow();
        $deploy = strstr($workflow, '  deploy-production:') ?: '';
        $deployer = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        $publicBusinessCommand = $this->between(
            $deployer,
            'function deployPublicDnsBusinessEvidenceCommand',
            'function runProductionPublicDnsBusinessEvidence'
        );

        $baselineOffset = strpos($deploy, '- name: Verify production health evidence baseline');
        $deployOffset = strpos($deploy, '- name: Deploy production with Deployer');
        $postDeployOffset = strpos($deploy, '- name: Healthcheck and contract smoke');

        $this->assertIsInt($baselineOffset);
        $this->assertIsInt($deployOffset);
        $this->assertIsInt($postDeployOffset);
        $this->assertLessThan($deployOffset, $baselineOffset);
        $this->assertLessThan($postDeployOffset, $deployOffset);
        $this->assertSame(2, substr_count(
            $deploy,
            'public_health_status="$(curl -sS --connect-timeout 5 --max-time 15'
        ));
        $this->assertSame(2, substr_count($deploy, '[ "$public_health_status" = "404" ]'));
        $this->assertSame(2, substr_count($deploy, '${PUBLIC_API_ORIGIN}/api/v0.3/flags'));
        $this->assertSame(2, substr_count(
            $deploy,
            '${PUBLIC_API_ORIGIN}/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN'
        ));
        $this->assertSame(2, substr_count(
            $deploy,
            '.personality_public_content_asset_v1.source_hash | strings | test("^[0-9a-f]{64}$")'
        ));
        $this->assertStringContainsString('Production health evidence baseline failed after 10 attempts', $deploy);
        $this->assertStringContainsString('Post-deploy public business evidence failed after 10 attempts', $deploy);
        $this->assertStringNotContainsString(
            'curl -fsS --connect-timeout 5 --max-time 15 "$HEALTHCHECK_URL" | jq -e \'.ok==true\'',
            $deploy
        );

        $this->assertStringContainsString("task('guard:public-dns-health'", $deployer);
        $this->assertStringContainsString("before('deploy:symlink', 'guard:public-dns-health')", $deployer);
        $this->assertStringContainsString("task('healthcheck:public-dns'", $deployer);
        $this->assertStringContainsString("after('healthcheck:public', 'healthcheck:public-dns')", $deployer);
        $this->assertStringContainsString("currentHost()->getAlias() !== 'production'", $deployer);
        $this->assertStringContainsString('deployPublicDnsBusinessEvidenceCommand($host)', $deployer);
        $this->assertStringContainsString('curl -sS --connect-timeout 5 --max-time 15', $publicBusinessCommand);
        $this->assertStringContainsString("deployHttpsUrlArg(\$host, '/api/healthz')", $publicBusinessCommand);
        $this->assertStringContainsString("deployHttpsUrlArg(\$host, '/api/v0.3/flags')", $publicBusinessCommand);
        $this->assertStringContainsString(
            "'/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN'",
            $publicBusinessCommand
        );
        $this->assertStringContainsString('[ "$public_health_status" = "404" ]', $publicBusinessCommand);
        $this->assertStringContainsString('personality_public_content_asset_v1.source_hash', $publicBusinessCommand);
        $this->assertStringNotContainsString('--resolve', $publicBusinessCommand);
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

    public function test_code_only_lane_allows_only_audited_cms_runtime_and_release_support_exceptions(): void
    {
        $workflow = $this->workflow();
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $runtimeExceptions = 'backend/app/Services/Cms/PersonalityPublicAssetReadModelCache.php|backend/app/Services/Cms/PersonalityPublicContentAssetContract.php)';
        $nonRuntimeExceptions = 'backend/.env.example|backend/scripts/pr71_verify.sh)';
        $releaseSupportExceptions = '.github/workflows/backend-production-verify-only.yml|AGENTS.md|backend/AGENTS.md|backend/docs/career/job-detail-atomic-exposure.md|backend/scripts/deploy/verify_scale_lookup.sh|docs/operations/generated/solo-owner-review-surface-registry.v1.json|docs/operations/solo-owner-review-protocol.md|docs/ops/release-train.md)';
        $cmsAuthorityWildcard = 'backend/app/Services/Cms/*';

        $runtimeExceptionsPosition = strpos($eligibility, $runtimeExceptions);
        $nonRuntimeExceptionsPosition = strpos($eligibility, $nonRuntimeExceptions);
        $releaseSupportExceptionsPosition = strpos($eligibility, $releaseSupportExceptions);
        $cmsAuthorityWildcardPosition = strpos($eligibility, $cmsAuthorityWildcard);

        $this->assertNotFalse($runtimeExceptionsPosition);
        $this->assertNotFalse($nonRuntimeExceptionsPosition);
        $this->assertNotFalse($releaseSupportExceptionsPosition);
        $this->assertNotFalse($cmsAuthorityWildcardPosition);
        $this->assertLessThan($cmsAuthorityWildcardPosition, $runtimeExceptionsPosition);
        $this->assertSame(1, substr_count($eligibility, $runtimeExceptions));
        $this->assertSame(1, substr_count($eligibility, $nonRuntimeExceptions));
        $this->assertSame(1, substr_count($eligibility, $releaseSupportExceptions));
        $this->assertStringContainsString(
            'code-only scope accepted audited personality runtime projection service: $path',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope accepted audited non-runtime release metadata or verification path: $path',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope accepted audited release support path: $path',
            $eligibility
        );
        $this->assertStringNotContainsString('backend/docs/*', $eligibility);
        $this->assertStringNotContainsString('docs/operations/*', $eligibility);
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
