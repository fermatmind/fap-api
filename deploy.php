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
// public_path 用相对 release_path 的路径
set('public_path', 'backend/public');

// artisan 文件实际在 backend/artisan
set('artisan', '{{release_path}}/backend/artisan');

// ⚠️ 最关键：所有 artisan:* 任务最终用的是 bin/artisan
// 不把它改掉，Deployer 仍然会去跑 releases/X/artisan（根目录）=> 你现在的报错
set('bin/php', 'php');
set('bin/composer', 'composer');
set('bin/artisan', '{{bin/php}} {{release_path}}/backend/artisan');

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

// 你现在服务器 ACL/权限容易踩坑，直接用 chmod 最稳
set('writable_mode', 'chmod');
set('writable_chmod_mode', '0775');
set('writable_use_sudo', false);

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

// ========= 服务重载 =========
task('reload:php-fpm', function () {
    run('sudo /usr/bin/systemctl reload php8.4-fpm');
});

task('reload:nginx', function () {
    run('sudo /usr/bin/systemctl reload nginx');
});

// ========= 健康检查（服务器本机 localhost，不依赖外网 DNS） =========
task('healthcheck', function () {
    // 1) questions
    run('curl -fsS http://127.0.0.1/api/v0.2/scales/MBTI/questions | grep -q "\"ok\":true"');

    // 2) attempts/start
    $payload = '{"anon_id":"dep-health-001","scale_code":"MBTI","scale_version":"v0.2","question_count":144,"client_platform":"web","region":"CN_MAINLAND","locale":"zh-CN"}';
    run('curl -fsS -X POST http://127.0.0.1/api/v0.2/attempts/start -H "Content-Type: application/json" -H "Accept: application/json" -d \'' . $payload . '\' | grep -q "\"ok\":true"');
});

// ========= 关键：跳过 view:cache（你的项目没有 views 会直接炸） =========
task('artisan:view:cache', function () {
    writeln('<comment>Skip artisan:view:cache (no views)</comment>');
});

// ========= 部署流的钩子（laravel.php 自带 deploy 流里已有 migrate 等） =========
after('artisan:migrate', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck');

after('deploy:failed', 'deploy:unlock');