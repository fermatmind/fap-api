<?php

namespace Deployer;

require 'recipe/laravel.php';

/**
 * ======================================================
 * 基础信息
 * ======================================================
 */
set('application', 'fap-api');
set('repository', 'git@github.com:fermatmind/fap-api.git');

set('git_tty', false);
set('keep_releases', 5);
set('default_timeout', 900);
set('deploy_mode', 'standard');

set('sentry_release', function () {
    return get('release_name');
});

/**
 * ======================================================
 * Laravel 在 backend 子目录
 * ======================================================
 */
set('public_path', 'backend/public');

set('bin/php', 'php');
set('bin/composer', 'composer');

/**
 * ======================================================
 * Shared / Writable
 * ======================================================
 */
set('shared_files', [
    'backend/.env',
]);

set('shared_dirs', [
    'backend/storage',
    'content_packages',
]);

set('writable_dirs', [
    'backend/storage',
    'backend/bootstrap/cache',
]);

// 使用 chmod，避免 ACL/权限坑
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_use_sudo', false);
set('cleanup_use_sudo', true);

/**
 * ======================================================
 * 默认 healthcheck / nginx / php-fpm
 * ======================================================
 */
set('healthcheck_scheme', 'https');
set('healthcheck_use_resolve', true);
set('static_media_healthcheck_use_resolve', false);
set('nginx_site', '/etc/nginx/sites-enabled/fap-api');
set('php_fpm_service', 'php8.4-fpm');
set('queue_manager', 'supervisor');
set('queue_supervisorctl', '/usr/bin/supervisorctl');
set('queue_supervisor_required_programs', [
    'fap-queue-default-high',
    'fap-queue-reports',
]);
set('queue_supervisor_optional_programs', [
    'fap-queue-ops',
    'fap-queue-commerce',
    'fap-queue-content',
    'fap-queue-insights',
]);
set('require_ops_queue_reload', false);
set('legacy_queue_systemd_service', 'fap-queue.service');
set('legacy_queue_systemd_disable', true);
set('required_public_static_media_assets', [
    'backend/public/static/social/wechat-qr-official-258.jpg',
    'backend/public/static/social/wechat-qr.jpg',
    'backend/public/static/share/mbti_wide_1200x630.png',
    'backend/public/static/share/mbti_square_600x600.png',
]);
set('required_public_scale_lookup_slugs', [
    'mbti-personality-test-16-personality-types',
    'big-five-personality-test-ocean-model',
    'enneagram-personality-test-nine-types',
    'iq-test-intelligence-quotient-assessment',
    'clinical-depression-anxiety-assessment-professional-edition',
]);
set('scale_lookup_healthcheck_host', 'api.fermatmind.com');
set('scale_lookup_healthcheck_use_resolve', false);
set('deploy_lock_metadata_path', '.dep/deploy.lock.meta.json');

/**
 * ======================================================
 * SSH identity helpers
 * ======================================================
 */
function resolveDeployIdentityFile(string $envKey, array $candidates = []): ?string
{
    $fromEnv = getenv($envKey);
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return trim($fromEnv);
    }

    foreach ($candidates as $candidate) {
        $expanded = preg_replace('/^~/', getenv('HOME') ?: '', $candidate);
        if (is_string($expanded) && $expanded !== '' && is_file($expanded)) {
            return $candidate;
        }
    }

    return null;
}

function deployShellArg(string $value): string
{
    return escapeshellarg($value);
}

function deployMode(): string
{
    $mode = strtolower(trim((string) get('deploy_mode', 'standard')));

    if (! in_array($mode, ['standard', 'code_only', 'schema_only'], true)) {
        throw new \RuntimeException("unsupported deploy_mode [{$mode}]");
    }

    return $mode;
}

function deployIsCodeOnly(): bool
{
    return deployMode() === 'code_only';
}

function deploySkipsAuthorityMutations(): bool
{
    return in_array(deployMode(), ['code_only', 'schema_only'], true);
}

function deploySchemaOnlyMigration(): string
{
    if (deployMode() !== 'schema_only') {
        throw new \RuntimeException('schema_only_migration is available only in schema_only deploy mode');
    }

    $migration = trim((string) get('schema_only_migration', ''));
    if (! preg_match('/\A[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+\.php\z/', $migration)) {
        throw new \RuntimeException('schema_only_migration must be one exact Laravel migration filename');
    }

    return $migration;
}

function deploySafeAbsolutePath(string $path, string $label): string
{
    $path = trim($path);

    if ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        throw new \RuntimeException("{$label} must be a non-empty absolute path");
    }

    if (preg_match('#(^|/)\.\.?(/|$)#', $path) || ! preg_match('#\A/[A-Za-z0-9._~+/\-]+\z#', $path)) {
        throw new \RuntimeException("{$label} contains unsafe path characters");
    }

    return $path;
}

function deploySafeRelativePath(string $path, string $label): string
{
    $path = ltrim(trim($path), '/');

    if ($path === '' || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        throw new \RuntimeException("{$label} must be a non-empty relative path");
    }

    if (preg_match('#(^|/)\.\.?(/|$)#', $path) || ! preg_match('#\A[A-Za-z0-9._~+/\-]+\z#', $path)) {
        throw new \RuntimeException("{$label} contains unsafe path characters");
    }

    return $path;
}

function deployPlaceholderPathArg(string $placeholder, string $relative = ''): string
{
    $path = rtrim($placeholder, '/');

    if ($relative !== '') {
        $path .= '/'.deploySafeRelativePath($relative, 'deploy placeholder relative path');
    }

    return deployShellArg($path);
}

function deploySafeHost(string $host, string $label): string
{
    $host = strtolower(trim($host));

    if ($host === '' || ! preg_match('/\A[A-Za-z0-9.-]+\z/', $host) || str_contains($host, '..')) {
        throw new \RuntimeException("{$label} contains unsafe host characters");
    }

    return $host;
}

function deployCurlResolveArg(string $host, bool $enabled): string
{
    if (! $enabled) {
        return '';
    }

    return '--resolve '.deployShellArg(deploySafeHost($host, 'curl resolve host').':443:127.0.0.1').' ';
}

function deployHttpsUrlArg(string $host, string $path): string
{
    $host = deploySafeHost($host, 'https URL host');
    $path = '/'.ltrim($path, '/');

    if (preg_match('/[\x00-\x1F\x7F]/', $path) || str_contains($path, '..')) {
        throw new \RuntimeException('https URL path contains unsafe characters');
    }

    return deployShellArg("https://{$host}{$path}");
}

function deployPublicDnsHealthcheckCommand(string $host): string
{
    $url = deployHttpsUrlArg($host, '/api/healthz');
    $jq = deployShellArg('.ok==true');
    $probe = "curl -fsS --connect-timeout 5 --max-time 15 {$url} | jq -e {$jq} >/dev/null";

    return 'attempt=1; while [ "$attempt" -le 10 ]; do '
        ."if {$probe}; then exit 0; fi; "
        .'if [ "$attempt" -eq 10 ]; then echo "Public DNS healthcheck failed after 10 attempts" >&2; exit 1; fi; '
        .'attempt=$((attempt + 1)); sleep 3; done';
}

function runProductionPublicDnsHealthcheck(): void
{
    if (currentHost()->getAlias() !== 'production') {
        return;
    }

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    run('bash -lc '.deployShellArg(deployPublicDnsHealthcheckCommand($host)));
}

function deploySystemdServiceArg(string $service, string $label): string
{
    $service = trim($service);

    if ($service === '' || ! preg_match('/\A[A-Za-z0-9_.@:+-]+\z/', $service)) {
        throw new \RuntimeException("{$label} contains unsafe systemd service characters");
    }

    return deployShellArg($service);
}

function deployOwnerGroupArg(string $owner, string $group): string
{
    foreach (['owner' => $owner, 'group' => $group] as $label => $value) {
        if (! preg_match('/\A[A-Za-z0-9_.-]+\z/', $value)) {
            throw new \RuntimeException("deploy {$label} contains unsafe account characters");
        }
    }

    return deployShellArg("{$owner}:{$group}");
}

$productionIdentityFile = resolveDeployIdentityFile('DEPLOY_IDENTITY_FILE_PROD', [
    '~/.ssh/fap_prod',
    '~/.ssh/fap_api_gha',
]);

$stagingIdentityFile = resolveDeployIdentityFile('DEPLOY_IDENTITY_FILE_STG', [
    '~/.ssh/fap_actions_staging',
]);

/**
 * ======================================================
 * Hosts
 * ======================================================
 */
/** @var \Deployer\Host\Host $productionHost */
$productionHost = host('production')
    ->setHostname(getenv('DEPLOY_HOST_PROD') ?: '139.224.130.204')
    ->setRemoteUser(getenv('DEPLOY_USER_PROD') ?: 'ubuntu')
    ->setPort((int) (getenv('DEPLOY_PORT_PROD') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_PROD') ?: '/var/www/fap-api')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_PROD') ?: 'api.fermatmind.com')
    ->set('static_media_healthcheck_host', getenv('STATIC_MEDIA_HEALTHCHECK_HOST_PROD') ?: 'api.fermatmind.com')
    ->set('scale_lookup_healthcheck_host', getenv('SCALE_LOOKUP_HEALTHCHECK_HOST_PROD') ?: 'api.fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_PROD') ?: 'ops.fermatmind.com')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-prod.conf')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_PROD') ?: 'php8.4-fpm')
    ->set('env', [
        'SEO_PUBLIC_SITEMAP_AUTHORITY' => getenv('SEO_PUBLIC_SITEMAP_AUTHORITY_PROD') ?: 'backend',
    ]);

if ($productionIdentityFile !== null) {
    $productionHost->setIdentityFile($productionIdentityFile);
}

/** @var \Deployer\Host\Host $stagingHost */
$stagingHost = host('staging')
    ->setHostname(getenv('DEPLOY_HOST_STG') ?: 'staging.fermatmind.com')
    ->setRemoteUser(getenv('DEPLOY_USER_STG') ?: 'ubuntu')
    ->setPort((int) (getenv('DEPLOY_PORT_STG') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_STG') ?: '/var/www/fap-api-staging')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging.fermatmind.com')
    ->set('static_media_healthcheck_host', getenv('STATIC_MEDIA_HEALTHCHECK_HOST_STG') ?: 'staging-api.fermatmind.com')
    ->set('static_media_healthcheck_use_resolve', true)
    ->set('scale_lookup_healthcheck_host', getenv('SCALE_LOOKUP_HEALTHCHECK_HOST_STG') ?: 'staging-api.fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_STG') ?: '')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_STG') ?: 'php8.4-fpm')
    ->set('env', [
        'SEO_PUBLIC_SITEMAP_AUTHORITY' => getenv('SEO_PUBLIC_SITEMAP_AUTHORITY_STG') ?: 'backend',
    ]);

if ($stagingIdentityFile !== null) {
    $stagingHost->setIdentityFile($stagingIdentityFile);
}

/**
 * Fail before the current symlink moves unless Deployer materialized the
 * exact revision authorized by the production workflow_run event.
 */
task('guard:expected-release-revision', function () {
    if (currentHost()->getAlias() !== 'production') {
        return;
    }

    $expectedRevision = trim((string) (getenv('DEPLOY_SHA') ?: ''));

    if (! preg_match('/\A[a-f0-9]{40}\z/i', $expectedRevision)) {
        throw new \RuntimeException('DEPLOY_SHA must be an exact 40-character Git revision');
    }

    $quotedExpectedRevision = deployShellArg(strtolower($expectedRevision));

    run(<<<BASH
set -euo pipefail
test -f '{{release_path}}/REVISION'
release_revision="$(tr -d '\r\n' < '{{release_path}}/REVISION')"
test "\$release_revision" = {$quotedExpectedRevision}
BASH);
});

before('deploy:symlink', 'guard:expected-release-revision');

/**
 * Refuse to move the production symlink while the real public-DNS route is
 * unhealthy. The loopback vhost probe cannot prove that the public edge and
 * origin routing still reach this production service.
 */
task('guard:public-dns-health', function () {
    runProductionPublicDnsHealthcheck();
});

before('deploy:symlink', 'guard:public-dns-health');

/**
 * The current symlink must never expose a Career directory whose published
 * detail routes are not fully backed by verified active/LKG/legacy cache
 * payloads. This is read-only and runs against the release code plus shared
 * runtime cache before activation.
 */
task('guard:career-detail-cache-coverage', function () {
    $minimumTargetsRaw = getenv('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS');
    $minimumTargetsRaw = $minimumTargetsRaw === false || trim($minimumTargetsRaw) === ''
        ? '2092'
        : trim($minimumTargetsRaw);
    if (preg_match('/^[1-9][0-9]*$/D', $minimumTargetsRaw) !== 1) {
        throw new RuntimeException('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS must be a positive base-10 integer.');
    }
    $minimumTargets = (int) $minimumTargetsRaw;
    $timeoutSeconds = (int) (getenv('DEPLOY_CAREER_DETAIL_COVERAGE_TIMEOUT') ?: 180);
    $timeoutSeconds = max(60, $timeoutSeconds);

    run(sprintf(
        'timeout %d {{bin/php}} %s career:verify-job-detail-cache-coverage --verify-only --locales=en,zh-CN --minimum-targets=%d --json --no-interaction --no-ansi',
        $timeoutSeconds,
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
        $minimumTargets,
    ));
});

/**
 * Staging deploys may outlive legacy detail-cache TTLs while rebuilding the
 * directory authority. Repair only the targets that are missing at the end of
 * that rebuild, then keep the complete read-only gate below as the authority.
 * Production is deliberately excluded from this cache-write task.
 */
task('career:repair-staging-detail-cache-coverage', function () {
    if (currentHost()->getAlias() !== 'staging') {
        writeln('<comment>Skipping staging-only Career detail cache repair.</comment>');

        return;
    }

    $maximumRepairsRaw = trim((string) (getenv('DEPLOY_CAREER_DETAIL_MAXIMUM_SYNC_REPAIRS') ?: '250'));
    if (preg_match('/^[1-9][0-9]*$/D', $maximumRepairsRaw) !== 1 || (int) $maximumRepairsRaw > 1000) {
        throw new \RuntimeException('DEPLOY_CAREER_DETAIL_MAXIMUM_SYNC_REPAIRS must be an integer between 1 and 1000.');
    }

    $minimumTargetsRaw = trim((string) (getenv('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS') ?: '2092'));
    if (preg_match('/^[1-9][0-9]*$/D', $minimumTargetsRaw) !== 1) {
        throw new \RuntimeException('DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS must be a positive base-10 integer.');
    }

    run(sprintf(
        'timeout 300 {{bin/php}} %s career:verify-job-detail-cache-coverage --repair-missing-sync --locales=en,zh-CN --minimum-targets=%d --maximum-sync-repairs=%d --json --no-interaction --no-ansi',
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
        (int) $minimumTargetsRaw,
        (int) $maximumRepairsRaw,
    ));
});

before('deploy:symlink', 'guard:career-detail-cache-coverage');
before('guard:career-detail-cache-coverage', 'career:repair-staging-detail-cache-coverage');

task('guard:ops-theme-asset', function () {
    $asset = deployPlaceholderPathArg('{{release_path}}', 'backend/public/css/app/ops-theme.css');

    if (! test("[ -s {$asset} ]")) {
        throw new \RuntimeException("ops theme asset missing or empty: {$asset}");
    }

    $rawSourcePattern = '@tailwind|@config|resources/css/filament/ops/theme\\.css|vendor/filament/filament/resources/css/base\\.css';
    if (test("grep -Eq '{$rawSourcePattern}' {$asset}")) {
        throw new \RuntimeException("ops theme asset is raw source, not compiled CSS: {$asset}");
    }
});

task('guard:filament-assets', function () {
    $assets = [
        'backend/public/css/filament/forms/forms.css',
        'backend/public/css/filament/support/support.css',
        'backend/public/css/filament/filament/app.css',
        'backend/public/js/filament/filament/app.js',
        'backend/public/js/filament/support/support.js',
        'backend/public/js/filament/notifications/notifications.js',
    ];

    foreach ($assets as $asset) {
        $assetPath = deployPlaceholderPathArg('{{release_path}}', $asset);

        if (! test("[ -s {$assetPath} ]")) {
            throw new \RuntimeException("filament asset missing or empty: {$assetPath}");
        }
    }
});

task('bootstrap-cache:clear-release', function () {
    within('{{release_path}}/backend', function () {
        run(<<<'BASH'
{{bin/php}} -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$paths = [
    $app->getCachedConfigPath(),
    $app->getCachedEventsPath(),
    $app->getCachedPackagesPath(),
    $app->getCachedServicesPath(),
];
foreach ($paths as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}
foreach (glob(dirname($app->getCachedRoutesPath()).DIRECTORY_SEPARATOR."routes-*.php") ?: [] as $path) {
    @unlink($path);
}
'
BASH);
    });
});

task('bootstrap-cache:rebuild-current', function () {
    within('{{current_path}}/backend', function () {
        run(<<<'BASH'
{{bin/php}} -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$paths = [
    $app->getCachedConfigPath(),
    $app->getCachedEventsPath(),
    $app->getCachedPackagesPath(),
    $app->getCachedServicesPath(),
];
foreach ($paths as $path) {
    if (is_file($path)) {
        @unlink($path);
    }
}
foreach (glob(dirname($app->getCachedRoutesPath()).DIRECTORY_SEPARATOR."routes-*.php") ?: [] as $path) {
    @unlink($path);
}
'
BASH);
        run('{{bin/php}} artisan package:discover --ansi');
        run('{{bin/php}} artisan config:cache --ansi');
        run('{{bin/php}} artisan route:cache --ansi');
        run('{{bin/php}} artisan event:cache --ansi');
    });
});

task('rollback:healthcheck', [
    'reload:php-fpm',
    'reload:nginx',
    'healthcheck:public',
    'healthcheck:auth-guest-contract',
    'healthcheck:public-static-media-assets',
    'healthcheck:ops-entry-contract',
]);

/**
 * ======================================================
 * Composer（backend）
 * ======================================================
 */
task('deploy:vendors', function () {
    run('cd '.deployPlaceholderPathArg('{{release_path}}', 'backend').' && {{bin/composer}} install --no-interaction --prefer-dist --optimize-autoloader --no-dev');
});

/**
 * ======================================================
 * Artisan（全部强制走 backend）
 * ======================================================
 */
task('artisan:filament:assets', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' filament:assets --ansi');
});

task('artisan:storage:link', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' storage:link --ansi');
});

task('artisan:config:cache', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' config:cache --ansi');
});

task('guard:sitemap-authority', function () {
    within('{{release_path}}/backend', function () {
        run(<<<'BASH'
{{bin/php}} -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$authority = strtolower(trim((string) config("services.seo.public_sitemap_authority", "frontend")));
if ($authority !== "backend") {
    fwrite(STDERR, "SEO_PUBLIC_SITEMAP_AUTHORITY must resolve to backend; got [{$authority}]\n");
    exit(1);
}
echo "SEO sitemap authority: {$authority}\n";
'
BASH);
    });
});

task('artisan:route:cache', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' route:cache --ansi');
});

task('artisan:event:cache', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' event:cache --ansi');
});

task('artisan:migrate', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' migrate --force --no-interaction --ansi');
});

task('artisan:migrate-schema-only', function () {
    $migration = deploySchemaOnlyMigration();
    $migrationPath = 'database/migrations/'.$migration;
    $migrationStem = substr($migration, 0, -4);

    within('{{release_path}}/backend', function () use ($migrationPath, $migrationStem): void {
        run(strtr(<<<'BASH'
set -euo pipefail
test -f __MIGRATION_PATH__
status_before="$({{bin/php}} artisan migrate:status --no-interaction --no-ansi)"
printf '%s\n' "$status_before"
pending_before="$(printf '%s\n' "$status_before" | grep -E '(^|[[:space:]])Pending($|[[:space:]])' || true)"
pending_count="$(printf '%s\n' "$pending_before" | grep -c . || true)"
if [ "$pending_count" -ne 1 ]; then
  echo "schema-only deploy requires exactly one pending migration; found $pending_count" >&2
  exit 1
fi
if ! printf '%s\n' "$pending_before" | grep -Fq __MIGRATION_STEM__; then
  echo "schema-only deploy pending migration does not match the approved migration" >&2
  exit 1
fi
{{bin/php}} artisan migrate --path=__MIGRATION_PATH__ --force --no-interaction --ansi
status_after="$({{bin/php}} artisan migrate:status --no-interaction --no-ansi)"
printf '%s\n' "$status_after"
if printf '%s\n' "$status_after" | grep -Eq '(^|[[:space:]])Pending($|[[:space:]])'; then
  echo "schema-only deploy left pending migrations" >&2
  exit 1
fi
if ! printf '%s\n' "$status_after" | grep -F __MIGRATION_STEM__ | grep -Eq '(^|[[:space:]])Ran($|[[:space:]])'; then
  echo "schema-only deploy could not verify the approved migration as Ran" >&2
  exit 1
fi
BASH, [
            '__MIGRATION_PATH__' => deployShellArg($migrationPath),
            '__MIGRATION_STEM__' => deployShellArg($migrationStem),
        ]));
    });
});

task('guard:no-pending-migrations', function () {
    within('{{release_path}}/backend', function () {
        run(<<<'BASH'
set -euo pipefail
status_output="$({{bin/php}} artisan migrate:status --no-interaction --no-ansi)"
printf '%s\n' "$status_output"
if printf '%s\n' "$status_output" | grep -Eq '(^|[[:space:]])Pending($|[[:space:]])'; then
  echo "pending migrations remain after deploy migrate" >&2
  exit 1
fi
BASH);
    });
});

task('artisan:scales:seed-default', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' fap:scales:seed-default --no-interaction --ansi');
});

task('career:warm-public-authority-cache', function () {
    $timeoutSeconds = (int) (getenv('DEPLOY_CAREER_WARM_CACHE_TIMEOUT') ?: 600);
    $timeoutSeconds = max(180, $timeoutSeconds);
    $killAfterSeconds = (int) (getenv('DEPLOY_CAREER_WARM_CACHE_KILL_AFTER') ?: 30);
    $killAfterSeconds = max(5, $killAfterSeconds);
    $strictWarmCache = filter_var((string) (getenv('DEPLOY_CAREER_WARM_CACHE_STRICT') ?: ''), FILTER_VALIDATE_BOOLEAN);
    $skipWarmCache = filter_var((string) (getenv('DEPLOY_SKIP_CAREER_WARM_CACHE') ?: ''), FILTER_VALIDATE_BOOLEAN);

    if ($skipWarmCache) {
        writeln('<comment>Skipping career:warm-public-authority-cache because DEPLOY_SKIP_CAREER_WARM_CACHE=true</comment>');

        return;
    }

    $command = sprintf(
        'timeout --kill-after=%ds %d {{bin/php}} %s career:warm-public-authority-cache --no-interaction --ansi',
        $killAfterSeconds,
        $timeoutSeconds,
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
    );

    if ($strictWarmCache) {
        run($command);

        return;
    }

    run(sprintf(
        <<<'BASH'
set +e
%s
status=$?
set -e
if [ "$status" -ne 0 ]; then
  echo "career_warm_public_authority_cache_nonblocking_failure=$status"
  echo "Continuing deploy because DEPLOY_CAREER_WARM_CACHE_STRICT is not true."
fi
exit 0
BASH,
        $command,
    ));
});

task('seo:warm-sitemap-source-cache', function () {
    $timeoutSeconds = (int) (getenv('DEPLOY_SEO_SITEMAP_SOURCE_WARM_TIMEOUT') ?: 180);
    $timeoutSeconds = max(120, $timeoutSeconds);

    run(sprintf(
        'timeout %d sudo -n -u www-data -- {{bin/php}} %s seo:warm-sitemap-source-cache --json --no-interaction --ansi',
        $timeoutSeconds,
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
    ));
});

task('guard:public-content-release', function () {
    $strictCareerFlag = filter_var((string) (getenv('DEPLOY_PUBLIC_CONTENT_STRICT_CAREER') ?: ''), FILTER_VALIDATE_BOOLEAN)
        ? ' --strict-career'
        : '';

    run('timeout 180 {{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' release:verify-public-content --content-source-dir='.deployPlaceholderPathArg('{{release_path}}', 'content_baselines/content_pages').' --no-interaction --ansi'.$strictCareerFlag);
});

task('cms:import-landing-surface-baselines', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' landing-surfaces:import-local-baseline --upsert --status=published --source-dir='.deployPlaceholderPathArg('{{release_path}}', 'content_baselines/landing_surfaces').' --no-interaction --ansi');
});

task('cms:import-content-page-baselines', function () {
    run('{{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' content-pages:import-local-baseline --upsert --status=published --source-dir='.deployPlaceholderPathArg('{{release_path}}', 'content_baselines/content_pages').' --no-interaction --ansi');
});

task('artisan:view:cache', function () {
    writeln('<comment>Skip artisan:view:cache (no views)</comment>');
});

/**
 * ======================================================
 * 禁止 destructive migration
 * ======================================================
 */
task('guard:forbid-destructive', function () {
    foreach (['migrate:fresh', 'db:wipe'] as $cmd) {
        task("artisan:{$cmd}", function () use ($cmd) {
            throw new \RuntimeException("FORBIDDEN: php artisan {$cmd}");
        });
    }
});

task('guard:code-only-mode', function () {
    if (! deployIsCodeOnly()) {
        throw new \RuntimeException('deploy:code-only requires deploy_mode=code_only');
    }
});

task('guard:schema-only-mode', function () {
    if (deployMode() !== 'schema_only') {
        throw new \RuntimeException('deploy:schema-only requires deploy_mode=schema_only');
    }

    deploySchemaOnlyMigration();
});

task('guard:deploy-shell-config', function () {
    deploySafeAbsolutePath((string) get('deploy_path'), 'deploy_path');
    deploySafeAbsolutePath((string) get('nginx_site'), 'nginx_site');
    deploySystemdServiceArg((string) get('php_fpm_service'), 'php_fpm_service');
    deployOwnerGroupArg(currentHost()->getRemoteUser() ?: 'ubuntu', 'www-data');
    deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    deploySafeHost((string) (get('static_media_healthcheck_host') ?: get('healthcheck_host')), 'static_media_healthcheck_host');
    deploySafeHost((string) (get('scale_lookup_healthcheck_host') ?: get('healthcheck_host')), 'scale_lookup_healthcheck_host');

    $opsEntryHost = trim((string) get('ops_entry_host', ''));
    if ($opsEntryHost !== '') {
        deploySafeHost($opsEntryHost, 'ops_entry_host');
    }

    $legacyQueueService = trim((string) get('legacy_queue_systemd_service', ''));
    if ($legacyQueueService !== '') {
        deploySystemdServiceArg($legacyQueueService, 'legacy_queue_systemd_service');
    }

    foreach ((array) get('required_public_static_media_assets', []) as $asset) {
        $asset = trim((string) $asset);
        if ($asset !== '') {
            deploySafeRelativePath($asset, 'required_public_static_media_assets entry');
        }
    }

    foreach ((array) get('required_public_scale_lookup_slugs', []) as $slug) {
        $slug = trim((string) $slug);
        if ($slug !== '') {
            deploySafeRelativePath($slug, 'required_public_scale_lookup_slugs entry');
        }
    }
});

/**
 * ======================================================
 * 服务重载
 * ======================================================
 */
task('reload:php-fpm', function () {
    $service = deploySystemdServiceArg((string) get('php_fpm_service'), 'php_fpm_service');

    run("sudo -n /usr/bin/systemctl reload {$service}");
});

task('reload:nginx', function () {
    if (deployIsCodeOnly()) {
        writeln('<comment>Skip nginx reload in code_only deploy mode</comment>');

        return;
    }

    run('sudo -n /usr/bin/systemctl reload nginx');
});

task('queue:reload-workers', function () {
    $codeOnly = deployIsCodeOnly();

    $manager = strtolower(trim((string) get('queue_manager', 'supervisor')));

    if ($manager === 'supervisor') {
        $supervisorctl = trim((string) get('queue_supervisorctl', '/usr/bin/supervisorctl'));
        $requiredPrograms = array_values(array_filter((array) get('queue_supervisor_required_programs', []), static fn (mixed $value): bool => trim((string) $value) !== ''));
        $optionalPrograms = array_values(array_filter((array) get('queue_supervisor_optional_programs', []), static fn (mixed $value): bool => trim((string) $value) !== ''));
        $requireOpsQueueReload = $codeOnly && in_array(
            strtolower(trim((string) get('require_ops_queue_reload', 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if ($requireOpsQueueReload) {
            $requiredPrograms[] = 'fap-queue-ops';
            $requiredPrograms = array_values(array_unique($requiredPrograms));
            $optionalPrograms = array_values(array_filter(
                $optionalPrograms,
                static fn (string $program): bool => $program !== 'fap-queue-ops'
            ));
            writeln('<comment>Require the ops queue worker reload for approval runtime code_only scope</comment>');
        }
        $legacySystemdService = trim((string) get('legacy_queue_systemd_service', ''));
        $disableLegacySystemd = (bool) get('legacy_queue_systemd_disable', true);

        if (! $codeOnly) {
            within('{{current_path}}/backend', function () {
                run('{{bin/php}} artisan queue:restart --ansi');
            });
        } else {
            writeln('<comment>Reload queue workers through the process manager without a cache restart signal in code_only deploy mode</comment>');
        }

        $supervisorctlAvailable = test('[ -x '.escapeshellarg($supervisorctl).' ] || command -v supervisorctl >/dev/null 2>&1');
        if (! $supervisorctlAvailable) {
            if ($requireOpsQueueReload) {
                throw new \RuntimeException('approval runtime code_only deploy requires the supervisor ops queue reload path');
            }

            if ($legacySystemdService !== '') {
                $quotedService = deploySystemdServiceArg($legacySystemdService, 'legacy_queue_systemd_service');
                $notFoundMessage = deployShellArg("legacy queue systemd service not found: {$legacySystemdService}");
                writeln('<comment>supervisorctl not found; fallback to legacy systemd queue service</comment>');
                run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1; then sudo -n /usr/bin/systemctl restart {$quotedService}; else printf '%s\\n' {$notFoundMessage} >&2; exit 1; fi");

                return;
            }

            if ($codeOnly) {
                throw new \RuntimeException('code_only deploy requires a queue process manager reload path');
            }

            writeln('<comment>supervisorctl not found and no legacy systemd service configured; skip manager-specific queue reload</comment>');

            return;
        }

        $resolvedSupervisorctl = trim((string) run(
            'if [ -x '.escapeshellarg($supervisorctl).' ]; then echo '.escapeshellarg($supervisorctl).'; else command -v supervisorctl; fi'
        ));
        $quotedSupervisorctl = escapeshellarg($resolvedSupervisorctl);

        run("sudo -n {$quotedSupervisorctl} reread");
        run("sudo -n {$quotedSupervisorctl} update");

        foreach ($requiredPrograms as $program) {
            $quotedProgramAll = escapeshellarg($program.':*');
            $quotedProgram = escapeshellarg($program);
            run("sudo -n {$quotedSupervisorctl} restart {$quotedProgramAll} >/dev/null 2>&1 || sudo -n {$quotedSupervisorctl} restart {$quotedProgram}");
        }

        foreach ($optionalPrograms as $program) {
            $quotedProgramAll = escapeshellarg($program.':*');
            $quotedProgram = escapeshellarg($program);
            run("sudo -n {$quotedSupervisorctl} restart {$quotedProgramAll} >/dev/null 2>&1 || sudo -n {$quotedSupervisorctl} restart {$quotedProgram} >/dev/null 2>&1 || true");
        }

        if ($legacySystemdService !== '') {
            $quotedService = deploySystemdServiceArg($legacySystemdService, 'legacy_queue_systemd_service');
            run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1; then sudo -n /usr/bin/systemctl stop {$quotedService} >/dev/null 2>&1 || true; fi");

            if ($disableLegacySystemd) {
                run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1; then sudo -n /usr/bin/systemctl disable {$quotedService} >/dev/null 2>&1 || true; fi");
            }

            $stillActiveMessage = deployShellArg("legacy queue systemd service still active: {$legacySystemdService}");
            run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1 && sudo -n /usr/bin/systemctl is-active --quiet {$quotedService}; then printf '%s\\n' {$stillActiveMessage} >&2; exit 1; fi");
        }

        return;
    }

    if ($manager === 'systemd') {
        $systemdService = trim((string) get('legacy_queue_systemd_service', ''));
        if ($systemdService === '') {
            throw new \RuntimeException('queue manager systemd requires legacy_queue_systemd_service');
        }

        if (! $codeOnly) {
            within('{{current_path}}/backend', function () {
                run('{{bin/php}} artisan queue:restart --ansi');
            });
        } else {
            writeln('<comment>Reload queue workers through systemd without a cache restart signal in code_only deploy mode</comment>');
        }
        $quotedService = deploySystemdServiceArg($systemdService, 'legacy_queue_systemd_service');
        run("sudo -n /usr/bin/systemctl restart {$quotedService}");

        return;
    }

    throw new \RuntimeException('unsupported queue_manager ['.$manager.']');
});

function deploySharedPath(string $base, string $relative): string
{
    return rtrim($base, '/').'/'.ltrim($relative, '/');
}

function ensureOwnedWritableTree(string $path, string $owner = 'ubuntu', string $group = 'www-data'): void
{
    $quotedPath = escapeshellarg($path);
    $quotedOwnerGroup = deployOwnerGroupArg($owner, $group);

    run("sudo -n /usr/bin/mkdir -p {$quotedPath}");
    run("sudo -n /usr/bin/chown -R {$quotedOwnerGroup} {$quotedPath}");
    run("sudo -n /usr/bin/find {$quotedPath} -type d -exec chmod 2775 {} \\;");
    run("sudo -n /usr/bin/find {$quotedPath} -type f -exec chmod 664 {} \\;");
}

function ensureOwnedWritableDir(string $path, string $owner = 'ubuntu', string $group = 'www-data'): void
{
    $quotedPath = escapeshellarg($path);
    $quotedOwnerGroup = deployOwnerGroupArg($owner, $group);

    run("sudo -n /usr/bin/mkdir -p {$quotedPath}");
    run("sudo -n /usr/bin/chown {$quotedOwnerGroup} {$quotedPath}");
    run("sudo -n /usr/bin/chmod 2775 {$quotedPath}");
}

/**
 * ======================================================
 * 固化权限修复（关键）
 * ======================================================
 */
task('ensure:shared-perms', function () {
    $base = get('deploy_path');
    $owner = currentHost()->getRemoteUser() ?: 'ubuntu';

    // Shared runtime directories are created with a setgid www-data group. PHP-FPM
    // can already access their existing contents, so only repair the directory
    // roots here. Recursively rewriting cache/content trees before every release
    // makes deploy duration depend on historical runtime data and can prevent the
    // release switch from being reached.
    $sharedWritableDirs = [
        'shared/backend/storage/framework/cache',
        'shared/backend/storage/framework/sessions',
        'shared/backend/storage/framework/views',
        'shared/backend/storage/logs',
        'shared/backend/storage/app/content-packs',
        'shared/backend/storage/app/private/career_release_ledger',
        'shared/backend/storage/app/private/career_runtime_publish_projection',
        'shared/backend/storage/app/private/packs_v2_materialized',
    ];

    foreach ($sharedWritableDirs as $relativePath) {
        ensureOwnedWritableDir(deploySharedPath($base, $relativePath), $owner, 'www-data');
    }

    ensureOwnedWritableDir(deploySharedPath($base, 'shared/content_packages'), $owner, 'www-data');
});

task('ensure:release-runtime-perms', function () {
    $owner = currentHost()->getRemoteUser() ?: 'ubuntu';
    $cacheDir = '{{release_path}}/backend/bootstrap/cache';

    ensureOwnedWritableTree($cacheDir, $owner, 'www-data');
});

/**
 * ======================================================
 * runtime dirs（healthz 依赖）
 * ======================================================
 */
task('ensure:healthz-deps', function () {
    $base = get('deploy_path').'/shared/backend/storage';
    $owner = currentHost()->getRemoteUser() ?: 'ubuntu';

    ensureOwnedWritableDir("{$base}/app/content-packs", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/app", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/app/private", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/app/private/artifacts", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/app/private/packs_v2_materialized", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/framework/cache", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/framework/sessions", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/framework/views", $owner, 'www-data');
    ensureOwnedWritableDir("{$base}/logs", $owner, 'www-data');
});

/**
 * ======================================================
 * phpredis 检查
 * ======================================================
 */
task('ensure:phpredis', function () {
    $ok = run('{{bin/php}} -m | grep -i "^redis$" >/dev/null 2>&1; echo $?');
    if (trim($ok) !== '0') {
        throw new \RuntimeException('phpredis missing');
    }
});

task('guard:required-public-static-media-assets', function () {
    $assets = (array) get('required_public_static_media_assets', []);

    foreach ($assets as $asset) {
        $path = trim((string) $asset);

        if ($path === '') {
            continue;
        }

        run('test -s '.deployPlaceholderPathArg('{{release_path}}', $path));
    }
});

task('ensure:release-public-static-compat', function () {
    run('mkdir -p '.deployPlaceholderPathArg('{{release_path}}', 'public'));
    run('ln -sfn '.deployShellArg('../backend/public/static').' '.deployPlaceholderPathArg('{{release_path}}', 'public/static'));
    run('test -s '.deployPlaceholderPathArg('{{release_path}}', 'public/static/social/wechat-qr-official-258.jpg'));
});

task('ensure:nginx-public-static-media-route', function () {
    if (deploySkipsAuthorityMutations()) {
        writeln('<comment>Skip nginx static media route mutation in authority-mutation-free deploy mode</comment>');

        return;
    }

    $host = deploySafeHost((string) (get('static_media_healthcheck_host') ?: get('healthcheck_host')), 'static_media_healthcheck_host');
    $primaryHost = deploySafeHost((string) get('healthcheck_host', ''), 'healthcheck_host');
    $site = deploySafeAbsolutePath((string) get('nginx_site', ''), 'nginx_site');

    if ($host === '' || $primaryHost === '' || $site === '') {
        throw new \RuntimeException('static media nginx route requires static_media_healthcheck_host and nginx_site');
    }

    $snippet = '/etc/nginx/snippets/fap-api-public-static-media-'.preg_replace('/[^A-Za-z0-9_.-]/', '-', $host).'.conf';
    $staticRoot = rtrim(deploySafeAbsolutePath((string) get('deploy_path'), 'deploy_path'), '/').'/current/backend/public/static/';
    $snippetBody = <<<NGINX
# Managed by fap-api deploy. Serve committed backend public static media.
location ^~ /static/ {
    alias {$staticRoot};
    access_log off;
    expires 30d;
    add_header Cache-Control "public, max-age=2592000, immutable" always;
    try_files \$uri =404;
}
NGINX;

    $encodedSnippet = escapeshellarg(base64_encode($snippetBody));
    $quotedSnippet = escapeshellarg($snippet);
    $quotedSite = escapeshellarg($site);
    $quotedHost = escapeshellarg($host);
    $quotedPrimaryHost = escapeshellarg($primaryHost);
    $quotedStaticAsset = escapeshellarg($staticRoot.'social/wechat-qr-official-258.jpg');

    $command = strtr(<<<'BASH'
set -euo pipefail
tmp_site="$(mktemp)"
tmp_script="$(mktemp)"
tmp_snippet="$(mktemp)"
site_backup="$(mktemp /tmp/fap-api-nginx-site-backup.XXXXXX.conf)"
snippet_backup="$(mktemp /tmp/fap-api-nginx-snippet-backup.XXXXXX.conf)"
site_path=__QUOTED_SITE__
snippet_path=__QUOTED_SNIPPET__
snippet_existed=0
static_route_action=install
trap 'rm -f "$tmp_site" "$tmp_script" "$tmp_snippet"; sudo -n rm -f "$site_backup" "$snippet_backup" 2>/dev/null || true' EXIT

printf %s __ENCODED_SNIPPET__ | base64 -d > "$tmp_snippet"
sudo -n test -f "$site_path"

restore_nginx_static_config() {
    echo "nginx static media route: restoring previous site file: $site_path" >&2
    sudo -n cp -p "$site_backup" "$site_path"

    if [ "$snippet_existed" = "1" ]; then
        echo "nginx static media route: restoring previous snippet: $snippet_path" >&2
        sudo -n cp -p "$snippet_backup" "$snippet_path"
    else
        echo "nginx static media route: removing newly-created snippet: $snippet_path" >&2
        sudo -n rm -f "$snippet_path"
    fi

    echo "nginx static media route: validating restored nginx config" >&2
    sudo -n nginx -t
}

cat > "$tmp_script" <<'PHP'
<?php
[$script, $site, $include, $host, $primaryHost] = $argv;

$content = shell_exec('sudo -n /usr/bin/cat ' . escapeshellarg($site));
if (! is_string($content) || $content === '') {
    fwrite(STDERR, "nginx site is empty or unreadable: {$site}\n");
    exit(1);
}

function hasStaticLocation(string $content): bool
{
    return preg_match('/^\\s*location\\s+(?:\\^~|=|~\\*?|~)?\\s*\\/static(?:\\/|\\s|\\{)/m', $content) === 1;
}

function nginxIncludePaths(string $content): array
{
    $includeCount = preg_match_all('/^\\s*include\\s+([^;]+);\\s*$/m', $content, $matches);

    if ($includeCount === false || $includeCount < 1) {
        return [];
    }

    $paths = [];

    foreach ($matches[1] as $includePath) {
        $includePath = trim((string) $includePath, " \t\n\r\0\x0B'\"");

        if ($includePath === '') {
            continue;
        }

        if ($includePath[0] !== '/') {
            $includePath = '/etc/nginx/'.ltrim($includePath, '/');
        }

        if (strpbrk($includePath, '*?[') !== false) {
            $expanded = glob($includePath, GLOB_NOSORT);

            foreach (is_array($expanded) ? $expanded : [] as $path) {
                if (is_string($path) && $path !== '') {
                    $paths[] = $path;
                }
            }

            continue;
        }

        $paths[] = $includePath;
    }

    return array_values(array_unique($paths));
}

function readableIncludeHasStaticLocation(string $content, array $seen = []): bool
{
    foreach (nginxIncludePaths($content) as $includePath) {
        $realPath = realpath($includePath) ?: $includePath;

        if (isset($seen[$realPath])) {
            continue;
        }

        $seen[$realPath] = true;
        $included = shell_exec('sudo -n /usr/bin/cat ' . escapeshellarg($includePath));

        if (is_string($included) && hasStaticLocation($included)) {
            fwrite(STDERR, "existing /static/ location found in included nginx file: {$includePath}; skipping managed static snippet\n");

            return true;
        }

        if (is_string($included) && readableIncludeHasStaticLocation($included, $seen)) {
            return true;
        }
    }

    return false;
}

$content = preg_replace('/^\\s*include\\s+\\/etc\\/nginx\\/snippets\\/fap-api-public-static-media-[^;]+;\\R/m', '', $content);

if (is_string($content) && hasStaticLocation($content)) {
    fwrite(STDERR, "existing /static/ location found in nginx site; skipping managed static snippet\n");
    echo $content;
    exit(2);
}

if (is_string($content) && readableIncludeHasStaticLocation($content)) {
    echo $content;
    exit(2);
}

$includeLine = '    include ' . $include . ';';
$hostPattern = preg_quote($host, '/');
$staticHostPattern = '/(^\\s*server_name\\s+[^;]*\\b' . $hostPattern . '\\b[^;]*;\\s*$)/m';
$next = preg_replace($staticHostPattern, '$1' . PHP_EOL . $includeLine, $content, -1, $count);

if ($count < 1 || ! is_string($next)) {
    $primaryHostPattern = preg_quote($primaryHost, '/');
    $apiHostPattern = '/(^\\s*server_name\\s+[^;]*\\b' . $primaryHostPattern . '\\b)([^;]*)(;\\s*$)/m';
    $next = preg_replace_callback(
        $apiHostPattern,
        static function (array $matches) use ($host, $includeLine): string {
            return $matches[1] . $matches[2] . ' ' . $host . $matches[3] . PHP_EOL . $includeLine;
        },
        $content,
        -1,
        $count
    );

    if ($count < 1 || ! is_string($next)) {
        fwrite(STDERR, "server_name for {$host} or {$primaryHost} not found in {$site}\n");
        exit(1);
    }
}

echo $next;
PHP

set +e
php "$tmp_script" "$site_path" "$snippet_path" __QUOTED_HOST__ __QUOTED_PRIMARY_HOST__ > "$tmp_site"
php_status=$?
set -e

if [ "$php_status" = "2" ]; then
    static_route_action=skip_existing_static_location
elif [ "$php_status" != "0" ]; then
    exit "$php_status"
fi

if sudo -n test -e "$snippet_path"; then
    snippet_existed=1
    sudo -n cp -p "$snippet_path" "$snippet_backup"
    echo "nginx static media route: snippet backup created: $snippet_backup"
else
    echo "nginx static media route: snippet did not exist before update: $snippet_path"
fi

sudo -n cp -p "$site_path" "$site_backup"
echo "nginx static media route: site backup created: $site_backup"

if [ "$static_route_action" = "skip_existing_static_location" ]; then
    echo "nginx static media route: existing /static/ route detected; skipping managed snippet install"
    sudo -n cp "$tmp_site" "$site_path"

    echo "nginx static media route: validating existing static route nginx config"
    if sudo -n nginx -t; then
        echo "nginx static media route: existing static route config valid; keeping update"
    else
        status=$?
        echo "nginx static media route: existing static route config invalid; restoring previous files" >&2
        restore_nginx_static_config
        exit "$status"
    fi

    test -s __QUOTED_STATIC_ASSET__
    echo "nginx static media route: final static asset path verified"
    exit 0
fi

echo "nginx static media route: installing candidate snippet and site config"
sudo -n cp "$tmp_snippet" "$snippet_path"
sudo -n cp "$tmp_site" "$site_path"

echo "nginx static media route: validating candidate nginx config"
if sudo -n nginx -t; then
    echo "nginx static media route: candidate nginx config valid; keeping update"
else
    status=$?
    echo "nginx static media route: candidate nginx config invalid; restoring previous files" >&2
    restore_nginx_static_config
    exit "$status"
fi

test -s __QUOTED_STATIC_ASSET__
echo "nginx static media route: final static asset path verified"
BASH, [
        '__ENCODED_SNIPPET__' => $encodedSnippet,
        '__QUOTED_HOST__' => $quotedHost,
        '__QUOTED_PRIMARY_HOST__' => $quotedPrimaryHost,
        '__QUOTED_SITE__' => $quotedSite,
        '__QUOTED_SNIPPET__' => $quotedSnippet,
        '__QUOTED_STATIC_ASSET__' => $quotedStaticAsset,
    ]);

    run($command);
});

/**
 * ======================================================
 * Healthcheck
 * ======================================================
 */
task('healthcheck:public', function () {
    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/healthz');
    $jq = deployShellArg('.ok==true');
    $cmd = "curl -fsS {$resolveArg}{$url} | jq -e {$jq}";
    run($cmd);
});

task('healthcheck:public-dns', function () {
    runProductionPublicDnsHealthcheck();
});

task('healthcheck:auth-guest-contract', function () {
    if (deploySkipsAuthorityMutations()) {
        writeln('<comment>Skip auth guest POST contract probe in authority-mutation-free deploy mode</comment>');

        return;
    }

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/v0.3/auth/guest');
    $payload = escapeshellarg((string) json_encode([
        'anon_id' => 'deploy_contract_probe',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $contentType = deployShellArg('Content-Type: application/json');
    $jq = deployShellArg('.ok==true and .anon_id=="deploy_contract_probe"');

    $cmd = "curl -fsS {$resolveArg}-H {$contentType} -X POST {$url} --data {$payload} | jq -e {$jq}";
    run($cmd);
});

task('healthcheck:public-static-media-assets', function () {
    $host = deploySafeHost((string) (get('static_media_healthcheck_host') ?: get('healthcheck_host')), 'static_media_healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('static_media_healthcheck_use_resolve', false));
    $assets = (array) get('required_public_static_media_assets', []);
    $contentTypePattern = deployShellArg('^content-type: image/');

    foreach ($assets as $asset) {
        $assetPath = trim((string) $asset);

        if ($assetPath === '') {
            continue;
        }

        $assetPath = deploySafeRelativePath($assetPath, 'static media healthcheck asset');
        $path = '/'.ltrim(preg_replace('#^backend/public/#', '', $assetPath) ?? '', '/');

        if ($path === '/') {
            continue;
        }

        $url = deployHttpsUrlArg($host, $path);
        run("curl -fsSI {$resolveArg}{$url} | grep -Ei {$contentTypePattern} >/dev/null");
    }
});

task('healthcheck:scale-lookup', function () {
    $host = deploySafeHost((string) (get('scale_lookup_healthcheck_host') ?: get('healthcheck_host')), 'scale_lookup_healthcheck_host');
    $useResolve = (bool) get('scale_lookup_healthcheck_use_resolve', false);
    $slugs = (array) get('required_public_scale_lookup_slugs', []);

    within('{{current_path}}/backend', function () use ($host, $useResolve, $slugs) {
        foreach ($slugs as $slug) {
            $slug = trim((string) $slug);

            if ($slug === '') {
                continue;
            }

            $slug = deploySafeRelativePath($slug, 'scale lookup slug');
            $environment = [
                'SCALE_LOOKUP_BASE_URL' => "https://{$host}",
                'SCALE_LOOKUP_SLUG' => $slug,
                'SCALE_LOOKUP_USE_RESOLVE' => $useResolve ? 'true' : 'false',
                'SCALE_LOOKUP_ATTEMPTS' => '3',
                'SCALE_LOOKUP_RETRY_DELAY_SECONDS' => '2',
                'SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS' => '5',
                'SCALE_LOOKUP_MAX_TIME_SECONDS' => '40',
            ];
            $assignments = [];

            foreach ($environment as $name => $value) {
                $assignments[] = $name.'='.deployShellArg($value);
            }

            run(implode(' ', $assignments).' bash scripts/deploy/verify_scale_lookup.sh');
        }
    });
});

task('healthcheck:ops-entry-contract', function () {
    $configuredHost = trim((string) get('ops_entry_host', ''));

    if ($configuredHost === '') {
        writeln('<comment>Skip ops entry contract smoke (ops_entry_host not configured)</comment>');

        return;
    }

    $host = deploySafeHost($configuredHost, 'ops_entry_host');

    $fetchHeaders = static function (string $url) use ($host): string {
        $resolveArg = deployCurlResolveArg($host, true);

        return run("curl -sSI --max-redirs 0 {$resolveArg}".deployShellArg($url));
    };

    $assertRedirect = static function (string $url, string $expectedRelative, string $expectedAbsolute, string $label) use ($fetchHeaders): void {
        $headers = $fetchHeaders($url);

        if (! preg_match('/^HTTP\\/[0-9.]+ 30[12]\\b/m', $headers)) {
            throw new \RuntimeException("{$label} did not return a 301/302 redirect");
        }

        if (! preg_match('/^Location:\\s*(.+)\\r?$/mi', $headers, $matches)) {
            throw new \RuntimeException("{$label} redirect response did not include a Location header");
        }

        $location = trim((string) ($matches[1] ?? ''));
        if ($location !== $expectedRelative && $location !== $expectedAbsolute) {
            throw new \RuntimeException("{$label} redirect target did not match expected location");
        }
    };

    $assertStatus = static function (string $url, int $status, string $label) use ($fetchHeaders): void {
        $headers = $fetchHeaders($url);

        if (! preg_match('/^HTTP\\/[0-9.]+ '.$status.'\\b/m', $headers)) {
            throw new \RuntimeException("{$label} did not return HTTP {$status}");
        }
    };

    $assertRedirect(
        "https://{$host}/",
        '/ops',
        "https://{$host}/ops",
        'ops host root'
    );
    $assertRedirect(
        "https://{$host}/admin",
        '/ops',
        "https://{$host}/ops",
        'ops host admin alias'
    );
    $assertRedirect(
        "https://{$host}/ops",
        '/ops/login',
        "https://{$host}/ops/login",
        'ops panel root'
    );
    $assertStatus(
        "https://{$host}/ops/login",
        200,
        'ops login page'
    );
});

task('healthcheck:queue-smoke', function () {
    within('{{current_path}}/backend', function () {
        run(<<<'BASH'
{{bin/php}} -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$queue = (string) config("ops.deploy_queue_smoke.queue", "default");
$maxDepth = max(0, (int) config("ops.deploy_queue_smoke.max_depth", 5));
$waitSeconds = max(1, (int) config("ops.deploy_queue_smoke.stability_wait_seconds", 15));
$maxGrowth = max(0, (int) config("ops.deploy_queue_smoke.max_growth", 1));
$pendingWindowMinutes = max(1, (int) config("ops.deploy_queue_smoke.pending_window_minutes", 30));
$maxRecentPending = max(0, (int) config("ops.deploy_queue_smoke.max_recent_pending", 3));

$queueConnectionName = (string) config("queue.default", "redis");
$queueConnection = (array) config("queue.connections." . $queueConnectionName, []);
$queueDriver = (string) ($queueConnection["driver"] ?? "");
if ($queueDriver !== "redis") {
    echo json_encode([
        "queue" => $queue,
        "queue_connection" => $queueConnectionName,
        "queue_driver" => $queueDriver === "" ? "unknown" : $queueDriver,
        "skipped" => true,
        "reason" => "non_redis_queue_driver",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$redisConnection = (string) ($queueConnection["connection"] ?? "default");
$redis = Illuminate\Support\Facades\Redis::connection($redisConnection);
$queueKey = "queues:" . $queue;
$before = (int) $redis->llen($queueKey);
sleep($waitSeconds);
$after = (int) $redis->llen($queueKey);
$recentPending = (int) Illuminate\Support\Facades\DB::table("attempt_submissions")
    ->whereIn("state", ["pending", "running"])
    ->where("updated_at", ">=", now()->subMinutes($pendingWindowMinutes))
    ->count();

$payload = [
    "queue" => $queue,
    "before" => $before,
    "after" => $after,
    "max_depth" => $maxDepth,
    "wait_seconds" => $waitSeconds,
    "max_growth" => $maxGrowth,
    "recent_pending_window_minutes" => $pendingWindowMinutes,
    "recent_pending" => $recentPending,
    "max_recent_pending" => $maxRecentPending,
];

if ($after > $maxDepth) {
    fwrite(STDERR, "deploy queue smoke failed: queue depth exceeds threshold: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

if (($after - $before) > $maxGrowth) {
    fwrite(STDERR, "deploy queue smoke failed: queue depth still growing: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

if ($recentPending > $maxRecentPending) {
    fwrite(STDERR, "deploy queue smoke failed: recent pending submissions exceed threshold: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
'
BASH);
    });
});

/**
 * ======================================================
 * Seed shared content_packages
 * ======================================================
 */
task('fap:seed_shared_content_packages', function () {
    if (deploySkipsAuthorityMutations()) {
        writeln('<comment>Skip shared content package copy in authority-mutation-free deploy mode</comment>');

        return;
    }

    run('mkdir -p '.deployPlaceholderPathArg('{{deploy_path}}', 'shared/content_packages'));
    run('cp -an '.deployPlaceholderPathArg('{{release_path}}', 'content_packages').'/. '.deployPlaceholderPathArg('{{deploy_path}}', 'shared/content_packages').' || true');
});

/**
 * ======================================================
 * Deploy lock metadata / ownership-aware cleanup
 * ======================================================
 */
task('fap:write-deploy-lock-metadata', function () {
    $metadata = getenv('DEPLOY_LOCK_METADATA');

    if (! is_string($metadata) || trim($metadata) === '') {
        return;
    }

    json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);

    run('mkdir -p '.deployPlaceholderPathArg('{{deploy_path}}', '.dep'));
    run('printf %s '.deployShellArg($metadata).' > '.deployPlaceholderPathArg('{{deploy_path}}', get('deploy_lock_metadata_path')));
});

task('fap:remove-deploy-lock-metadata', function () {
    run('rm -f '.deployPlaceholderPathArg('{{deploy_path}}', get('deploy_lock_metadata_path')));
});

task('fap:deploy-unlock-owned', function () {
    $runId = getenv('DEPLOY_LOCK_RUN_ID');
    $runAttempt = getenv('DEPLOY_LOCK_RUN_ATTEMPT');

    if (! is_string($runId) || trim($runId) === '' || ! is_string($runAttempt) || trim($runAttempt) === '') {
        invoke('deploy:unlock');

        return;
    }

    $metaPath = '{{deploy_path}}/'.get('deploy_lock_metadata_path');
    $checkScript = <<<'PHP'
$path = $argv[1] ?? '';
$expectedRunId = $argv[2] ?? '';
$expectedRunAttempt = $argv[3] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "deploy lock metadata is missing\n");
    exit(2);
}

$payload = json_decode((string) file_get_contents($path), true);

if (! is_array($payload)) {
    fwrite(STDERR, "deploy lock metadata is invalid JSON\n");
    exit(3);
}

if (
    (string) ($payload['run_id'] ?? '') !== (string) $expectedRunId
    || (string) ($payload['run_attempt'] ?? '') !== (string) $expectedRunAttempt
) {
    fwrite(STDERR, "deploy lock metadata is owned by another run\n");
    exit(4);
}

echo "owned\n";
PHP;

    try {
        $result = run(
            'php -r '.deployShellArg($checkScript)
            .' '.deployShellArg($metaPath)
            .' '.deployShellArg($runId)
            .' '.deployShellArg($runAttempt),
        );
    } catch (\Throwable $e) {
        writeln('<comment>Skipping deploy:unlock because lock ownership could not be verified.</comment>');
        writeln('<comment>'.$e->getMessage().'</comment>');

        return;
    }

    if (trim($result) === 'owned') {
        invoke('deploy:unlock');
    }
});

/**
 * ======================================================
 * Hooks
 * ======================================================
 */
before('deploy', 'guard:deploy-shell-config');
before('deploy', 'guard:forbid-destructive');
before('rollback', 'guard:deploy-shell-config');
before('deploy:prepare', 'ensure:phpredis');
before('deploy:shared', 'fap:seed_shared_content_packages');

after('deploy:lock', 'fap:write-deploy-lock-metadata');
after('deploy:unlock', 'fap:remove-deploy-lock-metadata');

after('deploy:vendors', 'bootstrap-cache:clear-release');

after('deploy:shared', 'ensure:shared-perms');
after('deploy:shared', 'ensure:healthz-deps');

/**
 * vendor 必须先安装完成：
 * - composer post-autoload-dump 会先完成 package:discover
 * - artisan:filament:assets 依赖 composer vendor，并发布 committed fallback CSS
 */
after('deploy:vendors', 'artisan:filament:assets');
after('artisan:filament:assets', 'guard:ops-theme-asset');
after('artisan:filament:assets', 'guard:filament-assets');
after('artisan:filament:assets', 'guard:required-public-static-media-assets');
after('guard:required-public-static-media-assets', 'ensure:release-public-static-compat');
after('artisan:config:cache', 'guard:sitemap-authority');
after('artisan:migrate', 'guard:no-pending-migrations');
after('guard:no-pending-migrations', 'artisan:scales:seed-default');
after('artisan:scales:seed-default', 'cms:import-landing-surface-baselines');
after('cms:import-landing-surface-baselines', 'cms:import-content-page-baselines');
after('cms:import-content-page-baselines', 'career:warm-public-authority-cache');
after('career:warm-public-authority-cache', 'seo:warm-sitemap-source-cache');
after('seo:warm-sitemap-source-cache', 'guard:public-content-release');
after('guard:public-content-release', 'ensure:release-runtime-perms');

after('deploy:symlink', 'ensure:nginx-public-static-media-route');
after('deploy:symlink', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'queue:reload-workers');
after('deploy:symlink', 'healthcheck:public');
after('healthcheck:public', 'healthcheck:public-dns');
after('deploy:symlink', 'healthcheck:auth-guest-contract');
after('deploy:symlink', 'healthcheck:public-static-media-assets');
after('deploy:symlink', 'healthcheck:scale-lookup');
after('deploy:symlink', 'healthcheck:ops-entry-contract');
after('deploy:symlink', 'healthcheck:queue-smoke');

/**
 * A code-only release deliberately omits every task that can mutate application
 * data or SEO/CMS authority. The workflow classifies the exact commit before
 * invoking this task; this guard prevents direct use with a weaker mode.
 */
task('deploy:code-only', [
    'guard:deploy-shell-config',
    'guard:forbid-destructive',
    'guard:code-only-mode',
    'deploy:prepare',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'artisan:event:cache',
    'guard:public-content-release',
    'deploy:publish',
]);

/**
 * A schema-only release installs the approved code revision and runs exactly one
 * SHA-bound pending migration. It deliberately uses a dedicated migration task
 * so the standard post-migration seed/import/cache hook chain cannot execute.
 */
task('deploy:schema-only', [
    'guard:deploy-shell-config',
    'guard:forbid-destructive',
    'guard:schema-only-mode',
    'deploy:prepare',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'artisan:event:cache',
    'artisan:migrate-schema-only',
    'guard:public-content-release',
    'deploy:publish',
]);

after('rollback', 'bootstrap-cache:rebuild-current');
after('bootstrap-cache:rebuild-current', 'rollback:healthcheck');

after('deploy:failed', 'fap:deploy-unlock-owned');
