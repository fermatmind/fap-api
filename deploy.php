<?php
namespace Deployer;

require 'recipe/laravel.php';

// ========= 基础信息 =========
set('application', 'fap-api');
set('repository', 'git@github.com:fermatmind/fap-api.git');

set('git_tty', false);
set('keep_releases', 5);
set('default_timeout', 900);

// ========= 关键：Laravel 在 backend 子目录 =========
set('public_path', 'backend/public');

// 服务器执行命令用什么
set('bin/php', 'php');
set('bin/composer', 'composer');

// ========= Shared / Writable =========
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

// 直接 chmod，避免 ACL/权限坑
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_use_sudo', false);

// ========= 默认：healthcheck / nginx / php-fpm（可被 host 覆盖） =========
set('healthcheck_scheme', 'https');          // production 用 https
set('healthcheck_use_resolve', true);        // production 用 --resolve 走本机 127.0.0.1:443
set('nginx_site', '/etc/nginx/sites-enabled/fap-api');
set('php_fpm_service', 'php8.4-fpm');

// ========= 生产机 =========
host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: '122.152.221.126')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setPort((int)(getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', '/var/www/fap-api')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST') ?: 'fermatmind.com')
    ->set('healthcheck_scheme', 'https')
    ->set('healthcheck_use_resolve', true)
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api')
    ->set('php_fpm_service', 'php8.4-fpm');

// ========= Staging 机 =========
host('staging')
    ->setHostname(getenv('DEPLOY_HOST') ?: 'staging.fermatmind.com')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setPort((int)(getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', '/var/www/fap-api-staging')
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST') ?: 'staging.fermatmind.com')
    ->set('healthcheck_scheme', 'http')      // 你现在 staging 只配了 80
    ->set('healthcheck_use_resolve', false)  // staging 直接访问域名
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', 'php8.3-fpm');

// ========= 覆盖 vendors：在 backend 里 composer install =========
task('deploy:vendors', function () {
    run('cd {{release_path}}/backend && {{bin/composer}} install --no-interaction --prefer-dist --optimize-autoloader --no-dev');
});

// ========= 强制覆盖 artisan:* 任务：永远走 backend/artisan =========
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

// 你的项目没有 views，view:cache 会炸：View path not found.
task('artisan:view:cache', function () {
    writeln('<comment>Skip artisan:view:cache (no views)</comment>');
});

// ========= 安全门禁：禁止 destructive migration =========
task('guard:forbid-destructive', function () {
    $blocked = [
        'migrate:fresh',
        'db:wipe',
    ];

    foreach ($blocked as $cmd) {
        task("artisan:{$cmd}", function () use ($cmd) {
            throw new \RuntimeException("❌ FORBIDDEN on production: php artisan {$cmd}. Use php artisan migrate --force only.");
        });
    }
});

// ========= 服务重载 =========
task('reload:php-fpm', function () {
    $svc = get('php_fpm_service');
    run("sudo -n /usr/bin/systemctl reload {$svc}");
});

task('reload:nginx', function () {
    run('sudo -n /usr/bin/systemctl reload nginx');
});

// ========= 健康检查 =========
task('healthcheck', function () {
    $host = get('healthcheck_host');
    $scheme = get('healthcheck_scheme');
    $useResolve = (bool) get('healthcheck_use_resolve');

    $nginxSite = get('nginx_site');
    $deployPath = get('deploy_path');
    $expectedRoot = "root {$deployPath}/current/backend/public;";

    // Ensure nginx root points to current release (drift-proof)
    run("test -f {$nginxSite}");
    $hasExpected = run("grep -F \"{$expectedRoot}\" {$nginxSite} >/dev/null 2>&1; echo $?");
    if (trim($hasExpected) !== '0') {
        run("sudo -n /usr/bin/sed -i -E 's#^[[:space:]]*root[[:space:]]+[^;]+;[[:space:]]*$#    {$expectedRoot}#' {$nginxSite}");
        run("sudo -n /usr/sbin/nginx -t");
        run("sudo -n /usr/bin/systemctl reload nginx");
    }

    $resolvePart = '';
    if ($useResolve && $scheme === 'https') {
        $resolvePart = '--resolve ' . $host . ':443:127.0.0.1 ';
    }

    $base = $scheme . '://' . $host;

    run('curl -fsS ' . $resolvePart . $base . '/api/v0.2/health | grep -q "\"ok\":true"');
    run('curl -fsS ' . $resolvePart . $base . '/api/v0.2/scales/MBTI/questions | grep -q "\"ok\":true"');

    $payload = '{"anon_id":"dep-health-001","scale_code":"MBTI","scale_version":"v0.2","question_count":144,"client_platform":"web","region":"CN_MAINLAND","locale":"zh-CN"}';
    run(
        'curl -fsS ' . $resolvePart .
        '-X POST ' . $base . '/api/v0.2/attempts/start ' .
        '-H "Content-Type: application/json" -H "Accept: application/json" ' .
        '-d \'' . $payload . '\' | grep -q "\"ok\":true"'
    );
});

task('healthcheck:content-packs', function () {
    $host = get('healthcheck_host');
    $scheme = get('healthcheck_scheme');
    $useResolve = (bool) get('healthcheck_use_resolve');

    $resolvePart = '';
    if ($useResolve && $scheme === 'https') {
        $resolvePart = '--resolve ' . $host . ':443:127.0.0.1 ';
    }

    $base = $scheme . '://' . $host;

    run(
        'curl -fsS ' . $resolvePart . $base .
        '/api/v0.2/content-packs | jq -e \'.ok==true and (.defaults.default_pack_id|length>0)\''
    );
});

// ========= hooks =========
before('deploy', 'guard:forbid-destructive');

after('artisan:migrate', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck');
after('healthcheck', 'healthcheck:content-packs');

after('deploy:failed', 'rollback');
after('rollback', 'reload:php-fpm');
after('rollback', 'reload:nginx');
after('deploy:failed', 'deploy:unlock');