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
    'backend/bootstrap/cache',
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
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_PROD') ?: 'php8.4-fpm');

host('staging')
    ->setHostname(getenv('DEPLOY_HOST_STG') ?: 'staging.fermatmind.com')
    ->setRemoteUser(getenv('DEPLOY_USER_STG') ?: 'ubuntu')
    ->setPort((int)(getenv('DEPLOY_PORT_STG') ?: 22))
    ->set('deploy_path', getenv('DEPLOY_PATH_STG') ?: '/var/www/fap-api-staging')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: 'staging.fermatmind.com')
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_STG') ?: 'php8.4-fpm');

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
    run("sudo -n /usr/bin/chown -R ubuntu:www-data {$base}/shared/backend/bootstrap/cache");
    run("sudo -n /usr/bin/chown -R ubuntu:www-data {$base}/shared/content_packages");

    run("find {$base}/shared/backend/storage -type d -exec chmod 2775 {} \\;");
    run("find {$base}/shared/backend/storage -type f -exec chmod 664 {} \\;");

    run("find {$base}/shared/backend/bootstrap/cache -type d -exec chmod 2775 {} \\;");
    run("find {$base}/shared/backend/bootstrap/cache -type f -exec chmod 664 {} \\;");

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
    $cmd  = "curl -fsS --resolve {$host}:443:127.0.0.1 https://{$host}/api/v0.2/healthz | jq -e '.ok==true'";
    run($cmd);
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

after('deploy:shared', 'ensure:shared-perms');
after('deploy:shared', 'ensure:healthz-deps');

after('deploy:symlink', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck:public');

after('deploy:failed', 'deploy:unlock');

