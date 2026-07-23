<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DeployStorageAndDatabaseConfigTest extends TestCase
{
    #[Test]
    public function staging_deploy_preflights_queue_capability_before_release_activation(): void
    {
        $deployer = $this->readRepoFile('deploy.php');
        $workflow = $this->readRepoFile('.github/workflows/deploy.yml');

        $this->assertStringContainsString("set('queue_reload_required', true);", $deployer);
        $this->assertStringContainsString("->set('queue_reload_required', false)", $deployer);
        $this->assertStringContainsString("task('guard:queue-reload-capability'", $deployer);
        $this->assertStringContainsString(
            "before('deploy:symlink', 'guard:queue-reload-capability')",
            $deployer,
        );
        $this->assertStringContainsString(
            'staging has unmanaged Laravel queue workers; configure a queue manager before deployment',
            $deployer,
        );
        $this->assertStringContainsString(
            'Skip queue worker reload for the explicit no-worker staging topology',
            $deployer,
        );

        $preflightOffset = strpos($workflow, '- name: Queue reload capability preflight');
        $lockOffset = strpos($workflow, '- name: Remote deploy lock guard');
        $deployOffset = strpos($workflow, '- name: Deploy (Deployer)');
        $this->assertIsInt($preflightOffset);
        $this->assertIsInt($lockOffset);
        $this->assertIsInt($deployOffset);
        $this->assertLessThan($lockOffset, $preflightOffset);
        $this->assertLessThan($deployOffset, $lockOffset);
        $this->assertStringNotContainsString('- name: Restart queue workers (Supervisor)', $workflow);
    }

    #[Test]
    public function staging_deploy_logs_keep_operational_topology_redacted(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/deploy.yml');

        foreach ([
            'DEPLOY_USER: ${{ secrets.STAGING_DEPLOY_USER }}',
            'DEPLOY_PORT: ${{ secrets.STAGING_DEPLOY_PORT }}',
            'DEPLOY_HOST: ${{ secrets.STAGING_DEPLOY_HOST }}',
            'DEPLOY_PATH: ${{ secrets.STAGING_DEPLOY_PATH }}',
            'HEALTHCHECK_URL: ${{ secrets.STAGING_HEALTHCHECK_URL }}',
            'AUTH_GUEST_CHECK_URL: ${{ secrets.STAGING_AUTH_GUEST_CHECK_URL }}',
            'OPS_HOST: ${{ secrets.STAGING_OPS_HOST }}',
            'Protected staging topology validation passed without disclosing values.',
            '- name: Set up SSH agent without key metadata output',
            'printf \'%s\\n\' "$SSH_PRIVATE_KEY" | ssh-add - >/dev/null 2>&1',
            'ssh-add -l >/dev/null 2>&1',
            '- name: Stop SSH agent',
            'ssh-agent -k >/dev/null 2>&1 || true',
            'SSH and deploy-root preflight passed without disclosing topology.',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }

        foreach ([
            'DEPLOY_HOST_STG: "',
            'DEPLOY_PATH_STG: "',
            'HEALTHCHECK_URL_STG: "',
            'AUTH_GUEST_CHECK_URL_STG: "',
            'staging.fermatmind.com',
            'whoami; hostname; id',
            'echo "SSH=',
            'echo "DEPLOY_PATH=',
            'echo "HEALTHCHECK_URL=',
            'echo "AUTH_GUEST_CHECK_URL=',
            'sed -n \'1,120p\' "$META"',
            'echo "$ACTIVE_PROCESSES"',
            '-vvv --no-interaction',
            'webfactory/ssh-agent@',
            '- name: Debug SSH agent (fingerprints only)',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }
    }

    #[Test]
    public function mysql_connection_keeps_ssl_ca_option_configurable(): void
    {
        $source = $this->readRepoFile('backend/config/database.php');
        $mysqlBlock = $this->extractArrayBlock($source, "'mysql' => [");

        $this->assertStringContainsString("'options' => extension_loaded('pdo_mysql')", $mysqlBlock);
        $this->assertStringContainsString('MYSQL_ATTR_SSL_CA', $mysqlBlock);
        $this->assertStringContainsString("env('MYSQL_ATTR_SSL_CA')", $mysqlBlock);
    }

    #[Test]
    public function deploy_keeps_artifact_parent_dirs_group_writable_without_rewriting_artifacts_tree(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString('ensureOwnedWritableDir("{$base}/app", $owner, \'www-data\');', $source);
        $this->assertStringContainsString('ensureOwnedWritableDir("{$base}/app/private", $owner, \'www-data\');', $source);
        $this->assertStringContainsString('ensureOwnedWritableDir("{$base}/app/private/artifacts", $owner, \'www-data\');', $source);
        $this->assertStringNotContainsString('ensureOwnedWritableTree(deploySharedPath($base, \'shared/backend/storage/app/private/artifacts\')', $source);
        $this->assertDoesNotMatchRegularExpression('/chmod\s+(?:0?777|a\+w|ugo\+rwX)/', $source);
    }

    #[Test]
    public function deploy_keeps_shared_runtime_roots_writable_without_rewriting_historical_trees(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString(
            "'shared/backend/storage/app/private/career_release_ledger'",
            $source,
        );
        $this->assertStringContainsString(
            "'shared/backend/storage/app/private/career_runtime_publish_projection'",
            $source,
        );
        $this->assertStringContainsString(
            "ensureOwnedWritableDir(deploySharedPath(\$base, \$relativePath), \$owner, 'www-data');",
            $source,
        );
        $this->assertStringContainsString(
            "ensureOwnedWritableDir(deploySharedPath(\$base, 'shared/content_packages'), \$owner, 'www-data');",
            $source,
        );
        $this->assertStringNotContainsString(
            "ensureOwnedWritableTree(deploySharedPath(\$base, \$relativePath), \$owner, 'www-data');",
            $source,
        );
        $this->assertStringNotContainsString(
            "ensureOwnedWritableTree(deploySharedPath(\$base, 'shared/content_packages'), \$owner, 'www-data');",
            $source,
        );
    }

    #[Test]
    public function sitemap_cache_warm_uses_the_php_fpm_identity_for_shared_file_cache_writes(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString(
            'sudo -n -u www-data -- {{bin/php}} %s seo:warm-sitemap-source-cache',
            $source,
        );
    }

    #[Test]
    public function failed_deploy_unlock_passes_runner_identity_to_the_remote_verifier_explicitly(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString("\$expectedRunId = \$argv[2] ?? '';", $source);
        $this->assertStringContainsString("\$expectedRunAttempt = \$argv[3] ?? '';", $source);
        $this->assertStringContainsString(".' '.deployShellArg(\$runId)", $source);
        $this->assertStringContainsString(".' '.deployShellArg(\$runAttempt)", $source);
        $this->assertStringNotContainsString("\$expectedRunId = getenv('DEPLOY_LOCK_RUN_ID')", $source);
        $this->assertStringNotContainsString("\$expectedRunAttempt = getenv('DEPLOY_LOCK_RUN_ATTEMPT')", $source);
    }

    #[Test]
    public function deploy_nginx_static_media_route_skips_when_static_location_already_exists(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString('static_route_action=install', $source);
        $this->assertStringContainsString('skip_existing_static_location', $source);
        $this->assertStringNotContainsString('function currentNginxConfigHasStaticLocation(): bool', $source);
        $this->assertStringNotContainsString("shell_exec('sudo -n nginx -T 2>/dev/null')", $source);
        $this->assertStringNotContainsString('existing /static/ location found in current nginx config', $source);
        $this->assertStringContainsString('function nginxIncludePaths(string $content): array', $source);
        $this->assertStringContainsString("\$includePath = '/etc/nginx/'.ltrim(\$includePath, '/');", $source);
        $this->assertStringContainsString('glob($includePath, GLOB_NOSORT)', $source);
        $this->assertStringContainsString('readableIncludeHasStaticLocation(string $content, array $seen = [])', $source);
        $this->assertStringContainsString('existing /static/ location found in nginx site', $source);
        $this->assertStringContainsString('existing /static/ location found in included nginx file', $source);
        $this->assertStringContainsString('existing /static/ route detected; skipping managed snippet install', $source);
        $this->assertStringContainsString('mktemp /tmp/fap-api-nginx-site-backup.XXXXXX.conf', $source);
        $this->assertStringContainsString('sudo -n rm -f "$site_backup" "$snippet_backup"', $source);
        $this->assertStringNotContainsString('mktemp /etc/nginx/sites-enabled', $source);
    }

    #[Test]
    public function production_deploy_is_manual_only_and_staging_success_cannot_trigger_it(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');
        $triggerStart = strpos($source, "on:\n");
        $permissionsStart = strpos($source, "\npermissions:");

        $this->assertIsInt($triggerStart);
        $this->assertIsInt($permissionsStart);
        $triggerBlock = substr(
            $source,
            $triggerStart,
            $permissionsStart - $triggerStart
        );

        $this->assertStringContainsString('workflow_dispatch:', $triggerBlock);
        $this->assertStringNotContainsString('workflow_run:', $triggerBlock);
        $this->assertStringNotContainsString('push:', $triggerBlock);
        $this->assertStringNotContainsString('pull_request:', $triggerBlock);
        $this->assertStringNotContainsString('github.event.workflow_run', $source);
        $this->assertStringNotContainsString('auto_deploy_policy_guard', $source);
        $this->assertStringNotContainsString('auto-{0}', $source);
        $this->assertStringContainsString('DEPLOY_SHA: ${{ inputs.expected_release_sha }}', $source);
        $this->assertStringContainsString('RELEASE_ID: ${{ inputs.release_id }}', $source);
        $this->assertStringContainsString('Confirm approved release satisfies deployment mode policy', $source);
        $this->assertStringContainsString('if ! git merge-base --is-ancestor "$DEPLOY_SHA" "$LATEST_MAIN_SHA"; then', $source);
        $this->assertStringContainsString('Manual standard production deploy refused because the staged candidate is not reachable from latest main.', $source);
        $this->assertStringContainsString('--revision "$DEPLOY_SHA"', $source);
        $this->assertStringContainsString("tr -d '\\r\\n' < REVISION", $source);
    }

    #[Test]
    public function manual_production_deploy_requires_exact_approval_and_matching_staging_evidence(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        $this->assertStringContainsString('expected_release_sha:', $source);
        $this->assertStringContainsString('staging_run_id:', $source);
        $this->assertStringContainsString('release_id:', $source);
        $this->assertStringContainsString('operator_approval_phrase:', $source);
        $this->assertStringContainsString('deploy_mode:', $source);
        $this->assertStringContainsString('expected_deployed_revision:', $source);
        $this->assertStringContainsString('approved_migration:', $source);
        $this->assertStringContainsString('default: auto', $source);
        $this->assertSame(4, substr_count($source, 'required: true'));
        $this->assertStringContainsString('actions: read', $source);
        $this->assertStringContainsString('Validate manual exact-SHA approval and staging evidence', $source);
        $this->assertStringNotContainsString("if: github.event_name == 'workflow_dispatch'", $source);
        $this->assertStringContainsString('[[ ! "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]]', $source);
        $this->assertStringContainsString('[[ ! "$STAGING_RUN_ID" =~ ^[0-9]+$ ]]', $source);
        $this->assertStringContainsString('[[ ! "$RELEASE_ID" =~ ^[A-Za-z0-9._-]{1,80}$ ]]', $source);
        $this->assertStringContainsString('I explicitly approve bounded backend production deploy for exact SHA ${DEPLOY_SHA}, excluding all newer main commits, release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('I explicitly approve backend code-only production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('I explicitly approve backend schema-only production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID} migration ${APPROVED_MIGRATION}.', $source);
        $this->assertStringContainsString('expected_deployed_revision as a lowercase 40-character deployed REVISION', $source);
        $this->assertStringContainsString('.name == "Deploy Application"', $source);
        $this->assertStringContainsString('.path == ".github/workflows/deploy.yml"', $source);
        $this->assertStringContainsString('.head_branch == "main"', $source);
        $this->assertStringContainsString('STAGING_SHA="$(jq -r \'.head_sha\' <<<"$RUN_JSON")"', $source);
        $this->assertStringContainsString('staging evidence does not cover the exact deploy artifact.', $source);
        $this->assertStringContainsString('staging-equivalence refused a runtime or deployment artifact change.', $source);
        $this->assertStringContainsString('.name == "Deploy checks (staging)" and .conclusion == "success"', $source);
        $this->assertStringContainsString('.name == "Deploy (staging)" and .conclusion == "success"', $source);
        $this->assertStringContainsString('Manual standard production deploy refused because the staged candidate is not reachable from latest main.', $source);
        $this->assertStringContainsString('Code-only production deploy refused because expected_release_sha is not reachable from latest main and has no exact isolated receipt.', $source);
        $this->assertStringContainsString('-o release_name="${RELEASE_ID}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"', $source);
        $this->assertLessThan(
            strpos($source, '- name: Deploy production with Deployer'),
            strpos($source, '- name: Validate manual exact-SHA approval and staging evidence')
        );
    }

    #[Test]
    public function deploy_application_manual_entry_is_staging_only(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy.yml');

        $this->assertStringContainsString('workflow_dispatch:', $source);
        $this->assertStringContainsString('TARGET: staging', $source);
        $this->assertStringContainsString('test "$TARGET" = "staging"', $source);
        $this->assertStringNotContainsString('DEPLOY_HOST_PROD', $source);
        $this->assertStringNotContainsString('- production', $source);
    }

    #[Test]
    public function deploy_application_supports_only_the_exact_receipted_runtime46_isolated_candidate(): void
    {
        $staging = $this->readRepoFile('.github/workflows/deploy.yml');
        $production = $this->readRepoFile('.github/workflows/deploy-production.yml');
        $exactPaths = [
            'backend/app/Console/Commands/PersonalityMbtiCompRuntime46IntpPromote.php',
            'backend/app/Console/Commands/PersonalityMbtiCompRuntime46IntpRevision.php',
            'backend/app/Services/Cms/MbtiCompRuntime46IntpPromotionService.php',
            'backend/app/Services/Cms/MbtiFullCmsImportService.php',
            'backend/docs/seo/import-packages/mbti-comp-runtime-46-intp-revision-2026-07-19.json',
            'backend/tests/Feature/Console/PersonalityMbtiCompRuntime46IntpRevisionCommandTest.php',
        ];

        $this->assertStringContainsString('mbti_runtime46_isolated', $staging);
        $this->assertStringContainsString('781d1636b2c74f5852076a5864b681910a1e0e47', $staging);
        $this->assertStringContainsString('test "$(git rev-list --parents -n 1 HEAD | awk', $staging);
        $this->assertStringContainsString('test "$(git rev-parse HEAD^)" = "$EXPECTED_BASE_SHA"', $staging);
        $this->assertStringContainsString('I explicitly approve isolated MBTI-COMP-RUNTIME-46 staging deploy for SHA ${EXPECTED_RELEASE_SHA} based on production SHA ${EXPECTED_BASE_SHA}; no production deploy or CMS/DB/content write.', $staging);
        $this->assertStringContainsString('git show "origin/main:$path" | cmp - "$path"', $staging);
        $this->assertStringContainsString('php artisan test tests/Feature/Console/PersonalityMbtiCompRuntime46IntpRevisionCommandTest.php', $staging);
        $this->assertStringContainsString('Normalize exact isolated candidate branch-diff baseline', $staging);
        $this->assertStringContainsString('git update-ref refs/remotes/origin/main "$EXPECTED_RELEASE_SHA"', $staging);
        $this->assertStringContainsString('test "$(git merge-base origin/main HEAD)" = "$EXPECTED_RELEASE_SHA"', $staging);
        $this->assertStringContainsString('DEPLOY_TASK="deploy:code-only"', $staging);
        $this->assertStringContainsString('DEPLOY_REVISION="$EXPECTED_RELEASE_SHA"', $staging);
        $this->assertStringContainsString('--revision "$DEPLOY_REVISION"', $staging);
        $this->assertStringContainsString('-o deploy_mode="$DEPLOY_MODE"', $staging);
        $this->assertStringContainsString("staging-\${{ github.event_name == 'workflow_dispatch' && inputs.release_mode == 'mbti_runtime46_isolated' && 'runtime46-isolated' || 'main' }}", $staging);
        $this->assertStringContainsString('Queue reload capability preflight', $staging);
        $this->assertStringContainsString('if [ "$RELEASE_MODE" = "mbti_runtime46_isolated" ]', $staging);
        $this->assertStringContainsString('mbti-runtime46-isolated-staging-${{ github.run_id }}', $staging);
        $this->assertStringContainsString('cms_or_db_write_attempted: false', $staging);
        $this->assertStringContainsString('production_deploy_attempted: false', $staging);

        foreach ($exactPaths as $path) {
            $this->assertSame(2, substr_count($staging, $path), $path);
            $this->assertGreaterThanOrEqual(2, substr_count($production, $path), $path);
        }

        $this->assertStringContainsString('gh run download "$STAGING_RUN_ID" --name "mbti-runtime46-isolated-staging-${STAGING_RUN_ID}"', $production);
        $this->assertStringContainsString('.schema_version == "mbti-runtime46-isolated-staging.v1"', $production);
        $this->assertStringContainsString('and .remote_revision == $candidate_sha', $production);
        $this->assertStringContainsString('and .cms_or_db_write_attempted == false', $production);
        $this->assertStringContainsString('test "$(git rev-parse "${DEPLOY_SHA}^")" = "$EXPECTED_DEPLOYED_REVISION"', $production);
        $this->assertStringContainsString('main-byte-identical scoped files', $production);
    }

    #[Test]
    public function production_deploy_validates_the_exact_release_revision_before_symlink_activation(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString("task('guard:expected-release-revision'", $source);
        $this->assertStringContainsString("currentHost()->getAlias() !== 'production'", $source);
        $this->assertStringContainsString("getenv('DEPLOY_SHA')", $source);
        $this->assertStringContainsString("test -f '{{release_path}}/REVISION'", $source);
        $this->assertStringContainsString("tr -d '\\r\\n' < '{{release_path}}/REVISION'", $source);
        $this->assertStringContainsString("before('deploy:symlink', 'guard:expected-release-revision')", $source);
    }

    #[Test]
    public function career_detail_cache_coverage_is_read_only_complete_and_required_before_symlink_activation(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString("task('guard:career-detail-cache-coverage'", $source);
        $this->assertStringContainsString('career:verify-job-detail-cache-coverage --verify-only --locales=en,zh-CN', $source);
        $this->assertStringContainsString('--minimum-targets=%d --json --no-interaction --no-ansi', $source);
        $this->assertStringContainsString("getenv('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS')", $source);
        $this->assertStringContainsString("preg_match('/^[1-9][0-9]*$/D', \$minimumTargetsRaw) !== 1", $source);
        $this->assertStringContainsString('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS must be a positive base-10 integer.', $source);
        $this->assertStringContainsString("before('deploy:symlink', 'guard:career-detail-cache-coverage')", $source);
        $this->assertStringContainsString("task('career:repair-staging-detail-cache-coverage'", $source);
        $this->assertStringContainsString("currentHost()->getAlias() !== 'staging'", $source);
        $this->assertStringContainsString('career:verify-job-detail-cache-coverage --repair-missing-sync --locales=en,zh-CN', $source);
        $this->assertStringContainsString('--maximum-sync-repairs=%d --json --no-interaction --no-ansi', $source);
        $this->assertStringContainsString("before('guard:career-detail-cache-coverage', 'career:repair-staging-detail-cache-coverage')", $source);
        $this->assertStringNotContainsString('career:verify-job-detail-cache-coverage --repair-missing --', $source);
    }

    #[Test]
    public function runtime46_production_ops_is_exact_hash_bound_and_separates_dry_run_from_single_content_write(): void
    {
        $source = $this->readRepoFile('.github/workflows/mbti-comp-runtime46-production-ops.yml');

        $this->assertStringContainsString('workflow_dispatch:', $source);
        $this->assertStringNotContainsString("\npush:", $source);
        $this->assertStringNotContainsString("\nschedule:", $source);
        $this->assertStringContainsString('environment: production', $source);
        $this->assertStringContainsString('mode:', $source);
        $this->assertStringContainsString('- dry_run', $source);
        $this->assertStringContainsString('- write', $source);
        $this->assertStringContainsString('expected_active_revision:', $source);
        $this->assertStringContainsString('expected_release_name:', $source);
        $this->assertStringContainsString('operator_approval_phrase:', $source);
        $this->assertStringContainsString('mbti-comp-runtime-46-intp-revision-2026-07-19-r1', $source);
        $this->assertStringContainsString('5fcf54132504ef85978a5424e428fb56763ffbaa7c60f50b94b9c91fc3e85dc8', $source);
        $this->assertStringContainsString('7a84cda503b6f328f0659ee5bd41c85f51c1eca44ac9aa7cfa721d59ab6197e2', $source);
        $this->assertStringContainsString('10b306f2dbac4f9a801a7718ec5584d84f56f6de601ada0f8f677bcb163f960e', $source);
        $this->assertStringNotContainsString('719df14a8b79159aaf889237c714774582e07cc731ccc95d2000209b8f4ce359', $source);
        $this->assertStringContainsString('d39be1b48b4ecc8a11d5eef20559cf9bc0ad05b9b82fb29b3ecdca09f3db4f39', $source);
        $this->assertStringContainsString('5b8afeec191d348dbb888c6cb4a63ea1e167e1a004bf35e41c1e64399f0c8369', $source);
        $this->assertStringContainsString('c9b3c3fa7f68a73e946f6bbc0a3f02ea6a95f3cbf5e9d3141778dd7d6408e03d', $source);
        $this->assertStringContainsString('6f7148e9787127ce128e19f0a37832be78119c7f1d9dcdf3a5f4d83aa8295ab9', $source);
        $this->assertStringContainsString('dry_run refuses an operator approval phrase.', $source);
        $this->assertStringContainsString('I explicitly approve MBTI-COMP-RUNTIME-46 production single-record content revision write for package ${PACKAGE_ID} payload SHA ${EXACT_PAYLOAD_SHA256} promotion SHA ${PROMOTION_PACKAGE_SHA256} promotion authorization SHA ${PROMOTION_AUTHORIZATION_SHA256} on active SHA ${EXPECTED_ACTIVE_REVISION}, including exact rollback on failed readback; no publication/indexability/sitemap/llms/search changes.', $source);
        $this->assertStringContainsString('personality:mbti-comp-runtime46-intp-revision', $source);
        $this->assertStringContainsString('personality:mbti-comp-runtime46-intp-promote', $source);
        $this->assertStringContainsString("stage_flags='--dry-run'", $source);
        $this->assertStringContainsString("promotion_flags='--dry-run'", $source);
        $this->assertStringContainsString("stage_flags='--write --production-write-authorized --no-publication-change --no-indexability-change --no-sitemap --no-llms --no-search-release'", $source);
        $this->assertStringContainsString("promotion_flags='--write --production-content-write-authorized --no-publication-change --no-indexability-change --no-sitemap --no-llms --no-search-release'", $source);
        $this->assertSame(3, substr_count($source, "jq -j -S -c '.comparison_public_projection_v1.sections'"));
        $this->assertStringContainsString('test "$before_sections_sha" = "$EXPECTED_PUBLIC_SECTIONS_SHA256"', $source);
        $this->assertStringContainsString('test "$post_sections_sha" = "$EXPECTED_POST_PUBLIC_SECTIONS_SHA256"', $source);
        $this->assertStringContainsString('--rollback-on-readback-failure-authorized', $source);
        $this->assertStringContainsString('test "$rollback_sections_sha" = "$EXPECTED_PUBLIC_SECTIONS_SHA256"', $source);
        $this->assertStringContainsString('publication_changed: false', $source);
        $this->assertStringContainsString('indexability_changed: false', $source);
        $this->assertStringContainsString('sitemap_or_llms_changed: false', $source);
        $this->assertStringContainsString('search_action_attempted: false', $source);
        $this->assertStringContainsString('actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02', $source);
    }

    #[Test]
    public function production_deploy_lock_guard_retries_the_full_script_and_only_removes_verified_stale_ci_locks(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        $this->assertStringContainsString('LOCK_GUARD_SCRIPT="$(cat', $source);
        $this->assertStringContainsString('LOCK_GUARD_B64="$(printf', $source);
        $this->assertStringContainsString('base64 -d | bash', $source);
        $this->assertStringNotContainsString("ssh_retry \"TARGET='\$TARGET' DEPLOY_PATH='\$DEPLOY_PATH' bash -s\" <<'REMOTE'", $source);
        $this->assertStringContainsString('STALE_LOCK_SECONDS="${STALE_LOCK_SECONDS:-1800}"', $source);
        $this->assertStringContainsString('[ "$OWNER" = "ci" ]', $source);
        $this->assertStringContainsString("grep -Fq '\"env\":\"production\"'", $source);
        $this->assertStringContainsString("grep -Fq '\"repository\":\"'\"\$GITHUB_REPOSITORY\"'\"'", $source);
        $this->assertStringContainsString('deploy lock guard: active deploy-like process exists', $source);
        $this->assertStringContainsString('code-only deploy refuses to remove an existing lock automatically', $source);
        $this->assertStringContainsString('rm -f "$LOCK" "$META"', $source);
    }

    #[Test]
    public function code_only_deploy_requires_a_remote_baseline_match_before_any_deploy_lock_action(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');
        $baselineStep = strpos($source, '- name: Verify mutation-limited deployed baseline before writes');
        $lockStep = strpos($source, '- name: Remote deploy lock guard');

        $this->assertNotFalse($baselineStep);
        $this->assertNotFalse($lockStep);
        $this->assertLessThan((int) $lockStep, (int) $baselineStep);
        $this->assertStringContainsString('test -f REVISION && tr -d', $source);
        $this->assertStringContainsString('remote deployed REVISION does not match expected_deployed_revision', $source);
        $this->assertStringContainsString('EXPECTED_DEPLOYED_REVISION: ${{ inputs.expected_deployed_revision }}', $source);
    }

    #[Test]
    public function code_only_scope_accepts_only_the_exact_audited_root5_paths(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        foreach ([
            '.github/workflows/career-detail-production-cache-repair.yml',
            '.github/workflows/career-detail-staging-cache-repair.yml',
            '.github/workflows/deploy.yml',
            '.github/workflows/mbti-comp-runtime46-production-ops.yml',
            '.github/workflows/mbti-comp-runtime46-staging-dry-run.yml',
            'backend/app/Filament/Ops/Resources/AdminApprovalResource.php',
            'backend/app/Models/AdminApproval.php',
            'backend/app/Models/DailyGivingRecord.php',
            'backend/app/Services/Cms/Mbti64CmsInternalLinkDraftWriter.php',
            'backend/app/Services/Cms/Mbti64CrossTypeComparisonPublicReadModel.php',
            'backend/app/Services/Cms/MbtiCrossPublisher49ContentService.php',
            'backend/app/Services/Cms/MbtiCrossPublisher49IndexabilityService.php',
            'backend/app/Services/Cms/MbtiCrossPublisher49Package.php',
            'backend/content_assets/personality_public/mbti-cross-approval-48-operator-authorization-r2-2026-07-23.json',
            'backend/content_assets/personality_public/mbti-cross-approval-48-package-2026-07-23.json',
            'backend/docs/career/README.md',
            'backend/docs/career/career-detail-stability-train-2026-07-18.md',
            'backend/docs/career/job-detail-cache-coverage.md',
            'backend/docs/seo/generated/mbti-comp-runtime-46-production-acceptance.v1.json',
            'backend/docs/seo/mbti-comp-runtime-46-production-acceptance.md',
            'docs/04-ops/deploy-incident-runbook.md',
            'docs/ops/big-five-en52-release.md',
            'docs/ops/big-five-legacy-alias-hard-purge.md',
            'docs/seo/career-jobs-index-lkg-resilience-01.md',
            'docs/seo/career-pilot-review-evidence-bridge-01.md',
        ] as $auditedPath) {
            $this->assertStringContainsString($auditedPath, $source);
        }

        $this->assertStringNotContainsString(
            'backend/docs/career/publish_track_reconciliation.json|',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope classification accepted the exact audited Runtime 46 subsumed baseline.',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope accepted exact audited Root5 runtime or inert evidence path:',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope accepted exact audited subsumed runtime or inert authority input path:',
            $source
        );
        $this->assertStringContainsString('REQUIRE_OPS_QUEUE_RELOAD=false', $source);
        $this->assertStringContainsString('REQUIRE_OPS_QUEUE_RELOAD=true', $source);
        $this->assertStringContainsString('code-only scope accepted approval runtime path and requires ops queue reload:', $source);
        $this->assertStringContainsString('require_ops_queue_reload=$REQUIRE_OPS_QUEUE_RELOAD', $source);
        $this->assertStringContainsString('require_ops_queue_reload: ${{ steps.resolve_deploy_mode.outputs.require_ops_queue_reload }}', $source);
        $this->assertStringContainsString('REQUIRE_OPS_QUEUE_RELOAD: ${{ needs.deployment-eligibility.outputs.require_ops_queue_reload }}', $source);
        $this->assertStringContainsString('-o require_ops_queue_reload="$REQUIRE_OPS_QUEUE_RELOAD"', $source);
        $this->assertStringContainsString('TRUSTED_STAGING_WORKFLOW_SHA256: ${{ vars.PRODUCTION_TRUSTED_STAGING_WORKFLOW_SHA256 }}', $source);
        $this->assertStringContainsString('code-only scope requires an external trusted SHA-256 receipt for deploy.yml.', $source);
        $this->assertStringContainsString('sha256sum .github/workflows/deploy.yml', $source);
        $this->assertStringContainsString('code-only scope refused deploy.yml because its SHA-256 does not match the external trusted receipt.', $source);
        $this->assertStringNotContainsString('.github/workflows/career-detail-staging-cache-repair.yml|.github/workflows/deploy.yml|', $source);
        $this->assertStringContainsString('backend/database/*', $source);
        $this->assertStringContainsString('backend/content_baselines/*', $source);
        $this->assertStringContainsString('content_packages/*', $source);
        $this->assertStringContainsString('backend/storage/*', $source);
        $this->assertStringContainsString('code-only scope refused authority path:', $source);
        $this->assertStringNotContainsString('backend/app/Services/Cms/*)', $source);
        $this->assertStringNotContainsString('backend/app/Models/*)', $source);
        $this->assertStringNotContainsString('backend/app/Filament/*)', $source);
    }

    #[Test]
    public function release_candidate_record_keeps_the_main_head_and_undeployed_commit_list_auditable(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        $this->assertStringContainsString('- name: Record production release candidate', $source);
        $this->assertStringContainsString('main_sha_at_eligibility: ${MAIN_SHA}', $source);
        $this->assertStringContainsString('main_commits_not_deployed: ${UNDEPLOYED_COUNT}', $source);
        $this->assertStringContainsString('Main commits intentionally not deployed', $source);
        $this->assertStringContainsString('git log --format=', $source);
    }

    #[Test]
    public function code_only_deploy_task_excludes_application_data_and_authority_mutations(): void
    {
        $source = $this->readRepoFile('deploy.php');
        $start = strpos($source, "task('deploy:code-only', [");
        $this->assertNotFalse($start);
        $end = strpos($source, ']);', (int) $start);
        $this->assertNotFalse($end);
        $task = substr($source, (int) $start, (int) $end - (int) $start + 3);

        foreach ([
            "'guard:code-only-mode'",
            "'deploy:prepare'",
            "'deploy:vendors'",
            "'artisan:config:cache'",
            "'guard:public-content-release'",
            "'deploy:publish'",
        ] as $requiredTask) {
            $this->assertStringContainsString($requiredTask, $task);
        }

        foreach ([
            'artisan:migrate',
            'artisan:scales:seed-default',
            'cms:import-landing-surface-baselines',
            'cms:import-content-page-baselines',
            'career:warm-public-authority-cache',
            'seo:warm-sitemap-source-cache',
        ] as $forbiddenTask) {
            $this->assertStringNotContainsString($forbiddenTask, $task);
        }

        $this->assertStringContainsString('$codeOnly = deployIsCodeOnly();', $source);
        $this->assertStringContainsString('if (! $codeOnly)', $source);
        $this->assertStringContainsString('Reload queue workers through the process manager without a cache restart signal in code_only deploy mode', $source);
        $this->assertStringContainsString('Reload queue workers through systemd without a cache restart signal in code_only deploy mode', $source);
        $this->assertStringContainsString('code_only deploy requires a queue process manager reload path', $source);
        $this->assertStringContainsString("else printf '%s\\\\n' {\$notFoundMessage} >&2; exit 1; fi", $source);
        $this->assertStringContainsString("deployBooleanOption('require_ops_queue_reload', false)", $source);
        $this->assertStringContainsString("\$requiredPrograms[] = 'fap-queue-ops';", $source);
        $this->assertStringContainsString("static fn (string \$program): bool => \$program !== 'fap-queue-ops'", $source);
        $this->assertStringContainsString('Require the ops queue worker reload for approval runtime code_only scope', $source);
        $this->assertStringContainsString('approval runtime code_only deploy requires the supervisor ops queue reload path', $source);
        $this->assertStringNotContainsString('Skip queue worker reload in code_only deploy mode', $source);
        $this->assertStringContainsString('Skip nginx reload in code_only deploy mode', $source);
        $this->assertStringContainsString('Skip auth guest POST contract probe in authority-mutation-free deploy mode', $source);
        $this->assertStringContainsString('Skip shared content package copy in authority-mutation-free deploy mode', $source);
    }

    #[Test]
    public function schema_only_deploy_is_bound_to_one_added_migration_and_excludes_authority_mutations(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/deploy-production.yml');
        $deployer = $this->readRepoFile('deploy.php');

        foreach ([
            'schema_only',
            'APPROVED_MIGRATION: ${{ inputs.approved_migration }}',
            'schema_only requires expected_deployed_revision as a lowercase 40-character deployed REVISION.',
            'schema_only requires one exact approved_migration filename.',
            'standard deploy refuses approved_migration; use schema_only for an exact migration.',
            'auto/code_only refuses approved_migration; use schema_only for an exact migration.',
            'git diff --no-renames --name-status "$EXPECTED_DEPLOYED_REVISION" "$DEPLOY_SHA" -- backend/database/migrations',
            '$\'A\\t\'"$MIGRATION_PATH"',
            'cumulative diff must add exactly the approved migration and no other migration',
            'schema-only scope refused authority or database path:',
            'Manual schema_only production deploy refused because expected_release_sha is not latest main.',
            'DEPLOY_TASK=deploy:schema-only',
            '-o schema_only_migration="$APPROVED_MIGRATION"',
            'Verify schema-only migration and schema state',
            'php artisan fap:schema:verify --no-interaction --no-ansi',
        ] as $requiredContract) {
            $this->assertStringContainsString($requiredContract, $workflow);
        }

        $start = strpos($deployer, "task('deploy:schema-only', [");
        $this->assertNotFalse($start);
        $end = strpos($deployer, ']);', (int) $start);
        $this->assertNotFalse($end);
        $task = substr($deployer, (int) $start, (int) $end - (int) $start + 3);

        foreach ([
            "'guard:schema-only-mode'",
            "'deploy:prepare'",
            "'deploy:vendors'",
            "'artisan:config:cache'",
            "'artisan:route:cache'",
            "'artisan:event:cache'",
            "'artisan:migrate-schema-only'",
            "'guard:public-content-release'",
            "'deploy:publish'",
        ] as $requiredTask) {
            $this->assertStringContainsString($requiredTask, $task);
        }

        foreach ([
            "'artisan:migrate',",
            'artisan:scales:seed-default',
            'cms:import-landing-surface-baselines',
            'cms:import-content-page-baselines',
            'career:warm-public-authority-cache',
            'seo:warm-sitemap-source-cache',
        ] as $forbiddenTask) {
            $this->assertStringNotContainsString($forbiddenTask, $task);
        }

        $this->assertStringContainsString("task('artisan:migrate-schema-only'", $deployer);
        $this->assertStringContainsString('schema-only deploy requires exactly one pending migration', $deployer);
        $this->assertStringContainsString('artisan migrate --path=__MIGRATION_PATH__ --force --no-interaction --ansi', $deployer);
        $this->assertStringContainsString('schema-only deploy left pending migrations', $deployer);
        $this->assertStringContainsString('schema-only deploy could not verify the approved migration as Ran', $deployer);
        $this->assertStringContainsString("after('artisan:migrate', 'guard:no-pending-migrations')", $deployer);
        $this->assertStringNotContainsString("after('artisan:migrate-schema-only'", $deployer);
        $this->assertStringContainsString("return in_array(deployMode(), ['code_only', 'schema_only'], true);", $deployer);
        $this->assertSame(3, substr_count($deployer, 'if (deploySkipsAuthorityMutations())'));
        $this->assertStringContainsString('Skip nginx static media route mutation in authority-mutation-free deploy mode', $deployer);
        $this->assertStringContainsString('Skip auth guest POST contract probe in authority-mutation-free deploy mode', $deployer);
        $this->assertStringContainsString('Skip shared content package copy in authority-mutation-free deploy mode', $deployer);
    }

    private function readRepoFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $source = file_get_contents($path);

        $this->assertIsString($source, 'unable to read '.$relativePath);

        return $source;
    }

    private function extractArrayBlock(string $source, string $needle): string
    {
        $offset = strpos($source, $needle);
        $this->assertNotFalse($offset, 'missing block start: '.$needle);

        $start = strpos($source, '[', (int) $offset);
        $this->assertNotFalse($start, 'missing array start: '.$needle);

        $depth = 0;
        $length = strlen($source);

        for ($i = (int) $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, (int) $offset, $i - (int) $offset + 1);
                }
            }
        }

        $this->fail('missing array end: '.$needle);
    }
}
