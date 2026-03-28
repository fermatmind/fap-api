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

/**
 * ======================================================
 * 默认 healthcheck / nginx / php-fpm
 * ======================================================
 */
set('healthcheck_scheme', 'https');
set('healthcheck_use_resolve', true);
set('nginx_site', '/etc/nginx/sites-enabled/fap-api');
set('php_fpm_service', 'php8.4-fpm');

/**
 * ======================================================
 * Hosts
 * ======================================================
 */
host('production')
    ->setHostname(getenv('DEPLOY_HOST_PROD') ?: '122.152.221.126')
    ->setRemoteUser(getenv('DEPLOY_USER_PROD') ?: 'ubuntu')
    ->setPort((int)(getenv('DEPLOY_PORT_PROD') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_PROD') ?: '/var/www/fap-api')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_PROD') ?: 'fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_PROD') ?: 'ops.fermatmind.com')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_PROD') ?: 'php8.4-fpm');

host('staging')
    ->setHostname(getenv('DEPLOY_HOST_STG') ?: 'staging.fermatmind.com')
    ->setRemoteUser(getenv('DEPLOY_USER_STG') ?: 'ubuntu')
    ->setPort((int)(getenv('DEPLOY_PORT_STG') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_STG') ?: '/var/www/fap-api-staging')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging.fermatmind.com')
    ->set('ops_entry_host', getenv('OPS_ENTRY_HOST_STG') ?: '')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_STG') ?: 'php8.4-fpm');

/**
 * ======================================================
 * Node / NPM（Ops Theme release build）
 * ======================================================
 */
task('ensure:node-toolchain', function () {
    if (! test('command -v node >/dev/null 2>&1')) {
        throw new \RuntimeException('node missing on deploy host');
    }

    if (! test('command -v npm >/dev/null 2>&1')) {
        throw new \RuntimeException('npm missing on deploy host');
    }

    $nodeVersion = trim(run('node -p "process.versions.node"'));
    $npmVersion = trim(run('npm -v'));

    if (version_compare($nodeVersion, '20.19.0', '<')) {
        throw new \RuntimeException("node {$nodeVersion} is too old; require >= 20.19.0 for current backend package.json");
    }

    if (version_compare($npmVersion, '10.0.0', '<')) {
        throw new \RuntimeException("npm {$npmVersion} is too old; require >= 10.0.0 for reproducible backend package-lock installs");
    }
});

task('build:ops-theme', function () {
    within('{{release_path}}/backend', function () {
        run('test -f package-lock.json');
        run('npm ci --no-audit --no-fund');
        run('npm run build:ops-theme');
    });
});

task('guard:ops-theme-asset', function () {
    $asset = '{{release_path}}/backend/public/css/filament/ops/theme.css';

    if (! test("[ -s {$asset} ]")) {
        throw new \RuntimeException("ops theme asset missing or empty: {$asset}");
    }

    $rawSourcePattern = '@tailwind|@config|vendor/filament/filament/resources/css/base\\.css';
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

task('artisan:route:cache', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan route:cache --ansi');
});

task('artisan:event:cache', function () {
    run('{{bin/php}} {{release_path}}/backend/artisan event:cache --ansi');
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

/**
 * ======================================================
 * 固化权限修复（关键）
 * ======================================================
 */
task('ensure:shared-perms', function () {
    $base = get('deploy_path');

    run("sudo -n /usr/bin/chown -R ubuntu:www-data {$base}/shared/backend/storage");
    run("sudo -n /usr/bin/chown -R ubuntu:www-data {$base}/shared/content_packages");

    run("find {$base}/shared/backend/storage -type d -exec chmod 2775 {} \\;");
    run("find {$base}/shared/backend/storage -type f -exec chmod 664 {} \\;");

    run("find {$base}/shared/content_packages -type d -exec chmod 2775 {} \\;");
    run("find {$base}/shared/content_packages -type f -exec chmod 664 {} \\;");
});

/**
 * ======================================================
 * runtime dirs（healthz 依赖）
 * ======================================================
 */
task('ensure:healthz-deps', function () {
    $base = get('deploy_path') . '/shared/backend/storage';

    run("mkdir -p {$base}/app/content-packs");
    run("chmod 2775 {$base}/app/content-packs");

    run("mkdir -p {$base}/app/private/packs_v2_materialized");
    run("chmod 2775 {$base}/app/private/packs_v2_materialized");

    run("mkdir -p {$base}/framework/cache {$base}/framework/sessions {$base}/framework/views {$base}/logs");
    run("chmod 2775 {$base}/framework/cache {$base}/framework/sessions {$base}/framework/views {$base}/logs");
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

after('deploy:update_code', 'ensure:node-toolchain');
after('deploy:vendors', 'bootstrap-cache:clear-release');

after('deploy:shared', 'ensure:shared-perms');
after('deploy:shared', 'ensure:healthz-deps');

/**
 * vendor 必须先安装完成：
 * - build:ops-theme 依赖 vendor/filament/filament/tailwind.config.preset
 * - artisan:filament:assets 也依赖 composer vendor
 */
after('deploy:vendors', 'build:ops-theme');
after('build:ops-theme', 'guard:ops-theme-asset');
after('guard:ops-theme-asset', 'artisan:filament:assets');
after('artisan:filament:assets', 'guard:filament-assets');

after('deploy:symlink', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck:public');
after('deploy:symlink', 'healthcheck:auth-guest-contract');
after('deploy:symlink', 'healthcheck:ops-entry-contract');

after('rollback', 'bootstrap-cache:rebuild-current');
after('bootstrap-cache:rebuild-current', 'rollback:healthcheck');

after('deploy:failed', 'deploy:unlock');
