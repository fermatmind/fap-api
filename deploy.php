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
set('seo_platform_10_closeout', false);
set('seo_agent_evidence_boundary', false);
set('seo_agent_policy_gateway', false);
set('seo_council_orchestration', false);
set('seo_competitive_evidence', false);
set('seo_measurement_sync_env', '');
set('seo_competitive_writer_env', '');
set('seo_council_closeout_deferred', false);
set('career_current_parity_required', false);
set('career_data_recovery', false);
set('private_result_authority_publish_required', true);

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
set('public_web_base_url', 'https://fermatmind.com');
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

/**
 * @return array<string, string>
 */
function deploySeoIntelRuntimeEnvironment(): array
{
    $hostAlias = currentHost()->getAlias();
    $runtimeEnvironment = trim((string) (getenv('SEO_INTEL_RUNTIME_ENVIRONMENT') ?: ''));

    if (! in_array($hostAlias, ['staging', 'production'], true) || $runtimeEnvironment !== $hostAlias) {
        throw new \RuntimeException('SEO Intel runtime configuration is not bound to the current deployment environment.');
    }

    $values = [];
    foreach ([
        'SEO_INTEL_ENABLED',
        'SEO_INTEL_DB_CONNECTION',
        'SEO_INTEL_DB_HOST',
        'SEO_INTEL_DB_PORT',
        'SEO_INTEL_DB_DATABASE',
        'SEO_INTEL_DB_USERNAME',
        'SEO_INTEL_DB_PASSWORD',
        'SEO_INTEL_WRITE_ENABLED',
        'SEO_INTEL_COLLECTORS_ENABLED',
        'SEO_INTEL_DRY_RUN_DEFAULT',
        'SEO_INTEL_ALLOW_EXTERNAL_API_CALLS',
    ] as $key) {
        $value = getenv($key);
        if (! is_string($value) || $value === '' || preg_match('/[\x00\r\n]/', $value)) {
            throw new \RuntimeException("{$key} is missing or contains unsupported control characters.");
        }
        $values[$key] = $value;
    }

    $fixed = [
        'SEO_INTEL_ENABLED' => 'true',
        'SEO_INTEL_DB_CONNECTION' => 'seo_intel',
        'SEO_INTEL_WRITE_ENABLED' => 'false',
        'SEO_INTEL_COLLECTORS_ENABLED' => 'false',
        'SEO_INTEL_DRY_RUN_DEFAULT' => 'true',
        'SEO_INTEL_ALLOW_EXTERNAL_API_CALLS' => 'false',
    ];
    foreach ($fixed as $key => $expected) {
        if ($values[$key] !== $expected) {
            throw new \RuntimeException("{$key} does not match the approved SEO Intel runtime value.");
        }
    }

    if (preg_match('/\A[1-9][0-9]{0,4}\z/', $values['SEO_INTEL_DB_PORT']) !== 1
        || (int) $values['SEO_INTEL_DB_PORT'] > 65535) {
        throw new \RuntimeException('SEO_INTEL_DB_PORT is invalid.');
    }

    $values += [
        'SEO_COUNCIL_SCHEDULER_ENABLED' => 'true',
        'SEO_COUNCIL_DAILY_READ_ONLY_ENABLED' => 'true',
        'SEO_COUNCIL_RUNTIME_CACHE_STORE' => 'redis',
    ];

    return $values;
}

/**
 * @return array<string, string>
 */
function deploySeoIntelMigrationEnvironment(): array
{
    $runtime = deploySeoIntelRuntimeEnvironment();
    $username = getenv('SEO_INTEL_MIGRATION_DB_USERNAME');
    $password = getenv('SEO_INTEL_MIGRATION_DB_PASSWORD');
    $usernamePresent = is_string($username) && $username !== '';
    $passwordPresent = is_string($password) && $password !== '';

    if ($usernamePresent !== $passwordPresent) {
        throw new \RuntimeException('SEO_INTEL_MIGRATION_AUTHORITY_PARTIAL');
    }

    if (! $usernamePresent) {
        if (currentHost()->getAlias() === 'production') {
            return [];
        }

        throw new \RuntimeException('SEO_INTEL_MIGRATION_AUTHORITY_UNAVAILABLE');
    }

    if (preg_match('/[\x00\r\n]/', $username) || preg_match('/[\x00\r\n]/', $password)) {
        throw new \RuntimeException('SEO_INTEL_MIGRATION_AUTHORITY_INVALID');
    }

    if (hash_equals($runtime['SEO_INTEL_DB_USERNAME'], $username)) {
        throw new \RuntimeException('SEO_INTEL_MIGRATION_RUNTIME_ACCOUNT_COLLISION');
    }

    return [
        'SEO_INTEL_MIGRATION_DB_USERNAME' => $username,
        'SEO_INTEL_MIGRATION_DB_PASSWORD' => $password,
    ];
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

function deployGitWithResourceLimits(string $git): string
{
    // Keep housekeeping synchronous with the deployment. Limit the delta
    // search per thread and stream large blobs instead of retaining them.
    return $git.' -c gc.autoDetach=false -c maintenance.autoDetach=false'
        .' -c pack.threads=1 -c pack.windowMemory=64m -c pack.deltaCacheSize=32m'
        .' -c core.bigFileThreshold=16m -c core.deltaBaseCacheLimit=32m';
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

function deploySeoPlatform10RuntimeEnabled(): bool
{
    $bootstrap = <<<'PHP'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config("seo_intel.enabled") ? "enabled" : "disabled";
PHP;
    $status = trim(run(sprintf(
        'cd %s && {{bin/php}} -r %s',
        deployPlaceholderPathArg('{{release_path}}', 'backend'),
        deployShellArg($bootstrap),
    )));

    if ($status === 'enabled') {
        return true;
    }
    if ($status === 'disabled') {
        return false;
    }

    throw new \RuntimeException('Unable to resolve the SEO Intel runtime state for Platform 10 closeout.');
}

function deploySeoPlatform10SkipsDisabledStaging(string $task): bool
{
    if (deploySeoPlatform10RuntimeEnabled()) {
        return false;
    }

    if (currentHost()->getAlias() !== 'staging') {
        throw new \RuntimeException("SEO Platform 10 {$task} requires SEO Intel in production.");
    }

    $receipt = json_encode([
        'schema_version' => 'seo-platform-10-staging-measurement-hold.v1',
        'status' => 'skipped',
        'reason' => 'seo_intel_disabled',
        'task' => $task,
        'writes_committed' => false,
        'search_submission_allowed' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeln("<comment>{$receipt}</comment>");

    return true;
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

function runProductionPublicDnsBusinessEvidence(string $runtimeRoot): void
{
    if (currentHost()->getAlias() !== 'production') {
        return;
    }

    if (! in_array($runtimeRoot, ['{{release_path}}', '{{current_path}}'], true)) {
        throw new \RuntimeException('public DNS business evidence runtime root is invalid');
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
        deployPlaceholderPathArg($runtimeRoot, 'backend/scripts/deploy/verify_public_dns_business_evidence.sh'),
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
    ->set('career_recommendation_publish_required', true)
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
    ->set('career_recommendation_publish_required', false)
    ->set('keep_releases', 3)
    ->set('queue_supervisor_required_programs', [
        'fap-queue-reports',
    ])
    ->set('public_web_base_url', getenv('PUBLIC_WEB_BASE_URL_STG') ?: 'https://fermatmind.com')
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
    runProductionPublicDnsBusinessEvidence('{{release_path}}');
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
    'rollback:healthcheck:public',
    'rollback:healthcheck:sitemap-source',
    'rollback:healthcheck:public-dns',
    'rollback:healthcheck:auth-guest-contract',
    'rollback:healthcheck:seo-council-anonymous',
    'rollback:healthcheck:public-static-media-assets',
    'rollback:healthcheck:ops-entry-contract',
]);

/**
 * ======================================================
 * Composer（backend）
 * ======================================================
 */
task('deploy:vendors', function () {
    run('cd '.deployPlaceholderPathArg('{{release_path}}', 'backend').' && COMPOSER_PROCESS_TIMEOUT=900 {{bin/composer}} install --no-interaction --prefer-dist --optimize-autoloader --no-dev');
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

task('runtime:configure-seo-intel', function (): void {
    $runtime = deploySeoIntelRuntimeEnvironment();
    $candidates = [
        'SEO_COUNCIL_APPROVED_DB_USERNAME' => (string) (getenv('SEO_COUNCIL_DB_USERNAME') ?: ''),
        'SEO_COUNCIL_APPROVED_DB_PASSWORD' => (string) (getenv('SEO_COUNCIL_DB_PASSWORD') ?: ''),
        'SEO_COUNCIL_RUNTIME_DB_USERNAME' => $runtime['SEO_INTEL_DB_USERNAME'],
        'SEO_COUNCIL_RUNTIME_DB_PASSWORD' => $runtime['SEO_INTEL_DB_PASSWORD'],
        'SEO_COUNCIL_MIGRATION_DB_USERNAME' => (string) (getenv('SEO_INTEL_MIGRATION_DB_USERNAME') ?: ''),
        'SEO_COUNCIL_MIGRATION_DB_PASSWORD' => (string) (getenv('SEO_INTEL_MIGRATION_DB_PASSWORD') ?: ''),
        'SEO_COUNCIL_DB_HOST' => $runtime['SEO_INTEL_DB_HOST'],
        'SEO_COUNCIL_DB_PORT' => $runtime['SEO_INTEL_DB_PORT'],
        'SEO_COUNCIL_DB_DATABASE' => $runtime['SEO_INTEL_DB_DATABASE'],
    ];
    $selector = <<<'PHP'
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('SEO_COUNCIL_DB_HOST'), getenv('SEO_COUNCIL_DB_PORT'), getenv('SEO_COUNCIL_DB_DATABASE'));
    foreach (['council' => 'SEO_COUNCIL_APPROVED', 'runtime' => 'SEO_COUNCIL_RUNTIME',
        'migration' => 'SEO_COUNCIL_MIGRATION'] as $name => $prefix) {
        $username = getenv($prefix.'_DB_USERNAME');
        $password = getenv($prefix.'_DB_PASSWORD');
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            continue;
        }
        try {
            $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $principal = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
            if (preg_match('/\Aseo_intel_writer@[A-Za-z0-9.%_:-]{1,255}\z/D', $principal) === 1) {
                echo $name;
                exit(0);
            }
        } catch (Throwable) {
            continue;
        }
    }
} catch (Throwable) {
    // The fixed error below is the only externally visible diagnostic.
}
echo "unavailable";
exit(0);
PHP;
    $selected = trim(run('{{bin/php}} -d display_errors=0 -r '.deployShellArg($selector), ['env' => $candidates]));
    if (! in_array($selected, ['council', 'runtime', 'migration'], true)) {
        $scrubber = <<<'PHP'
$path = $argv[1] ?? '';
$keys = ['SEO_COUNCIL_DB_CONNECTION', 'SEO_COUNCIL_DB_USERNAME', 'SEO_COUNCIL_DB_PASSWORD'];
if ($path === '' || is_link($path) || ! is_file($path)) {
    fwrite(STDERR, "SEO_COUNCIL_RUNTIME_ENV_SCRUB_FAILED\n");
    exit(1);
}
$handle = fopen($path, 'c+b');
if ($handle === false || ! flock($handle, LOCK_EX)) {
    fwrite(STDERR, "SEO_COUNCIL_RUNTIME_ENV_SCRUB_FAILED\n");
    exit(1);
}
$stat = fstat($handle);
$pathStat = lstat($path);
rewind($handle);
$original = stream_get_contents($handle);
if (! is_array($stat) || ! is_array($pathStat) || $stat['ino'] !== $pathStat['ino']
    || $stat['dev'] !== $pathStat['dev'] || ! is_string($original)) {
    fwrite(STDERR, "SEO_COUNCIL_RUNTIME_ENV_SCRUB_FAILED\n");
    exit(1);
}
$updated = '';
foreach (preg_split('/(?<=\n)/', $original, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
    $body = preg_replace('/\r?\n\z/', '', $segment);
    if (preg_match('/\A\s*([A-Z0-9_]+)\s*=/', (string) $body, $matches) === 1
        && in_array($matches[1], $keys, true)) {
        continue;
    }
    $updated .= $segment;
}
$temporary = tempnam(dirname($path), '.seo-council-env-scrub-');
if (! is_string($temporary) || file_put_contents($temporary, $updated, LOCK_EX) === false
    || ! chmod($temporary, $stat['mode'] & 0777)) {
    fwrite(STDERR, "SEO_COUNCIL_RUNTIME_ENV_SCRUB_FAILED\n");
    exit(1);
}
$temporaryHandle = fopen($temporary, 'rb');
if ($temporaryHandle === false || ! fsync($temporaryHandle) || ! fclose($temporaryHandle)
    || ! rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "SEO_COUNCIL_RUNTIME_ENV_SCRUB_FAILED\n");
    exit(1);
}
$readback = (string) file_get_contents($path);
foreach ($keys as $key) {
    if (preg_match('/^'.preg_quote($key, '/').'\s*=/m', $readback) === 1) {
        fwrite(STDERR, "SEO_COUNCIL_RUNTIME_ENV_SCRUB_FAILED\n");
        exit(1);
    }
}
flock($handle, LOCK_UN);
fclose($handle);
echo "SEO Council unavailable writer aliases removed.\n";
PHP;
        run('{{bin/php}} -d display_errors=0 -r '.deployShellArg($scrubber).' '.deployPlaceholderPathArg('{{deploy_path}}', 'shared/backend/.env'));
        throw new \RuntimeException('SEO_COUNCIL_RUNTIME_WRITER_UNAVAILABLE');
    }
    $runtime += [
        'SEO_COUNCIL_DB_CONNECTION' => 'seo_council',
        'SEO_COUNCIL_DB_USERNAME' => $candidates[match ($selected) {
            'council' => 'SEO_COUNCIL_APPROVED_DB_USERNAME',
            'runtime' => 'SEO_COUNCIL_RUNTIME_DB_USERNAME',
            default => 'SEO_COUNCIL_MIGRATION_DB_USERNAME',
        }],
        'SEO_COUNCIL_DB_PASSWORD' => $candidates[match ($selected) {
            'council' => 'SEO_COUNCIL_APPROVED_DB_PASSWORD',
            'runtime' => 'SEO_COUNCIL_RUNTIME_DB_PASSWORD',
            default => 'SEO_COUNCIL_MIGRATION_DB_PASSWORD',
        }],
    ];
    $localPatch = tempnam(sys_get_temp_dir(), 'seo-intel-runtime-');
    if (! is_string($localPatch)) {
        throw new \RuntimeException('Unable to allocate the SEO Intel runtime patch.');
    }

    $remotePatch = '{{release_path}}/.seo-intel-runtime.json';
    try {
        if (file_put_contents($localPatch, json_encode($runtime, JSON_THROW_ON_ERROR)) === false
            || ! chmod($localPatch, 0600)) {
            throw new \RuntimeException('Unable to stage the SEO Intel runtime patch.');
        }
        upload($localPatch, $remotePatch);
    } finally {
        @unlink($localPatch);
    }

    $script = <<<'PHP'
$environmentPath = $argv[1] ?? '';
$patchPath = $argv[2] ?? '';
$allowed = [
    'SEO_INTEL_ENABLED',
    'SEO_INTEL_DB_CONNECTION',
    'SEO_INTEL_DB_HOST',
    'SEO_INTEL_DB_PORT',
    'SEO_INTEL_DB_DATABASE',
    'SEO_INTEL_DB_USERNAME',
    'SEO_INTEL_DB_PASSWORD',
    'SEO_INTEL_WRITE_ENABLED',
    'SEO_INTEL_COLLECTORS_ENABLED',
    'SEO_INTEL_DRY_RUN_DEFAULT',
    'SEO_INTEL_ALLOW_EXTERNAL_API_CALLS',
    'SEO_COUNCIL_SCHEDULER_ENABLED',
    'SEO_COUNCIL_DAILY_READ_ONLY_ENABLED',
    'SEO_COUNCIL_RUNTIME_CACHE_STORE',
    'SEO_COUNCIL_DB_CONNECTION',
    'SEO_COUNCIL_DB_USERNAME',
    'SEO_COUNCIL_DB_PASSWORD',
];

if ($environmentPath === '' || $patchPath === '' || is_link($environmentPath) || ! is_file($environmentPath)) {
    throw new RuntimeException('SEO_INTEL_ENVIRONMENT_PATH_INVALID');
}
$patch = json_decode((string) file_get_contents($patchPath), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($patch) || array_keys($patch) !== $allowed) {
    throw new RuntimeException('SEO_INTEL_PATCH_SCOPE_INVALID');
}
foreach ($patch as $value) {
    if (! is_string($value) || $value === '' || preg_match('/[\x00\r\n]/', $value)) {
        throw new RuntimeException('SEO_INTEL_PATCH_VALUE_INVALID');
    }
}

$quote = static fn (string $value): string => '"'.strtr($value, [
    '\\' => '\\\\',
    '"' => '\\"',
    '$' => '\\$',
]).'"';
$expectedLines = [];
foreach ($patch as $key => $value) {
    $expectedLines[$key] = $key.'='.$quote($value);
}

$handle = fopen($environmentPath, 'c+b');
if ($handle === false || ! flock($handle, LOCK_EX)) {
    throw new RuntimeException('SEO_INTEL_ENVIRONMENT_LOCK_FAILED');
}
$stat = fstat($handle);
$pathStat = lstat($environmentPath);
if (! is_array($stat) || ! is_array($pathStat) || $stat['ino'] !== $pathStat['ino'] || $stat['dev'] !== $pathStat['dev']) {
    throw new RuntimeException('SEO_INTEL_ENVIRONMENT_CHANGED_BEFORE_WRITE');
}
rewind($handle);
$original = stream_get_contents($handle);
if (! is_string($original)) {
    throw new RuntimeException('SEO_INTEL_ENVIRONMENT_READ_FAILED');
}

$seen = [];
$segments = preg_split('/(?<=\n)/', $original, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$updated = '';
foreach ($segments as $segment) {
    $body = preg_replace('/\r?\n\z/', '', $segment);
    $eol = substr($segment, strlen((string) $body));
    if (preg_match('/\A\s*([A-Z0-9_]+)\s*=/', (string) $body, $matches) === 1
        && array_key_exists($matches[1], $expectedLines)) {
        $key = $matches[1];
        if (! isset($seen[$key])) {
            $updated .= $expectedLines[$key].$eol;
            $seen[$key] = true;
        }
        continue;
    }
    $updated .= $segment;
}
foreach ($expectedLines as $key => $line) {
    if (isset($seen[$key])) {
        continue;
    }
    if ($updated !== '' && ! str_ends_with($updated, "\n")) {
        $updated .= "\n";
    }
    $updated .= $line."\n";
}

$atomicWrite = static function (string $bytes) use ($environmentPath, $stat): void {
    $temporary = tempnam(dirname($environmentPath), '.seo-intel-env-');
    if (! is_string($temporary)) {
        throw new RuntimeException('SEO_INTEL_ENVIRONMENT_TEMP_FAILED');
    }
    try {
        if (file_put_contents($temporary, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('SEO_INTEL_ENVIRONMENT_TEMP_WRITE_FAILED');
        }
        chmod($temporary, $stat['mode'] & 0777);
        $temporaryHandle = fopen($temporary, 'rb');
        if ($temporaryHandle === false || ! fsync($temporaryHandle)) {
            throw new RuntimeException('SEO_INTEL_ENVIRONMENT_FSYNC_FAILED');
        }
        fclose($temporaryHandle);
        if (! rename($temporary, $environmentPath)) {
            throw new RuntimeException('SEO_INTEL_ENVIRONMENT_RENAME_FAILED');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
};

$atomicWrite($updated);
clearstatcache(true, $environmentPath);
$readback = (string) file_get_contents($environmentPath);
$valid = ! is_link($environmentPath) && is_file($environmentPath);
foreach ($expectedLines as $line) {
    $valid = $valid && preg_match('/^'.preg_quote($line, '/').'$/m', $readback) === 1;
}
if (! $valid) {
    $atomicWrite($original);
    throw new RuntimeException('SEO_INTEL_ENVIRONMENT_READBACK_FAILED');
}
flock($handle, LOCK_UN);
fclose($handle);
echo "SEO Intel runtime environment configured atomically.\n";
PHP;

    run(sprintf(
        'set -euo pipefail; patch=%s; trap \'rm -f "$patch"\' EXIT; chmod 600 "$patch"; {{bin/php}} -d display_errors=0 -r %s %s "$patch"',
        deployPlaceholderPathArg($remotePatch),
        deployShellArg($script),
        deployPlaceholderPathArg('{{deploy_path}}', 'shared/backend/.env'),
    ));
});

task('guard:seo-intel-runtime-config', function (): void {
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
{{bin/php}} -d display_errors=0 -r '
try {
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connectionName = (string) config("seo_intel.connection");
    $seo = (array) config("database.connections.".$connectionName, []);
    $defaultName = (string) config("database.default");
    $business = (array) config("database.connections.".$defaultName, []);
    $valid = config("seo_intel.enabled") === true
        && $connectionName === "seo_intel"
        && ($seo["driver"] ?? null) === "mysql"
        && trim((string) ($seo["host"] ?? "")) !== ""
        && preg_match("/\\A[1-9][0-9]{0,4}\\z/", (string) ($seo["port"] ?? "")) === 1
        && trim((string) ($seo["database"] ?? "")) !== ""
        && trim((string) ($seo["username"] ?? "")) !== ""
        && (string) ($seo["password"] ?? "") !== ""
        && (string) ($seo["database"] ?? "") !== (string) ($business["database"] ?? "")
        && config("seo_intel.write_enabled") === false
        && config("seo_intel.collectors_enabled") === false
        && config("seo_intel.dry_run_default") === true
        && config("seo_intel.allow_external_api_calls") === false
        && config("seo_council.scheduler_enabled") === true
        && config("seo_council.daily_read_only_enabled") === true
        && config("seo_council.runtime_cache_store") === "redis"
        && config("seo_council.connection") === "seo_council"
        && (config("database.connections.seo_council.database") ?? null) === ($seo["database"] ?? null)
        && trim((string) (config("database.connections.seo_council.username") ?? "")) !== ""
        && (string) (config("database.connections.seo_council.password") ?? "") !== ""
        && config("seo_council.model_runtime_enabled") === false
        && config("seo_council.tool_broker_enabled") === false;
    if (! $valid) {
        throw new RuntimeException("invalid");
    }
    $probe = Illuminate\Support\Facades\DB::connection("seo_intel")->selectOne("SELECT 1 AS probe");
    if ((int) ($probe->probe ?? 0) !== 1) {
        throw new RuntimeException("probe");
    }
    $councilProbe = Illuminate\Support\Facades\DB::connection("seo_council")->selectOne("SELECT 1 AS probe");
    if ((int) ($councilProbe->probe ?? 0) !== 1) {
        throw new RuntimeException("council_probe");
    }
    echo "SEO Intel isolated read-only runtime guard passed.\n";
} catch (Throwable $throwable) {
    $databaseFailure = $throwable instanceof Illuminate\Database\QueryException
        ? $throwable->getPrevious()
        : $throwable;
    $driverCode = $databaseFailure instanceof PDOException && is_array($databaseFailure->errorInfo)
        ? (int) ($databaseFailure->errorInfo[1] ?? 0)
        : 0;
    $category = match (true) {
        in_array($driverCode, [1044, 1045], true) => "credentials",
        $driverCode === 1049 => "database",
        in_array($driverCode, [2002, 2003, 2005, 2006], true) => "transport",
        default => "configuration",
    };
    fwrite(STDERR, "SEO Intel isolated read-only runtime guard failed (".$category.").\n");
    exit(1);
}
'
BASH);
    });
});

task('crawler:configure-aggregate-runtime', function () {
    if (currentHost()->getAlias() !== 'production') {
        writeln('<comment>Skipping crawler aggregate runtime configuration outside production.</comment>');

        return;
    }

    $sourcePath = deploySafeAbsolutePath(
        (string) (getenv('SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY') ?: ''),
        'SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY',
    );

    run(sprintf(
        'SEO_INTEL_CRAWLER_LOG_SOURCE_AUTHORITY=%s {{bin/php}} %s %s %s %s',
        deployShellArg($sourcePath),
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/configure_crawler_aggregate_runtime.php'),
        deployPlaceholderPathArg('{{deploy_path}}', 'shared/backend/.env'),
        deployPlaceholderPathArg('/usr/bin/sudo'),
        deployShellArg('www-data'),
    ));
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

task('career:recover-data', function () {
    if (! deployBooleanOption('career_data_recovery', false)) {
        writeln('<comment>Skipping unchanged Career data recovery.</comment>');

        return;
    }

    within('{{release_path}}/backend', function (): void {
        run('{{bin/php}} artisan career:recover-guide-locale-corruption --execute --json --no-interaction --ansi');
        if ((bool) get('career_recommendation_publish_required')) {
            run('{{bin/php}} artisan career:compile-recommendation-subjects --no-interaction --ansi');
        } else {
            writeln('<comment>Staging has no authoritative Career import history; recommendation publication remains production-only.</comment>');
        }
    });
});

task('artisan:migrate-seo-intel', function () {
    $migration = deploySeoIntelMigrationEnvironment();
    $migration['SEO_INTEL_MIGRATION_AUTHORITY_AVAILABLE'] = $migration === [] ? 'false' : 'true';
    within('{{release_path}}/backend', function () use ($migration): void {
        $script = <<<'PHP'
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    if (config('seo_intel.enabled') !== true) {
        echo "SEO Intel is disabled; skip dedicated migrations.\n";
        exit(0);
    }

    $authorityAvailable = getenv('SEO_INTEL_MIGRATION_AUTHORITY_AVAILABLE') === 'true';
    if (! $authorityAvailable) {
        $status = $kernel->call('migrate:status', [
            '--database' => 'seo_intel',
            '--path' => 'database/migrations/seo_intel',
            '--no-interaction' => true,
            '--no-ansi' => true,
        ]);
        $statusOutput = $kernel->output();
        if ($status !== 0) {
            throw new RuntimeException('SEO_INTEL_MIGRATION_STATUS_UNAVAILABLE');
        }
        if (preg_match('/(^|\s)Pending($|\s)/m', $statusOutput) === 1) {
            throw new RuntimeException('SEO_INTEL_MIGRATION_AUTHORITY_UNAVAILABLE');
        }
        echo "SEO Intel migrations current; migration authority absent, skip.\n";
        exit(0);
    }

    $username = getenv('SEO_INTEL_MIGRATION_DB_USERNAME');
    $password = getenv('SEO_INTEL_MIGRATION_DB_PASSWORD');
    if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
        throw new RuntimeException('SEO_INTEL_MIGRATION_AUTHORITY_UNAVAILABLE');
    }

    config([
        'database.connections.seo_intel.username' => $username,
        'database.connections.seo_intel.password' => $password,
    ]);
    Illuminate\Support\Facades\DB::purge('seo_intel');
    $status = $kernel->call('migrate', [
        '--database' => 'seo_intel',
        '--path' => 'database/migrations/seo_intel',
        '--force' => true,
        '--no-interaction' => true,
        '--ansi' => true,
    ]);
    if ($status !== 0) {
        throw new RuntimeException('SEO_INTEL_MIGRATION_FAILED');
    }
    echo "SEO Intel dedicated migrations complete.\n";
    exit(0);
} catch (Throwable $failure) {
    $reason = in_array($failure->getMessage(), [
        'SEO_INTEL_MIGRATION_AUTHORITY_UNAVAILABLE',
        'SEO_INTEL_MIGRATION_STATUS_UNAVAILABLE',
        'SEO_INTEL_MIGRATION_FAILED',
    ], true) ? $failure->getMessage() : 'SEO_INTEL_MIGRATION_FAILED';
    fwrite(STDERR, $reason."\n");
    exit(1);
}
PHP;

        run('{{bin/php}} -d display_errors=0 -r '.deployShellArg($script), ['env' => $migration]);
    });
});

task('guard:no-pending-seo-intel-migrations', function () {
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
set +e
{{bin/php}} -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(config("seo_intel.enabled") ? 0 : 42);'
seo_intel_status="$?"
set -e
if [ "$seo_intel_status" -eq 42 ]; then
  echo "SEO Intel is disabled; no dedicated migration receipt is required."
  exit 0
fi
if [ "$seo_intel_status" -ne 0 ]; then
  echo "unable to resolve SEO Intel runtime configuration" >&2
  exit "$seo_intel_status"
fi
set +e
status_output="$({{bin/php}} artisan migrate:status --database=seo_intel --path=database/migrations/seo_intel --no-interaction --no-ansi 2>/dev/null)"
status_rc="$?"
set -e
if [ "$status_rc" -ne 0 ]; then
  echo "SEO_INTEL_MIGRATION_STATUS_UNAVAILABLE" >&2
  exit 1
fi
if printf '%s\n' "$status_output" | grep -Eq '(^|[[:space:]])Pending($|[[:space:]])'; then
  echo "SEO_INTEL_MIGRATION_AUTHORITY_UNAVAILABLE" >&2
  exit 1
fi
echo "SEO Intel migration status verified."
BASH);
    });
});

task('seo:council-runtime-db-access', function () {
    if (! deployBooleanOption('seo_council_orchestration', false)) {
        writeln('<comment>Skip unchanged SEO Council runtime access probe.</comment>');

        return;
    }

    within('{{release_path}}/backend', function (): void {
        $script = <<<'PHP'
$council = null;
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $connectionName = (string) config('seo_council.connection', 'seo_intel');
    if ($connectionName !== 'seo_council' || config('seo_intel.write_enabled') !== false) {
        throw new RuntimeException('SEO_COUNCIL_RUNTIME_DB_BOUNDARY_INVALID');
    }
    $connectionConfig = config('database.connections.'.$connectionName);
    $database = is_array($connectionConfig) ? (string) ($connectionConfig['database'] ?? '') : '';
    $seoIntelDatabase = (string) config('database.connections.seo_intel.database', '');
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
        || ! hash_equals($seoIntelDatabase, $database)) {
        throw new RuntimeException('SEO_COUNCIL_RUNTIME_DB_BOUNDARY_INVALID');
    }

    $council = Illuminate\Support\Facades\DB::connection($connectionName);
    $identity = $council->selectOne('SELECT CURRENT_USER() AS principal');
    $principal = is_object($identity) ? (string) ($identity->principal ?? '') : '';
    if (preg_match('/\Aseo_intel_writer@[A-Za-z0-9.%_:-]{1,255}\z/D', $principal) !== 1) {
        throw new RuntimeException('SEO_COUNCIL_RUNTIME_DB_WRITER_IDENTITY_INVALID');
    }

    $council->beginTransaction();
    $now = now('UTC')->format('Y-m-d H:i:s');
    $council->table('seo_council_scheduler_leases')->insert([
        'lease_key' => 'deploy:access-probe:'.bin2hex(random_bytes(12)),
        'owner_token_hash' => hash('sha256', random_bytes(32)),
        'fencing_token' => 1,
        'lease_expires_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $council->rollBack();
    echo "SEO Council isolated audit persistence access verified.\n";
    exit(0);
} catch (Throwable $failure) {
    if ($council instanceof Illuminate\Database\ConnectionInterface && $council->transactionLevel() > 0) {
        $council->rollBack();
    }
    $databaseFailure = $failure instanceof Illuminate\Database\QueryException ? $failure->getPrevious() : $failure;
    $driverCode = $databaseFailure instanceof PDOException && is_array($databaseFailure->errorInfo)
        ? (int) ($databaseFailure->errorInfo[1] ?? 0) : 0;
    $reason = match (true) {
        in_array($driverCode, [1044, 1045], true) => 'SEO_COUNCIL_RUNTIME_DB_WRITER_CREDENTIALS_INVALID',
        in_array($driverCode, [1142, 1143], true) => 'SEO_COUNCIL_RUNTIME_DB_WRITE_PRIVILEGE_MISSING',
        $driverCode === 1146 => 'SEO_COUNCIL_RUNTIME_DB_TABLE_MISSING',
        in_array($driverCode, [2002, 2003, 2005, 2006], true) => 'SEO_COUNCIL_RUNTIME_DB_TRANSPORT_UNAVAILABLE',
        $failure->getMessage() === 'SEO_COUNCIL_RUNTIME_DB_WRITER_IDENTITY_INVALID'
            => 'SEO_COUNCIL_RUNTIME_DB_WRITER_IDENTITY_INVALID',
        default => 'SEO_COUNCIL_RUNTIME_DB_ACCESS_FAILED',
    };
    fwrite(STDERR, $reason."\n");
    exit(1);
}
PHP;

        run('{{bin/php}} -d display_errors=0 -r '.deployShellArg($script));
    });
});

task('seo:platform-10-material-backfill', function () {
    if (! deployBooleanOption('seo_platform_10_closeout', false)) {
        writeln('<comment>Skip SEO Platform 10 bounded material backfill.</comment>');

        return;
    }

    if (deploySeoPlatform10SkipsDisabledStaging('material_backfill')) {
        return;
    }

    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
max_records="$({{bin/php}} -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (int) config("seo_platform_10.max_records");')"
canary_size="$({{bin/php}} -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (int) config("seo_platform_10.canary_size");')"
test "$max_records" = 10000
test "$canary_size" = 10

set +e
dry_run="$({{bin/php}} artisan seo-intel:url-truth-material-backfill --max-records="$max_records" --canary-size="$canary_size" --json --no-interaction --no-ansi)"
dry_run_rc=$?
set -e
printf '%s\n' "$dry_run"
test "$dry_run_rc" = 0
printf '%s' "$dry_run" | {{bin/php}} -r '
$receipt = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$ok = ($receipt["status"] ?? null) === "success"
    && ($receipt["mode"] ?? null) === "dry_run"
    && ($receipt["writes_committed"] ?? null) === false
    && ($receipt["artifact"]["record_count"] ?? 0) > 0
    && ($receipt["bounds"]["max_records"] ?? null) === 10000
    && ($receipt["bounds"]["canary_size"] ?? null) === 10
    && ($receipt["boundaries"]["unknown_legacy_action"] ?? null) === "hold"
    && ($receipt["boundaries"]["search_submission_allowed"] ?? null) === false;
exit($ok ? 0 : 1);
'

first="$({{bin/php}} artisan seo-intel:url-truth-material-backfill --execute --max-records="$max_records" --canary-size="$canary_size" --json --no-interaction --no-ansi)"
printf '%s\n' "$first"
printf '%s' "$first" | {{bin/php}} -r '
$receipt = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$readback = $receipt["readback"] ?? [];
$ok = ($receipt["status"] ?? null) === "success"
    && ($receipt["mode"] ?? null) === "controlled_write"
    && ($receipt["writes_committed"] ?? null) === true
    && ($receipt["idempotent_rerun"]["passed"] ?? null) === true
    && ($receipt["idempotent_rerun"]["pending_writes"] ?? null) === 0
    && ($receipt["projection_state"]["status"] ?? null) === "available"
    && preg_match("/^[a-f0-9]{64}$/", (string) ($receipt["projection_state"]["projection_digest"] ?? "")) === 1
    && count(array_filter($readback, static fn (array $row): bool => ($row["passed"] ?? null) !== true)) === 0;
exit($ok ? 0 : 1);
'
first_digest="$(printf '%s' "$first" | {{bin/php}} -r '$r=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR); echo $r["projection_state"]["projection_digest"] ?? "";')"

repeat="$({{bin/php}} artisan seo-intel:url-truth-material-backfill --execute --max-records="$max_records" --canary-size="$canary_size" --json --no-interaction --no-ansi)"
printf '%s\n' "$repeat"
printf '%s' "$repeat" | EXPECTED_DIGEST="$first_digest" {{bin/php}} -r '
$receipt = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$counts = $receipt["plan"]["counts"] ?? [];
$ok = ($receipt["status"] ?? null) === "success"
    && ($counts["apply"] ?? null) === 0
    && ($counts["retire"] ?? null) === 0
    && ($receipt["idempotent_rerun"]["pending_writes"] ?? null) === 0
    && hash_equals((string) getenv("EXPECTED_DIGEST"), (string) ($receipt["projection_state"]["projection_digest"] ?? ""))
    && ($receipt["boundaries"]["unknown_legacy_action"] ?? null) === "hold";
exit($ok ? 0 : 1);
'
BASH, timeout: 600);
    });
});

task('seo:platform-10-public-closeout', function () {
    if (! deployBooleanOption('seo_platform_10_closeout', false)) {
        writeln('<comment>Skip SEO Platform 10 public closeout.</comment>');

        return;
    }

    if (deploySeoPlatform10SkipsDisabledStaging('public_closeout')) {
        return;
    }

    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
receipt="$({{bin/php}} artisan seo-intel:platform-10-closeout --json --no-interaction --no-ansi)"
printf '%s\n' "$receipt"
printf '%s' "$receipt" | {{bin/php}} -r '
$receipt = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$locales = $receipt["locale_counts"] ?? [];
$ok = ($receipt["status"] ?? null) === "success"
    && ($receipt["url_count"] ?? 0) > 0
    && ($locales["en"] ?? 0) > 0
    && ($locales["zh-CN"] ?? 0) > 0
    && ($receipt["lkg"]["active_pointer_bound"] ?? null) === true
    && ($receipt["lkg"]["immutable_snapshot_readable"] ?? null) === true
    && ($receipt["lkg"]["recovery_ready_without_destructive_probe"] ?? null) === true
    && ($receipt["boundaries"]["destructive_probe_performed"] ?? null) === false
    && ($receipt["boundaries"]["search_submission_allowed"] ?? null) === false;
exit($ok ? 0 : 1);
'
BASH);
    });

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $baseUrl = deployHttpsUrlArg($host, '/');
    $baseUrl = rtrim(trim($baseUrl, "'"), '/');
    $resolve = (bool) get('healthcheck_use_resolve', true) ? $host.':443:127.0.0.1' : '';
    $command = sprintf(
        'SEO_PLATFORM_10_BASE_URL=%s SEO_PLATFORM_10_RESOLVE_TARGET=%s bash %s',
        deployShellArg($baseUrl),
        deployShellArg($resolve),
        deployPlaceholderPathArg('{{release_path}}', 'backend/scripts/deploy/verify_seo_platform_10_public_closeout.sh'),
    );
    run($command);
});

task('seo:agent-evidence-boundary-closeout', function () {
    if (! deployBooleanOption('seo_agent_evidence_boundary', false)) {
        writeln('<comment>Skip SEO agent evidence boundary closeout.</comment>');

        return;
    }

    within('{{current_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
expected_sha="$(tr -d '\r\n' < ../REVISION)"
case "$expected_sha" in (*[!0-9a-f]*|'') exit 1 ;; esac
test "${#expected_sha}" -eq 40
receipt="$({{bin/php}} artisan seo:evidence-boundary-closeout --expected-sha="$expected_sha" --json --no-interaction --no-ansi)"
printf '%s' "$receipt" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$ok = ($payload["contract_version"] ?? null) === "seo.evidence_boundary_closeout.v4"
    && ($payload["release_sha"] ?? null) === ($argv[1] ?? null)
    && in_array(($payload["dependency_status"] ?? null), ["READY", "DEPENDENCY_HOLD"], true)
    && ($payload["execution_allowed"] ?? null) === false
    && ($payload["bundle_write_enabled"] ?? null) === false
    && ($payload["context_build_enabled"] ?? null) === false
    && ($payload["external_fetch_enabled"] ?? null) === false
    && ($payload["retention_delete_enabled"] ?? null) === false
    && ($payload["query_hmac_dual_write_enabled"] ?? null) === false
    && ($payload["agent_external_egress"] ?? null) === false
    && ($payload["allowed_sources_count"] ?? null) === 0
    && ($payload["read_only_gsc"] ?? null) === true
    && ($payload["search_submission_allowed"] ?? null) === false
    && ($payload["post12_agent_write_enabled"] ?? null) === false
    && ($payload["l4_state"] ?? null) === "dormant_not_authorized"
    && ($payload["self_checks"]["private_route_probes"] ?? null) === ["total" => 36, "rejected" => 36, "bypass" => 0]
    && ($payload["self_checks"]["pii_evasion_probes"]["bypass"] ?? null) === 0
    && ($payload["self_checks"]["invalid_context_scope"]["ready"] ?? null) === 0
    && ($payload["self_checks"]["metadata_privacy_probes"] ?? null) === [
        "total" => 52,
        "factory" => ["total" => 19, "rejected" => 19, "bypass" => 0],
        "verifier" => ["total" => 27, "rejected" => 27, "bypass" => 0],
        "context_builder" => ["total" => 6, "held" => 6, "fully_sanitized" => 6, "bypass" => 0],
    ]
    && ($payload["self_checks"]["payment_identifier_evasion_probes"] ?? null) === [
        "malicious_total" => 9,
        "rejected" => 9,
        "bypass" => 0,
        "scanner" => ["total" => 3, "rejected" => 3, "bypass" => 0],
        "factory" => ["total" => 3, "rejected" => 3, "bypass" => 0],
        "verifier" => ["total" => 3, "rejected" => 3, "bypass" => 0],
        "valid_hash_chain" => ["total" => 3, "passed" => 3],
        "valid_hash_false_positive" => 0,
    ]
    && !in_array("fail", (array) ($payload["self_checks"]["gateway"] ?? []), true)
    && ($payload["model_calls"] ?? null) === 0
    && ($payload["tool_calls"] ?? null) === 0
    && ($payload["external_calls"] ?? null) === 0
    && ($payload["business_writes"] ?? null) === 0
    && ($payload["negative_guarantees"]["agent_runtime_created"] ?? null) === false
    && ($payload["negative_guarantees"]["agent_write_permissions"] ?? null) === 0
    && ($payload["negative_guarantees"]["cms_write"] ?? null) === 0
    && ($payload["negative_guarantees"]["search_submission"] ?? null) === 0
    && ($payload["negative_guarantees"]["url_truth_write"] ?? null) === 0
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["receipt_hash"] ?? "")) === 1;
exit($ok ? 0 : 1);
' "$expected_sha"
receipt_dir='{{deploy_path}}/shared/backend/storage/app/release-receipts/seo-agent-evidence-boundary'
receipt_path="$receipt_dir/$expected_sha.json"
receipt_owner=deploy
mkdir -p "$receipt_dir" 2>/dev/null || true
as_receipt_owner() {
  if [ "$receipt_owner" = www-data ]; then
    sudo -n -u www-data -- "$@"
  else
    "$@"
  fi
}
if ! tmp="$(mktemp "$receipt_dir/.${expected_sha}.XXXXXX" 2>/dev/null)"; then
  sudo -n -u www-data -- mkdir -p "$receipt_dir"
  receipt_owner=www-data
  tmp="$(as_receipt_owner mktemp "$receipt_dir/.${expected_sha}.XXXXXX")"
fi
trap 'as_receipt_owner rm -f "$tmp"' EXIT
printf '%s\n' "$receipt" | as_receipt_owner tee "$tmp" >/dev/null
as_receipt_owner chmod 0640 "$tmp"
if as_receipt_owner ln "$tmp" "$receipt_path" 2>/dev/null; then
  :
else
  as_receipt_owner test -f "$receipt_path"
  as_receipt_owner test ! -L "$receipt_path"
  as_receipt_owner cmp -s "$tmp" "$receipt_path"
fi
BASH);
    });
});

task('seo:competitive-evidence-preactivation', function () {
    if (! deployBooleanOption('seo_competitive_evidence', false)) {
        writeln('<comment>Skip SEO competitive evidence ingestion.</comment>');

        return;
    }
    if (currentHost()->getAlias() !== 'production') {
        writeln('<comment>Defer staging competitive evidence until real 11F readmodels are ready.</comment>');

        return;
    }

    set('competitive_environment', 'production');
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
writer_env='{{seo_competitive_writer_env}}'
gsc_env='{{seo_measurement_sync_env}}'
[[ "$writer_env" =~ ^/tmp/fermatmind-11g-production-[1-9][0-9]*-[1-9][0-9]*/competitive-writer\.env$ ]]
[[ "$gsc_env" =~ ^/tmp/fermatmind-11g-production-[1-9][0-9]*-[1-9][0-9]*/measurement\.env$ ]]
test -f "$writer_env"
test ! -L "$writer_env"
test -f "$gsc_env"
test ! -L "$gsc_env"
set -a
. "$writer_env"
set +a
[[ "${APP_CONFIG_CACHE:-}" =~ ^/tmp/fermatmind-11g-production-[1-9][0-9]*-[1-9][0-9]*/competitive-config\.php$ ]]
test ! -e "$APP_CONFIG_CACHE"
test "${SEO_INTEL_WRITE_ENABLED:-}" = true
test -n "${SEO_INTEL_DB_USERNAME:-}"
test -n "${SEO_INTEL_DB_PASSWORD:-}"
candidate_sha="$(tr -d '\r\n' < ../REVISION)"
case "$candidate_sha" in (*[!0-9a-f]*|'') exit 1 ;; esac
test "${#candidate_sha}" -eq 40
environment={{competitive_environment}}
receipt_dir='{{deploy_path}}/shared/backend/storage/app/release-receipts/seo-competitive-evidence'
receipt_probe_owner=deploy
receipt_probe=''
receipt_probe_link=''
cleanup_receipt_probe() {
  if [ -n "$receipt_probe_link" ]; then as_receipt_probe_owner rm -f "$receipt_probe_link" 2>/dev/null || true; fi
  if [ -n "$receipt_probe" ]; then as_receipt_probe_owner rm -f "$receipt_probe" 2>/dev/null || true; fi
}
trap cleanup_receipt_probe EXIT HUP INT TERM
fail_receipt_preflight() {
  printf 'competitive_prepare_status=HOLD\n' >&2
  printf 'competitive_prepare_stage=local_preflight\n' >&2
  printf 'competitive_prepare_reason=RECEIPT_OWNER_PREFLIGHT_HOLD\n' >&2
  exit 1
}
as_receipt_probe_owner() {
  if [ "$receipt_probe_owner" = www-data ]; then
    sudo -n -u www-data -- "$@"
  else
    "$@"
  fi
}
mkdir -p "$receipt_dir" 2>/dev/null || true
[ ! -L "$receipt_dir" ] || fail_receipt_preflight
if ! receipt_probe="$(mktemp "$receipt_dir/.preflight-${candidate_sha}.XXXXXX" 2>/dev/null)"; then
  sudo -n -u www-data -- mkdir -p "$receipt_dir" 2>/dev/null || fail_receipt_preflight
  receipt_probe_owner=www-data
  as_receipt_probe_owner test -d "$receipt_dir" || fail_receipt_preflight
  as_receipt_probe_owner test ! -L "$receipt_dir" || fail_receipt_preflight
  receipt_probe="$(as_receipt_probe_owner mktemp "$receipt_dir/.preflight-${candidate_sha}.XXXXXX" 2>/dev/null)" \
    || fail_receipt_preflight
fi
receipt_probe_link="$receipt_probe.link"
printf 'receipt-preflight\n' | as_receipt_probe_owner tee "$receipt_probe" >/dev/null \
  || fail_receipt_preflight
as_receipt_probe_owner chmod 0640 "$receipt_probe" || fail_receipt_preflight
as_receipt_probe_owner ln "$receipt_probe" "$receipt_probe_link" || fail_receipt_preflight
as_receipt_probe_owner cmp -s "$receipt_probe" "$receipt_probe_link" || fail_receipt_preflight
as_receipt_probe_owner rm -f "$receipt_probe_link" "$receipt_probe" || fail_receipt_preflight
receipt_probe=''
receipt_probe_link=''
set +e
prepare="$(SEO_RELEASE_SHA="$candidate_sha" \
  SEO_COMPETITIVE_EXTERNAL_READ_ENABLED=true \
  SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED=true \
  {{bin/php}} artisan seo:competitive-release-prepare \
    --candidate-sha="$candidate_sha" \
    --cohort=competitive.big-five.live.v2 \
    --gsc-env="$gsc_env" \
    --writer-env="$writer_env" \
    --json --no-interaction --no-ansi)"
prepare_status=$?
set -e
if [ "$prepare_status" -ne 0 ]; then
  printf '%s' "$prepare" | {{bin/php}} scripts/deploy/extract_competitive_preactivation_receipt.php --diagnose || true
  exit "$prepare_status"
fi
receipt="$(printf '%s' "$prepare" | {{bin/php}} scripts/deploy/extract_competitive_preactivation_receipt.php "$candidate_sha" "$environment")"
receipt_path="$receipt_dir/preactivation-$candidate_sha.json"
receipt_owner=deploy
fail_receipt_persistence() {
  printf 'competitive_receipt_status=HOLD\n' >&2
  printf 'competitive_receipt_stage=preactivation\n' >&2
  printf 'competitive_receipt_reason=COMPETITIVE_RECEIPT_PERSISTENCE_UNAVAILABLE\n' >&2
  exit 1
}
as_receipt_owner() {
  if [ "$receipt_owner" = www-data ]; then
    sudo -n -u www-data -- "$@"
  else
    "$@"
  fi
}
mkdir -p "$receipt_dir" 2>/dev/null || true
[ ! -L "$receipt_dir" ] || fail_receipt_persistence
if ! tmp="$(mktemp "$receipt_dir/.${candidate_sha}.XXXXXX" 2>/dev/null)"; then
  sudo -n -u www-data -- mkdir -p "$receipt_dir" 2>/dev/null || fail_receipt_persistence
  receipt_owner=www-data
  as_receipt_owner test -d "$receipt_dir" || fail_receipt_persistence
  as_receipt_owner test ! -L "$receipt_dir" || fail_receipt_persistence
  tmp="$(as_receipt_owner mktemp "$receipt_dir/.${candidate_sha}.XXXXXX" 2>/dev/null)" \
    || fail_receipt_persistence
fi
trap 'as_receipt_owner rm -f "$tmp" >/dev/null 2>&1 || true' EXIT
printf '%s\n' "$receipt" | as_receipt_owner tee "$tmp" >/dev/null \
  || fail_receipt_persistence
as_receipt_owner chmod 0640 "$tmp" || fail_receipt_persistence
if as_receipt_owner ln "$tmp" "$receipt_path" 2>/dev/null; then
  :
else
  as_receipt_owner test -f "$receipt_path" \
    && as_receipt_owner test ! -L "$receipt_path" \
    && as_receipt_owner cmp -s "$tmp" "$receipt_path" \
    || fail_receipt_persistence
fi
BASH, timeout: 2100);
    });
});

task('seo:competitive-evidence-finalize', function () {
    if (! deployBooleanOption('seo_competitive_evidence', false) || currentHost()->getAlias() !== 'production') {
        return;
    }

    within('{{current_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
active_sha="$(tr -d '\r\n' < ../REVISION)"
[[ "$active_sha" =~ ^[0-9a-f]{40}$ ]]
receipt_dir='{{deploy_path}}/shared/backend/storage/app/release-receipts/seo-competitive-evidence'
preactivation="$receipt_dir/preactivation-$active_sha.json"
test -f "$preactivation"
test ! -L "$preactivation"
finalize_owner=deploy
if ! test -r "$preactivation"; then
  sudo -n -u www-data -- test -r "$preactivation"
  finalize_owner=www-data
fi
finalize_config_dir="$(mktemp -d /tmp/fermatmind-competitive-finalize.XXXXXX)"
chmod 0755 "$finalize_config_dir"
trap 'rmdir "$finalize_config_dir"' EXIT
set +e
if [ "$finalize_owner" = www-data ]; then
  receipt="$(sudo -n -u www-data -- env SEO_RELEASE_SHA="$active_sha" APP_CONFIG_CACHE="$finalize_config_dir/config.php" SEO_COMPETITIVE_EXTERNAL_READ_ENABLED=true SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED=true {{bin/php}} artisan seo:competitive-evidence-ingest --cohort=competitive.big-five.live.v2 --finalize-activation --preactivation-receipt="$preactivation" --json --no-interaction --no-ansi)"
  finalize_status=$?
else
  receipt="$(APP_CONFIG_CACHE="$finalize_config_dir/config.php" SEO_RELEASE_SHA="$active_sha" SEO_COMPETITIVE_EXTERNAL_READ_ENABLED=true SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED=true {{bin/php}} artisan seo:competitive-evidence-ingest --cohort=competitive.big-five.live.v2 --finalize-activation --preactivation-receipt="$preactivation" --json --no-interaction --no-ansi)"
  finalize_status=$?
fi
set -e
rmdir "$finalize_config_dir"
trap - EXIT
if [ "$finalize_status" -ne 0 ]; then
  printf '%s' "$receipt" | jq -r '
    "competitive_finalize_status=" + ((.status // "HOLD") | tostring),
    "competitive_finalize_reason=" + ((.hold_reason // "COMPETITIVE_FINALIZE_FAILED") | tostring)
  ' >&2
  exit "$finalize_status"
fi
printf '%s' "$receipt" | jq -e --arg sha "$active_sha" '.receipt_version == "seo.competitive_evidence_closeout.v3" and .candidate_sha == $sha and .production_sha == $sha and .environment == "production" and .closeout_state == "CLOSED" and ."SEO-PLATFORM-11G" == "CLOSED" and .ready_for_11H == true and ."11i_handoff_ready" == true and .competitive_context_status == "READY" and .competitive_hold_reason == "NONE" and .execution_allowed == false and .production_permissions == 0 and .model_calls == 0 and .tool_calls == 0 and .cms_writes == 0 and .url_truth_writes == 0 and .search_writes == 0 and .business_writes == 0' >/dev/null
final="$receipt_dir/$active_sha.json"
receipt_owner=deploy
fail_receipt_persistence() {
  printf 'competitive_receipt_status=HOLD\n' >&2
  printf 'competitive_receipt_stage=finalize\n' >&2
  printf 'competitive_receipt_reason=COMPETITIVE_RECEIPT_PERSISTENCE_UNAVAILABLE\n' >&2
  exit 1
}
as_receipt_owner() {
  if [ "$receipt_owner" = www-data ]; then
    sudo -n -u www-data -- "$@"
  else
    "$@"
  fi
}
mkdir -p "$receipt_dir" 2>/dev/null || true
[ ! -L "$receipt_dir" ] || fail_receipt_persistence
if ! tmp="$(mktemp "$receipt_dir/.${active_sha}.XXXXXX" 2>/dev/null)"; then
  sudo -n -u www-data -- mkdir -p "$receipt_dir" 2>/dev/null || fail_receipt_persistence
  receipt_owner=www-data
  as_receipt_owner test -d "$receipt_dir" || fail_receipt_persistence
  as_receipt_owner test ! -L "$receipt_dir" || fail_receipt_persistence
  tmp="$(as_receipt_owner mktemp "$receipt_dir/.${active_sha}.XXXXXX" 2>/dev/null)" \
    || fail_receipt_persistence
fi
trap 'as_receipt_owner rm -f "$tmp" >/dev/null 2>&1 || true' EXIT
printf '%s\n' "$receipt" | as_receipt_owner tee "$tmp" >/dev/null \
  || fail_receipt_persistence
as_receipt_owner chmod 0640 "$tmp" || fail_receipt_persistence
if as_receipt_owner ln "$tmp" "$final" 2>/dev/null; then
  :
else
  as_receipt_owner test -f "$final" \
    && as_receipt_owner test ! -L "$final" \
    && as_receipt_owner cmp -s "$tmp" "$final" \
    || fail_receipt_persistence
fi
BASH);
    });
});

task('seo:agent-policy-gateway-closeout', function () {
    if (! deployBooleanOption('seo_agent_policy_gateway', false)) {
        writeln('<comment>Skip SEO agent Policy Gateway closeout.</comment>');

        return;
    }

    within('{{current_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
expected_sha="$(tr -d '\r\n' < ../REVISION)"
case "$expected_sha" in (*[!0-9a-f]*|'') exit 1 ;; esac
test "${#expected_sha}" -eq 40
receipt="$({{bin/php}} artisan seo:policy-gateway-closeout --expected-sha="$expected_sha" --json --no-interaction --no-ansi)"
printf '%s' "$receipt" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$zero = ["decision_allow_count", "admission_bypass", "execution_bypass", "manifest_bypass", "entrypoint_bypass", "l4_allow_count", "active_manifest_count", "trusted_signing_key_count", "model_calls", "tool_calls", "external_calls", "business_writes", "cms_writes", "url_truth_writes", "search_submissions"];
$ok = ($payload["contract_version"] ?? null) === "seo.policy_gateway_closeout.v2"
    && ($payload["release_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["policy_registry_id"] ?? null) === "fermatmind.seo.policy_gateway_registry"
    && ($payload["policy_registry_version"] ?? null) === "1.0.0"
    && ($payload["state"] ?? null) === "DEPLOYED_DISABLED"
    && ($payload["mode"] ?? null) === "DETERMINISTIC_DENY_ONLY"
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["policy_registry_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["receipt_hash"] ?? "")) === 1;
foreach ($zero as $field) {
    $ok = $ok && ($payload[$field] ?? null) === 0;
}
$ok = $ok && ($payload["manifest_contract"] ?? null) === [
    "total" => 3,
    "rejected" => 3,
    "bypass" => 0,
    "probes" => [
        ["probe_id" => "review_state_invalid", "outcome" => "REJECTED", "reason_code" => "MANIFEST_CONTRACT_INVALID"],
        ["probe_id" => "authority_revision_empty", "outcome" => "REJECTED", "reason_code" => "MANIFEST_CONTRACT_INVALID"],
        ["probe_id" => "canary_stage_empty", "outcome" => "REJECTED", "reason_code" => "MANIFEST_CONTRACT_INVALID"],
    ],
];
$ok = $ok && ($payload["execution_scope_binding"] ?? null) === [
    "total" => 10,
    "denied" => 8,
    "held" => 2,
    "bypass" => 0,
    "probes" => [
        ["probe_id" => "role_binding_mismatch", "outcome" => "DENIED", "reason_code" => "MANIFEST_ROLE_BINDING_MISMATCH"],
        ["probe_id" => "mission_binding_mismatch", "outcome" => "DENIED", "reason_code" => "MANIFEST_MISSION_BINDING_MISMATCH"],
        ["probe_id" => "autonomy_binding_expansion", "outcome" => "DENIED", "reason_code" => "MANIFEST_AUTONOMY_BINDING_MISMATCH"],
        ["probe_id" => "target_environment_mismatch", "outcome" => "DENIED", "reason_code" => "MANIFEST_TARGET_ENVIRONMENT_MISMATCH"],
        ["probe_id" => "evidence_threshold_unmet", "outcome" => "HELD", "reason_code" => "EVIDENCE_THRESHOLD_UNMET"],
        ["probe_id" => "canary_stage_mismatch", "outcome" => "DENIED", "reason_code" => "CANARY_STAGE_MISMATCH"],
        ["probe_id" => "approval_pending", "outcome" => "HELD", "reason_code" => "APPROVAL_PENDING"],
        ["probe_id" => "approval_rejected", "outcome" => "DENIED", "reason_code" => "APPROVAL_REJECTED"],
        ["probe_id" => "approval_unknown", "outcome" => "DENIED", "reason_code" => "APPROVAL_UNKNOWN"],
        ["probe_id" => "blast_radius_scope_mismatch", "outcome" => "DENIED", "reason_code" => "BLAST_RADIUS_SCOPE_MISMATCH"],
    ],
];
exit($ok ? 0 : 1);
' "$expected_sha"
receipt_dir='{{deploy_path}}/shared/backend/storage/app/release-receipts/seo-agent-policy-gateway'
receipt_path="$receipt_dir/$expected_sha.json"
receipt_owner=deploy
mkdir -p "$receipt_dir" 2>/dev/null || true
as_receipt_owner() {
  if [ "$receipt_owner" = www-data ]; then
    sudo -n -u www-data -- "$@"
  else
    "$@"
  fi
}
if ! tmp="$(mktemp "$receipt_dir/.${expected_sha}.XXXXXX" 2>/dev/null)"; then
  sudo -n -u www-data -- mkdir -p "$receipt_dir"
  receipt_owner=www-data
  tmp="$(as_receipt_owner mktemp "$receipt_dir/.${expected_sha}.XXXXXX")"
fi
trap 'as_receipt_owner rm -f "$tmp"' EXIT
printf '%s\n' "$receipt" | as_receipt_owner tee "$tmp" >/dev/null
as_receipt_owner chmod 0640 "$tmp"
if as_receipt_owner ln "$tmp" "$receipt_path" 2>/dev/null; then
  :
else
  as_receipt_owner test -f "$receipt_path"
  as_receipt_owner test ! -L "$receipt_path"
  as_receipt_owner cmp -s "$tmp" "$receipt_path"
fi
test -f "$receipt_path"
BASH);
    });
});

task('seo:council-orchestration-closeout', function () {
    if (! deployBooleanOption('seo_council_orchestration', false)) {
        writeln('<comment>Skip SEO Council orchestration closeout.</comment>');

        return;
    }
    if (deployBooleanOption('seo_council_closeout_deferred', false)) {
        writeln('<comment>Defer SEO Council orchestration closeout to the owning workflow.</comment>');

        return;
    }

    set('technical_closeout_environment', currentHost()->getAlias() === 'production' ? 'production_runtime' : 'staging_runtime');
    within('{{current_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
expected_sha="$(tr -d '\r\n' < ../REVISION)"
case "$expected_sha" in (*[!0-9a-f]*|'') exit 1 ;; esac
test "${#expected_sha}" -eq 40
runner=({{bin/php}} artisan)
if [ "{{technical_closeout_environment}}" = production_runtime ]; then
  runner=(sudo -n -u www-data -- {{bin/php}} artisan)
fi
if receipt="$("${runner[@]}" seo:council-closeout --expected-sha="$expected_sha" --closeout-environment={{technical_closeout_environment}} --json --no-interaction --no-ansi)"; then
  :
else
  printf '%s' "$receipt" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true);
$technical = is_array($payload["technical_diagnosis"] ?? null) ? $payload["technical_diagnosis"] : [];
$nonZero = [];
$unavailableRefs = [];
foreach ([
    "url_truth_revision", "url_truth_projection_hash", "runtime_evidence_revision",
    "runtime_evidence_hash", "authority_revision", "deployment_revision",
] as $field) {
    $value = $technical[$field] ?? null;
    if (!is_string($value) || $value === "" || $value === "unavailable") {
        $unavailableRefs[] = $field;
    }
}
foreach ([
    "real_dependency_binding_bypass", "dependency_ref_mismatch_bypass", "detector_ref_mismatch_bypass",
    "cross_source_field_bypass", "cross_source_overwrite_bypass", "bundle_order_variance_count",
    "unsupported_p0_p1_count", "authority_invention_count", "hardcoded_negative_guarantee_count",
    "orchestrator_runner_bypass", "private_url_leak_count", "policy_bypass_count", "write_attempt_count",
    "shared_root_misclassification_count", "model_calls", "tool_calls", "external_calls", "business_writes",
    "cms_writes", "url_truth_writes", "canonical_writes", "robots_writes", "feed_writes", "search_writes",
    "active_manifest_count", "trusted_key_count", "l4_allow_count", "production_permissions",
] as $field) {
    if (is_int($technical[$field] ?? null) && $technical[$field] !== 0) {
        $nonZero[] = $field;
    }
}
$diagnostic = [
    "safe_error_code" => $payload["safe_error_code"] ?? "SEO_COUNCIL_CLOSEOUT_HELD",
    "council_state" => $payload["SEO-PLATFORM-11D"] ?? "UNAVAILABLE",
    "technical_state" => $technical["closeout_state"] ?? "UNAVAILABLE",
    "dependency_mode" => $technical["dependency_mode"] ?? "UNAVAILABLE",
    "dependency_hold_count" => $technical["authority_metrics"]["dependency_hold_count"] ?? null,
    "contract_hash_drift_count" => $technical["authority_metrics"]["contract_hash_drift_count"] ?? null,
    "historical_authority_drift_count" => $technical["authority_metrics"]["historical_authority_drift_count"] ?? null,
    "active_sha_match" => isset($technical["candidate_sha"], $technical["observed_active_sha"])
        && hash_equals((string) $technical["candidate_sha"], (string) $technical["observed_active_sha"]),
    "unavailable_dependency_refs" => $unavailableRefs,
    "non_zero_metrics" => $nonZero,
];
fwrite(STDERR, "SEO Council safe closeout diagnostic: ".json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
$measurement = is_array($payload["measurement_review"] ?? null) ? $payload["measurement_review"] : [];
$enum = static function (mixed $value, array $allowed, string $fallback): string {
    return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
};
$hash = static function (mixed $value, string $fallback): string {
    return is_string($value) && preg_match("/^[a-f0-9]{64}$/D", $value) === 1
        ? $value
        : hash("sha256", $fallback);
};
$measurementDiagnostic = [
    "search_source_state" => $enum($measurement["search_source_state"] ?? null, ["available", "held", "unavailable", "offline_not_loaded"], "unavailable"),
    "search_freshness_state" => $enum($measurement["search_freshness_state"] ?? null, ["fresh", "stale", "unknown", "not_applicable"], "unknown"),
    "search_bundle_verification" => $enum($measurement["search_bundle_verification"] ?? null, ["valid", "invalid", "unavailable", "not_applicable"], "unavailable"),
    "search_context_status" => $enum($measurement["search_context_status"] ?? null, ["READY", "HOLD", "UNAVAILABLE", "NOT_APPLICABLE"], "UNAVAILABLE"),
    "search_hold_reason" => $enum($measurement["search_hold_reason"] ?? null, ["NONE", "OFFLINE_NOT_LOADED", "GSC_SCHEMA_UNAVAILABLE", "GSC_NO_ELIGIBLE_ROWS", "GSC_QUALITY_HOLD", "GSC_WINDOW_INCOMPLETE", "GSC_STALE", "GSC_MAPPING_FAILED", "GSC_AUTHORITY_CONFLICT", "GSC_READMODEL_UNHEALTHY", "BUNDLE_PRIVACY_HOLD", "BUNDLE_VERIFICATION_HOLD", "CONTEXT_HOLD", "INTERNAL_SAFE_HOLD"], "INTERNAL_SAFE_HOLD"),
    "search_authority_revision" => $hash($measurement["search_authority_revision"] ?? null, "search-unavailable"),
    "cro_source_state" => $enum($measurement["cro_source_state"] ?? null, ["available", "held", "unavailable", "offline_not_loaded"], "unavailable"),
    "cro_freshness_state" => $enum($measurement["cro_freshness_state"] ?? null, ["fresh", "stale", "unknown", "not_applicable"], "unknown"),
    "cro_bundle_verification" => $enum($measurement["cro_bundle_verification"] ?? null, ["valid", "invalid", "unavailable", "not_applicable"], "unavailable"),
    "cro_context_status" => $enum($measurement["cro_context_status"] ?? null, ["READY", "HOLD", "UNAVAILABLE", "NOT_APPLICABLE"], "UNAVAILABLE"),
    "cro_hold_reason" => $enum($measurement["cro_hold_reason"] ?? null, ["NONE", "OFFLINE_NOT_LOADED", "CRO_SCHEMA_UNAVAILABLE", "CRO_READMODEL_UNHEALTHY", "CRO_WINDOW_INCOMPLETE", "CRO_STALE", "CRO_MAPPING_FAILED", "CRO_STAGE_COVERAGE_INCOMPLETE", "BUNDLE_PRIVACY_HOLD", "BUNDLE_VERIFICATION_HOLD", "CONTEXT_HOLD", "INTERNAL_SAFE_HOLD"], "INTERNAL_SAFE_HOLD"),
    "cro_authority_revision" => $hash($measurement["cro_authority_revision"] ?? null, "cro-unavailable"),
    "execution_allowed" => is_bool($measurement["execution_allowed"] ?? null)
        ? $measurement["execution_allowed"]
        : true,
    "production_permissions_zero" => ($measurement["production_permissions"] ?? null) === 0,
];
fwrite(STDERR, "SEO Council safe measurement diagnostic: ".json_encode($measurementDiagnostic, JSON_THROW_ON_ERROR).PHP_EOL);
' || true
  {{bin/php}} -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = (string) config("seo_intel.connection", "seo_intel");
$database = config("database.connections.".$connection.".database");
$diagnostic = [
    "seo_intel_enabled" => config("seo_intel.enabled") === true,
    "connection_name_configured" => $connection !== "",
    "connection_database_configured" => is_string($database) && trim($database) !== "",
    "url_truth_table_available" => false,
    "url_truth_columns_available" => false,
    "url_truth_query_available" => false,
    "technical_health_read_available" => false,
];
try {
    $schema = Illuminate\Support\Facades\Schema::connection($connection);
    $diagnostic["url_truth_table_available"] = $schema->hasTable("seo_urls");
    $diagnostic["url_truth_columns_available"] = $diagnostic["url_truth_table_available"];
    foreach (["indexability_state", "is_private_flow", "updated_at"] as $column) {
        $diagnostic["url_truth_columns_available"] = $diagnostic["url_truth_columns_available"]
            && $schema->hasColumn("seo_urls", $column);
    }
    if ($diagnostic["url_truth_columns_available"]) {
        Illuminate\Support\Facades\DB::connection($connection)->table("seo_urls")
            ->where("indexability_state", "indexable")
            ->where("is_private_flow", false)
            ->selectRaw("COUNT(*) AS current_public_count")
            ->selectRaw("MAX(updated_at) AS revision_at")
            ->first();
        $diagnostic["url_truth_query_available"] = true;
    }
} catch (Throwable) {
}
try {
    $runtime = $app->make(App\Services\SeoIntel\OpsDashboard\SeoTechnicalHealthReadService::class)->read();
    $diagnostic["technical_health_read_available"] = ($runtime["schema_version"] ?? null) === "seo-platform-07-technical-health.v1"
        && data_get($runtime, "boundaries.read_only") === true
        && data_get($runtime, "boundaries.write_authorization_granted") === false;
} catch (Throwable) {
}
fwrite(STDERR, "SEO Council safe source diagnostic: ".json_encode($diagnostic, JSON_THROW_ON_ERROR).PHP_EOL);
' || true
  exit 1
fi
printf '%s' "$receipt" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$environment = (string) ($argv[2] ?? "");
$production = $environment === "production_runtime";
$expectedState = $production ? "CLOSED" : "STAGING_READY";
$expectedMeasurementState = $production ? "CLOSED" : "STAGING_READY";
$zero = [
    "binding_schema_probe_failed", "binding_hash_drift_count", "unbound_mission_count",
    "unknown_role_count", "unknown_capability_count", "unknown_tool_count",
    "admission_deny_bypass", "admission_hold_bypass", "requested_role_expansion_bypass",
    "csrf_bypass", "career_chain_bypass", "policy_reason_overwrite_count",
    "unauthorized_route_execution_count", "receipt_projection_bypass",
    "model_calls", "tool_calls", "external_calls", "business_writes",
    "cms_writes", "url_truth_writes", "search_writes", "active_manifest_count", "trusted_key_count",
    "l4_allow_count", "production_permissions", "active_legacy_seo_agent_entrypoints",
];
$ok = ($payload["contract_version"] ?? null) === "seo.council_closeout.v2"
    && ($payload["source_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["state"] ?? null) === "DEPLOYED_DISABLED"
    && ($payload["runtime_mode"] ?? null) === "DETERMINISTIC_ROUTE_HOLD_ONLY"
    && ($payload["unique_orchestrator_probe_total"] ?? null) === 1
    && ($payload["dependency_status"] ?? null) === "READY"
    && ($payload["contract_schema_hash_drift_count"] ?? null) === 0
    && ($payload["binding_schema_probe_total"] ?? null) === 1
    && ($payload["binding_schema_probe_passed"] ?? null) === 1
    && ($payload["admission_deny_probe_total"] ?? null) === 1
    && ($payload["admission_hold_probe_total"] ?? null) === 5
    && ($payload["five_entrypoint_probe_total"] ?? null) === 5
    && ($payload["five_entrypoint_probe_passed"] ?? null) === 5
    && ($payload["csrf_negative_probe_total"] ?? null) === 3
    && ($payload["career_chain_probe_total"] ?? null) === 1
    && ($payload["receipt_projection_probe_total"] ?? null) === 1
    && ($payload["routing"]["routing_precision"] ?? null) === ["numerator" => 32, "denominator" => 32, "measurement_state" => "observed"]
    && ($payload["routing"]["routing_recall"] ?? null) === ["numerator" => 32, "denominator" => 32, "measurement_state" => "observed"]
    && ($payload["routing"]["missed_required_mode_rate"]["numerator"] ?? null) === 0
    && ($payload["routing"]["unnecessary_mode_rate"]["numerator"] ?? null) === 0
    && ($payload["routing"]["all_team_invocation_count"]["numerator"] ?? null) === 1
    && ($payload["routing"]["unauthorized_all_team_invocation_count"]["numerator"] ?? null) === 0
    && ($payload["career_runtime"] ?? null) === "unavailable_manifest_validator_risk_open"
    && ($payload["mission_persistence_enabled"] ?? null) === false
    && ($payload["execution_allowed"] ?? null) === false
    && ($payload["SEO-PLATFORM-11D"] ?? null) === "CLOSED"
    && ($payload["ready_for_11E"] ?? null) === true
    && ($payload["SEO-PLATFORM-11E"] ?? null) === ($production ? "CLOSED" : "HOLD")
    && ($payload["ready_for_11F"] ?? null) === $production
    && ($payload["SEO-PLATFORM-11F"] ?? null) === ($production ? "CLOSED" : "HOLD")
    && ($payload["ready_for_11G"] ?? null) === $production
    && ($payload["measurement_review"]["receipt_version"] ?? null) === "seo.measurement_closeout.v3"
    && ($payload["measurement_review"]["environment"] ?? null) === $environment
    && ($payload["measurement_review"]["closeout_state"] ?? null) === $expectedMeasurementState
    && ($payload["measurement_review"]["mode_state"] ?? null) === "OFFLINE_EVAL_READY"
    && ($payload["measurement_review"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["measurement_review"]["production_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["measurement_review"]["dependency_status"] ?? null) === "READY"
    && ($payload["measurement_review"]["evidence_source_state"] ?? null) === "available"
    && ($payload["measurement_review"]["evidence_freshness_state"] ?? null) === "fresh"
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["measurement_review"]["evidence_authority_revision"] ?? "")) === 1
    && ($payload["measurement_review"]["search_source_state"] ?? null) === "available"
    && ($payload["measurement_review"]["search_freshness_state"] ?? null) === "fresh"
    && ($payload["measurement_review"]["search_bundle_verification"] ?? null) === "valid"
    && ($payload["measurement_review"]["search_context_status"] ?? null) === "READY"
    && ($payload["measurement_review"]["search_hold_reason"] ?? null) === "NONE"
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["measurement_review"]["search_authority_revision"] ?? "")) === 1
    && ($payload["measurement_review"]["cro_source_state"] ?? null) === "available"
    && ($payload["measurement_review"]["cro_freshness_state"] ?? null) === "fresh"
    && ($payload["measurement_review"]["cro_bundle_verification"] ?? null) === "valid"
    && ($payload["measurement_review"]["cro_context_status"] ?? null) === "READY"
    && ($payload["measurement_review"]["cro_hold_reason"] ?? null) === "NONE"
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["measurement_review"]["cro_authority_revision"] ?? "")) === 1
    && ($payload["measurement_review"]["model_calls"] ?? null) === 0
    && ($payload["measurement_review"]["tool_calls"] ?? null) === 0
    && ($payload["measurement_review"]["external_calls"] ?? null) === 0
    && ($payload["measurement_review"]["cms_writes"] ?? null) === 0
    && ($payload["measurement_review"]["url_truth_writes"] ?? null) === 0
    && ($payload["measurement_review"]["search_writes"] ?? null) === 0
    && ($payload["measurement_review"]["business_writes"] ?? null) === 0
    && ($payload["measurement_review"]["production_permissions"] ?? null) === 0
    && ($payload["measurement_review"]["production_execution_enabled"] ?? null) === false
    && ($payload["measurement_review"]["execution_allowed"] ?? null) === false
    && ($payload["measurement_review"]["SEO-PLATFORM-11F"] ?? null) === ($production ? "CLOSED" : "HOLD")
    && ($payload["measurement_review"]["ready_for_11G"] ?? null) === $production
    && ($payload["platform11"]["receipt_version"] ?? null) === "seo.intent_ownership_closeout.v1"
    && ($payload["platform11"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["platform11"]["environment"] ?? null) === $environment
    && ($payload["platform11"]["closeout_state"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11"]["dependency_status"] ?? null) === "READY"
    && ($payload["platform11"]["dependency_snapshot"]["SEO-PLATFORM-11G"] ?? null) === "CLOSED"
    && ($payload["platform11"]["dependency_snapshot"]["ready_for_11H"] ?? null) === true
    && ($payload["platform11"]["dependency_snapshot"]["11i_handoff_ready"] ?? null) === true
    && ($payload["platform11"]["negative_probes"]["bypass_count"] ?? null) === 0
    && ($payload["platform11"]["role_count"] ?? null) === 9
    && ($payload["platform11"]["seo_orchestrator_count"] ?? null) === 1
    && ($payload["platform11"]["execution_allowed"] ?? null) === false
    && ($payload["platform11"]["SEO-PLATFORM-11H"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11"]["ready_for_11I"] ?? null) === $production
    && ($payload["platform11_editorial"]["receipt_version"] ?? null) === "seo.editorial_draft_closeout.v1"
    && ($payload["platform11_editorial"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["platform11_editorial"]["environment"] ?? null) === $environment
    && ($payload["platform11_editorial"]["closeout_state"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_editorial"]["dependency_status"] ?? null) === "READY"
    && ($payload["platform11_editorial"]["negative_probes"]["bypass_count"] ?? null) === 0
    && ($payload["platform11_editorial"]["artifact_only"] ?? null) === true
    && ($payload["platform11_editorial"]["dry_run_only"] ?? null) === true
    && ($payload["platform11_editorial"]["cms_write"] ?? null) === false
    && ($payload["platform11_editorial"]["publish"] ?? null) === false
    && ($payload["platform11_editorial"]["active_manifest_count"] ?? null) === 0
    && ($payload["platform11_editorial"]["trusted_signing_key_count"] ?? null) === 0
    && ($payload["platform11_editorial"]["execution_allowed"] ?? null) === false
    && ($payload["platform11_editorial"]["SEO-PLATFORM-11I"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_editorial"]["ready_for_11J"] ?? null) === $production
    && ($payload["platform11_runtime_qa"]["receipt_version"] ?? null) === "seo.runtime_qa_closeout.v1"
    && ($payload["platform11_runtime_qa"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["platform11_runtime_qa"]["environment"] ?? null) === $environment
    && ($payload["platform11_runtime_qa"]["closeout_state"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_runtime_qa"]["dependency_status"] ?? null) === "READY"
    && ($payload["platform11_runtime_qa"]["negative_probes"]["bypass_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["http_200_false_pass_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["revision_mismatch_miss_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["causality_overclaim_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["rollback_classification_error_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["prohibited_rollback_attempt_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["write_attempt_count"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["model_calls"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["tool_calls"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["external_calls"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["cms_writes"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["url_truth_writes"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["search_writes"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["business_writes"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["production_permissions"] ?? null) === 0
    && ($payload["platform11_runtime_qa"]["execution_allowed"] ?? null) === false
    && ($payload["platform11_runtime_qa"]["SEO-PLATFORM-11J"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_runtime_qa"]["ready_for_11K"] ?? null) === $production
    && ($payload["platform11_independent_review"]["receipt_version"] ?? null) === "seo.independent_review_closeout.v1"
    && ($payload["platform11_independent_review"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["platform11_independent_review"]["environment"] ?? null) === $environment
    && ($payload["platform11_independent_review"]["closeout_state"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_independent_review"]["dependency_status"] ?? null) === "READY"
    && ($payload["platform11_independent_review"]["negative_probes"]["bypass_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["run_id_reuse_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["context_reuse_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["generation_context_inheritance_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["hidden_reasoning_ingestion_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["mutable_manifest_acceptance_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["forbidden_tool_exposure_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["verdict_enum_violation_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["policy_approve_bypass_count"] ?? null) === 0
    && ($payload["platform11_independent_review"]["model_calls"] ?? null) === 0
    && ($payload["platform11_independent_review"]["tool_calls"] ?? null) === 0
    && ($payload["platform11_independent_review"]["external_calls"] ?? null) === 0
    && ($payload["platform11_independent_review"]["cms_writes"] ?? null) === 0
    && ($payload["platform11_independent_review"]["deploy_writes"] ?? null) === 0
    && ($payload["platform11_independent_review"]["url_truth_writes"] ?? null) === 0
    && ($payload["platform11_independent_review"]["search_writes"] ?? null) === 0
    && ($payload["platform11_independent_review"]["business_writes"] ?? null) === 0
    && ($payload["platform11_independent_review"]["production_permissions"] ?? null) === 0
    && ($payload["platform11_independent_review"]["execution_allowed"] ?? null) === false
    && ($payload["platform11_independent_review"]["SEO-PLATFORM-11K"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_independent_review"]["ready_for_11L"] ?? null) === $production
    && ($payload["platform11_lifecycle"]["receipt_version"] ?? null) === "seo.platform11_closeout.v1"
    && ($payload["platform11_lifecycle"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["platform11_lifecycle"]["environment"] ?? null) === $environment
    && ($payload["platform11_lifecycle"]["closeout_state"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_lifecycle"]["dependency_status"] ?? null) === "READY"
    && ($payload["platform11_lifecycle"]["lifecycle_probes"]["bypass_count"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["canary_probes"]["bypass_count"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["evaluation"]["sample_size"] ?? null) === 96
    && ($payload["platform11_lifecycle"]["evaluation"]["golden_fixture_passed"] ?? null) === 96
    && ($payload["platform11_lifecycle"]["evaluation"]["golden_fixture_total"] ?? null) === 96
    && ($payload["platform11_lifecycle"]["evaluation"]["zero_sample_state"]["measurement_state"] ?? null) === "not_measured"
    && ($payload["platform11_lifecycle"]["fault_drill"]["scenario_count"] ?? null) === 15
    && ($payload["platform11_lifecycle"]["fault_drill"]["passed_count"] ?? null) === 15
    && ($payload["platform11_lifecycle"]["capability_states"] ?? null) === [
        "L0" => "READY", "L1" => "READY", "L2" => "IMPLEMENTED_WRITE_DISABLED",
        "L3" => "IMPLEMENTED_WRITE_DISABLED", "L4" => "DORMANT_NOT_AUTHORIZED",
    ]
    && ($payload["platform11_lifecycle"]["role_count"] ?? null) === 9
    && ($payload["platform11_lifecycle"]["seo_orchestrator_count"] ?? null) === 1
    && ($payload["platform11_lifecycle"]["active_manifest_count"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["trusted_signing_key_count"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["model_calls"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["tool_calls"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["external_calls"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["cms_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["publish_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["url_truth_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["canonical_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["robots_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["search_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["business_writes"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["production_permissions"] ?? null) === 0
    && ($payload["platform11_lifecycle"]["post12_agent_write_enabled"] ?? null) === false
    && ($payload["platform11_lifecycle"]["execution_allowed"] ?? null) === false
    && ($payload["platform11_lifecycle"]["SEO-PLATFORM-11L"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_lifecycle"]["SEO-PLATFORM-11"] ?? null) === ($production ? "CLOSED" : "STAGING_READY")
    && ($payload["platform11_lifecycle"]["ready_for_12"] ?? null) === $production
    && ($payload["technical_diagnosis"]["receipt_version"] ?? null) === "seo.technical_diagnosis_closeout.v2"
    && ($payload["technical_diagnosis"]["environment"] ?? null) === $environment
    && ($payload["technical_diagnosis"]["dependency_mode"] ?? null) === "RUNTIME_READ_ONLY"
    && ($payload["technical_diagnosis"]["closeout_state"] ?? null) === $expectedState
    && ($payload["technical_diagnosis"]["candidate_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["technical_diagnosis"]["observed_active_sha"] ?? null) === ($argv[1] ?? null)
    && ($payload["technical_diagnosis"]["SEO-PLATFORM-11E"] ?? null) === ($production ? "CLOSED" : "HOLD")
    && ($payload["technical_diagnosis"]["ready_for_11F"] ?? null) === $production
    && ! str_contains((string) ($payload["technical_diagnosis"]["url_truth_revision"] ?? ""), "offline-eval")
    && ! str_contains((string) ($payload["technical_diagnosis"]["runtime_evidence_revision"] ?? ""), "offline-eval")
    && ! str_contains((string) ($payload["technical_diagnosis"]["authority_revision"] ?? ""), "offline-eval")
    && ($payload["technical_diagnosis"]["private_url_leak_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["unsupported_p0_p1_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["authority_invention_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["policy_bypass_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["write_attempt_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["shared_root_misclassification_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["real_dependency_binding_bypass"] ?? null) === 0
    && ($payload["technical_diagnosis"]["dependency_ref_mismatch_bypass"] ?? null) === 0
    && ($payload["technical_diagnosis"]["detector_ref_mismatch_bypass"] ?? null) === 0
    && ($payload["technical_diagnosis"]["cross_source_field_bypass"] ?? null) === 0
    && ($payload["technical_diagnosis"]["cross_source_overwrite_bypass"] ?? null) === 0
    && ($payload["technical_diagnosis"]["bundle_order_variance_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["hardcoded_negative_guarantee_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["orchestrator_runner_bypass"] ?? null) === 0
    && ($payload["technical_diagnosis"]["model_calls"] ?? null) === 0
    && ($payload["technical_diagnosis"]["tool_calls"] ?? null) === 0
    && ($payload["technical_diagnosis"]["external_calls"] ?? null) === 0
    && ($payload["technical_diagnosis"]["business_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["cms_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["url_truth_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["canonical_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["robots_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["feed_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["search_writes"] ?? null) === 0
    && ($payload["technical_diagnosis"]["active_manifest_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["trusted_key_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["l4_allow_count"] ?? null) === 0
    && ($payload["technical_diagnosis"]["production_permissions"] ?? null) === 0
    && ($payload["technical_diagnosis"]["execution_allowed"] ?? null) === false
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["technical_diagnosis"]["receipt_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["measurement_review"]["contract_manifest_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["measurement_review"]["dependency_snapshot_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["measurement_review"]["receipt_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["binding_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["contract_manifest_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["receipt_hash"] ?? "")) === 1;
foreach ($zero as $field) {
    $ok = $ok && ($payload[$field] ?? null) === 0;
}
foreach ([
    "real_evidence_bundle_bypass_count", "bundle_verifier_bypass_count", "context_builder_bypass_count",
    "request_pii_bypass_count", "evidence_pii_bypass_count", "metadata_pii_bypass_count",
    "output_pii_bypass_count", "private_url_leak_count", "cro_causal_overclaim_count",
    "source_conflict_bypass_count", "schema_validation_bypass_count", "orchestrator_runner_bypass_count",
    "direct_mode_entry_bypass_count", "policy_bypass_count", "role_expansion_bypass_count",
    "write_attempt_count", "all_privacy_bypass", "source_conflict_bypass", "causal_overclaim", "orchestrator_bypass",
    "model_calls", "tool_calls", "external_calls", "cms_writes",
    "url_truth_writes", "search_writes", "business_writes", "production_permissions",
] as $field) {
    $ok = $ok && ($payload["measurement_review"][$field] ?? null) === 0;
}
exit($ok ? 0 : 1);
' "$expected_sha" "{{technical_closeout_environment}}"
receipt_dir='{{deploy_path}}/shared/backend/storage/app/release-receipts/seo-council-orchestration'
receipt_path="$receipt_dir/$expected_sha.json"
receipt_owner=deploy
mkdir -p "$receipt_dir" 2>/dev/null || true
as_receipt_owner() {
  if [ "$receipt_owner" = www-data ]; then
    sudo -n -u www-data -- "$@"
  else
    "$@"
  fi
}
if ! tmp="$(mktemp "$receipt_dir/.${expected_sha}.XXXXXX" 2>/dev/null)"; then
  sudo -n -u www-data -- mkdir -p "$receipt_dir"
  receipt_owner=www-data
  tmp="$(as_receipt_owner mktemp "$receipt_dir/.${expected_sha}.XXXXXX")"
fi
trap 'as_receipt_owner rm -f "$tmp"' EXIT
printf '%s\n' "$receipt" | as_receipt_owner tee "$tmp" >/dev/null
as_receipt_owner chmod 0640 "$tmp"
if as_receipt_owner ln "$tmp" "$receipt_path" 2>/dev/null; then
  :
else
  as_receipt_owner test -f "$receipt_path"
  as_receipt_owner test ! -L "$receipt_path"
  as_receipt_owner cmp -s "$tmp" "$receipt_path"
fi
test -f "$receipt_path"
BASH);
    });
});

task('seo:detector-foundation-receipt', function () {
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
set +e
{{bin/php}} -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(! config("seo_intel.enabled") ? 42 : (config("seo_intel.write_enabled") ? 0 : 43));'
detector_config_status="$?"
set -e
if [ "$detector_config_status" -ne 0 ] && [ "$detector_config_status" -ne 42 ] && [ "$detector_config_status" -ne 43 ]; then
  exit 19
fi
set +e
dry_run="$({{bin/php}} artisan seo-intel:collect --collector=detector_foundation --dry-run --canary --limit=10 --json --no-interaction --no-ansi)"
dry_status="$?"
set -e
if [ "$dry_status" -ne 0 ]; then
  exit 21
fi
printf '%s\n' "$dry_run"
printf '%s' "$dry_run" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$ok = ($payload["collector"] ?? null) === "detector_foundation"
    && ($payload["status"] ?? null) === "success"
    && ($payload["dry_run"] ?? null) === true
    && ($payload["writes_attempted"] ?? null) === false
    && ($payload["external_calls_attempted"] ?? null) === false
    && ($payload["metadata"]["read_only_gsc"] ?? null) === true
    && ($payload["metadata"]["search_submission_allowed"] ?? null) === false
    && ($payload["metadata"]["source"]["raw_rows_read"] ?? null) === false
    && ($payload["metadata"]["source"]["aggregate_fields_only"] ?? null) === true
    && ($payload["metadata"]["first_receipt"]["boundaries"]["raw_sensitive_fields_output"] ?? null) === false;
exit($ok ? 0 : 1);
' || exit 22
if [ "$detector_config_status" -eq 42 ]; then
  printf '%s' "$dry_run" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$ok = ($payload["metadata"]["source"]["source_state"] ?? null) === "measurement_hold"
    && in_array("detector_source_measurement_hold", $payload["issues"] ?? [], true)
    && ($payload["metadata"]["readback"]["performed"] ?? null) === false
    && array_key_exists("duplicate_rows", $payload["metadata"]["readback"] ?? [])
    && $payload["metadata"]["readback"]["duplicate_rows"] === null;
exit($ok ? 0 : 1);
' || exit 23
  exit 0
fi
if [ "$detector_config_status" -eq 43 ]; then
  printf '%s' "$dry_run" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$sourceState = $payload["metadata"]["source"]["source_state"] ?? null;
$ok = in_array($sourceState, ["available", "measurement_hold"], true)
    && ($sourceState !== "measurement_hold"
        || in_array("detector_source_measurement_hold", $payload["issues"] ?? [], true))
    && ($payload["writes_attempted"] ?? null) === false
    && ($payload["external_calls_attempted"] ?? null) === false
    && ($payload["metadata"]["readback"]["performed"] ?? null) === false
    && ($payload["metadata"]["search_submission_allowed"] ?? null) === false;
exit($ok ? 0 : 1);
' || exit 24
  exit 0
fi

set +e
controlled="$({{bin/php}} artisan seo-intel:collect --collector=detector_foundation --materialize-detector-queues --canary --limit=10 --json --no-interaction --no-ansi)"
controlled_status="$?"
set -e
if [ "$controlled_status" -ne 0 ]; then
  exit 31
fi
printf '%s\n' "$controlled"
printf '%s' "$controlled" | {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$rerun = $payload["metadata"]["idempotent_rerun_receipt"]["counts"] ?? [];
$ok = ($payload["collector"] ?? null) === "detector_foundation"
    && ($payload["status"] ?? null) === "success"
    && ($payload["dry_run"] ?? null) === false
    && ($payload["writes_attempted"] ?? null) === true
    && ($payload["external_calls_attempted"] ?? null) === false
    && ($payload["metadata"]["read_only_gsc"] ?? null) === true
    && ($payload["metadata"]["search_submission_allowed"] ?? null) === false
    && ($payload["metadata"]["readback"]["duplicate_rows"] ?? null) === 0
    && ($rerun["created"] ?? null) === 0
    && ($rerun["updated"] ?? null) === 0
    && ($rerun["reopened"] ?? null) === 0
    && ($rerun["closed"] ?? null) === 0
    && ($payload["metadata"]["first_receipt"]["boundaries"]["raw_sensitive_fields_output"] ?? null) === false;
exit($ok ? 0 : 1);
' || exit 32
BASH);
    });
});

task('seo:url-truth-reconciliation-receipt', function () {
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
set +e
{{bin/php}} -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(config("seo_intel.enabled") ? 0 : 42);'
seo_intel_status="$?"
set -e
if [ "$seo_intel_status" -ne 0 ] && [ "$seo_intel_status" -ne 42 ]; then
  exit 41
fi
probe_args=(--no-http)
if [ "$seo_intel_status" -eq 0 ]; then
  probe_args=(--limit=10 --concurrency=4 --timeout=10 --retries=1)
fi
snapshot="$({{bin/php}} artisan seo-intel:url-truth-reconcile-snapshot "${probe_args[@]}" --json --no-interaction --no-ansi)"
printf '%s\n' "$snapshot"
printf '%s' "$snapshot" | SEO_INTEL_STATUS="$seo_intel_status" {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$counts = $payload["counts"] ?? [];
$sources = $payload["source_state"] ?? [];
$boundaries = $payload["boundaries"] ?? [];
$ok = ($payload["schema_version"] ?? null) === "seo-platform-url-truth-reconciliation.v1"
    && ($boundaries["backend_cms_authority_only"] ?? null) === true
    && ($boundaries["consumers_create_authority"] ?? null) === false
    && ($boundaries["database_write"] ?? null) === false
    && ($boundaries["cms_write"] ?? null) === false
    && ($boundaries["search_submission_allowed"] ?? null) === false
    && ($boundaries["read_only_gsc"] ?? null) === true
    && ($boundaries["raw_url_emitted"] ?? null) === false
    && ($boundaries["response_body_emitted"] ?? null) === false
    && is_int($counts["private_negative_set"] ?? null)
    && $counts["private_negative_set"] > 0;
if ((int) getenv("SEO_INTEL_STATUS") === 42) {
    $ok = $ok
        && ($sources["url_truth"] ?? null) === "measurement_hold"
        && ($sources["entity_bindings"] ?? null) === "measurement_hold"
        && ($sources["live_http"] ?? null) === "measurement_hold"
        && array_key_exists("url_truth_total", $counts)
        && $counts["url_truth_total"] === null;
} else {
    $live = $payload["live_http"] ?? [];
    $ok = $ok
        && ($sources["authority"] ?? null) === "available"
        && ($sources["url_truth"] ?? null) === "available"
        && ($sources["entity_bindings"] ?? null) === "available"
        && ($sources["live_http"] ?? null) === "available"
        && is_int($counts["authority_total"] ?? null)
        && is_int($counts["effective_public"] ?? null)
        && is_int($counts["url_truth_total"] ?? null)
        && ($live["bounded"] ?? null) === true
        && ($live["requested_count"] ?? 0) <= 10
        && ($live["concurrency"] ?? 0) <= 4
        && ($live["timeout_seconds"] ?? 0) <= 10
        && ($live["max_retries"] ?? 0) <= 1
        && ($live["raw_url_emitted"] ?? null) === false
        && ($live["response_body_emitted"] ?? null) === false;
}
exit($ok ? 0 : 1);
' || exit 42
BASH);
    });
});

task('seo:url-truth-controlled-reconcile', function () {
    within('{{release_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
set +e
{{bin/php}} -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(config("seo_intel.enabled") && config("seo_intel.write_enabled") ? 0 : 42);'
write_status="$?"
set -e
args=(--no-http)
if [ "$write_status" -eq 0 ]; then
  args=(--execute --batch-size=250 --max-records=5000)
fi
set +e
receipt="$({{bin/php}} artisan seo-intel:url-truth-controlled-reconcile "${args[@]}" --json --no-interaction --no-ansi)"
command_status="$?"
set -e
printf '%s\n' "$receipt"
printf '%s' "$receipt" | WRITE_STATUS="$write_status" COMMAND_STATUS="$command_status" {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$boundaries = $payload["boundaries"] ?? [];
$ok = ($payload["schema_version"] ?? null) === "seo-platform-controlled-url-truth-reconciliation.v1"
    && ($boundaries["search_submission_allowed"] ?? null) === false
    && ($boundaries["hard_delete"] ?? null) === false;
if ((int) getenv("WRITE_STATUS") === 42) {
    $ok = $ok
        && (int) getenv("COMMAND_STATUS") !== 0
        && ($payload["status"] ?? null) === "blocked"
        && in_array("url_truth_hardened_schema_unavailable", $payload["issues"] ?? [], true)
        && ($payload["writes_committed"] ?? null) === false;
} else {
    $rerun = $payload["idempotent_rerun"] ?? [];
    $batches = $payload["batches"] ?? [];
    $detector = $payload["sitemap_authority_detector"] ?? [];
    $detectorDifference = $detector["sitemap_without_authority_count"] ?? null;
    $detectorCounts = $detector["materialization"]["counts"] ?? [];
    $detectorMaterialized = array_sum(array_map(
        "intval",
        array_intersect_key($detectorCounts, array_flip(["created", "updated", "reopened", "no_change"])),
    ));
    $ok = $ok
        && (int) getenv("COMMAND_STATUS") === 0
        && ($payload["status"] ?? null) === "success"
        && ($payload["mode"] ?? null) === "controlled_write"
        && ($payload["writes_attempted"] ?? null) === true
        && ($payload["writes_committed"] ?? null) === true
        && is_int($payload["artifact"]["record_count"] ?? null)
        && ($payload["artifact"]["record_count"] ?? 0) > 0
        && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["artifact"]["artifact_hash"] ?? "")) === 1
        && ($rerun["added"] ?? null) === 0
        && ($rerun["duplicate"] ?? null) === 0
        && ($rerun["unexpected_updated"] ?? null) === 0
        && ($rerun["private_leakage"] ?? null) === 0
        && ($rerun["current_binding_conflicts"] ?? null) === 0
        && ($rerun["passed"] ?? null) === true
        && $batches !== []
        && count(array_filter($batches, static fn (array $batch): bool => ($batch["database_readback_ok"] ?? null) !== true)) === 0
        && ($boundaries["backend_cms_authority_only"] ?? null) === true
        && ($boundaries["cms_write"] ?? null) === false
        && ($boundaries["content_publication"] ?? null) === false
        && ($boundaries["sitemap_authority_write"] ?? null) === false
        && ($detector["status"] ?? null) === "success"
        && is_int($detectorDifference)
        && ($detectorDifference === 0
            || (($detector["planned_issues"] ?? 0) > 0
                && ($detector["materialization"]["mode"] ?? null) === "controlled_materialization"
                && $detectorMaterialized > 0));
}
exit($ok ? 0 : 1);
' || exit 43
BASH, timeout: 1800);
    });
});

task('seo:url-truth-incremental-cms-canary', function () {
    $canaryMode = currentHost()->getAlias() === 'staging' ? '--allow-measurement-hold' : '';
    within('{{release_path}}/backend', function () use ($canaryMode): void {
        $script = str_replace('__CANARY_MODE__', $canaryMode, <<<'BASH'
set -euo pipefail
set +e
canary_mode="__CANARY_MODE__"
receipt="$({{bin/php}} artisan seo-intel:url-truth-cms-canary --timeout=10 $canary_mode --json --no-interaction --no-ansi)"
command_status="$?"
set -e
printf '%s\n' "$receipt"
test "$command_status" -eq 0 || exit 44
printf '%s' "$receipt" | CANARY_MODE="$canary_mode" {{bin/php}} -r '
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$ok = ($payload["schema_version"] ?? null) === "seo-platform-url-truth-cms-canary.v1";
if (getenv("CANARY_MODE") === "--allow-measurement-hold" && ($payload["status"] ?? null) === "measurement_hold") {
    $ok = $ok
        && ($payload["reason"] ?? null) === "write_lane_disabled"
        && ($payload["cms_publish_service_used"] ?? null) === false
        && ($payload["post_commit_event_path"] ?? null) === false
        && ($payload["url_truth_readback"] ?? null) === false
        && ($payload["runtime_flags_persisted"] ?? null) === false
        && ($payload["boundaries"]["content_body_changed"] ?? null) === false
        && ($payload["boundaries"]["sitemap_authority_mutation_attempted"] ?? null) === false
        && ($payload["boundaries"]["search_submission_allowed"] ?? null) === false;
} else {
    $ok = $ok
    && ($payload["status"] ?? null) === "success"
    && ($payload["cms_publish_service_used"] ?? null) === true
    && ($payload["post_commit_event_path"] ?? null) === true
    && ($payload["canary_queue_transport"] ?? null) === "sync"
    && ($payload["runtime_flags_persisted"] ?? null) === false
    && ($payload["url_truth_readback"] ?? null) === true
    && ($payload["boundaries"]["content_body_changed"] ?? null) === false
    && ($payload["boundaries"]["sitemap_authority_mutation_attempted"] ?? null) === false
    && ($payload["boundaries"]["search_submission_allowed"] ?? null) === false
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["identity_hash"] ?? "")) === 1
    && preg_match("/^[a-f0-9]{64}$/", (string) ($payload["revision_hash"] ?? "")) === 1;
}
exit($ok ? 0 : 1);
' || exit 44
BASH);
        run($script);
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
    if (filter_var(get('private_result_authority_publish_required', true), FILTER_VALIDATE_BOOLEAN) !== true) {
        writeln('Skipping unchanged Big Five private result authority publication.');

        return;
    }
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
    if (filter_var(get('private_result_authority_publish_required', true), FILTER_VALIDATE_BOOLEAN) !== true) {
        writeln('Skipping unchanged RIASEC private result authority publication.');

        return;
    }
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
    if (filter_var(get('private_result_authority_publish_required', true), FILTER_VALIDATE_BOOLEAN) !== true) {
        writeln('Skipping unchanged Enneagram private result authority publication.');

        return;
    }
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
    if (filter_var(get('private_result_authority_publish_required', true), FILTER_VALIDATE_BOOLEAN) !== true) {
        writeln('Skipping unchanged EQ60 private result authority publication.');

        return;
    }
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

                $programPattern = '^'.str_replace('\\-', '-', preg_quote($program, '/')).'(:|$)';
                $statusCommand = "{ sudo -n {$quotedSupervisorctl} status 2>/dev/null || true; }"
                    .' | awk -v pattern='.escapeshellarg($programPattern)
                    ." '\$1 ~ pattern { found=1; if (\$2 != \"RUNNING\" && \$2 != \"STOPPED\") bad=1 } END { exit !(found && !bad) }'";
                if (! test($statusCommand)) {
                    throw new \RuntimeException("queue capability preflight requires a recoverable supervisor program [{$program}] before release activation");
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
                .' --attempts=1'
                .' --delay-seconds=2'
                .' --restart-timeout-seconds=390'
                .' --heartbeat-seconds=20'
                ." --required={$quotedRequired}";
        };

        run("sudo -n {$quotedSupervisorctl} reread");
        run("sudo -n {$quotedSupervisorctl} update");

        foreach ($requiredPrograms as $program) {
            run($restartSupervisorProgram($program, true), timeout: 420);
        }

        foreach ($optionalPrograms as $program) {
            run($restartSupervisorProgram($program, false), timeout: 420);
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

task('scheduler:install-managed-cron', function () {
    if (currentHost()->getAlias() !== 'production') {
        writeln('<comment>Skip managed scheduler installation outside production</comment>');

        return;
    }

    $supervisorctl = trim((string) get('queue_supervisorctl', '/usr/bin/supervisorctl'));
    $resolvedSupervisorctl = trim((string) run(
        'if [ -x '.escapeshellarg($supervisorctl).' ]; then echo '.escapeshellarg($supervisorctl).'; else command -v supervisorctl; fi'
    ));
    if ($resolvedSupervisorctl === '') {
        throw new \RuntimeException('scheduler cron installation requires supervisor status capability');
    }

    $schedulerScript = deployPlaceholderPathArg(
        '{{release_path}}',
        'backend/scripts/deploy/restart_supervisor_scheduler.sh',
    );
    run(
        'php_bin="$(command -v {{bin/php}})"; test -n "$php_bin"; /usr/bin/timeout --signal=TERM --kill-after=5s 90s bash '.$schedulerScript
            .' --supervisorctl='.escapeshellarg($resolvedSupervisorctl)
            .' --sudo=/usr/bin/sudo'
            .' --timeout-bin=/usr/bin/timeout'
            .' --crontab=/usr/bin/crontab'
            .' --php-bin="$php_bin"'
            .' --deploy-path='.deployPlaceholderPathArg('{{deploy_path}}')
            .' --proc-root=/proc'
            .' --required=true',
        timeout: 90,
    );
});

task('scheduler:wait-natural-heartbeat', function () {
    if (currentHost()->getAlias() !== 'production') {
        writeln('<comment>Skip scheduler heartbeat gate outside production</comment>');

        return;
    }

    within('{{current_path}}/backend', function (): void {
        run(<<<'BASH'
set -euo pipefail
started_epoch="$(date -u +%s)"
deadline_epoch="$((started_epoch + 90))"
while [[ "$(date -u +%s)" -le "$deadline_epoch" ]]; do
  set +e
  heartbeat="$({{bin/php}} artisan ops:scheduler-heartbeat-check --max-age-seconds=180 --json --no-interaction --no-ansi 2>/dev/null)"
  heartbeat_rc=$?
  set -e
  if [[ "$heartbeat_rc" -eq 0 ]] && printf '%s' "$heartbeat" | STARTED_EPOCH="$started_epoch" {{bin/php}} -r '
    $payload = json_decode(stream_get_contents(STDIN), true);
    $observed = is_array($payload) ? strtotime((string) ($payload["observed_at"] ?? "")) : false;
    exit(($payload["ok"] ?? false) === true && is_int($observed) && $observed >= (int) getenv("STARTED_EPOCH") ? 0 : 1);
  '; then
    printf 'scheduler_heartbeat_gate_pass\n'
    exit 0
  fi
  sleep 3
done
printf 'scheduler_heartbeat_gate_failed reason=natural_tick_timeout\n' >&2
exit 1
BASH, timeout: 95);
    });
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

task('healthcheck:career-data-recovery', function () {
    if (! deployBooleanOption('career_data_recovery', false)) {
        writeln('<comment>Skipping unchanged Career data recovery smoke.</comment>');

        return;
    }

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $checks = [
        [
            '/api/v0.5/career-guides/annual-career-review-system?locale=zh-CN',
            '.ok == true and .guide.locale == "zh-CN" and .guide.title == "年度职业复盘系统"',
        ],
        [
            '/api/v0.5/career-guides/annual-career-review-system?locale=en',
            '.ok == true and .guide.locale == "en" and .guide.title == "Annual Career Review System"',
        ],
    ];

    if ((bool) get('career_recommendation_publish_required')) {
        array_unshift($checks, [
            '/api/v0.5/career/recommendations/mbti',
            '.bundle_kind == "career_recommendation_index" and (.items | length) == 16 and ([.items[].recommendation_subject_meta.public_route_slug] | unique | length) == 16',
        ]);
    }

    foreach ($checks as [$path, $filter]) {
        $url = deployHttpsUrlArg($host, $path);
        $jq = deployShellArg($filter);
        run("curl -fsS --max-time 15 {$resolveArg}{$url} | jq -e {$jq} >/dev/null");
    }
});

task('healthcheck:public-dns', function () {
    runProductionPublicDnsBusinessEvidence('{{release_path}}');
});

task('rollback:healthcheck:public', function () {
    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/healthz');
    $jq = deployShellArg('.ok==true');
    run("curl -fsS {$resolveArg}{$url} | jq -e {$jq}");
});

task('rollback:healthcheck:sitemap-source', function () {
    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/v0.5/seo/sitemap-source');
    $jq = deployShellArg(
        '.ok==true and .count >= 1 and (.source=="backend_sitemap_generator" or .source=="backend_sitemap_generator_fallback")'
    );
    run("curl -fsS {$resolveArg}{$url} | jq -e {$jq}");
});

task('rollback:healthcheck:public-dns', function () {
    runProductionPublicDnsBusinessEvidence('{{current_path}}');
});

$authGuestContractHealthcheck = function () {
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
};
task('healthcheck:auth-guest-contract', $authGuestContractHealthcheck);
task('rollback:healthcheck:auth-guest-contract', $authGuestContractHealthcheck);

$seoCouncilAnonymousHealthcheck = function () {
    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/v0.5/ops/seo-intel/council/missions');
    $contentType = deployShellArg('Content-Type: application/json');
    $payload = deployShellArg('{}');
    $jq = deployShellArg('.ok==false and .error_code=="UNAUTHORIZED"');

    run("set -euo pipefail; body=\$(mktemp); trap 'rm -f \"\$body\"' EXIT; status=\$(curl -sS {$resolveArg}-o \"\$body\" -w '%{http_code}' -H {$contentType} -X POST {$url} --data {$payload}); test \"\$status\" = 401; jq -e {$jq} \"\$body\" >/dev/null");
};
task('healthcheck:seo-council-anonymous', $seoCouncilAnonymousHealthcheck);
task('rollback:healthcheck:seo-council-anonymous', $seoCouncilAnonymousHealthcheck);

$publicStaticMediaAssetsHealthcheck = function () {
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
};
task('healthcheck:public-static-media-assets', $publicStaticMediaAssetsHealthcheck);
task('rollback:healthcheck:public-static-media-assets', $publicStaticMediaAssetsHealthcheck);

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

$opsEntryContractHealthcheck = function () {
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
};
task('healthcheck:ops-entry-contract', $opsEntryContractHealthcheck);
task('rollback:healthcheck:ops-entry-contract', $opsEntryContractHealthcheck);

task('seo:ledger-production-closeout', function () {
    $target = currentHost()->getAlias();
    $configuredHost = trim((string) get('ops_entry_host', ''));

    if ($configuredHost === '') {
        if ($target === 'production') {
            throw new \RuntimeException('Production SEO ledger closeout requires ops_entry_host.');
        }

        writeln('<comment>Skip staging SEO ledger closeout (ops_entry_host not configured)</comment>');

        return;
    }

    $expectedSha = strtolower(trim((string) (getenv('DEPLOY_REVISION') ?: '')));
    if (preg_match('/\A[a-f0-9]{40}\z/', $expectedSha) !== 1) {
        throw new \RuntimeException('SEO ledger closeout requires an exact deploy SHA.');
    }

    $host = deploySafeHost($configuredHost, 'ops_entry_host');
    $resolveArg = deployCurlResolveArg($host, true);
    $url = deployHttpsUrlArg($host, '/api/v0.5/ops/seo-intel/experiment-ledger');
    $expectedShaArg = deployShellArg($expectedSha);
    $allowUnproven = $target === 'staging' ? '--allow-unproven' : '';

    within('{{current_path}}/backend', function () use ($resolveArg, $url, $expectedShaArg, $allowUnproven): void {
        run(<<<BASH
set -euo pipefail
permission_status="\$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' {$resolveArg}{$url})"
test "\$permission_status" = 401
{{bin/php}} artisan seo-ledger:production-closeout \
  --expected-sha={$expectedShaArg} \
  --permission-negative-status="\$permission_status" \
  {$allowUnproven} --json --no-interaction --no-ansi
BASH);
    });
});

task('seo:weekly-decision-production-closeout', function () {
    $target = currentHost()->getAlias();
    $configuredHost = trim((string) get('ops_entry_host', ''));
    if ($configuredHost === '') {
        if ($target === 'production') {
            throw new \RuntimeException('Production weekly decision closeout requires ops_entry_host.');
        }

        return;
    }

    $expectedSha = strtolower(trim((string) (getenv('DEPLOY_REVISION') ?: '')));
    if (preg_match('/\A[a-f0-9]{40}\z/', $expectedSha) !== 1) {
        throw new \RuntimeException('Weekly decision closeout requires an exact deploy SHA.');
    }

    $host = deploySafeHost($configuredHost, 'ops_entry_host');
    $resolveArg = deployCurlResolveArg($host, true);
    $url = deployHttpsUrlArg($host, '/api/v0.5/ops/seo-intel/weekly-decisions');
    $expectedShaArg = deployShellArg($expectedSha);
    $closeoutOptions = '--allow-unproven';

    within('{{current_path}}/backend', function () use ($resolveArg, $url, $expectedShaArg, $closeoutOptions): void {
        run(<<<BASH
set -euo pipefail
permission_status="\$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' {$resolveArg}{$url})"
test "\$permission_status" = 401
{{bin/php}} artisan seo:weekly-decision-closeout \
  --expected-sha={$expectedShaArg} \
  {$closeoutOptions} --json --no-interaction --no-ansi
BASH, timeout: 60);
    });
});

task('healthcheck:queue-smoke', function () {
    within('{{current_path}}/backend', function () {
        run('bash scripts/deploy/verify_queue_smoke.sh', timeout: 60);
    });
});

task('healthcheck:staging-big-five-report-delivery', function () {
    if (currentHost()->getAlias() !== 'staging') {
        writeln('<comment>Skip staging Big Five report delivery smoke outside staging</comment>');

        return;
    }

    $healthcheckHost = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $publicWebBaseUrl = rtrim(trim((string) get('public_web_base_url', 'https://fermatmind.com')), '/');
    if (preg_match('#\Ahttps://[A-Za-z0-9.-]+(?::[0-9]+)?\z#D', $publicWebBaseUrl) !== 1) {
        throw new \RuntimeException('public_web_base_url must be an HTTPS origin');
    }

    within('{{current_path}}/backend', function () use ($healthcheckHost, $publicWebBaseUrl): void {
        run(
            'HEALTHCHECK_HOST='.deployShellArg($healthcheckHost)
                .' PUBLIC_WEB_BASE_URL='.deployShellArg($publicWebBaseUrl)
                .' DEPLOY_REVISION='.deployShellArg((string) (getenv('DEPLOY_REVISION') ?: ''))
                .' bash scripts/deploy/verify_staging_big_five_report_delivery.sh',
            timeout: 120,
        );
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
task('fap:reclaim-stale-serialized-ci-lock', function () {
    if (getenv('TRUNK_DEPLOY_SERIALIZED') !== 'true') {
        return;
    }

    $runId = trim((string) (getenv('DEPLOY_LOCK_RUN_ID') ?: ''));
    $runAttempt = trim((string) (getenv('DEPLOY_LOCK_RUN_ATTEMPT') ?: ''));
    if (preg_match('/\A[1-9][0-9]*\z/', $runId) !== 1 || preg_match('/\A[1-9][0-9]*\z/', $runAttempt) !== 1) {
        throw new \RuntimeException('Serialized deploy lock recovery requires numeric run ownership.');
    }

    $lockPath = '{{deploy_path}}/.dep/deploy.lock';
    $metaPath = '{{deploy_path}}/'.get('deploy_lock_metadata_path');
    $reclaimScript = <<<'PHP'
$lockPath = $argv[1] ?? '';
$metaPath = $argv[2] ?? '';
$currentRunId = $argv[3] ?? '';
$currentRunAttempt = $argv[4] ?? '';
$minimumAgeSeconds = 300;

if (! file_exists($lockPath) && ! is_link($lockPath)) {
    echo "absent\n";
    exit(0);
}

if (is_link($lockPath) || ! is_file($lockPath)) {
    fwrite(STDERR, "deploy lock is not a regular file\n");
    exit(2);
}

if (trim((string) file_get_contents($lockPath)) !== 'ci') {
    fwrite(STDERR, "deploy lock is not CI-owned\n");
    exit(3);
}

$lockMtime = filemtime($lockPath);
if ($lockMtime === false || (time() - $lockMtime) < $minimumAgeSeconds) {
    fwrite(STDERR, "deploy lock is not stale\n");
    exit(4);
}

if (file_exists($metaPath) || is_link($metaPath)) {
    if (is_link($metaPath) || ! is_file($metaPath)) {
        fwrite(STDERR, "deploy lock metadata is not a regular file\n");
        exit(5);
    }

    $metadata = json_decode((string) file_get_contents($metaPath), true);
    $ownerRunId = is_array($metadata) ? (string) ($metadata['run_id'] ?? '') : '';
    $ownerRunAttempt = is_array($metadata) ? (string) ($metadata['run_attempt'] ?? '') : '';
    if (
        preg_match('/\A[1-9][0-9]*\z/', $ownerRunId) !== 1
        || preg_match('/\A[1-9][0-9]*\z/', $ownerRunAttempt) !== 1
    ) {
        fwrite(STDERR, "deploy lock metadata is invalid\n");
        exit(6);
    }

    if ($ownerRunId === $currentRunId && $ownerRunAttempt === $currentRunAttempt) {
        fwrite(STDERR, "deploy lock is owned by the current run\n");
        exit(7);
    }

    if (! unlink($metaPath)) {
        fwrite(STDERR, "deploy lock metadata could not be removed\n");
        exit(8);
    }
}

if (! unlink($lockPath)) {
    fwrite(STDERR, "stale deploy lock could not be removed\n");
    exit(9);
}

echo "stale_ci_deploy_lock_reclaimed\n";
PHP;

    $result = run(
        'php -r '.deployShellArg($reclaimScript)
        .' '.deployShellArg($lockPath)
        .' '.deployShellArg($metaPath)
        .' '.deployShellArg($runId)
        .' '.deployShellArg($runAttempt),
    );

    if (trim($result) === 'stale_ci_deploy_lock_reclaimed') {
        writeln('<info>Reclaimed a stale serialized CI deploy lock.</info>');
    }
});

task('fap:write-deploy-lock-metadata', function () {
    $metadata = getenv('DEPLOY_LOCK_METADATA');

    if (! is_string($metadata) || trim($metadata) === '') {
        $runId = trim((string) (getenv('DEPLOY_LOCK_RUN_ID') ?: ''));
        $runAttempt = trim((string) (getenv('DEPLOY_LOCK_RUN_ATTEMPT') ?: ''));
        if (preg_match('/\A[1-9][0-9]*\z/', $runId) !== 1 || preg_match('/\A[1-9][0-9]*\z/', $runAttempt) !== 1) {
            return;
        }

        $metadata = json_encode([
            'run_id' => $runId,
            'run_attempt' => $runAttempt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
    $git = deployGitWithResourceLimits(get('bin/git'));
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

before('deploy:lock', 'fap:reclaim-stale-serialized-ci-lock');
after('deploy:lock', 'fap:write-deploy-lock-metadata');
after('deploy:unlock', 'fap:remove-deploy-lock-metadata');

after('deploy:update_code', 'release:prune-non-runtime-source');
after('deploy:vendors', 'bootstrap-cache:clear-release');

after('deploy:shared', 'guard:shared-permissions');
after('guard:shared-permissions', 'crawler:configure-aggregate-runtime');
after('crawler:configure-aggregate-runtime', 'runtime:configure-seo-intel');
before('artisan:config:cache', 'guard:seo-intel-runtime-config');

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

/**
 * CI owns the complete 1046 x 2 package scan. Production validates the
 * accountant bilingual reference page against the real DB/cache before
 * migrations, shared cache warming, publisher execution, or symlink activation.
 */
task('career:current-authority-production-preactivation-parity', function () {
    if (currentHost()->getAlias() !== 'production'
        || filter_var(get('career_current_parity_required', false), FILTER_VALIDATE_BOOLEAN) !== true) {
        return;
    }
    if (! deployCanSudoWwwData()) {
        throw new \RuntimeException('Career production preactivation parity requires the application runtime identity.');
    }

    run(<<<'BASH'
set -euo pipefail
candidate_sha="$(tr -d '\r\n' < '{{release_path}}/REVISION')"
active_sha="$(tr -d '\r\n' < '{{deploy_path}}/current/REVISION')"
case "$candidate_sha" in (*[!0-9a-f]*|'') exit 1 ;; esac
case "$active_sha" in (*[!0-9a-f]*|'') exit 1 ;; esac
test "${#candidate_sha}" -eq 40
test "${#active_sha}" -eq 40
receipt_dir='{{deploy_path}}/shared/backend/storage/app/release-receipts/career-current-authority-preactivation'
receipt_path="$receipt_dir/$candidate_sha.json"
sudo -n -u www-data -- mkdir -p "$receipt_dir"
if ! sudo -n -u www-data -- env \
  CAREER_PARITY_BACKEND_ROOT='{{release_path}}/backend' \
  CAREER_PARITY_RELEASE_SHA="$candidate_sha" \
  CAREER_PARITY_ACTIVE_SHA="$active_sha" \
  CAREER_PARITY_MODE=production-preactivation \
  CAREER_PARITY_REDIS_MODE=readonly \
  CAREER_PARITY_RECEIPT_PATH="$receipt_path" \
  {{bin/php}} -d memory_limit=1024M '{{release_path}}/backend/scripts/ci/career_current_authority_parity.php' >/dev/null; then
  safe_error_code="$(sudo -n -u www-data -- jq -r '.safe_error_code // "CAREER_PARITY_FAILED"' "$receipt_path" 2>/dev/null || true)"
  case "$safe_error_code" in (*[!A-Z0-9_]*|'') safe_error_code=CAREER_PARITY_FAILED ;; esac
  echo "Career production parity failed: $safe_error_code" >&2
  exit 1
fi
test -f "$receipt_path"
BASH);
});

after('artisan:config:cache', 'seo:competitive-evidence-preactivation');
after('seo:competitive-evidence-preactivation', 'career:current-authority-production-preactivation-parity');
after('career:current-authority-production-preactivation-parity', 'guard:sitemap-authority');
after('artisan:migrate', 'guard:no-pending-migrations');
after('guard:no-pending-migrations', 'career:recover-data');
after('career:recover-data', 'artisan:migrate-seo-intel');
after('artisan:migrate-seo-intel', 'guard:no-pending-seo-intel-migrations');
after('guard:no-pending-seo-intel-migrations', 'seo:council-runtime-db-access');
after('seo:council-runtime-db-access', 'seo:platform-10-material-backfill');
after('seo:platform-10-material-backfill', 'seo:detector-foundation-receipt');
after('seo:detector-foundation-receipt', 'artisan:scales:seed-default');
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
after('healthcheck:public-dns', 'healthcheck:career-data-recovery');
after('healthcheck:public-dns', 'seo:url-truth-reconciliation-receipt');
after('seo:url-truth-reconciliation-receipt', 'seo:platform-10-public-closeout');
after('deploy:symlink', 'healthcheck:auth-guest-contract');
after('deploy:symlink', 'healthcheck:public-static-media-assets');
after('deploy:symlink', 'healthcheck:scale-lookup');
after('deploy:symlink', 'healthcheck:ops-entry-contract');
after('deploy:symlink', 'healthcheck:seo-council-anonymous');
after('healthcheck:seo-council-anonymous', 'seo:competitive-evidence-finalize');
after('seo:competitive-evidence-finalize', 'seo:council-orchestration-closeout');
after('healthcheck:ops-entry-contract', 'seo:ledger-production-closeout');
after('healthcheck:ops-entry-contract', 'seo:agent-evidence-boundary-closeout');
after('healthcheck:ops-entry-contract', 'seo:agent-policy-gateway-closeout');
after('queue:reload-workers', 'scheduler:install-managed-cron');
after('queue:reload-workers', 'healthcheck:queue-smoke');
after('healthcheck:queue-smoke', 'healthcheck:staging-big-five-report-delivery');
after('scheduler:install-managed-cron', 'scheduler:wait-natural-heartbeat');

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
