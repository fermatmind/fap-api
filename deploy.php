<?php
namespace Deployer;

require 'recipe/laravel.php';

// ========= 基础信息 =========
set('application', 'fap-api');
set('repository', 'git@github.com:fermatmind/fap-api.git');

set('git_tty', false);
set('keep_releases', 5);
set('default_timeout', 900);

set('bin/php', 'php');
set('bin/composer', 'composer');

// ========= Laravel 在 backend 子目录（全局默认） =========
set('public_path', 'backend/public');
set('artisan', '{{release_path}}/backend/artisan');

// ========= writable 策略（避免 ACL/ setfacl 依赖） =========
set('writable_mode', 'chmod');
set('writable_recursive', true);
set('writable_chmod_mode', '0775');

// ========= 共享：.env + storage/cache + content_packages =========
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

// ========= 生产机 =========
host('production')
    ->setHostname(getenv('DEPLOY_HOST') ?: '122.152.221.126')
    ->setRemoteUser(getenv('DEPLOY_USER') ?: 'deploy')
    ->setPort((int)(getenv('DEPLOY_PORT') ?: 22))
    ->set('deploy_path', '/var/www/fap-api')
    // 关键：在 host 级别再压一遍，确保 recipe 不会覆盖成 releases/<n>/artisan
    ->set('public_path', 'backend/public')
    ->set('artisan', '{{release_path}}/backend/artisan');

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

    // 2) attempts/start（按接口必填字段）
    $payload = '{"anon_id":"dep-health-001","scale_code":"MBTI","scale_version":"v0.2","question_count":144,"client_platform":"web","region":"CN_MAINLAND","locale":"zh-CN"}';
    run('curl -fsS -X POST http://127.0.0.1/api/v0.2/attempts/start -H "Content-Type: application/json" -H "Accept: application/json" -d \'' . $payload . '\' | grep -q "\"ok\":true"');
});

// ========= Hook（laravel.php 自带 deploy 流） =========
// laravel recipe 默认会跑 artisan:migrate；迁移后 reload php-fpm
after('artisan:migrate', 'reload:php-fpm');

// 切换 symlink 后 reload nginx + 健康检查
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck');

after('deploy:failed', 'deploy:unlock');