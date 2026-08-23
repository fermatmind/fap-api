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

// Shared directory ownership and modes belong to explicit server provisioning.
// Ordinary deploys retain the recipe task for topology compatibility, but it is
// intentionally a no-op and a separate guard verifies the provisioned state.
set('writable_dirs', []);
set('writable_mode', 'skip');
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
set('queue_reload_required', true);
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
set('require_career_candidate_preflight', false);
set('career_public_cache_summary_sha256', '');
set('career_expected_candidate_summary_sha256', '');
set('career_cache_repair_required', false);
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

function deployIsTransientGitTransportFailure(\Throwable $failure): bool
{
    $message = $failure->getMessage();

    foreach ([
        'Connection closed by',
        'Connection reset by peer',
        'Connection timed out',
        'Could not resolve hostname github.com',
        'kex_exchange_identification',
        'ssh_exchange_identification',
    ] as $marker) {
        if (str_contains($message, $marker)) {
            return true;
        }
    }

    return false;
}

/**
 * Updating the local bare repository is read-only with respect to the active
 * application release and safe to repeat after a proven transport-only SSH
 * failure. Authentication, host-key and repository errors remain fail closed.
 *
 * @param  array<string, string>  $environment
 */
function deployRunGitRemoteUpdateWithBoundedRetry(string $command, array $environment): void
{
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            run($command, ['env' => $environment]);

            return;
        } catch (\Throwable $failure) {
            if ($attempt === 2 || ! deployIsTransientGitTransportFailure($failure)) {
                throw $failure;
            }

            writeln('<comment>Transient repository transport failure; retrying once.</comment>');
            sleep(5);
        }
    }
}

function deployMode(): string
{
    $mode = strtolower(trim((string) get('deploy_mode', 'standard')));

    if (! in_array($mode, ['standard', 'code_only', 'candidate_only', 'schema_only'], true)) {
        throw new \RuntimeException("unsupported deploy_mode [{$mode}]");
    }

    return $mode;
}

function deployIsCodeOnly(): bool
{
    return deployMode() === 'code_only';
}

function deployIsCandidateOnly(): bool
{
    return deployMode() === 'candidate_only';
}

function deployBooleanOption(string $name, bool $default): bool
{
    $value = get($name, $default);
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    throw new \RuntimeException("{$name} must be an explicit boolean");
}

function deployCareerDetailMinimumTargets(string $hostAlias): int
{
    if (! in_array($hostAlias, ['staging', 'production'], true)) {
        throw new \RuntimeException('Career detail cache coverage is supported only for staging or production.');
    }

    return 1;
}

function deploySkipsAuthorityMutations(): bool
{
    return in_array(deployMode(), ['code_only', 'candidate_only', 'schema_only'], true);
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

function runProductionPublicDnsBusinessEvidence(): void
{
    if (currentHost()->getAlias() !== 'production') {
        return;
    }

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $environment = [
        'PUBLIC_DNS_PROBE_BASE_URL' => "https://{$host}",
        'PUBLIC_DNS_PROBE_ATTEMPTS' => '3',
        'PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS' => '2 5',
        'PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS' => '3',
        'PUBLIC_DNS_PROBE_MAX_TIME_SECONDS' => '10',
    ];
    $assignments = [];

    foreach ($environment as $name => $value) {
        $assignments[] = $name.'='.deployShellArg($value);
    }

    run(sprintf(
        '%s bash %s',
        implode(' ', $assignments),
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/verify_public_dns_business_evidence.sh'),
    ));
}

function deploySystemdServiceArg(string $service, string $label): string
{
    $service = trim($service);

    if ($service === '' || ! preg_match('/\A[A-Za-z0-9_.@:+-]+\z/', $service)) {
        throw new \RuntimeException("{$label} contains unsafe systemd service characters");
    }

    return deployShellArg($service);
}

function deployCanSudoWwwData(): bool
{
    static $canSudo = null;
    if ($canSudo === null) {
        $canSudo = trim(run('sudo -n -u www-data -- true 2>/dev/null && echo yes || echo no')) === 'yes';
    }

    return $canSudo;
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
    ->setForwardAgent(true)
    ->set('git_ssh_command', 'ssh -o BatchMode=yes -o IdentitiesOnly=no -o StrictHostKeyChecking=yes')
    ->set('deploy_path', getenv('DEPLOY_PATH_STG') ?: '/var/www/fap-api-staging')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging-api.fermatmind.com')
    ->set('static_media_healthcheck_host', getenv('STATIC_MEDIA_HEALTHCHECK_HOST_STG') ?: 'staging-api.fermatmind.com')
    ->set('static_media_healthcheck_use_resolve', true)
    ->set('scale_lookup_healthcheck_host', getenv('SCALE_LOOKUP_HEALTHCHECK_HOST_STG') ?: 'staging-api.fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_STG') ?: '')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_STG') ?: 'php8.4-fpm')
    ->set('keep_releases', 3)
    ->set('queue_reload_required', false)
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
 * Staging consumes the exact successful MySQL/Redis parity receipt verified by
 * the protected workflow. This task keeps the receipt gate visible in the
 * Deployer task tree/timing receipt and refuses an unbound deployment.
 */
task('guard:ci-parity-receipt', function () {
    if (currentHost()->getAlias() !== 'staging') {
        return;
    }

    $verified = trim((string) (getenv('CI_PARITY_RECEIPT_VERIFIED') ?: ''));
    $receiptSha = strtolower(trim((string) (getenv('CI_PARITY_RECEIPT_SHA') ?: '')));
    $deployRevision = strtolower(trim((string) (getenv('DEPLOY_REVISION') ?: '')));
    $artifactDigest = trim((string) (getenv('CI_PARITY_RECEIPT_ARTIFACT_DIGEST') ?: ''));
    $configFingerprint = trim((string) (getenv('CI_PARITY_RECEIPT_CONFIG_FINGERPRINT') ?: ''));
    $runId = trim((string) (getenv('CI_PARITY_RECEIPT_RUN_ID') ?: ''));
    $runAttempt = trim((string) (getenv('CI_PARITY_RECEIPT_RUN_ATTEMPT') ?: ''));

    if ($verified !== 'true') {
        throw new \RuntimeException('staging requires a verified CI parity receipt');
    }
    if (
        preg_match('/\A[a-f0-9]{40}\z/', $receiptSha) !== 1
        || preg_match('/\A[a-f0-9]{40}\z/', $deployRevision) !== 1
        || ! hash_equals($deployRevision, $receiptSha)
    ) {
        throw new \RuntimeException('CI parity receipt SHA does not match the deploy revision');
    }
    if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $artifactDigest) !== 1) {
        throw new \RuntimeException('CI parity receipt artifact digest is missing or malformed');
    }
    if (preg_match('/\A[a-f0-9]{64}\z/', $configFingerprint) !== 1) {
        throw new \RuntimeException('CI parity receipt config fingerprint is missing or malformed');
    }
    if (
        preg_match('/\A[1-9][0-9]*\z/', $runId) !== 1
        || preg_match('/\A[1-9][0-9]*\z/', $runAttempt) !== 1
    ) {
        throw new \RuntimeException('CI parity receipt workflow identity is missing or malformed');
    }

    writeln('<info>Verified exact-SHA CI parity receipt for staging deployment.</info>');
});

before('deploy:prepare', 'guard:ci-parity-receipt');

/**
 * Refuse to move the production symlink unless the protected health policy and
 * real public-DNS business routes are healthy. The loopback vhost probe alone
 * cannot prove that the public edge and origin routing reach this service.
 */
task('guard:public-dns-health', function () {
    runProductionPublicDnsBusinessEvidence();
});

before('deploy:symlink', 'guard:public-dns-health');

/**
 * Standard and schema-only releases must never expose a Career directory whose
 * published detail routes are not fully backed by verified active/LKG/legacy
 * cache payloads. A code-only release neither rebuilds nor mutates those detail
 * caches, so an existing L3 cache-coverage deficit must not block an unrelated
 * L1 runtime activation.
 */
task('guard:career-detail-cache-coverage', function () {
    if (deploySkipsAuthorityMutations()) {
        writeln('<comment>Skipping Career detail cache coverage because this isolated release does not mutate Career authority caches.</comment>');

        return;
    }

    $minimumTargets = deployCareerDetailMinimumTargets(currentHost()->getAlias());
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
 * directory authority, while a Greenfield production deploy may begin with an
 * empty derived cache. Repair only the current published cohort when the whole
 * repair set fits inside the bounded synchronous limit, then keep the complete
 * read-only gate below as the activation authority.
 */
task('career:repair-published-detail-cache-coverage', function () {
    if (deploySkipsAuthorityMutations()) {
        writeln('<comment>Skipping Career detail cache repair because this isolated release does not mutate Career authority caches.</comment>');

        return;
    }

    $hostAlias = currentHost()->getAlias();
    if (! in_array($hostAlias, ['staging', 'production'], true)) {
        throw new \RuntimeException('Career detail cache repair is supported only for staging or production.');
    }

    $maximumRepairsRaw = trim((string) (getenv('DEPLOY_CAREER_DETAIL_MAXIMUM_SYNC_REPAIRS') ?: '2092'));
    if (preg_match('/^[1-9][0-9]*$/D', $maximumRepairsRaw) !== 1 || (int) $maximumRepairsRaw > 2092) {
        throw new \RuntimeException('DEPLOY_CAREER_DETAIL_MAXIMUM_SYNC_REPAIRS must be an integer between 1 and 2092.');
    }

    $minimumTargets = deployCareerDetailMinimumTargets($hostAlias);
    $productionConfirmation = $hostAlias === 'production'
        ? ' --confirm-production-write'
        : '';

    run(sprintf(
        'timeout 300 {{bin/php}} %s career:verify-job-detail-cache-coverage --repair-missing-sync --locales=en,zh-CN --minimum-targets=%d --maximum-sync-repairs=%d --json --no-interaction --no-ansi%s',
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
        $minimumTargets,
        (int) $maximumRepairsRaw,
        $productionConfirmation,
    ));
});

before('deploy:symlink', 'guard:queue-reload-capability');

/**
 * Resolve the exact materialized Career publication cohort before any standard
 * cache mutation. Schema-only activation is read-only here but still fails
 * closed when the shared private authority artifact is missing or malformed.
 */
task('guard:career-runtime-projection-authority', function () {
    if (in_array(deployMode(), ['code_only', 'candidate_only'], true)) {
        writeln('<comment>Skipping Career runtime projection gate for isolated code/candidate release.</comment>');

        return;
    }

    if (! deployCanSudoWwwData()) {
        throw new \RuntimeException('Career runtime projection gate requires the application runtime identity.');
    }

    run(sprintf(
        <<<'BASH'
php_bin="$(command -v {{bin/php}})"
test -n "$php_bin"
sudo -n -u www-data -- env FM_CAREER_COLD_CACHE_GATE_EXECUTE=1 "$php_bin" %s authority
BASH,
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/verify_career_cold_cache_discoverability.php'),
    ));
});

/**
 * Detail coverage changes which Career links are reader-safe. Always rebuild
 * both directory locales after the bounded detail repair and complete coverage
 * gate, even when the broader warm fingerprint was unchanged.
 */
task('career:rebuild-directory-after-detail-repair', function () {
    if (deployMode() !== 'standard') {
        writeln('<comment>Skipping Career directory-only rebuild outside standard deploy.</comment>');

        return;
    }

    run(sprintf(
        'timeout 300 {{bin/php}} %s career:warm-public-authority-cache --directory-only --json --no-interaction --no-ansi',
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
    ));
});

task('guard:career-discoverability-pre-sitemap', function () {
    if (deployMode() !== 'standard') {
        return;
    }

    run(sprintf(
        'FM_CAREER_COLD_CACHE_GATE_EXECUTE=1 {{bin/php}} %s pre_sitemap',
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/verify_career_cold_cache_discoverability.php'),
    ));
});

task('guard:career-discoverability-post-sitemap', function () {
    if (deployMode() !== 'standard') {
        return;
    }

    run(sprintf(
        'FM_CAREER_COLD_CACHE_GATE_EXECUTE=1 {{bin/php}} %s post_sitemap',
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/verify_career_cold_cache_discoverability.php'),
    ));
});

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

task('artisan:migrate-seo-intel', function () {
    within('{{release_path}}/backend', function (): void {
        run('{{bin/php}} artisan migrate --database=seo_intel --path=database/migrations/seo_intel --force --no-interaction --ansi');
    });
});

task('guard:no-pending-seo-intel-migrations', function () {
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
status_output="$({{bin/php}} artisan migrate:status --database=seo_intel --path=database/migrations/seo_intel --no-interaction --no-ansi)"
printf '%s\n' "$status_output"
if printf '%s\n' "$status_output" | grep -Eq '(^|[[:space:]])Pending($|[[:space:]])'; then
  echo "pending seo_intel migrations remain after deploy migrate" >&2
  exit 1
fi
BASH);
    });
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
    run('FAP_PRESERVE_EXISTING_BIG5_CMS_CONTENT=1 {{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' fap:scales:seed-default --no-interaction --ansi');
});

task('big5:publish-private-result-authority', function () {
    within('{{release_path}}/backend', function (): void {
        run(sprintf(<<<'BASH'
set -euo pipefail
previous="$({{bin/php}} artisan packs2:list --pack=%s --pack-version=v2 --active-release-id --no-interaction --no-ansi)"
if [ -n "$previous" ]; then
  printf '%%s\n' "$previous" > ../.big5-private-result-previous-release
fi
timeout 180 {{bin/php}} artisan packs2:publish --pack=%s --pack-version=v2 --activate=1 --source_commit=%s --no-interaction --ansi
BASH,
            deployShellArg('BIG5_OCEAN_PRIVATE_RESULT'),
            deployShellArg('BIG5_OCEAN_PRIVATE_RESULT'),
            deployShellArg((string) get('revision')),
        ));
    });
});

task('big5:rollback-private-result-authority-on-failure', function () {
    if (! test('test -r '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'))) {
        return;
    }
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
previous_file=../.big5-private-result-previous-release
if [ ! -s "$previous_file" ]; then
  exit 0
fi
previous="$(tr -d '\r\n' < "$previous_file")"
if [[ ! "$previous" =~ ^[0-9a-fA-F-]{36}$ ]]; then
  exit 1
fi
{{bin/php}} artisan packs2:rollback --pack=BIG5_OCEAN_PRIVATE_RESULT --pack-version=v2 --to_release_id="$previous" --no-interaction --ansi
BASH);
    });
});

task('riasec:publish-private-result-authority', function () {
    within('{{release_path}}/backend', function (): void {
        run(sprintf(<<<'BASH'
set -euo pipefail
previous="$({{bin/php}} artisan packs2:list --pack=%s --pack-version=v1 --active-release-id --no-interaction --no-ansi)"
if [ -n "$previous" ]; then
  printf '%%s\n' "$previous" > ../.riasec-private-result-previous-release
fi
timeout 180 {{bin/php}} artisan packs2:publish --pack=%s --pack-version=v1 --activate=1 --source_commit=%s --no-interaction --ansi
BASH,
            deployShellArg('RIASEC_PRIVATE_RESULT'),
            deployShellArg('RIASEC_PRIVATE_RESULT'),
            deployShellArg((string) get('revision')),
        ));
    });
});

task('riasec:rollback-private-result-authority-on-failure', function () {
    if (! test('test -r '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'))) {
        return;
    }
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
previous_file=../.riasec-private-result-previous-release
if [ ! -s "$previous_file" ]; then
  exit 0
fi
previous="$(tr -d '\r\n' < "$previous_file")"
if [[ ! "$previous" =~ ^[0-9a-fA-F-]{36}$ ]]; then
  exit 1
fi
{{bin/php}} artisan packs2:rollback --pack=RIASEC_PRIVATE_RESULT --pack-version=v1 --to_release_id="$previous" --no-interaction --ansi
BASH);
    });
});

task('enneagram:publish-private-result-authority', function () {
    within('{{release_path}}/backend', function (): void {
        run(sprintf(<<<'BASH'
set -euo pipefail
previous="$({{bin/php}} artisan packs2:list --pack=%s --pack-version=v2 --active-release-id --no-interaction --no-ansi)"
if [ -n "$previous" ]; then
  printf '%%s\n' "$previous" > ../.enneagram-private-result-previous-release
fi
timeout 180 {{bin/php}} artisan packs2:publish --pack=%s --pack-version=v2 --activate=1 --source_commit=%s --no-interaction --ansi
BASH,
            deployShellArg('ENNEAGRAM_PRIVATE_RESULT'),
            deployShellArg('ENNEAGRAM_PRIVATE_RESULT'),
            deployShellArg((string) get('revision')),
        ));
    });
});

task('enneagram:rollback-private-result-authority-on-failure', function () {
    if (! test('test -r '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'))) {
        return;
    }
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
previous_file=../.enneagram-private-result-previous-release
if [ ! -s "$previous_file" ]; then
  exit 0
fi
previous="$(tr -d '\r\n' < "$previous_file")"
if [[ ! "$previous" =~ ^[0-9a-fA-F-]{36}$ ]]; then
  exit 1
fi
{{bin/php}} artisan packs2:rollback --pack=ENNEAGRAM_PRIVATE_RESULT --pack-version=v2 --to_release_id="$previous" --no-interaction --ansi
BASH);
    });
});

task('eq60:publish-private-result-authority', function () {
    within('{{release_path}}/backend', function (): void {
        run(sprintf(<<<'BASH'
set -euo pipefail
previous="$({{bin/php}} artisan packs2:list --pack=%s --pack-version=v1 --active-release-id --no-interaction --no-ansi)"
if [ -z "$previous" ]; then
  current_compiled={{deploy_path}}/current/backend/content_packs/EQ_60/v1/compiled
  current_revision="$(tr -d '\r\n' < {{deploy_path}}/current/REVISION)"
  test -f "$current_compiled/manifest.json"
  [[ "$current_revision" =~ ^[0-9a-f]{40}$ ]]
  timeout 180 {{bin/php}} artisan packs2:publish --pack=%s --pack-version=v1 --activate=1 --compile=0 --compare-and-swap=1 --source-dir="$current_compiled" --source_commit="$current_revision" --no-interaction --ansi
  previous="$({{bin/php}} artisan packs2:list --pack=%s --pack-version=v1 --active-release-id --no-interaction --no-ansi)"
fi
[[ "$previous" =~ ^[0-9a-fA-F-]{36}$ ]]
printf '%%s\n' "$previous" > ../.eq60-private-result-previous-release
release_revision="$(tr -d '\r\n' < ../REVISION)"
[[ "$release_revision" =~ ^[0-9a-f]{40}$ ]]
timeout 180 {{bin/php}} artisan packs2:publish --pack=%s --pack-version=v1 --activate=1 --force-new-release=1 --compare-and-swap=1 --expected-previous-release-id="$previous" --source_commit="$release_revision" --no-interaction --ansi
BASH,
            deployShellArg('EQ_60'),
            deployShellArg('EQ_60'),
            deployShellArg('EQ_60'),
            deployShellArg('EQ_60'),
        ));
    });
});

task('eq60:rollback-private-result-authority-on-failure', function () {
    if (! test('test -r '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'))) {
        return;
    }
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
previous_file=../.eq60-private-result-previous-release
if [ ! -s "$previous_file" ]; then
  exit 0
fi
previous="$(tr -d '\r\n' < "$previous_file")"
if [[ ! "$previous" =~ ^[0-9a-fA-F-]{36}$ ]]; then
  exit 1
fi
{{bin/php}} artisan packs2:rollback --pack=EQ_60 --pack-version=v1 --to_release_id="$previous" --no-interaction --ansi
BASH);
    });
});

task('career:public-authority-cache-verified_unchanged', function () {
    writeln('<info>Career public authority cache fingerprint and readability verified unchanged.</info>');
});

task('career:public-authority-cache-rebuilt', function () {
    writeln('<info>Career public authority cache rebuilt for a changed fingerprint.</info>');
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
        'timeout --kill-after=%ds %d {{bin/php}} %s career:warm-public-authority-cache --refresh-if-changed --no-interaction --ansi',
        $killAfterSeconds,
        $timeoutSeconds,
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
    );

    $heartbeatCommand = sprintf(
        <<<'BASH'
set +e
%s &
warm_pid=$!
cleanup_warm() {
  if kill -0 "$warm_pid" 2>/dev/null; then
    kill -TERM "$warm_pid" 2>/dev/null || true
    wait "$warm_pid" 2>/dev/null || true
  fi
}
trap 'cleanup_warm; exit 143' HUP INT TERM
while kill -0 "$warm_pid" 2>/dev/null; do
  sleep 20
  if kill -0 "$warm_pid" 2>/dev/null; then
    echo "career_warm_heartbeat=running"
  fi
done
wait "$warm_pid"
status=$?
trap - HUP INT TERM
set -e
BASH,
        $command,
    );

    if ($strictWarmCache) {
        $output = run($heartbeatCommand."\n".'exit "$status"');
    } else {
        $output = run($heartbeatCommand."\n".<<<'BASH'
if [ "$status" -ne 0 ]; then
  echo "career_warm_public_authority_cache_nonblocking_failure=$status"
  echo "Continuing deploy because DEPLOY_CAREER_WARM_CACHE_STRICT is not true."
fi
exit 0
BASH);
    }

    if (preg_match('/career_cache_refresh_result=(verified_unchanged|rebuilt)/', $output, $match) === 1) {
        invoke('career:public-authority-cache-'.$match[1]);
    }
});

task('career:verify-public-dataset-cache-equivalence', function () {
    if (! deployIsCodeOnly() || ! deployBooleanOption('require_career_candidate_preflight', false)) {
        writeln('<comment>Skip Career candidate dataset equivalence outside the exact required code_only scope</comment>');

        return;
    }

    $expectedCurrentSha256 = strtolower(trim((string) get('career_public_cache_summary_sha256', '')));
    $expectedCandidateSha256 = strtolower(trim((string) get('career_expected_candidate_summary_sha256', '')));
    if (preg_match('/^[a-f0-9]{64}$/', $expectedCurrentSha256) !== 1
        || preg_match('/^[a-f0-9]{64}$/', $expectedCandidateSha256) !== 1) {
        throw new \RuntimeException('Career candidate dataset equivalence requires exact current and candidate summary SHA-256 values');
    }
    $repairRequired = deployBooleanOption('career_cache_repair_required', false);

    within('{{release_path}}/backend', function () use (
        $expectedCurrentSha256,
        $expectedCandidateSha256,
        $repairRequired,
    ) {
        $repairOptions = $repairRequired
            ? ' --repair-live-public-cache --repair-id='.deployShellArg('{{release_name}}')
            : '';
        run(sprintf(
            '{{bin/php}} artisan career:verify-public-dataset-cache-equivalence --expected-sha256=%s --expected-current-sha256=%s --verify-live-public-cache%s --json --no-interaction --ansi',
            escapeshellarg($expectedCandidateSha256),
            escapeshellarg($expectedCurrentSha256),
            $repairOptions,
        ));
    });
});

task('career:finalize-public-dataset-cache-equivalence', function () {
    if (! deployIsCodeOnly()
        || ! deployBooleanOption('require_career_candidate_preflight', false)
        || ! deployBooleanOption('career_cache_repair_required', false)) {
        return;
    }

    $expectedCurrentSha256 = strtolower(trim((string) get('career_public_cache_summary_sha256', '')));
    $expectedCandidateSha256 = strtolower(trim((string) get('career_expected_candidate_summary_sha256', '')));
    within('{{release_path}}/backend', function () use ($expectedCurrentSha256, $expectedCandidateSha256) {
        run(sprintf(
            <<<'BASH'
set +e
{{bin/php}} artisan career:verify-public-dataset-cache-equivalence --expected-sha256=%s --expected-current-sha256=%s --repair-id=%s --finalize-repair --json --no-interaction --ansi
status=$?
set -e
if [ "$status" -ne 0 ]; then
  echo "career_dataset_cache_repair_finalize_nonblocking_failure=$status"
fi
exit 0
BASH,
            escapeshellarg($expectedCandidateSha256),
            escapeshellarg($expectedCurrentSha256),
            deployShellArg('{{release_name}}'),
        ));
    });
});

task('career:rollback-public-dataset-cache-equivalence', function () {
    if (! deployIsCodeOnly()
        || ! deployBooleanOption('require_career_candidate_preflight', false)
        || ! deployBooleanOption('career_cache_repair_required', false)) {
        return;
    }

    if (test('[ "$(readlink -f {{deploy_path}}/current)" = "$(readlink -f {{release_path}})" ]')) {
        writeln('<comment>Keep the candidate-exact Career dataset cache because this release is already active</comment>');
        invoke('career:finalize-public-dataset-cache-equivalence');

        return;
    }

    $expectedCurrentSha256 = strtolower(trim((string) get('career_public_cache_summary_sha256', '')));
    $expectedCandidateSha256 = strtolower(trim((string) get('career_expected_candidate_summary_sha256', '')));
    within('{{release_path}}/backend', function () use ($expectedCurrentSha256, $expectedCandidateSha256) {
        run(sprintf(
            '{{bin/php}} artisan career:verify-public-dataset-cache-equivalence --expected-sha256=%s --expected-current-sha256=%s --repair-id=%s --rollback-repair --json --no-interaction --ansi',
            escapeshellarg($expectedCandidateSha256),
            escapeshellarg($expectedCurrentSha256),
            deployShellArg('{{release_name}}'),
        ));
    });
});

task('seo:sitemap-source-cache-verified_unchanged', function () {
    writeln('<info>Sitemap source cache fingerprint and readability verified unchanged.</info>');
});

task('seo:sitemap-source-cache-rebuilt', function () {
    writeln('<info>Sitemap source cache rebuilt for a changed authority fingerprint.</info>');
});

task('seo:warm-sitemap-source-cache', function () {
    $timeoutSeconds = (string) (getenv('DEPLOY_SEO_SITEMAP_SOURCE_WARM_TIMEOUT') ?: '180');
    $killAfterSeconds = (string) (getenv('DEPLOY_SEO_SITEMAP_SOURCE_WARM_KILL_AFTER') ?: '30');
    $strict = (string) (getenv('DEPLOY_SEO_SITEMAP_SOURCE_WARM_STRICT') ?: 'false');

    $canSudoWwwData = deployCanSudoWwwData();
    $sudoPrefix = $canSudoWwwData
        ? 'sudo -n -u www-data -- env'
        : '';

    $output = run(sprintf(
        <<<'BASH'
php_bin="$(command -v {{bin/php}})"
test -n "$php_bin"
%s SITEMAP_SOURCE_WARM_PHP_BIN="$php_bin" SITEMAP_SOURCE_WARM_ARTISAN=%s SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS=%s SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS=%s SITEMAP_SOURCE_WARM_STRICT=%s bash %s
BASH,
        $sudoPrefix,
        deployPlaceholderPathArg('{{release_path}}', 'backend/artisan'),
        deployShellArg($timeoutSeconds),
        deployShellArg($killAfterSeconds),
        deployShellArg($strict),
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/verify_sitemap_source_cache_refresh.sh'),
    ));

    if (preg_match('/sitemap_source_cache_warm_status=(verified_unchanged|rebuilt)/', $output, $match) === 1) {
        invoke('seo:sitemap-source-cache-'.$match[1]);
    }
});

task('guard:public-content-release', function () {
    $strictCareerFlag = filter_var((string) (getenv('DEPLOY_PUBLIC_CONTENT_STRICT_CAREER') ?: ''), FILTER_VALIDATE_BOOLEAN)
        ? ' --strict-career'
        : '';

    run('timeout 180 {{bin/php}} '.deployPlaceholderPathArg('{{release_path}}', 'backend/artisan').' release:verify-public-content --content-source-dir='.deployPlaceholderPathArg('{{release_path}}', 'content_baselines/content_pages').' --no-interaction --ansi'.$strictCareerFlag);
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

task('guard:candidate-only-mode', function () {
    if (! deployIsCandidateOnly()) {
        throw new \RuntimeException('deploy:candidate-only requires deploy_mode=candidate_only');
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

task('guard:queue-reload-capability', function () {
    $codeOnly = deployIsCodeOnly();
    $reloadRequired = deployBooleanOption('queue_reload_required', true);
    $manager = strtolower(trim((string) get('queue_manager', 'supervisor')));

    if (! $reloadRequired) {
        if (currentHost()->getAlias() !== 'staging') {
            throw new \RuntimeException('queue reload may be optional only on the staging host');
        }

        if (test("pgrep -af '(^|[[:space:]])artisan[[:space:]]+(queue:work|horizon)([[:space:]]|$)' >/dev/null 2>&1")) {
            throw new \RuntimeException('staging has unmanaged Laravel queue workers; configure a queue manager before deployment');
        }

        writeln('<comment>Staging queue capability preflight passed with no configured or running workers</comment>');

        return;
    }

    if (! $codeOnly) {
        $artisan = deployPlaceholderPathArg('{{release_path}}', 'backend/artisan');
        $cacheData = deployPlaceholderPathArg(
            '{{release_path}}',
            'backend/storage/framework/cache/data',
        );

        $sudoPrefix = deployCanSudoWwwData() ? 'sudo -n -u www-data -- ' : '';

        if (! test("{$sudoPrefix}test -r {$artisan}")) {
            throw new \RuntimeException('queue restart preflight requires the application runtime identity to read Artisan');
        }
        if (! test("{$sudoPrefix}test -w {$cacheData}")) {
            throw new \RuntimeException('queue restart preflight requires the application runtime identity to write the shared cache directory');
        }
    }

    if ($manager === 'supervisor') {
        $supervisorctl = trim((string) get('queue_supervisorctl', '/usr/bin/supervisorctl'));
        if (test('[ -x '.escapeshellarg($supervisorctl).' ] || command -v supervisorctl >/dev/null 2>&1')) {
            $resolvedSupervisorctl = trim((string) run(
                'if [ -x '.escapeshellarg($supervisorctl).' ]; then echo '.escapeshellarg($supervisorctl).'; else command -v supervisorctl; fi'
            ));
            $quotedSupervisorctl = escapeshellarg($resolvedSupervisorctl);
            $requiredPrograms = array_values(array_filter(
                (array) get('queue_supervisor_required_programs', []),
                static fn (mixed $value): bool => trim((string) $value) !== ''
            ));
            if (currentHost()->getAlias() === 'production'
                || deployBooleanOption('require_ops_queue_reload', false)) {
                $requiredPrograms[] = 'fap-queue-ops';
                $requiredPrograms = array_values(array_unique($requiredPrograms));
            }

            foreach ($requiredPrograms as $program) {
                $program = trim((string) $program);
                if (! preg_match('/^[A-Za-z0-9._-]+$/', $program)) {
                    throw new \RuntimeException('queue capability preflight found an invalid supervisor program name');
                }

                $programPattern = '^'.preg_quote($program, '/').'(:|$)';
                $statusCommand = "{ sudo -n {$quotedSupervisorctl} status 2>/dev/null || true; }"
                    .' | awk -v pattern='.escapeshellarg($programPattern)
                    ." '\$1 ~ pattern { found=1; if (\$2 != \"RUNNING\") bad=1 } END { exit !(found && !bad) }'";
                if (! test($statusCommand)) {
                    throw new \RuntimeException("queue capability preflight requires running supervisor program [{$program}] before release activation");
                }
            }

            writeln('<comment>Queue capability preflight passed for supervisor</comment>');

            return;
        }

        $legacySystemdService = trim((string) get('legacy_queue_systemd_service', ''));
        if ($legacySystemdService !== '') {
            $quotedService = deploySystemdServiceArg($legacySystemdService, 'legacy_queue_systemd_service');
            if (test("sudo -n /usr/bin/systemctl cat {$quotedService} >/dev/null 2>&1")) {
                writeln('<comment>Queue capability preflight passed for the declared systemd fallback</comment>');

                return;
            }
        }

        throw new \RuntimeException('queue capability preflight found no configured supervisor or systemd reload path');
    }

    if ($manager === 'systemd') {
        $systemdService = trim((string) get('legacy_queue_systemd_service', ''));
        if ($systemdService === '') {
            throw new \RuntimeException('queue manager systemd requires legacy_queue_systemd_service');
        }
        $quotedService = deploySystemdServiceArg($systemdService, 'legacy_queue_systemd_service');
        if (! test("sudo -n /usr/bin/systemctl cat {$quotedService} >/dev/null 2>&1")) {
            throw new \RuntimeException('queue capability preflight could not find the declared systemd service');
        }

        writeln('<comment>Queue capability preflight passed for systemd</comment>');

        return;
    }

    throw new \RuntimeException('unsupported queue_manager ['.$manager.']');
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
    $reloadRequired = deployBooleanOption('queue_reload_required', true);

    if (! $reloadRequired) {
        if (currentHost()->getAlias() !== 'staging') {
            throw new \RuntimeException('queue reload may be optional only on the staging host');
        }

        writeln('<comment>Skip queue worker reload for the explicit no-worker staging topology</comment>');

        return;
    }

    $manager = strtolower(trim((string) get('queue_manager', 'supervisor')));

    if ($manager === 'supervisor') {
        $supervisorctl = trim((string) get('queue_supervisorctl', '/usr/bin/supervisorctl'));
        $requiredPrograms = array_values(array_filter((array) get('queue_supervisor_required_programs', []), static fn (mixed $value): bool => trim((string) $value) !== ''));
        $optionalPrograms = array_values(array_filter((array) get('queue_supervisor_optional_programs', []), static fn (mixed $value): bool => trim((string) $value) !== ''));
        $requireOpsQueueReload = currentHost()->getAlias() === 'production'
            || deployBooleanOption('require_ops_queue_reload', false);
        if ($requireOpsQueueReload) {
            $requiredPrograms[] = 'fap-queue-ops';
            $requiredPrograms = array_values(array_unique($requiredPrograms));
            $optionalPrograms = array_values(array_filter(
                $optionalPrograms,
                static fn (string $program): bool => $program !== 'fap-queue-ops'
            ));
            writeln('<comment>Require the ops queue worker for the production approval runtime topology</comment>');
        }
        $legacySystemdService = trim((string) get('legacy_queue_systemd_service', ''));
        $disableLegacySystemd = (bool) get('legacy_queue_systemd_disable', true);

        if (! $codeOnly) {
            within('{{current_path}}/backend', function () {
                $sudoPrefix = deployCanSudoWwwData() ? 'sudo -n -u www-data -- ' : '';
                run($sudoPrefix.'{{bin/php}} artisan queue:restart --ansi');
            });
        } else {
            writeln('<comment>Reload queue workers through the process manager without a cache restart signal in code_only deploy mode</comment>');
        }

        $supervisorctlAvailable = test('[ -x '.escapeshellarg($supervisorctl).' ] || command -v supervisorctl >/dev/null 2>&1');
        if (! $supervisorctlAvailable) {
            if ($requireOpsQueueReload) {
                throw new \RuntimeException('production approval runtime requires the supervisor ops queue reload path');
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
        $supervisorRestartScript = escapeshellarg('{{release_path}}/backend/scripts/deploy/restart_supervisor_program_group.sh');
        $quotedSudo = escapeshellarg('/usr/bin/sudo');
        $quotedTimeout = escapeshellarg('/usr/bin/timeout');
        $restartSupervisorProgram = static function (string $program, bool $required) use (
            $quotedSupervisorctl,
            $quotedSudo,
            $quotedTimeout,
            $supervisorRestartScript
        ): string {
            $quotedProgram = escapeshellarg($program);
            $quotedRequired = escapeshellarg($required ? 'true' : 'false');

            return "bash {$supervisorRestartScript}"
                ." --supervisorctl={$quotedSupervisorctl}"
                ." --sudo={$quotedSudo}"
                ." --timeout-bin={$quotedTimeout}"
                ." --program={$quotedProgram}"
                .' --attempts=3'
                .' --delay-seconds=2'
                .' --restart-timeout-seconds=390'
                .' --heartbeat-seconds=20'
                ." --required={$quotedRequired}";
        };

        run("sudo -n {$quotedSupervisorctl} reread");
        run("sudo -n {$quotedSupervisorctl} update");

        foreach ($requiredPrograms as $program) {
            run($restartSupervisorProgram($program, true), timeout: 1200);
        }

        foreach ($optionalPrograms as $program) {
            run($restartSupervisorProgram($program, false), timeout: 1200);
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
                $sudoPrefix = deployCanSudoWwwData() ? 'sudo -n -u www-data -- ' : '';
                run($sudoPrefix.'{{bin/php}} artisan queue:restart --ansi');
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

task('guard:shared-permissions', function () {
    $owner = currentHost()->getRemoteUser() ?: 'ubuntu';

    // Resolve the actual group of the shared/ tree from the filesystem.
    $sharedRoot = deployPlaceholderPathArg('{{deploy_path}}', 'shared');
    $group = trim(run("stat -c '%G' '{$sharedRoot}' 2>/dev/null || id -gn '{$owner}' 2>/dev/null"));

    // When the deploy user cannot sudo to www-data, accept the owner as
    // the runtime user to avoid RUNTIME_USER_CAPABILITY_MISSING failures.
    $runtimeUser = deployCanSudoWwwData() ? 'www-data' : $owner;

    deployOwnerGroupArg($owner, $group);
    $verifier = deployPlaceholderPathArg(
        '{{release_path}}',
        'backend/scripts/deploy/verify_shared_permissions.sh',
    );

    run(
        'SHARED_PERMISSIONS_ROOT='.$sharedRoot
        .' SHARED_PERMISSIONS_OWNER='.deployShellArg($owner)
        .' SHARED_PERMISSIONS_GROUP='.deployShellArg($group)
        .' SHARED_PERMISSIONS_RUNTIME_USER='.deployShellArg($runtimeUser)
        .' bash '.$verifier,
    );
});

task('prepare:release-bootstrap-cache-access', function () {
    $owner = currentHost()->getRemoteUser() ?: 'ubuntu';
    $group = 'www-data';
    $ownerGroup = deployOwnerGroupArg($owner, $group);
    $cacheDir = deployPlaceholderPathArg(
        '{{release_path}}',
        'backend/bootstrap/cache',
    );

    run(
        'test -d '.$cacheDir
        .' && test ! -L '.$cacheDir
        .' && sudo -n /usr/bin/chown '.$ownerGroup.' '.$cacheDir
        .' && sudo -n /usr/bin/chmod 2775 '.$cacheDir,
    );
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
tmp_site_source="$(mktemp)"
tmp_script="$(mktemp)"
tmp_snippet="$(mktemp)"
site_backup="$(mktemp /tmp/fap-api-nginx-site-backup.XXXXXX.conf)"
snippet_backup="$(mktemp /tmp/fap-api-nginx-snippet-backup.XXXXXX.conf)"
site_path=__QUOTED_SITE__
snippet_path=__QUOTED_SNIPPET__
snippet_existed=0
static_route_action=install
trap 'rm -f "$tmp_site" "$tmp_site_source" "$tmp_script" "$tmp_snippet"; sudo -n rm -f "$site_backup" "$snippet_backup" 2>/dev/null || true' EXIT

printf %s __ENCODED_SNIPPET__ | base64 -d > "$tmp_snippet"
sudo -n test -f "$site_path"
sudo -n /usr/bin/cat "$site_path" > "$tmp_site_source"

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
[$script, $siteSource, $site, $include, $host, $primaryHost] = $argv;

$content = file_get_contents($siteSource);
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
        $included = @file_get_contents($includePath);

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
php "$tmp_script" "$tmp_site_source" "$site_path" "$snippet_path" __QUOTED_HOST__ __QUOTED_PRIMARY_HOST__ > "$tmp_site"
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

task('healthcheck:sitemap-source', function () {
    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/v0.5/seo/sitemap-source');
    $jq = deployShellArg(
        '.ok==true and .count >= 1 and (.source=="backend_sitemap_generator" or .source=="backend_sitemap_generator_fallback")'
    );
    $cmd = "curl -fsS {$resolveArg}{$url} | jq -e {$jq}";
    run($cmd);
});

task('healthcheck:public-dns', function () {
    runProductionPublicDnsBusinessEvidence();
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
                'SCALE_LOOKUP_ATTEMPTS' => '1',
                'SCALE_LOOKUP_RETRY_DELAY_SECONDS' => '0',
                'SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS' => '3',
                'SCALE_LOOKUP_MAX_TIME_SECONDS' => '10',
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

    $source = deployPlaceholderPathArg('{{release_path}}', 'content_packages');
    $destination = deployPlaceholderPathArg('{{deploy_path}}', 'shared/content_packages');

    run('mkdir -p '.$destination);
    run(
        'find '.$source
        .' -mindepth 1 -maxdepth 1'
        .' -exec cp -an -- {} '.$destination.'/ \;'
        .' || true',
    );
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
 * Repository-only instructions, documentation, and tests are not runtime
 * inputs. Remove only this fixed allowlist from the new immutable release
 * before Composer or any activation gate runs.
 */
task('release:prune-non-runtime-source', function () {
    $releasePath = deployPlaceholderPathArg('{{release_path}}');
    $deployPath = deployPlaceholderPathArg('{{deploy_path}}');

    run(<<<BASH
set -euo pipefail
release_path="\$(readlink -f {$releasePath})"
deploy_path="\$(readlink -f {$deployPath})"
case "\$release_path" in
  "\$deploy_path"/releases/*) ;;
  *) echo "release prune refused: release path escaped managed releases" >&2; exit 1 ;;
esac

for relative_path in .agents .github .vscode docs tests backend/tests; do
  target="\$release_path/\$relative_path"
  if [ -e "\$target" ] || [ -L "\$target" ]; then
    parent="\$(readlink -f "\$(dirname "\$target")")"
    case "\$parent" in
      "\$release_path"|"\$release_path"/*) ;;
      *) echo "release prune refused: target parent escaped release" >&2; exit 1 ;;
    esac
    rm -rf -- "\$target"
  fi
done
BASH);
});

/**
 * Keep Deployer's archive strategy unchanged while making only its remote
 * repository refresh resilient to one proven transient SSH transport failure.
 */
task('deploy:update_code', function () {
    $git = get('bin/git');
    $repository = get('repository');
    $target = get('target');

    if (empty($repository)) {
        throw new \Deployer\Exception\ConfigurationException('Missing repository configuration.');
    }

    $targetWithDir = $target;
    if (! empty(get('sub_directory'))) {
        $targetWithDir .= ':{{sub_directory}}';
    }

    $bare = parse('{{deploy_path}}/.dep/repo');
    $environment = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_SSH_COMMAND' => get('git_ssh_command'),
    ];

    start:
    run("[ -d $bare ] || mkdir -p $bare");
    run("[ -f $bare/HEAD ] || $git clone --mirror $repository $bare 2>&1", ['env' => $environment]);

    cd($bare);

    if (run("$git config --get remote.origin.url") !== $repository) {
        cd('{{deploy_path}}');
        run("rm -rf $bare");
        goto start;
    }

    deployRunGitRemoteUpdateWithBoundedRetry("$git remote update 2>&1", $environment);

    if (get('update_code_strategy') === 'archive') {
        run("$git archive $targetWithDir | tar -x -f - -C {{release_path}} 2>&1");
    } elseif (get('update_code_strategy') === 'clone') {
        cd('{{release_path}}');
        run("$git clone -l $bare .");
        run("$git remote set-url origin $repository", ['env' => $environment]);
        run("$git checkout --force $target");
    } else {
        throw new \Deployer\Exception\ConfigurationException(
            parse('Unknown update_code_strategy [{{update_code_strategy}}].'),
        );
    }

    $revision = escapeshellarg(run("$git rev-list $target -1"));
    run("echo $revision > {{release_path}}/REVISION");
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

after('deploy:update_code', 'release:prune-non-runtime-source');
after('deploy:vendors', 'bootstrap-cache:clear-release');

after('deploy:shared', 'guard:shared-permissions');

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
after('guard:no-pending-migrations', 'artisan:migrate-seo-intel');
after('artisan:migrate-seo-intel', 'guard:no-pending-seo-intel-migrations');
after('guard:no-pending-seo-intel-migrations', 'artisan:scales:seed-default');
after('artisan:scales:seed-default', 'big5:publish-private-result-authority');
after('big5:publish-private-result-authority', 'riasec:publish-private-result-authority');
after('riasec:publish-private-result-authority', 'enneagram:publish-private-result-authority');
after('enneagram:publish-private-result-authority', 'eq60:publish-private-result-authority');
after('eq60:publish-private-result-authority', 'guard:career-runtime-projection-authority');
after('guard:career-runtime-projection-authority', 'career:repair-published-detail-cache-coverage');
after('career:repair-published-detail-cache-coverage', 'guard:career-detail-cache-coverage');
after('guard:career-detail-cache-coverage', 'career:warm-public-authority-cache');
after('career:warm-public-authority-cache', 'career:rebuild-directory-after-detail-repair');
after('career:rebuild-directory-after-detail-repair', 'guard:career-discoverability-pre-sitemap');
after('guard:career-discoverability-pre-sitemap', 'seo:warm-sitemap-source-cache');
after('seo:warm-sitemap-source-cache', 'guard:career-discoverability-post-sitemap');
after('guard:career-discoverability-post-sitemap', 'guard:public-content-release');
after('guard:public-content-release', 'prepare:release-bootstrap-cache-access');
after('deploy:symlink', 'ensure:nginx-public-static-media-route');
after('deploy:symlink', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'queue:reload-workers');
after('deploy:symlink', 'healthcheck:public');
after('healthcheck:public', 'healthcheck:sitemap-source');
after('healthcheck:sitemap-source', 'healthcheck:public-dns');
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
    'career:verify-public-dataset-cache-equivalence',
    'deploy:publish',
    'career:finalize-public-dataset-cache-equivalence',
]);

/**
 * An inactive candidate is materialized from exact staged code without
 * changing the active symlink or any application-data/authority surface.
 * The owning workflow verifies the release identity and inactive state after
 * this task returns successfully.
 */
task('deploy:candidate-only', [
    'guard:deploy-shell-config',
    'guard:forbid-destructive',
    'guard:candidate-only-mode',
    'deploy:prepare',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:view:cache',
    'artisan:event:cache',
    'guard:public-content-release',
    'fap:deploy-unlock-owned',
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
    'guard:career-runtime-projection-authority',
    'guard:public-content-release',
    'deploy:publish',
]);

after('rollback', 'bootstrap-cache:rebuild-current');
after('bootstrap-cache:rebuild-current', 'rollback:healthcheck');

after('deploy:failed', 'fap:deploy-unlock-owned');
after('deploy:failed', 'big5:rollback-private-result-authority-on-failure');
after('deploy:failed', 'riasec:rollback-private-result-authority-on-failure');
after('deploy:failed', 'enneagram:rollback-private-result-authority-on-failure');
after('deploy:failed', 'eq60:rollback-private-result-authority-on-failure');
before('fap:deploy-unlock-owned', 'career:rollback-public-dataset-cache-equivalence');
