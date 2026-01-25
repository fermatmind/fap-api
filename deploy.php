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

// ========= 生产机 =========
host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: '122.152.221.126')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setPort((int)(getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', '/var/www/fap-api');

// 你的线上域名（healthcheck 用）
set('healthcheck_host', getenv('HEALTHCHECK_HOST') ?: 'fermatmind.com');

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

// ========= 服务重载 =========
task('reload:php-fpm', function () {
    run('sudo /usr/bin/systemctl reload php8.4-fpm');
});

task('reload:nginx', function () {
    run('sudo /usr/bin/systemctl reload nginx');
});

// ========= 健康检查（修复点：HTTP->HTTPS 301 + 命中正确 vhost） =========
task('healthcheck', function () {
    $host = get('healthcheck_host');

    // 说明：
    // - 用 https://$host/... 走正确 vhost + 正常路由
    // - 用 --resolve 把 $host:443 固定指向 127.0.0.1，不依赖外网/公网回环
    // - 不再走 http://127.0.0.1 触发 301

    $nginxSite = '/etc/nginx/sites-enabled/fap-api';
    $expectedRoot = 'root /var/www/fap-api/current/backend/public;';
    // Ensure nginx root points to current release (one-time drift-proof)
    run("sudo test -f {$nginxSite}");
    $hasExpected = run("sudo grep -F \"{$expectedRoot}\" {$nginxSite} >/dev/null 2>&1; echo $?");
    if (trim($hasExpected) !== '0') {
        // Replace any existing root directive inside this site file
        run("sudo sed -i -E 's#^\\sroot\\s+[^;]+;\\s#    {$expectedRoot}\\n#' {$nginxSite}");
        run("sudo nginx -t");
        run("sudo /usr/bin/systemctl reload nginx");
    }

    run('curl -fsS --resolve ' . $host . ':443:127.0.0.1 https://' . $host . '/api/v0.2/health | grep -q "\"ok\":true"');

    run('curl -fsS --resolve ' . $host . ':443:127.0.0.1 https://' . $host . '/api/v0.2/scales/MBTI/questions | grep -q "\"ok\":true"');

    $payload = '{"anon_id":"dep-health-001","scale_code":"MBTI","scale_version":"v0.2","question_count":144,"client_platform":"web","region":"CN_MAINLAND","locale":"zh-CN"}';
    run(
        'curl -fsS --resolve ' . $host . ':443:127.0.0.1 ' .
        '-X POST https://' . $host . '/api/v0.2/attempts/start ' .
        '-H "Content-Type: application/json" -H "Accept: application/json" ' .
        '-d \'' . $payload . '\' | grep -q "\"ok\":true"'
    );
});

// ========= hooks =========
after('artisan:migrate', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck');

after('deploy:failed', 'rollback');
after('rollback', 'reload:php-fpm');
after('rollback', 'reload:nginx');
after('deploy:failed', 'deploy:unlock');
