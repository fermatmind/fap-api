<?php
namespace Deployer;

require 'recipe/laravel.php';

// ========= 基础信息 =========
set('application', 'fap-api');
set('repository', 'git@github.com:fermatmind/fap-api.git');

set('git_tty', false);
set('keep_releases', 5);
set('default_timeout', 900);

set('sentry_release', function () {
    return get('release_name');
});

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
set('healthcheck_use_resolve', true);        // 用 --resolve 走本机 127.0.0.1:443
set('nginx_site', '/etc/nginx/sites-enabled/fap-api');
set('php_fpm_service', 'php8.4-fpm');

// ========= 生产机 =========
// 兼容 GitHub Actions 常见 env：DEPLOY_HOST/DEPLOY_USER/DEPLOY_PORT/DEPLOY_PATH/HEALTHCHECK_HOST
host('production')
    ->setHostname(getenv('DEPLOY_HOST_PROD') ?: (getenv('DEPLOY_HOST') ?: '122.152.221.126'))
    ->setRemoteUser(getenv('DEPLOY_USER_PROD') ?: (getenv('DEPLOY_USER') ?: 'ubuntu'))
    ->setPort((int)(getenv('DEPLOY_PORT_PROD') ?: (getenv('DEPLOY_PORT') ?: 22)))
    ->set('deploy_path', getenv('DEPLOY_PATH_PROD') ?: (getenv('DEPLOY_PATH') ?: '/var/www/fap-api'))
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_PROD') ?: (getenv('HEALTHCHECK_HOST') ?: 'fermatmind.com'))
    ->set('healthcheck_scheme', 'https')
    ->set('healthcheck_use_resolve', true)
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_PROD') ?: (getenv('PHP_FPM_SERVICE') ?: 'php8.4-fpm'));

// ========= Staging 机 =========
// 兼容 GitHub Actions 常见 env：DEPLOY_HOST/DEPLOY_USER/DEPLOY_PORT/DEPLOY_PATH/HEALTHCHECK_HOST
host('staging')
    ->setHostname(getenv('DEPLOY_HOST_STG') ?: (getenv('DEPLOY_HOST') ?: 'staging.fermatmind.com'))
    ->setRemoteUser(getenv('DEPLOY_USER_STG') ?: (getenv('DEPLOY_USER') ?: 'ubuntu'))
    ->setPort((int)(getenv('DEPLOY_PORT_STG') ?: (getenv('DEPLOY_PORT') ?: 22)))
    ->set('deploy_path', getenv('DEPLOY_PATH_STG') ?: (getenv('DEPLOY_PATH') ?: '/var/www/fap-api-staging'))
    ->set('healthcheck_host', getenv('HEALTHCHECK_HOST_STG') ?: (getenv('HEALTHCHECK_HOST') ?: 'staging.fermatmind.com'))
    ->set('healthcheck_scheme', getenv('HEALTHCHECK_SCHEME_STG') ?: 'https')
    ->set('healthcheck_use_resolve', true)
    ->set('nginx_site', '/etc/nginx/sites-enabled/fap-api-staging')
    ->set('php_fpm_service', getenv('PHP_FPM_SERVICE_STG') ?: (getenv('PHP_FPM_SERVICE') ?: 'php8.4-fpm'));

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

// 项目没有 views，view:cache 会炸：View path not found.
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

task('restart:php-fpm', function () {
    $svc = get('php_fpm_service');
    run("sudo -n /usr/bin/systemctl restart {$svc}");
});

task('reload:nginx', function () {
    run('sudo -n /usr/bin/systemctl reload nginx');
});

// ========= Sentry release =========
task('sentry:release', function () {
    $rel = get('sentry_release');
    run("cd {{deploy_path}} && test -f shared/backend/.env");
    run("grep -q '^SENTRY_RELEASE=' {{deploy_path}}/shared/backend/.env && sed -i 's/^SENTRY_RELEASE=.*/SENTRY_RELEASE={$rel}/' {{deploy_path}}/shared/backend/.env || echo 'SENTRY_RELEASE={$rel}' >> {{deploy_path}}/shared/backend/.env");
});

// ========= runtime dirs (healthz deps) =========
task('ensure:healthz-deps', function () {
    $base = get('deploy_path') . '/shared/backend/storage';

    // storage/app/content-packs
    run("mkdir -p {$base}/app/content-packs");
    run("chmod 2775 {$base}/app/content-packs");

    // cache dirs
    run("mkdir -p {$base}/framework/cache {$base}/framework/sessions {$base}/framework/views {$base}/logs");
    run("chmod 2775 {$base}/framework/cache {$base}/framework/sessions {$base}/framework/views {$base}/logs");
});

// ========= 系统依赖：phpredis（Redis 扩展） =========
task('ensure:phpredis', function () {
    $php = get('bin/php');

    // PHP 版本（8.4 / 8.3 等）
    $ver = trim(run($php . " -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;'"));

    // CLI 是否已加载 redis 扩展
    $ok = trim(run($php . " -m | grep -i '^redis$' >/dev/null 2>&1; echo $?"));
    if ($ok !== '0') {
        writeln('<error>Missing PHP extension: redis (phpredis)</error>');
        writeln("<comment>Install (Ubuntu + ondrej/php):</comment>");
        writeln("sudo apt-get update");
        writeln("sudo apt-get install -y php{$ver}-redis");
        writeln("sudo systemctl restart php{$ver}-fpm");
        throw new \RuntimeException("phpredis missing: install php{$ver}-redis and restart php{$ver}-fpm");
    }
});

// ========= 健康检查 =========
task('healthcheck:public', function () {
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

    run(
        'curl -fsS ' . $resolvePart . $base . '/api/v0.2/healthz | ' .
        'jq -e \'.ok==true and (.deps.db.ok==true) and (.deps.redis.ok==true) and (.deps.queue.ok==true) and (.deps.cache_dirs.ok==true) and (.deps.content_source.ok==true)\' > /dev/null'
    );
    run('curl -fsS ' . $resolvePart . $base . '/api/v0.2/health | jq -e \'.ok==true\' > /dev/null');
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

// 系统依赖检查：尽早失败（防止部署到一半才 rollback）
before('deploy:prepare', 'ensure:phpredis');

after('deploy:shared', 'ensure:healthz-deps');

after('artisan:migrate', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'sentry:release');
after('deploy:symlink', 'healthcheck:public');
after('deploy:symlink', function () {
    try {
        run('{{bin/php}} {{release_path}}/backend/artisan ops:deploy-event --status=success --ansi || true');
        run('{{bin/php}} {{release_path}}/backend/artisan ops:healthz-snapshot --ansi || true');
    } catch (\Throwable $e) {
        writeln('<comment>[ops] deploy hooks failed: ' . $e->getMessage() . '</comment>');
    }
});
after('healthcheck:public', 'healthcheck:content-packs');

after('deploy:failed', 'rollback');
after('rollback', 'reload:php-fpm');
after('rollback', 'reload:nginx');
after('deploy:failed', 'deploy:unlock');
