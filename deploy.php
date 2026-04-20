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
set('legacy_queue_systemd_service', 'fap-queue.service');
set('legacy_queue_systemd_disable', true);

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
    ->setHostname(getenv('DEPLOY_HOST_PROD') ?: '122.152.221.126')
    ->setRemoteUser(getenv('DEPLOY_USER_PROD') ?: 'ubuntu')
    ->setPort((int)(getenv('DEPLOY_PORT_PROD') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_PROD') ?: '/var/www/fap-api')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_PROD') ?: 'fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_PROD') ?: 'ops.fermatmind.com')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api')
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
    ->setPort((int)(getenv('DEPLOY_PORT_STG') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_STG') ?: '/var/www/fap-api-staging')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging.fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_STG') ?: '')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_STG') ?: 'php8.4-fpm')
    ->set('env', [
        'SEO_PUBLIC_SITEMAP_AUTHORITY' => getenv('SEO_PUBLIC_SITEMAP_AUTHORITY_STG') ?: 'backend',
    ]);

if ($stagingIdentityFile !== null) {
    $stagingHost->setIdentityFile($stagingIdentityFile);
}

task('guard:ops-theme-asset', function () {
    $asset = '{{release_path}}/backend/public/css/app/ops-theme.css';

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
        '{{release_path}}/backend/public/css/filament/forms/forms.css',
        '{{release_path}}/backend/public/css/filament/support/support.css',
        '{{release_path}}/backend/public/css/filament/filament/app.css',
        '{{release_path}}/backend/public/js/filament/filament/app.js',
        '{{release_path}}/backend/public/js/filament/support/support.js',
        '{{release_path}}/backend/public/js/filament/notifications/notifications.js',
    ];

    foreach ($assets as $asset) {
        if (! test("[ -s {$asset} ]")) {
            throw new \RuntimeException("filament asset missing or empty: {$asset}");
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
    'healthcheck:ops-entry-contract',
]);

/**
 * ======================================================
 * Composer（backend）
 * ======================================================
 */
task('deploy:vendors', function () {
    run('cd {{release_path}}/backend && {{bin/composer}} install --no-interaction --prefer-dist --optimize-autoloader --no-dev');
});

/**
 * ======================================================
 * Artisan（全部强制走 backend）
 * ======================================================
 */
task('artisan:filament:assets', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan filament:assets --ansi');
});

task('artisan:storage:link', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan storage:link --ansi');
});

task('artisan:config:cache', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan config:cache --ansi');
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
    run('{{bin/php}} {{release_path}}/backend/artisan route:cache --ansi');
});

task('artisan:event:cache', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan event:cache --ansi');
});

task('artisan:migrate', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan migrate --force --no-interaction --ansi');
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

task('career:warm-public-authority-cache', function () {
    run('timeout 180 {{bin/php}} {{release_path}}/backend/artisan career:warm-public-authority-cache --no-interaction --ansi');
});

task('guard:public-content-release', function () {
    run('timeout 180 {{bin/php}} {{release_path}}/backend/artisan release:verify-public-content --content-source-dir={{release_path}}/content_baselines/content_pages --no-interaction --ansi');
});

task('cms:import-landing-surface-baselines', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan landing-surfaces:import-local-baseline --upsert --status=published --source-dir={{release_path}}/content_baselines/landing_surfaces --no-interaction --ansi');
});

task('cms:import-content-page-baselines', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan content-pages:import-local-baseline --upsert --status=published --source-dir={{release_path}}/content_baselines/content_pages --no-interaction --ansi');
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

/**
 * ======================================================
 * 服务重载
 * ======================================================
 */
task('reload:php-fpm', function () {
    run('sudo -n /usr/bin/systemctl reload ' . get('php_fpm_service'));
});

task('reload:nginx', function () {
    run('sudo -n /usr/bin/systemctl reload nginx');
});

task('queue:reload-workers', function () {
    $manager = strtolower(trim((string) get('queue_manager', 'supervisor')));

    if ($manager === 'supervisor') {
        $supervisorctl = trim((string) get('queue_supervisorctl', '/usr/bin/supervisorctl'));
        $requiredPrograms = array_values(array_filter((array) get('queue_supervisor_required_programs', []), static fn (mixed $value): bool => trim((string) $value) !== ''));
        $optionalPrograms = array_values(array_filter((array) get('queue_supervisor_optional_programs', []), static fn (mixed $value): bool => trim((string) $value) !== ''));
        $legacySystemdService = trim((string) get('legacy_queue_systemd_service', ''));
        $disableLegacySystemd = (bool) get('legacy_queue_systemd_disable', true);

        within('{{current_path}}/backend', function () {
            run('{{bin/php}} artisan queue:restart --ansi');
        });

        $supervisorctlAvailable = test('[ -x ' . escapeshellarg($supervisorctl) . ' ] || command -v supervisorctl >/dev/null 2>&1');
        if (! $supervisorctlAvailable) {
            if ($legacySystemdService !== '') {
                $quotedService = escapeshellarg($legacySystemdService);
                writeln('<comment>supervisorctl not found; fallback to legacy systemd queue service</comment>');
                run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1; then sudo -n /usr/bin/systemctl restart {$quotedService}; else echo 'legacy queue systemd service not found: {$legacySystemdService}' >&2; fi");
                return;
            }

            writeln('<comment>supervisorctl not found and no legacy systemd service configured; skip manager-specific queue reload</comment>');
            return;
        }

        $resolvedSupervisorctl = trim((string) run(
            'if [ -x ' . escapeshellarg($supervisorctl) . ' ]; then echo ' . escapeshellarg($supervisorctl) . '; else command -v supervisorctl; fi'
        ));
        $quotedSupervisorctl = escapeshellarg($resolvedSupervisorctl);

        run("sudo -n {$quotedSupervisorctl} reread");
        run("sudo -n {$quotedSupervisorctl} update");

        foreach ($requiredPrograms as $program) {
            $quotedProgramAll = escapeshellarg($program . ':*');
            $quotedProgram = escapeshellarg($program);
            run("sudo -n {$quotedSupervisorctl} restart {$quotedProgramAll} >/dev/null 2>&1 || sudo -n {$quotedSupervisorctl} restart {$quotedProgram}");
        }

        foreach ($optionalPrograms as $program) {
            $quotedProgramAll = escapeshellarg($program . ':*');
            $quotedProgram = escapeshellarg($program);
            run("sudo -n {$quotedSupervisorctl} restart {$quotedProgramAll} >/dev/null 2>&1 || sudo -n {$quotedSupervisorctl} restart {$quotedProgram} >/dev/null 2>&1 || true");
        }

        if ($legacySystemdService !== '') {
            $quotedService = escapeshellarg($legacySystemdService);
            run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1; then sudo -n /usr/bin/systemctl stop {$quotedService} >/dev/null 2>&1 || true; fi");

            if ($disableLegacySystemd) {
                run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1; then sudo -n /usr/bin/systemctl disable {$quotedService} >/dev/null 2>&1 || true; fi");
            }

            run("if sudo -n /usr/bin/systemctl list-unit-files {$quotedService} >/dev/null 2>&1 && sudo -n /usr/bin/systemctl is-active --quiet {$quotedService}; then echo 'legacy queue systemd service still active: {$legacySystemdService}' >&2; exit 1; fi");
        }

        return;
    }

    if ($manager === 'systemd') {
        $systemdService = trim((string) get('legacy_queue_systemd_service', ''));
        if ($systemdService === '') {
            throw new \RuntimeException('queue manager systemd requires legacy_queue_systemd_service');
        }

        within('{{current_path}}/backend', function () {
            run('{{bin/php}} artisan queue:restart --ansi');
        });
        run('sudo -n /usr/bin/systemctl restart ' . $systemdService);

        return;
    }

    throw new \RuntimeException('unsupported queue_manager [' . $manager . ']');
});

function deploySharedPath(string $base, string $relative): string
{
    return rtrim($base, '/').'/'.ltrim($relative, '/');
}

function ensureOwnedWritableTree(string $path, string $owner = 'ubuntu', string $group = 'www-data'): void
{
    $quotedPath = escapeshellarg($path);

    run("sudo -n /usr/bin/mkdir -p {$quotedPath}");
    run("sudo -n /usr/bin/chown -R {$owner}:{$group} {$quotedPath}");
    run("sudo -n /usr/bin/find {$quotedPath} -type d -exec chmod 2775 {} \\;");
    run("sudo -n /usr/bin/find {$quotedPath} -type f -exec chmod 664 {} \\;");
}

function ensureOwnedWritableDir(string $path, string $owner = 'ubuntu', string $group = 'www-data'): void
{
    $quotedPath = escapeshellarg($path);

    run("sudo -n /usr/bin/mkdir -p {$quotedPath}");
    run("sudo -n /usr/bin/chown {$owner}:{$group} {$quotedPath}");
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

    // Limit deploy-time permission repair to the runtime/shared dirs the release
    // actively needs. app/private/artifacts is governed by the storage lifecycle
    // control plane and may contain historical evidence trees that should not be
    // rewritten on every deploy.
    $sharedWritableDirs = [
        'shared/backend/storage/framework/cache',
        'shared/backend/storage/framework/sessions',
        'shared/backend/storage/framework/views',
        'shared/backend/storage/logs',
        'shared/backend/storage/app/content-packs',
        'shared/backend/storage/app/private/packs_v2_materialized',
    ];

    foreach ($sharedWritableDirs as $relativePath) {
        ensureOwnedWritableTree(deploySharedPath($base, $relativePath), $owner, 'www-data');
    }

    ensureOwnedWritableTree(deploySharedPath($base, 'shared/content_packages'), $owner, 'www-data');
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
    $base = get('deploy_path') . '/shared/backend/storage';
    $owner = currentHost()->getRemoteUser() ?: 'ubuntu';

    ensureOwnedWritableDir("{$base}/app/content-packs", $owner, 'www-data');
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

/**
 * ======================================================
 * Healthcheck
 * ======================================================
 */
task('healthcheck:public', function () {
    $host = get('healthcheck_host');
    $cmd  = "curl -fsS --resolve {$host}:443:127.0.0.1 https://{$host}/api/healthz | jq -e '.ok==true'";
    run($cmd);
});

task('healthcheck:auth-guest-contract', function () {
    $host = get('healthcheck_host');
    $payload = escapeshellarg((string) json_encode([
        'anon_id' => 'deploy_contract_probe',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cmd = "curl -fsS --resolve {$host}:443:127.0.0.1 -H 'Content-Type: application/json' -X POST https://{$host}/api/v0.3/auth/guest --data {$payload} | jq -e '.ok==true and .anon_id==\"deploy_contract_probe\"'";
    run($cmd);
});

task('healthcheck:ops-entry-contract', function () {
    $host = trim((string) get('ops_entry_host', ''));

    if ($host === '') {
        writeln('<comment>Skip ops entry contract smoke (ops_entry_host not configured)</comment>');
        return;
    }

    $fetchHeaders = static function (string $url) use ($host): string {
        return run("curl -sSI --max-redirs 0 --resolve {$host}:443:127.0.0.1 {$url}");
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

        if (! preg_match('/^HTTP\\/[0-9.]+ ' . $status . '\\b/m', $headers)) {
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
    run('mkdir -p {{deploy_path}}/shared/content_packages');
    run('cp -an {{release_path}}/content_packages/. {{deploy_path}}/shared/content_packages/ || true');
});

/**
 * ======================================================
 * Hooks
 * ======================================================
 */
before('deploy', 'guard:forbid-destructive');
before('deploy:prepare', 'ensure:phpredis');
before('deploy:shared', 'fap:seed_shared_content_packages');

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
after('artisan:config:cache', 'guard:sitemap-authority');
after('artisan:migrate', 'guard:no-pending-migrations');
after('guard:no-pending-migrations', 'cms:import-landing-surface-baselines');
after('cms:import-landing-surface-baselines', 'cms:import-content-page-baselines');
after('cms:import-content-page-baselines', 'career:warm-public-authority-cache');
after('career:warm-public-authority-cache', 'guard:public-content-release');
after('guard:public-content-release', 'ensure:release-runtime-perms');

after('deploy:symlink', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'queue:reload-workers');
after('deploy:symlink', 'healthcheck:public');
after('deploy:symlink', 'healthcheck:auth-guest-contract');
after('deploy:symlink', 'healthcheck:ops-entry-contract');
after('deploy:symlink', 'healthcheck:queue-smoke');

after('rollback', 'bootstrap-cache:rebuild-current');
after('bootstrap-cache:rebuild-current', 'rollback:healthcheck');

after('deploy:failed', 'deploy:unlock');
