<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DeployStorageAndDatabaseConfigTest extends TestCase
{
    #[Test]
    public function staging_deployer_healthchecks_the_api_vhost(): void
    {
        $deployer = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString(
            "->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging-api.fermatmind.com')",
            $deployer,
        );
        $this->assertStringNotContainsString(
            "->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging.fermatmind.com')",
            $deployer,
        );
    }

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
            'function deployCanSudoWwwData(): bool',
            $deployer,
        );
        $this->assertStringContainsString(
            "\$sudoPrefix = deployCanSudoWwwData() ? 'sudo -n -u www-data -- ' : '';",
            $deployer,
        );
        $this->assertStringContainsString(
            'if (! test("{$sudoPrefix}test -r {$artisan}"))',
            $deployer,
        );
        $this->assertStringContainsString(
            'if (! test("{$sudoPrefix}test -w {$cacheData}"))',
            $deployer,
        );
        $this->assertStringContainsString(
            'queue restart preflight requires the application runtime identity to write the shared cache directory',
            $deployer,
        );
        $this->assertSame(
            2,
            substr_count(
                $deployer,
                "run(\$sudoPrefix.'{{bin/php}} artisan queue:restart --ansi');",
            ),
        );
        $this->assertStringNotContainsString(
            "run('{{bin/php}} artisan queue:restart --ansi');",
            $deployer,
        );
        $this->assertStringContainsString(
            'queue capability preflight requires running supervisor program [{$program}] before release activation',
            $deployer,
        );
        $this->assertStringContainsString(
            "currentHost()->getAlias() === 'production'",
            $deployer,
        );
        $this->assertSame(
            2,
            substr_count($deployer, "currentHost()->getAlias() === 'production'"),
        );
        $this->assertStringContainsString(
            "|| deployBooleanOption('require_ops_queue_reload', false)",
            $deployer,
        );
        $this->assertStringContainsString("\$requiredPrograms[] = 'fap-queue-ops';", $deployer);
        $this->assertStringContainsString(
            '{ sudo -n {$quotedSupervisorctl} status 2>/dev/null || true; }',
            $deployer,
        );
        $this->assertStringContainsString('END { exit !(found && !bad) }', $deployer);
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
            '[[ "$DEPLOY_USER" =~ ^[A-Za-z0-9_][A-Za-z0-9_-]{0,31}$ ]]',
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

        $this->assertStringNotContainsString(
            '[[ "$DEPLOY_USER" =~ ^[A-Za-z_][A-Za-z0-9_-]{0,31}$ ]]',
            $workflow,
        );
    }

    #[Test]
    public function staging_ops_asset_smoke_accepts_the_same_numeric_prefix_deploy_user_as_the_workflow(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/deploy.yml');
        $smokeRunner = $this->readRepoFile('backend/scripts/deploy/verify_ops_asset_smoke.sh');
        $numericPrefixContract = '^[A-Za-z0-9_][A-Za-z0-9_-]{0,31}$';
        $legacyLetterPrefixContract = '^[A-Za-z_][A-Za-z0-9_-]{0,31}$';

        $this->assertStringContainsString($numericPrefixContract, $workflow);
        $this->assertStringContainsString($numericPrefixContract, $smokeRunner);
        $this->assertStringNotContainsString($legacyLetterPrefixContract, $workflow);
        $this->assertStringNotContainsString($legacyLetterPrefixContract, $smokeRunner);
    }

    #[Test]
    public function mysql_connections_keep_ssl_ca_and_fail_closed_verification_configurable(): void
    {
        $source = $this->readRepoFile('backend/config/database.php');
        $mysqlBlock = $this->extractArrayBlock($source, "'mysql' => [");
        $mariadbBlock = $this->extractArrayBlock($source, "'mariadb' => [");

        $this->assertStringContainsString("env('MYSQL_ATTR_SSL_CA')", $source);
        $this->assertStringContainsString("env('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', true)", $source);
        $this->assertStringContainsString('FILTER_VALIDATE_BOOLEAN', $source);
        $this->assertStringContainsString('FILTER_NULL_ON_FAILURE', $source);
        $this->assertStringContainsString('$verifyServerCertificate ?? true', $source);
        $this->assertStringNotContainsString('array_filter([', $source);
        $this->assertStringContainsString("'options' => \$mysqlSslOptions()", $mysqlBlock);
        $this->assertStringContainsString("'options' => \$mysqlSslOptions()", $mariadbBlock);
    }

    #[Test]
    public function mysql_ssl_options_retain_false_only_when_a_ca_is_configured(): void
    {
        $ca = '/tmp/fermatmind-rds-ca.pem';

        $withoutCa = $this->loadDatabaseConfigWithSslEnvironment(null, 'false');
        $withFalse = $this->loadDatabaseConfigWithSslEnvironment($ca, 'false');
        $withTrue = $this->loadDatabaseConfigWithSslEnvironment($ca, 'true');
        $withInvalid = $this->loadDatabaseConfigWithSslEnvironment($ca, 'not-a-boolean');

        $this->assertSame([], $withoutCa['mysql']['options']);
        $this->assertSame([], $withoutCa['mariadb']['options']);

        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame($ca, $withFalse[$connection]['options'][$this->mysqlSslCaAttribute()]);
            $this->assertFalse($withFalse[$connection]['options'][$this->mysqlSslVerifyServerCertificateAttribute()]);
            $this->assertTrue($withTrue[$connection]['options'][$this->mysqlSslVerifyServerCertificateAttribute()]);
            $this->assertTrue($withInvalid[$connection]['options'][$this->mysqlSslVerifyServerCertificateAttribute()]);
        }
    }

    #[Test]
    public function ordinary_deploy_uses_a_bounded_read_only_shared_permissions_guard(): void
    {
        $deployer = $this->readRepoFile('deploy.php');
        $verifier = $this->readRepoFile('backend/scripts/deploy/verify_shared_permissions.sh');

        $this->assertStringContainsString("set('writable_dirs', []);", $deployer);
        $this->assertStringContainsString("set('writable_mode', 'skip');", $deployer);
        $this->assertStringContainsString("task('guard:shared-permissions'", $deployer);
        $this->assertStringContainsString(
            "after('deploy:shared', 'guard:shared-permissions');",
            $deployer,
        );
        $this->assertStringContainsString('verify_shared_permissions.sh', $deployer);
        $this->assertStringNotContainsString('ensureOwnedWritableTree', $deployer);
        $this->assertStringNotContainsString('ensureOwnedWritableDir', $deployer);
        $this->assertStringNotContainsString("task('ensure:shared-perms'", $deployer);
        $this->assertStringNotContainsString("task('ensure:healthz-deps'", $deployer);
        $this->assertStringNotContainsString("task('ensure:release-runtime-perms'", $deployer);

        foreach (['chmod', 'chown', 'setfacl', 'mkdir', 'find '] as $mutation) {
            $this->assertStringNotContainsString($mutation, $verifier);
        }
        $this->assertStringContainsString('SHARED_PERMISSIONS_RUNTIME_USER', $verifier);
        $this->assertStringContainsString('run_explicit_shared_permissions_provisioning', $verifier);
        $this->assertStringContainsString('shared_permissions_status=success', $verifier);
    }

    #[Test]
    public function shared_permission_provisioning_is_explicit_idempotent_and_separate_from_deploy(): void
    {
        $deployer = $this->readRepoFile('deploy.php');
        $deployAdapter = $this->readRepoFile('backend/scripts/deploy/deploy_backend.sh');
        $rollbackAdapter = $this->readRepoFile('backend/scripts/deploy/rollback_backend.sh');
        $provisioner = $this->readRepoFile('backend/scripts/deploy/provision_shared_permissions.sh');
        $paths = $this->readRepoFile('backend/scripts/deploy/shared_permissions_paths.txt');

        $this->assertStringContainsString('SHARED_PERMISSIONS_APPLY', $provisioner);
        $this->assertStringContainsString('EXPLICIT_PROVISIONING_REQUIRED', $provisioner);
        $this->assertStringContainsString('chmod 2775', $provisioner);
        $this->assertStringContainsString('chown', $provisioner);
        $this->assertStringNotContainsString(' -R ', $provisioner);
        $this->assertStringNotContainsString('provision_shared_permissions.sh', $deployer);
        $this->assertStringNotContainsString('provision_shared_permissions.sh', $deployAdapter);
        $this->assertStringNotContainsString('provision_shared_permissions.sh', $rollbackAdapter);
        $this->assertStringContainsString(
            "' -mindepth 1 -maxdepth 1'",
            $deployer,
        );
        $this->assertStringContainsString(
            "' -exec cp -an -- {} '.\$destination.'/ \\;'",
            $deployer,
        );
        $this->assertStringNotContainsString(
            "deployPlaceholderPathArg('{{release_path}}', 'content_packages').'/. '",
            $deployer,
        );
        $this->assertStringContainsString('backend/storage/app/private/artifacts', $paths);
        $this->assertStringContainsString('backend/storage/framework/cache', $paths);
        $this->assertStringContainsString('content_packages', $paths);
    }

    #[Test]
    public function release_bootstrap_cache_access_is_prepared_without_recursive_permission_repair(): void
    {
        $deployer = $this->readRepoFile('deploy.php');
        $taskStart = strpos($deployer, "task('prepare:release-bootstrap-cache-access'");
        $taskEnd = is_int($taskStart) ? strpos($deployer, "\n});", $taskStart) : false;

        $this->assertIsInt($taskStart);
        $this->assertIsInt($taskEnd);
        $taskBlock = substr($deployer, $taskStart, $taskEnd - $taskStart + 4);
        $this->assertStringContainsString(
            "deployPlaceholderPathArg(\n        '{{release_path}}',\n        'backend/bootstrap/cache',",
            $taskBlock,
        );
        $this->assertStringContainsString(
            "' && sudo -n /usr/bin/chown '.\$ownerGroup.' '.\$cacheDir",
            $taskBlock,
        );
        $this->assertStringContainsString(
            "' && sudo -n /usr/bin/chmod 2775 '.\$cacheDir",
            $taskBlock,
        );
        $this->assertStringNotContainsString(' -R ', $taskBlock);
        $this->assertStringNotContainsString('/usr/bin/find', $taskBlock);
        $this->assertStringNotContainsString('backend/storage', $taskBlock);
        $this->assertStringContainsString(
            "after('guard:public-content-release', 'prepare:release-bootstrap-cache-access');",
            $deployer,
        );
    }

    #[Test]
    public function sitemap_cache_warm_uses_the_php_fpm_identity_for_shared_file_cache_writes(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString('php_bin="$(command -v {{bin/php}})"', $source);
        $this->assertStringContainsString('$canSudoWwwData = deployCanSudoWwwData();', $source);
        $this->assertStringContainsString(
            "? 'sudo -n -u www-data -- env'",
            $source,
        );
        $this->assertStringContainsString(
            '%s SITEMAP_SOURCE_WARM_PHP_BIN="$php_bin"',
            $source,
        );
        $this->assertStringNotContainsString('SITEMAP_SOURCE_WARM_PHP_BIN={{bin/php}}', $source);
        $this->assertStringContainsString('verify_sitemap_source_cache_refresh.sh', $source);
        $this->assertStringContainsString('SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS', $source);
        $this->assertStringContainsString('SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS', $source);
        $this->assertStringContainsString('SITEMAP_SOURCE_WARM_STRICT', $source);
    }

    #[Test]
    public function sitemap_cache_warm_timeout_is_nonblocking_only_with_a_post_symlink_safe_fallback_gate(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString("task('healthcheck:sitemap-source'", $source);
        $this->assertStringContainsString('/api/v0.5/seo/sitemap-source', $source);
        $this->assertStringContainsString('.ok==true and .count >= 1', $source);
        $this->assertStringContainsString('backend_sitemap_generator_fallback', $source);
        $this->assertStringContainsString(
            "after('healthcheck:public', 'healthcheck:sitemap-source')",
            $source,
        );
        $this->assertStringContainsString(
            "after('healthcheck:sitemap-source', 'healthcheck:public-dns')",
            $source,
        );
    }

    #[Test]
    public function immutable_candidate_uses_a_runner_only_hash_locked_sitemap_control_recipe(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/deploy-production.yml');
        $wrapper = $this->readRepoFile(
            'backend/scripts/deploy/immutable_candidate_sitemap_control.php'
        );

        foreach ([
            '49038deb50cda789e4365ea42068832ed28d6023',
            '29977064260',
            'e814b6ff4996669097db0f32fd3caebc1fcd05dd9015e2260016e3f4ece3c068',
            '04f9d6b6b66b0be10a4996064cdf9150e1b0f1ec300edf93f87e8c0368ea0713',
            'Prepare exact immutable candidate deployment control',
            'WORKFLOW_CONTROL_SHA: ${{ github.sha }}',
            'git show "${WORKFLOW_CONTROL_SHA}:${wrapper_path}"',
            'git show "${WORKFLOW_CONTROL_SHA}:${helper_path}"',
            'test "$(git rev-parse HEAD^{tree})" = "$candidate_tree_before"',
            'candidate_recipe_with_runner_only_sitemap_override',
            'release_overlay: false',
            'remote_control_file_write: false',
            'IMMUTABLE_CANDIDATE_RECIPE_PATH="$GITHUB_WORKSPACE/deploy.php"',
            'php /tmp/dep.phar "$DEPLOY_TASK" production -f "$deployer_recipe"',
            '--revision "$DEPLOY_SHA"',
            'backend-immutable-candidate-control-receipt.v1',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $workflow);
        }

        $this->assertStringContainsString('require $candidateRecipe;', $wrapper);
        $this->assertStringContainsString(
            "task('seo:warm-sitemap-source-cache'",
            $wrapper,
        );
        $this->assertStringContainsString(
            "task('healthcheck:sitemap-source'",
            $wrapper,
        );
        $this->assertSame(2, substr_count($wrapper, "task('"));
        $this->assertStringContainsString(
            'php_bin="$(command -v {{bin/php}})"',
            $wrapper,
        );
        $this->assertStringContainsString('test -n "$php_bin"', $wrapper);
        $this->assertStringContainsString(
            'SITEMAP_SOURCE_WARM_PHP_BIN="$php_bin"',
            $wrapper,
        );
        $this->assertStringNotContainsString(
            'SITEMAP_SOURCE_WARM_PHP_BIN={{bin/php}}',
            $wrapper,
        );
        $this->assertStringContainsString('| base64 -d', $wrapper);
        $this->assertStringContainsString('SITEMAP_SOURCE_WARM_STRICT=false', $wrapper);
        $this->assertStringContainsString(
            "before('healthcheck:public-dns', 'healthcheck:sitemap-source')",
            $wrapper,
        );
        $this->assertStringNotContainsString('file_put_contents', $wrapper);
        $this->assertStringNotContainsString('upload(', $wrapper);
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
        $this->assertStringNotContainsString("shell_exec('sudo -n /usr/bin/cat '", $source);
        $this->assertStringContainsString('sudo -n /usr/bin/cat "$site_path" > "$tmp_site_source"', $source);
        $this->assertStringContainsString('$content = file_get_contents($siteSource);', $source);
        $this->assertStringContainsString('$included = @file_get_contents($includePath);', $source);
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
        $this->assertStringContainsString('deploy_mode must be auto, code_only, candidate_only, schema_only, or standard.', $source);
        $this->assertSame(3, substr_count($source, 'required: true'));
        $this->assertStringContainsString('actions: read', $source);
        $this->assertStringContainsString('Validate manual exact-SHA approval and staging evidence', $source);
        $this->assertStringNotContainsString("if: github.event_name == 'workflow_dispatch'", $source);
        $this->assertStringContainsString('[[ ! "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]]', $source);
        $this->assertStringContainsString('staging_run_id must be numeric outside the exact-active candidate_only fast path.', $source);
        $this->assertStringContainsString('[[ ! "$RELEASE_ID" =~ ^[A-Za-z0-9._-]{1,80}$ ]]', $source);
        $this->assertStringContainsString('RELEASE_ID="standard-${DEPLOY_SHA:0:12}"', $source);
        $this->assertStringContainsString('gh run list --workflow deploy.yml --branch main --commit "$DEPLOY_SHA" --status completed --limit 100', $source);
        $this->assertStringContainsString('--json databaseId,headSha,name,event,status,conclusion', $source);
        $this->assertStringContainsString('standard deploy could not resolve a successful exact-SHA staging run.', $source);
        $this->assertStringContainsString('standard deploy requires approved_migration to be omitted; do not submit false.', $source);
        $this->assertStringContainsString('I explicitly approve bounded backend production deploy for exact SHA ${DEPLOY_SHA}, excluding all newer main commits, release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('I explicitly approve backend code-only production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('I explicitly approve bounded backend inactive candidate materialization for exact SHA ${DEPLOY_SHA} using exact staging run ${STAGING_RUN_ID} from active SHA ${EXPECTED_DEPLOYED_REVISION}, excluding all newer main commits, release ${RELEASE_ID}; distinct inactive release path, zero activation.', $source);
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
    public function index52_isolated_runtime_candidate_is_pinned_to_active_staged_and_main_identical_blobs(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        foreach ([
            'isolated_index52: ${{ steps.resolve_deploy_mode.outputs.isolated_index52 }}',
            'INDEX52_CANDIDATE_SHA="9d2877a7e519f768fd741398e76777620770fb71"',
            'INDEX52_ACTIVE_SHA="e7ed3b9e894730ff0f973687eb552337db5c6db9"',
            'INDEX52_STAGED_MAIN_SHA="e9166c5eae03ad13c7ef616b9ca7c528d14bd582"',
            'INDEX52_STAGING_RUN_ID="30268270565"',
            'INDEX52_PATCH_SHA256="2cf768ab7896be204b983c377bce5d1b00771f6a5c2dd62c82d5c265b0748f00"',
            'test "$(git rev-parse "${DEPLOY_SHA}^")" = "$INDEX52_ACTIVE_SHA"',
            'test "$(git diff --no-renames --name-only "$INDEX52_ACTIVE_SHA" "$DEPLOY_SHA" | sort)" = "$index52_expected_files"',
            'test "$(git rev-parse "${DEPLOY_SHA}:${path}")" = "$(git rev-parse "${STAGING_SHA}:${path}")"',
            'ISOLATED_INDEX52: ${{ steps.resolve_deploy_mode.outputs.isolated_index52 }}',
            'test "$(git rev-parse "${DEPLOY_SHA}:${path}")" = "$(git rev-parse "${LATEST_MAIN_SHA}:${path}")"',
            'Code-only production deploy accepted exact isolated INDEX-52 runtime candidate with staged-main-byte-identical scoped files.',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        $this->assertSame(3, substr_count(
            $source,
            'backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php'
        ));
        $this->assertSame(4, substr_count(
            $source,
            'backend/app/Services/Cms/Mbti64CrossTypeComparisonPublicReadModel.php'
        ));
        $this->assertStringContainsString("task('deploy:code-only', [", $this->readRepoFile('deploy.php'));
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
    public function career_cold_cache_activation_is_ordered_cohort_complete_and_keeps_isolated_modes_separate(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString("task('guard:career-runtime-projection-authority'", $source);
        $this->assertStringContainsString("in_array(deployMode(), ['code_only', 'candidate_only'], true)", $source);
        $this->assertStringContainsString('verify_career_cold_cache_discoverability.php', $source);
        $this->assertStringContainsString('if (! deployCanSudoWwwData()) {', $source);
        $this->assertStringContainsString(
            'Career runtime projection gate requires the application runtime identity.',
            $source
        );
        $this->assertStringContainsString('php_bin="$(command -v {{bin/php}})"', $source);
        $this->assertStringContainsString(
            'sudo -n -u www-data -- env FM_CAREER_COLD_CACHE_GATE_EXECUTE=1 "$php_bin" %s authority',
            $source
        );
        $this->assertStringContainsString("'artisan:migrate-schema-only',\n    'guard:career-runtime-projection-authority'", $source);
        $this->assertStringContainsString("task('guard:career-detail-cache-coverage'", $source);
        $this->assertStringContainsString('if (deploySkipsAuthorityMutations()) {', $source);
        $this->assertStringContainsString(
            'Skipping Career detail cache coverage because this isolated release does not mutate Career authority caches.',
            $source
        );
        $this->assertStringContainsString('function deployCareerDetailMinimumTargets(string $hostAlias): int', $source);
        $this->assertStringContainsString("in_array(\$hostAlias, ['staging', 'production'], true)", $source);
        $this->assertStringContainsString('return 1;', $source);
        $this->assertStringContainsString('career:verify-job-detail-cache-coverage --verify-only --locales=en,zh-CN', $source);
        $this->assertStringContainsString('--minimum-targets=%d --json --no-interaction --no-ansi', $source);
        $this->assertStringNotContainsString("getenv('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS')", $source);
        $this->assertSame(
            3,
            substr_count($source, 'deployCareerDetailMinimumTargets(')
        );
        $this->assertStringNotContainsString("before('deploy:symlink', 'guard:career-detail-cache-coverage')", $source);
        $this->assertStringContainsString("task('career:repair-published-detail-cache-coverage'", $source);
        $this->assertStringContainsString('Skipping Career detail cache repair because this isolated release does not mutate Career authority caches.', $source);
        $this->assertStringContainsString("\$hostAlias === 'production'", $source);
        $this->assertStringContainsString("? ' --confirm-production-write'", $source);
        $this->assertStringContainsString('DEPLOY_CAREER_DETAIL_MAXIMUM_SYNC_REPAIRS must be an integer between 1 and 250.', $source);
        $this->assertStringContainsString('career:verify-job-detail-cache-coverage --repair-missing-sync --locales=en,zh-CN', $source);
        $this->assertStringContainsString('--maximum-sync-repairs=%d --json --no-interaction --no-ansi%s', $source);
        $this->assertStringNotContainsString("before('guard:career-detail-cache-coverage', 'career:repair-published-detail-cache-coverage')", $source);
        $this->assertStringNotContainsString('career:verify-job-detail-cache-coverage --repair-missing --', $source);

        $orderedHooks = [
            "after('artisan:scales:seed-default', 'guard:career-runtime-projection-authority')",
            "after('guard:career-runtime-projection-authority', 'career:repair-published-detail-cache-coverage')",
            "after('career:repair-published-detail-cache-coverage', 'guard:career-detail-cache-coverage')",
            "after('guard:career-detail-cache-coverage', 'career:warm-public-authority-cache')",
            "after('career:warm-public-authority-cache', 'career:rebuild-directory-after-detail-repair')",
            "after('career:rebuild-directory-after-detail-repair', 'guard:career-discoverability-pre-sitemap')",
            "after('guard:career-discoverability-pre-sitemap', 'seo:warm-sitemap-source-cache')",
            "after('seo:warm-sitemap-source-cache', 'guard:career-discoverability-post-sitemap')",
            "after('guard:career-discoverability-post-sitemap', 'guard:public-content-release')",
        ];
        $previous = -1;
        foreach ($orderedHooks as $hook) {
            $position = strpos($source, $hook);
            $this->assertNotFalse($position, $hook);
            $this->assertGreaterThan($previous, $position, $hook);
            $previous = $position;
        }
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
        $this->assertStringContainsString('actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a', $source);
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
            '.github/workflows/deploy.yml',
            '.github/workflows/mbti-comp-runtime46-production-ops.yml',
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

        $this->assertStringContainsString(
            'backend/docs/career/publish_track_reconciliation.json)',
            $source
        );
        $this->assertStringContainsString('REQUIRE_CAREER_CANDIDATE_PREFLIGHT=true', $source);
        $this->assertStringContainsString(
            'EXPECTED_CAREER_RECONCILIATION_SHA256="98880c3de1473e1dd9ff2466e256a888ccad3620540ad7d42b19d556cefff184"',
            $source
        );
        $this->assertStringContainsString(
            'EXPECTED_CAREER_PUBLIC_CACHE_SUMMARY_SHA256="53e4e854dadee2260637c0288ead58818808bad098c3fce8e2168ea38746ba09"',
            $source
        );
        $this->assertStringContainsString(
            'AUDITED_STALE_CAREER_PUBLIC_CACHE_SUMMARY_SHA256="cb248a673e4827a4dcaae3f41f74cffd50d99f73984286cb733e6ba0b935158b"',
            $source
        );
        $this->assertStringContainsString('CAREER_CACHE_REPAIR_REQUIRED=true', $source);
        $this->assertStringContainsString(
            'pending atomic candidate repair and rollback protection.',
            $source
        );
        $this->assertSame(4, substr_count($source, 'read_career_public_cache_summary_sha256)"'));
        $this->assertSame(2, substr_count(
            $source,
            'curl --http1.1 --connect-timeout 3 --max-time 10 -fsS'
        ));
        $this->assertStringNotContainsString('--retry-all-errors', $source);
        $this->assertStringNotContainsString('third_career_summary_sha256', $source);
        $this->assertStringNotContainsString('third_live_summary_sha256', $source);
        $this->assertStringContainsString(
            'backend/scripts/deploy/career_candidate_exact_cache_bootstrap.php',
            $source
        );
        $this->assertStringContainsString(
            'backend/app/Console/Commands/CareerVerifyPublicDatasetCacheEquivalence.php',
            $source
        );
        $this->assertStringContainsString(
            'EXPECTED_CAREER_EQUIVALENCE_VERIFIER_SHA256="e02a12517b69de48eab515d8ea9f48dadf6bb30345cfba0f99d0538d89b0de30"',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed Career dataset equivalence verifier hash.',
            $source
        );
        $this->assertStringContainsString(
            'EXPECTED_CAREER_BOOTSTRAP_RUNNER_SHA256="603d5d7f1d53057903ec76baad238a818c8ecefbd0d00f0f312e01d57068de86"',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed Career candidate bootstrap runner hash.',
            $source
        );
        $this->assertStringContainsString(
            'backend/scripts/deploy/verify_sitemap_source_cache_warm.sh',
            $source
        );
        $this->assertStringContainsString(
            'EXPECTED_SITEMAP_WARM_VERIFIER_SHA256="04f9d6b6b66b0be10a4996064cdf9150e1b0f1ec300edf93f87e8c0368ea0713"',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed sitemap source-cache warm verifier hash.',
            $source
        );
        $this->assertStringContainsString(
            'backend/scripts/deploy/immutable_candidate_sitemap_control.php',
            $source
        );
        $this->assertStringContainsString(
            'EXPECTED_IMMUTABLE_SITEMAP_CONTROL_SHA256="5111aac8197ba8df85698eab2a199475baab7ef7456db01dfb698ed54a928dcf"',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope refused an unreviewed immutable sitemap control hash.',
            $source
        );
        $this->assertStringContainsString(
            'code-only scope classification accepted the exact audited Runtime 46 subsumed baseline.',
            $source
        );
        $this->assertStringContainsString(
            'git fetch --no-tags origin "$EXPECTED_DEPLOYED_REVISION"',
            $source
        );
        $this->assertStringContainsString(
            'actual_runtime46_diff="$(git diff --no-renames --name-status "$CLASSIFICATION_BASE" "$EXPECTED_DEPLOYED_REVISION")"',
            $source
        );
        $this->assertStringContainsString(
            '$(git rev-parse "${DEPLOY_SHA}:${runtime46_path}")',
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
        $this->assertStringNotContainsString('.github/workflows/career-detail-staging-cache-repair.yml', $source);
        $this->assertStringNotContainsString('.github/workflows/mbti-comp-runtime46-staging-dry-run.yml', $source);
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
        $restartScript = $this->readRepoFile('backend/scripts/deploy/restart_supervisor_program_group.sh');
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
            "'career:verify-public-dataset-cache-equivalence'",
            "'guard:public-content-release'",
            "'deploy:publish'",
            "'career:finalize-public-dataset-cache-equivalence'",
        ] as $requiredTask) {
            $this->assertStringContainsString($requiredTask, $task);
        }
        $guardPosition = strpos($task, "'guard:public-content-release'");
        $careerPosition = strpos($task, "'career:verify-public-dataset-cache-equivalence'");
        $publishPosition = strpos($task, "'deploy:publish'");
        $finalizePosition = strpos($task, "'career:finalize-public-dataset-cache-equivalence'");
        $this->assertNotFalse($guardPosition);
        $this->assertNotFalse($careerPosition);
        $this->assertNotFalse($publishPosition);
        $this->assertNotFalse($finalizePosition);
        $this->assertLessThan($careerPosition, $guardPosition);
        $this->assertLessThan($publishPosition, $careerPosition);
        $this->assertLessThan($finalizePosition, $publishPosition);

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
        $this->assertStringContainsString("deployBooleanOption('require_career_candidate_preflight', false)", $source);
        $this->assertStringContainsString(
            'career:verify-public-dataset-cache-equivalence --expected-sha256=%s --expected-current-sha256=%s --verify-live-public-cache%s --json --no-interaction --ansi',
            $source
        );
        $this->assertStringContainsString("set('career_public_cache_summary_sha256', '');", $source);
        $this->assertStringContainsString("set('career_expected_candidate_summary_sha256', '');", $source);
        $this->assertStringContainsString("set('career_cache_repair_required', false);", $source);
        $this->assertStringContainsString('--rollback-repair --json --no-interaction --ansi', $source);
        $this->assertStringContainsString(
            "before('fap:deploy-unlock-owned', 'career:rollback-public-dataset-cache-equivalence')",
            $source
        );
        $this->assertStringContainsString(
            'test(\'[ "$(readlink -f {{deploy_path}}/current)" = "$(readlink -f {{release_path}})" ]\')',
            $source
        );
        $this->assertStringContainsString(
            'Keep the candidate-exact Career dataset cache because this release is already active',
            $source
        );
        $this->assertStringContainsString(
            "invoke('career:finalize-public-dataset-cache-equivalence');",
            $source
        );
        $this->assertStringContainsString("\$requiredPrograms[] = 'fap-queue-ops';", $source);
        $this->assertStringContainsString("static fn (string \$program): bool => \$program !== 'fap-queue-ops'", $source);
        $this->assertStringContainsString('Require the ops queue worker for the production approval runtime topology', $source);
        $this->assertStringContainsString('production approval runtime requires the supervisor ops queue reload path', $source);
        $this->assertStringContainsString(
            '{{release_path}}/backend/scripts/deploy/restart_supervisor_program_group.sh',
            $source,
        );
        $this->assertStringContainsString("' --attempts=3'", $source);
        $this->assertStringContainsString("' --delay-seconds=2'", $source);
        $this->assertStringContainsString("' --restart-timeout-seconds=390'", $source);
        $this->assertStringContainsString("' --heartbeat-seconds=20'", $source);
        $this->assertStringContainsString('" --timeout-bin={$quotedTimeout}"', $source);
        $this->assertSame(
            2,
            substr_count($source, 'run($restartSupervisorProgram($program, '),
        );
        $this->assertSame(
            2,
            substr_count($source, '), timeout: 1200);'),
        );
        $this->assertStringNotContainsString(
            'restart {$quotedProgramAll} >/dev/null 2>&1 || sudo -n {$quotedSupervisorctl} restart {$quotedProgram}',
            $source,
        );
        $this->assertStringContainsString('target_exists "${program}:*" "group"', $restartScript);
        $this->assertStringContainsString('target_is_running "$target" "$target_kind"', $restartScript);
        $this->assertStringContainsString(
            'supervisor_program_restart_failed program=%s attempts=%s',
            $restartScript,
        );
        $this->assertStringContainsString(
            'supervisor_program_restart_heartbeat program=%s attempt=%s',
            $restartScript,
        );
        $this->assertStringContainsString(
            'supervisor_program_restart_timeout program=%s attempt=%s',
            $restartScript,
        );
        $this->assertStringContainsString('--kill-after=5s', $restartScript);
        $this->assertStringContainsString('trap \'cleanup_restart; exit 143\' HUP INT TERM', $restartScript);
        $this->assertStringContainsString('active_restart_pgid=$active_restart_pid', $restartScript);
        $this->assertStringContainsString('kill -TERM -- "-$active_restart_pgid"', $restartScript);
        $this->assertStringContainsString('kill -KILL -- "-$active_restart_pgid"', $restartScript);
        $this->assertStringNotContainsString("task('ensure:required-ops-queue-supervisor-program'", $source);
        $this->assertStringContainsString("after('deploy:symlink', 'queue:reload-workers');", $source);
        $this->assertStringNotContainsString('Skip queue worker reload in code_only deploy mode', $source);
        $this->assertStringContainsString('Skip nginx reload in code_only deploy mode', $source);
        $this->assertStringContainsString('Skip auth guest POST contract probe in authority-mutation-free deploy mode', $source);
        $this->assertStringContainsString('Skip shared content package copy in authority-mutation-free deploy mode', $source);
    }

    #[Test]
    public function candidate_only_materializes_an_exact_inactive_release_without_authority_or_activation(): void
    {
        $workflow = $this->readRepoFile('.github/workflows/deploy-production.yml');
        $deployer = $this->readRepoFile('deploy.php');

        foreach ([
            'auto|code_only|candidate_only|schema_only|standard) ;;',
            'candidate_only accepted an empty diff for the exact-active same-SHA inactive-release fast path.',
            'exact-active candidate_only fast path requires staging_run_id to be empty.',
            'Exact-active candidate_only fast path requires the candidate SHA to equal expected_deployed_revision.',
            'Exact-active candidate_only fast path requires the active SHA to remain reachable from latest main.',
            'Inactive candidate materialization requires expected_release_sha to remain reachable from latest main.',
            'Inactive candidate materialization requires exact-SHA successful staging evidence.',
            'Inactive candidate materialization excludes newer main commits from this bounded release.',
            'candidate_only materialization requires the current active revision to be an ancestor of the candidate.',
            'candidate_only materialization refused an unexplained empty active-to-candidate diff.',
            'candidate_only accepted an exact staged main-ancestor artifact without reusing the code-only cumulative path allowlist.',
            'I explicitly approve bounded backend inactive candidate materialization for exact SHA ${DEPLOY_SHA} using exact staging run ${STAGING_RUN_ID} from active SHA ${EXPECTED_DEPLOYED_REVISION}, excluding all newer main commits, release ${RELEASE_ID}; distinct inactive release path, zero activation.',
            'I explicitly approve backend exact-active inactive candidate materialization for SHA ${DEPLOY_SHA} release ${RELEASE_ID}; same revision, distinct release path, zero activation.',
            'DEPLOY_TASK=deploy:candidate-only',
            '- name: Revalidate bounded inactive candidate identities before writes',
            'Candidate-only pre-write guard requires the control-plane SHA to remain reachable from current main.',
            'Candidate-only pre-write guard requires the candidate SHA to remain an ancestor of the control plane and current main.',
            'Candidate-only pre-write guard requires exact staging evidence for the candidate SHA.',
            '- name: Verify exact inactive candidate materialization',
            'backend.inactive_candidate_materialization.v2',
            'PASS_INACTIVE_CANDIDATE_MATERIALIZED',
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
            '- name: Upload inactive candidate materialization receipt',
            "if: \${{ needs.deployment-eligibility.outputs.deploy_mode != 'candidate_only' }}",
            'SSH and deploy-root preflight passed without disclosing topology.',
            'deploy lock guard: existing lock detected age_seconds=$AGE_SECONDS',
            'deploy lock guard: candidate-only materialization refuses to remove an existing lock automatically',
        ] as $contract) {
            $this->assertStringContainsString($contract, $workflow);
        }
        $this->assertStringNotContainsString('whoami; hostname; id', $workflow);
        $this->assertStringNotContainsString('sed -n \'1,120p\' "$META"', $workflow);
        $this->assertStringNotContainsString('echo "$ACTIVE_PROCESSES"', $workflow);
        $this->assertLessThan(
            strpos($workflow, '- name: Deploy production with Deployer'),
            strpos($workflow, '- name: Revalidate bounded inactive candidate identities before writes')
        );
        $this->assertTrue(
            strpos($workflow, '- name: Verify mutation-limited deployed baseline before writes')
            < strpos($workflow, '- name: Revalidate bounded inactive candidate identities before writes')
        );

        $start = strpos($deployer, "task('deploy:candidate-only', [");
        $this->assertNotFalse($start);
        $end = strpos($deployer, ']);', (int) $start);
        $this->assertNotFalse($end);
        $task = substr($deployer, (int) $start, (int) $end - (int) $start + 3);

        foreach ([
            "'guard:candidate-only-mode'",
            "'deploy:prepare'",
            "'deploy:vendors'",
            "'artisan:storage:link'",
            "'artisan:config:cache'",
            "'artisan:route:cache'",
            "'artisan:event:cache'",
            "'guard:public-content-release'",
            "'fap:deploy-unlock-owned'",
        ] as $requiredTask) {
            $this->assertStringContainsString($requiredTask, $task);
        }

        foreach ([
            'deploy:publish',
            'deploy:symlink',
            'artisan:migrate',
            'artisan:scales:seed-default',
            'cms:import-landing-surface-baselines',
            'cms:import-content-page-baselines',
            'career:warm-public-authority-cache',
            'career:verify-public-dataset-cache-equivalence',
            'seo:warm-sitemap-source-cache',
            'queue:reload-workers',
        ] as $forbiddenTask) {
            $this->assertStringNotContainsString($forbiddenTask, $task);
        }

        $this->assertStringContainsString(
            "return in_array(deployMode(), ['code_only', 'candidate_only', 'schema_only'], true);",
            $deployer
        );
        $this->assertStringContainsString(
            '.github/workflows/backend-production-ops-queue-control.yml|deploy/supervisor/fap-queue-ops.conf.template',
            $workflow,
        );
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
            'standard deploy requires approved_migration to be omitted; do not submit false. Use schema_only for an exact migration.',
            'auto/code_only/candidate_only refuses approved_migration; use schema_only for an exact migration.',
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
            "'guard:career-runtime-projection-authority'",
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
        $this->assertStringContainsString("return in_array(deployMode(), ['code_only', 'candidate_only', 'schema_only'], true);", $deployer);
        $this->assertSame(5, substr_count($deployer, 'if (deploySkipsAuthorityMutations())'));
        $this->assertStringContainsString('Skip nginx static media route mutation in authority-mutation-free deploy mode', $deployer);
        $this->assertStringContainsString('Skip auth guest POST contract probe in authority-mutation-free deploy mode', $deployer);
        $this->assertStringContainsString('Skip shared content package copy in authority-mutation-free deploy mode', $deployer);
    }

    #[Test]
    public function ordinary_deploy_excludes_cms_baseline_import_tasks_and_hooks(): void
    {
        $deployer = $this->readRepoFile('deploy.php');

        $this->assertStringNotContainsString("task('cms:import-landing-surface-baselines'", $deployer);
        $this->assertStringNotContainsString("task('cms:import-content-page-baselines'", $deployer);
        $this->assertStringNotContainsString('landing-surfaces:import-local-baseline', $deployer);
        $this->assertStringNotContainsString('content-pages:import-local-baseline', $deployer);
        $this->assertStringContainsString(
            "after('artisan:scales:seed-default', 'guard:career-runtime-projection-authority');",
            $deployer,
        );
        $this->assertStringContainsString(
            "after('guard:career-detail-cache-coverage', 'career:warm-public-authority-cache');",
            $deployer,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadDatabaseConfigWithSslEnvironment(?string $ca, string $verify): array
    {
        $names = ['MYSQL_ATTR_SSL_CA', 'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'];
        $snapshot = [];

        foreach ($names as $name) {
            $snapshot[$name] = [
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'getenv' => getenv($name),
            ];
        }

        try {
            $this->setEnvironmentValue('MYSQL_ATTR_SSL_CA', $ca);
            $this->setEnvironmentValue('MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', $verify);

            /** @var array{connections: array<string, array<string, mixed>>} $config */
            $config = require dirname(__DIR__, 2).'/config/database.php';

            return $config['connections'];
        } finally {
            foreach ($snapshot as $name => $values) {
                if ($values['env_exists']) {
                    $_ENV[$name] = $values['env'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($values['server_exists']) {
                    $_SERVER[$name] = $values['server'];
                } else {
                    unset($_SERVER[$name]);
                }

                if ($values['getenv'] === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$values['getenv']);
                }
            }
        }
    }

    private function setEnvironmentValue(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    private function mysqlSslCaAttribute(): int
    {
        return PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA;
    }

    private function mysqlSslVerifyServerCertificateAttribute(): int
    {
        return PHP_VERSION_ID >= 80500
            ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
            : \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
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
