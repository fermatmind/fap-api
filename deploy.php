<?php
namespace Deployer;

require 'recipe/laravel.php';

// ========= 基础信息 =========
set('application', 'fap-api');
set('repository', 'git@github.com:fermatmind/fap-api.git');

set('git_tty', false);
set('keep_releases', 5);
set('default_timeout', 900);

// ========= 目录结构（Laravel 在 backend 子目录） =========
set('public_path', 'backend/public');

// 关键：统一 artisan 入口（强制指向 backend/artisan）
set('bin/php', 'php');
set('bin/artisan', function () {
    return '{{bin/php}} {{release_path}}/backend/artisan';
});

//（可选）composer 在远端用系统 composer
set('bin/composer', 'composer');

// ========= shared / writable =========
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

// 关键：不要用 ACL（GitHub Actions / deploy 用户常见会卡），用 chmod
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_recursive', true);

// ========= 生产机 =========
host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: '122.152.221.126')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setPort((int)(getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', '/var/www/fap-api');

// ========= 覆盖 vendors：在 backend 里 composer install =========
task('deploy:vendors', function () {
    run('cd {{release_path}}/backend && {{bin/composer}} install --no-interaction --prefer-dist --optimize-autoloader --no-dev');
});

// ========= 覆盖 laravel recipe 的 artisan 相关任务（全部走 backend/artisan） =========
task('artisan:storage:link', function () {
    run('{{bin/artisan}} storage:link');
});

task('artisan:config:cache', function () {
    run('{{bin/artisan}} config:cache');
});

task('artisan:route:cache', function () {
    run('{{bin/artisan}} route:cache');
});

task('artisan:view:cache', function () {
    run('{{bin/artisan}} view:cache');
});

task('artisan:event:cache', function () {
    run('{{bin/artisan}} event:cache');
});

task('artisan:migrate', function () {
    run('{{bin/artisan}} migrate --force');
});

// 关键：laravel recipe 有时会先取版本号（默认去找 release_path/artisan）
// 这里强制改成 backend/artisan
set('laravel_version', function () {
    return run('{{bin/artisan}} --version');
});

// ========= 服务重载 =========
task('reload:php-fpm', function () {
    run('sudo /usr/bin/systemctl reload php8.4-fpm');
});

task('reload:nginx', function () {
    run('sudo /usr/bin/systemctl reload nginx');
});

// ========= 健康检查（服务器本机 localhost，不依赖外网 DNS） =========
task('healthcheck', function () {
    run('curl -fsS http://127.0.0.1/api/v0.2/scales/MBTI/questions | grep -q "\"ok\":true"');

    $payload = '{"anon_id":"dep-health-001","scale_code":"MBTI","scale_version":"v0.2","question_count":144,"client_platform":"web","region":"CN_MAINLAND","locale":"zh-CN"}';
    run('curl -fsS -X POST http://127.0.0.1/api/v0.2/attempts/start -H "Content-Type: application/json" -H "Accept: application/json" -d \'' . $payload . '\' | grep -q "\"ok\":true"');
});

// ========= 部署流（laravel.php 自带 deploy 流） =========
after('artisan:migrate', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck');

after('deploy:failed', 'deploy:unlock');