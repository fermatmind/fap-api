<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DeployStorageAndDatabaseConfigTest extends TestCase
{
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
        $this->assertStringContainsString('if [ "$DEPLOY_SHA" != "$LATEST_MAIN_SHA" ]', $source);
        $this->assertStringContainsString('Manual standard production deploy refused because expected_release_sha is not latest main.', $source);
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
        $this->assertStringContainsString('I explicitly approve backend production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('I explicitly approve backend code-only production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('I explicitly approve backend schema-only production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID} migration ${APPROVED_MIGRATION}.', $source);
        $this->assertStringContainsString('expected_deployed_revision as a lowercase 40-character deployed REVISION', $source);
        $this->assertStringContainsString('.name == "Deploy Application"', $source);
        $this->assertStringContainsString('.path == ".github/workflows/deploy.yml"', $source);
        $this->assertStringContainsString('.head_branch == "main"', $source);
        $this->assertStringContainsString('.head_sha == $sha', $source);
        $this->assertStringContainsString('.name == "Deploy checks (staging)" and .conclusion == "success"', $source);
        $this->assertStringContainsString('.name == "Deploy (staging)" and .conclusion == "success"', $source);
        $this->assertStringContainsString('Manual standard production deploy refused because expected_release_sha is not latest main.', $source);
        $this->assertStringContainsString('Code-only production deploy refused because expected_release_sha is not reachable from latest main.', $source);
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
        $this->assertStringContainsString("getenv('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS') ?: 2092", $source);
        $this->assertStringContainsString("before('deploy:symlink', 'guard:career-detail-cache-coverage')", $source);
        $this->assertStringNotContainsString('career:verify-job-detail-cache-coverage --repair-missing', $source);
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

        $this->assertStringContainsString('Skip queue worker reload in code_only deploy mode', $source);
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
