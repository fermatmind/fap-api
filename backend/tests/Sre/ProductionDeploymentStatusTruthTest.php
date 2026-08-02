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

    public function test_index52_isolated_candidate_composes_only_staged_read_model_blobs_without_authority_writes(): void
    {
        $workflow = $this->workflow();
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $deployer = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        $codeOnlyStart = strpos($deployer, "task('deploy:code-only', [");
        $this->assertIsInt($codeOnlyStart);
        $codeOnlyEnd = strpos($deployer, ']);', $codeOnlyStart);
        $this->assertIsInt($codeOnlyEnd);
        $codeOnlyTask = substr($deployer, $codeOnlyStart, $codeOnlyEnd - $codeOnlyStart + 3);

        foreach ([
            'INDEX52_CANDIDATE_SHA="9d2877a7e519f768fd741398e76777620770fb71"',
            'INDEX52_ACTIVE_SHA="e7ed3b9e894730ff0f973687eb552337db5c6db9"',
            'INDEX52_STAGED_MAIN_SHA="e9166c5eae03ad13c7ef616b9ca7c528d14bd582"',
            'INDEX52_STAGING_RUN_ID="30268270565"',
            'INDEX52_PATCH_SHA256="2cf768ab7896be204b983c377bce5d1b00771f6a5c2dd62c82d5c265b0748f00"',
            'backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php',
            'backend/app/Services/Cms/Mbti64CrossTypeComparisonPublicReadModel.php',
            'staging evidence accepted: exact isolated INDEX-52 runtime candidate is byte-identical to the staged main read model.',
            'Code-only production deploy accepted exact isolated INDEX-52 runtime candidate with staged-main-byte-identical scoped files.',
        ] as $contract) {
            $this->assertStringContainsString($contract, $eligibility);
        }

        $this->assertStringNotContainsString('artisan:migrate', $codeOnlyTask);
        $this->assertStringNotContainsString('artisan:scales:seed-default', $codeOnlyTask);
        $this->assertStringNotContainsString('cms:import-landing-surface-baselines', $codeOnlyTask);
        $this->assertStringNotContainsString('cms:import-content-page-baselines', $codeOnlyTask);
        $this->assertStringContainsString("'deploy:publish'", $codeOnlyTask);
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
        $this->assertStringContainsString("after('healthcheck:sitemap-source', 'healthcheck:public-dns')", $deployer);
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

    public function test_candidate_only_lane_supports_exact_active_fast_path_without_weakening_activation_guards(): void
    {
        $workflow = $this->workflow();
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $deploy = strstr($workflow, '  deploy-production:') ?: '';
        $deployer = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');

        foreach ([
            'candidate_only',
            'auto|code_only|candidate_only',
            'RESOLVED_DEPLOY_MODE=candidate_only',
            'I explicitly approve bounded backend inactive candidate materialization for exact SHA ${DEPLOY_SHA} using exact staging run ${STAGING_RUN_ID} from active SHA ${EXPECTED_DEPLOYED_REVISION}, excluding all newer main commits, release ${RELEASE_ID}; distinct inactive release path, zero activation.',
            'I explicitly approve backend exact-active inactive candidate materialization for SHA ${DEPLOY_SHA} release ${RELEASE_ID}; same revision, distinct release path, zero activation.',
            'candidate_only accepted an empty diff for the exact-active same-SHA inactive-release fast path.',
            'exact-active candidate_only fast path requires staging_run_id to be empty.',
            'Exact-active candidate_only fast path requires the candidate SHA to equal expected_deployed_revision.',
            'Exact-active candidate_only fast path requires the active SHA to remain reachable from latest main.',
            'Exact-active same-SHA candidate_only fast path accepted without staging evidence.',
            'Inactive candidate materialization requires expected_release_sha to remain reachable from latest main.',
            'Inactive candidate materialization requires exact-SHA successful staging evidence.',
            'Inactive candidate materialization excludes newer main commits from this bounded release.',
            'candidate_only materialization requires the current active revision to be an ancestor of the candidate.',
            'candidate_only materialization refused an unexplained empty active-to-candidate diff.',
            'candidate_only accepted an exact staged main-ancestor artifact without reusing the code-only cumulative path allowlist.',
        ] as $contract) {
            $this->assertStringContainsString($contract, $eligibility);
        }

        $candidateScopeStart = strpos($eligibility, 'if [ "$REQUESTED_DEPLOY_MODE" = candidate_only ]; then');
        $this->assertNotFalse($candidateScopeStart);
        $candidateScopeEnd = strpos($eligibility, "              else\n                CLASSIFICATION_BASE=", (int) $candidateScopeStart);
        $this->assertNotFalse($candidateScopeEnd);
        $candidateScope = substr($eligibility, (int) $candidateScopeStart, (int) $candidateScopeEnd - (int) $candidateScopeStart);
        $this->assertStringNotContainsString('CODE_ONLY_SCOPE', $candidateScope);
        $this->assertStringNotContainsString('backend/database/*', $candidateScope);
        $this->assertStringNotContainsString('backend/app/Services/Cms/*', $candidateScope);

        foreach ([
            'DEPLOY_TASK=deploy:candidate-only',
            '- name: Revalidate bounded inactive candidate identities before writes',
            'Candidate-only pre-write guard requires the control-plane SHA to remain reachable from current main.',
            'Candidate-only pre-write guard requires the candidate SHA to remain an ancestor of the control plane and current main.',
            'Candidate-only pre-write guard requires exact staging evidence for the candidate SHA.',
            '- name: Verify exact inactive candidate materialization',
            'backend.inactive_candidate_materialization.v2',
            'test \"\$current\" != \"\$candidate\"',
            'test \"\$(tr -d \'\r\n\' < \"\$current/REVISION\")\" = \'$EXPECTED_DEPLOYED_REVISION\'',
            'test \"\$(tr -d \'\r\n\' < \"\$candidate/REVISION\")\" = \'$DEPLOY_SHA\'',
            'candidate_activation: false',
            'active_pointer_changed: false',
            'staging_evidence_waived: $exact_active_candidate_fast_path',
            'bounded_staged_ancestor: $bounded_staged_ancestor',
            'newer_main_commits_excluded: $newer_main_commits_excluded',
            'main_sha_at_write: $main_sha_at_write',
            'control_workflow_sha256: $control_workflow_sha256',
            'git show "${WORKFLOW_CONTROL_SHA}:.github/workflows/deploy-production.yml"',
            'cache_write_count: 0',
            'queue_dispatch_count: 0',
            'database_write_count: 0',
            'cms_authority_write_count: 0',
            'publication_write_count: 0',
            'discoverability_write_count: 0',
            'backend-inactive-candidate-${{ github.run_id }}-${{ github.run_attempt }}',
        ] as $contract) {
            $this->assertStringContainsString($contract, $deploy);
        }
        $this->assertLessThan(
            strpos($deploy, '- name: Deploy production with Deployer'),
            strpos($deploy, '- name: Revalidate bounded inactive candidate identities before writes')
        );
        $this->assertTrue(
            strpos($deploy, '- name: Verify mutation-limited deployed baseline before writes')
            < strpos($deploy, '- name: Revalidate bounded inactive candidate identities before writes')
        );

        $start = strpos($deployer, "task('deploy:candidate-only', [");
        $this->assertNotFalse($start);
        $end = strpos($deployer, ']);', (int) $start);
        $this->assertNotFalse($end);
        $task = substr($deployer, (int) $start, (int) $end - (int) $start + 3);
        $this->assertStringContainsString("'fap:deploy-unlock-owned'", $task);
        $this->assertStringNotContainsString('deploy:publish', $task);
        $this->assertStringNotContainsString('deploy:symlink', $task);
        $this->assertStringNotContainsString('artisan:migrate', $task);
        $this->assertStringNotContainsString('career:verify-public-dataset-cache-equivalence', $task);
        $this->assertStringNotContainsString('queue:reload-workers', $task);
    }

    public function test_code_only_lane_allows_only_audited_cms_runtime_and_release_support_exceptions(): void
    {
        $workflow = $this->workflow();
        $deployer = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        $eligibility = $this->between($workflow, '  deployment-eligibility:', '  deploy-production:');
        $runtimeExceptions = 'backend/app/Services/Cms/PersonalityPublicAssetReadModelCache.php|backend/app/Services/Cms/PersonalityPublicContentAssetContract.php)';
        $subsumedRuntimeExceptions = '.github/workflows/career-detail-production-cache-repair.yml|backend/app/Services/Cms/Mbti64CmsInternalLinkDraftWriter.php|backend/app/Services/Cms/MbtiCrossPublisher49ContentService.php|backend/app/Services/Cms/MbtiCrossPublisher49IndexabilityService.php|backend/app/Services/Cms/MbtiCrossPublisher49Package.php|backend/content_assets/personality_public/mbti-cross-approval-48-operator-authorization-r2-2026-07-23.json|backend/content_assets/personality_public/mbti-cross-approval-48-package-2026-07-23.json|docs/04-ops/deploy-incident-runbook.md|docs/seo/career-jobs-index-lkg-resilience-01.md|docs/seo/career-pilot-review-evidence-bridge-01.md)';
        $nonRuntimeExceptions = 'backend/.env.example|backend/scripts/pr71_verify.sh)';
        $releaseSupportExceptions = '.github/workflows/backend-production-release-discovery.yml|.github/workflows/backend-production-verify-only.yml|.github/workflows/backend-production-ops-queue-control.yml|deploy/supervisor/fap-queue-ops.conf.template|AGENTS.md|backend/AGENTS.md|backend/docs/career/job-detail-atomic-exposure.md|backend/scripts/deploy/verify_scale_lookup.sh|docs/operations/generated/solo-owner-review-surface-registry.v1.json|docs/operations/solo-owner-review-protocol.md|docs/ops/release-train.md)';
        $cmsAuthorityWildcard = 'backend/app/Services/Cms/*';

        $runtimeExceptionsPosition = strpos($eligibility, $runtimeExceptions);
        $subsumedRuntimeExceptionsPosition = strpos($eligibility, $subsumedRuntimeExceptions);
        $nonRuntimeExceptionsPosition = strpos($eligibility, $nonRuntimeExceptions);
        $releaseSupportExceptionsPosition = strpos($eligibility, $releaseSupportExceptions);
        $cmsAuthorityWildcardPosition = strpos($eligibility, $cmsAuthorityWildcard);

        $this->assertNotFalse($runtimeExceptionsPosition);
        $this->assertNotFalse($subsumedRuntimeExceptionsPosition);
        $this->assertNotFalse($nonRuntimeExceptionsPosition);
        $this->assertNotFalse($releaseSupportExceptionsPosition);
        $this->assertNotFalse($cmsAuthorityWildcardPosition);
        $this->assertLessThan($cmsAuthorityWildcardPosition, $runtimeExceptionsPosition);
        $this->assertLessThan($cmsAuthorityWildcardPosition, $subsumedRuntimeExceptionsPosition);
        $this->assertStringContainsString(
            'backend/docs/career/publish_track_reconciliation.json)',
            $eligibility
        );
        $this->assertStringContainsString('REQUIRE_CAREER_CANDIDATE_PREFLIGHT=true', $eligibility);
        $this->assertStringContainsString(
            'EXPECTED_CAREER_RECONCILIATION_SHA256="98880c3de1473e1dd9ff2466e256a888ccad3620540ad7d42b19d556cefff184"',
            $eligibility
        );
        $this->assertStringContainsString(
            'EXPECTED_CAREER_PUBLIC_CACHE_SUMMARY_SHA256="53e4e854dadee2260637c0288ead58818808bad098c3fce8e2168ea38746ba09"',
            $eligibility
        );
        $this->assertStringContainsString(
            'AUDITED_STALE_CAREER_PUBLIC_CACHE_SUMMARY_SHA256="cb248a673e4827a4dcaae3f41f74cffd50d99f73984286cb733e6ba0b935158b"',
            $eligibility
        );
        $this->assertStringContainsString('CAREER_CACHE_REPAIR_REQUIRED=true', $eligibility);
        $this->assertStringContainsString(
            'pending atomic candidate repair and rollback protection.',
            $eligibility
        );
        $this->assertStringContainsString(
            'backend/scripts/deploy/career_candidate_exact_cache_bootstrap.php',
            $eligibility
        );
        $this->assertStringContainsString(
            'backend/app/Console/Commands/CareerVerifyPublicDatasetCacheEquivalence.php',
            $eligibility
        );
        $this->assertStringContainsString(
            'EXPECTED_CAREER_EQUIVALENCE_VERIFIER_SHA256="e02a12517b69de48eab515d8ea9f48dadf6bb30345cfba0f99d0538d89b0de30"',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed Career dataset equivalence verifier hash.',
            $eligibility
        );
        $this->assertStringContainsString(
            'EXPECTED_CAREER_BOOTSTRAP_RUNNER_SHA256="603d5d7f1d53057903ec76baad238a818c8ecefbd0d00f0f312e01d57068de86"',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed Career candidate bootstrap runner hash.',
            $eligibility
        );
        $this->assertStringContainsString(
            'backend/scripts/deploy/verify_sitemap_source_cache_warm.sh',
            $eligibility
        );
        $this->assertStringContainsString(
            'EXPECTED_SITEMAP_WARM_VERIFIER_SHA256="04f9d6b6b66b0be10a4996064cdf9150e1b0f1ec300edf93f87e8c0368ea0713"',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed sitemap source-cache warm verifier hash.',
            $eligibility
        );
        $this->assertStringContainsString(
            'backend/scripts/deploy/immutable_candidate_sitemap_control.php',
            $eligibility
        );
        $this->assertStringContainsString(
            'EXPECTED_IMMUTABLE_SITEMAP_CONTROL_SHA256="5111aac8197ba8df85698eab2a199475baab7ef7456db01dfb698ed54a928dcf"',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed immutable sitemap control hash.',
            $eligibility
        );
        $this->assertStringContainsString(
            'backend/scripts/seo/seo_13_article_atomic_promotion_production_ops.sh',
            $eligibility
        );
        $this->assertStringContainsString(
            'EXPECTED_SEO13_PREFLIGHT_RUNNER_SHA256="ceab5aa7d97a27f2d9d5d8f803897296baf00dade2387cd4e59a77884bb0ad26"',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed SEO13 preflight runner hash.',
            $eligibility
        );
        $this->assertSame(
            'ceab5aa7d97a27f2d9d5d8f803897296baf00dade2387cd4e59a77884bb0ad26',
            hash_file(
                'sha256',
                dirname(__DIR__, 3).'/backend/scripts/seo/seo_13_article_atomic_promotion_production_ops.sh'
            )
        );
        $this->assertStringContainsString(
            'code-only scope classification accepted the exact audited Runtime 46 subsumed baseline.',
            $eligibility
        );
        $this->assertStringContainsString(
            'git fetch --no-tags origin "$EXPECTED_DEPLOYED_REVISION"',
            $eligibility
        );
        $this->assertStringContainsString(
            'actual_runtime46_diff="$(git diff --no-renames --name-status "$CLASSIFICATION_BASE" "$EXPECTED_DEPLOYED_REVISION")"',
            $eligibility
        );
        $this->assertStringContainsString(
            '$(git rev-parse "${DEPLOY_SHA}:${runtime46_path}")',
            $eligibility
        );
        $this->assertSame(1, substr_count($eligibility, $runtimeExceptions));
        $this->assertSame(1, substr_count($eligibility, $subsumedRuntimeExceptions));
        $this->assertSame(1, substr_count($eligibility, $nonRuntimeExceptions));
        $this->assertSame(1, substr_count($eligibility, $releaseSupportExceptions));
        $this->assertStringContainsString(
            'code-only scope accepted audited personality runtime projection service: $path',
            $eligibility
        );
        $this->assertStringContainsString(
            'code-only scope accepted exact audited subsumed runtime or inert authority input path: $path',
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
        $this->assertStringContainsString(
            'REQUIRE_CAREER_CANDIDATE_PREFLIGHT: ${{ needs.deployment-eligibility.outputs.require_career_candidate_preflight }}',
            $workflow
        );
        $this->assertStringContainsString(
            '-o career_public_cache_summary_sha256="$CAREER_PUBLIC_CACHE_SUMMARY_SHA256"',
            $workflow
        );
        $this->assertStringContainsString(
            '-o career_expected_candidate_summary_sha256="$CAREER_EXPECTED_CANDIDATE_SUMMARY_SHA256"',
            $workflow
        );
        $this->assertStringContainsString(
            '-o career_cache_repair_required="$CAREER_CACHE_REPAIR_REQUIRED"',
            $workflow
        );
        $this->assertStringContainsString(
            '--verify-live-public-cache%s --json --no-interaction --ansi',
            $deployer
        );
        $this->assertStringContainsString('--repair-live-public-cache', $deployer);
        $this->assertStringContainsString('--rollback-repair', $deployer);
        $this->assertStringContainsString('--finalize-repair', $deployer);
        $revalidationPosition = strpos(
            $workflow,
            '- name: Revalidate live Career public-cache summary before activation'
        );
        $deployerPosition = strpos($workflow, '- name: Deploy production with Deployer');
        $this->assertNotFalse($revalidationPosition);
        $this->assertNotFalse($deployerPosition);
        $this->assertLessThan($deployerPosition, $revalidationPosition);
        $this->assertStringContainsString(
            'Career public-cache summary drifted after eligibility; refusing activation.',
            $workflow
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($workflow, 'https://api.fermatmind.com/api/v0.5/career/datasets/occupations')
        );
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
