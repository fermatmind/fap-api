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
    public function deploy_keeps_career_runtime_publication_authority_readable_by_php_fpm(): void
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
            "ensureOwnedWritableTree(deploySharedPath(\$base, \$relativePath), \$owner, 'www-data');",
            $source,
        );
        $this->assertStringContainsString('find {$quotedPath} -type d -exec chmod 2775', $source);
        $this->assertStringContainsString('find {$quotedPath} -type f -exec chmod 664', $source);
    }

    #[Test]
    public function sitemap_cache_warm_uses_the_php_fpm_identity_for_shared_file_cache_writes(): void
    {
        $source = $this->readRepoFile('deploy.php');

        $this->assertStringContainsString(
            "sudo -n -u www-data -- {{bin/php}} %s seo:warm-sitemap-source-cache",
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
    public function production_auto_deploy_requires_successful_staging_and_latest_main(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        $this->assertStringContainsString("workflows: [\"Deploy Application\"]", $source);
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $source);
        $this->assertStringContainsString("github.event.workflow_run.event == 'push'", $source);
        $this->assertStringContainsString("github.event.workflow_run.head_branch == 'main'", $source);
        $this->assertStringContainsString('Confirm approved revision is still latest main', $source);
        $this->assertStringContainsString('if [ "$DEPLOY_SHA" != "$LATEST_MAIN_SHA" ]', $source);
        $this->assertStringContainsString('--revision "$DEPLOY_SHA"', $source);
        $this->assertStringContainsString("tr -d '\\r\\n' < REVISION", $source);
        $this->assertStringContainsString('Single-developer mode: a latest-main revision with a successful staging deploy is eligible for automatic production deployment regardless of PR labels or changed paths.', $source);
        $this->assertStringNotContainsString('Production auto-deploy requires exactly one merged PR', $source);
        $this->assertStringNotContainsString('/repos/${GITHUB_REPOSITORY}/pulls/${pr_number}/files', $source);
    }

    #[Test]
    public function manual_production_deploy_requires_exact_approval_and_matching_staging_evidence(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        $this->assertStringContainsString('expected_release_sha:', $source);
        $this->assertStringContainsString('staging_run_id:', $source);
        $this->assertStringContainsString('release_id:', $source);
        $this->assertStringContainsString('operator_approval_phrase:', $source);
        $this->assertStringContainsString('actions: read', $source);
        $this->assertStringContainsString('Validate manual exact-SHA approval and staging evidence', $source);
        $this->assertStringContainsString('I explicitly approve backend production deploy for SHA ${DEPLOY_SHA} release ${RELEASE_ID}.', $source);
        $this->assertStringContainsString('.name == "Deploy Application"', $source);
        $this->assertStringContainsString('.path == ".github/workflows/deploy.yml"', $source);
        $this->assertStringContainsString('.head_branch == "main"', $source);
        $this->assertStringContainsString('.head_sha == $sha', $source);
        $this->assertStringContainsString('.name == "Deploy checks (staging)" and .conclusion == "success"', $source);
        $this->assertStringContainsString('.name == "Deploy (staging)" and .conclusion == "success"', $source);
        $this->assertStringContainsString('Manual production deploy refused because expected_release_sha is not latest main.', $source);
        $this->assertStringContainsString('-o release_name="${RELEASE_ID}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"', $source);
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
    public function production_deploy_lock_guard_retries_the_full_script_and_only_removes_verified_stale_ci_locks(): void
    {
        $source = $this->readRepoFile('.github/workflows/deploy-production.yml');

        $this->assertStringContainsString('LOCK_GUARD_SCRIPT="$(cat', $source);
        $this->assertStringContainsString('LOCK_GUARD_B64="$(printf', $source);
        $this->assertStringContainsString("base64 -d | bash", $source);
        $this->assertStringNotContainsString("ssh_retry \"TARGET='\$TARGET' DEPLOY_PATH='\$DEPLOY_PATH' bash -s\" <<'REMOTE'", $source);
        $this->assertStringContainsString('STALE_LOCK_SECONDS="${STALE_LOCK_SECONDS:-1800}"', $source);
        $this->assertStringContainsString('[ "$OWNER" = "ci" ]', $source);
        $this->assertStringContainsString("grep -Fq '\"env\":\"production\"'", $source);
        $this->assertStringContainsString("grep -Fq '\"repository\":\"'\"\$GITHUB_REPOSITORY\"'\"'", $source);
        $this->assertStringContainsString('deploy lock guard: active deploy-like process exists', $source);
        $this->assertStringContainsString('rm -f "$LOCK" "$META"', $source);
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
