<?php
namespace Deployer;

require 'recipe/laravel.php';

// ========= 基础信息 =========
set('application', 'fap-api');
set('repository', 'git@github.com:fermatmind/fap-api.git');

set('git_tty', false);
set('keep_releases', 5);
set('default_timeout', 900);

// ========= Laravel 在 backend 子目录 =========
set('public_path', 'backend/public');

// artisan 路径（关键：指向 backend/artisan）
set('artisan', '{{release_path}}/backend/artisan');
set('bin/php', 'php');
set('bin/composer', 'composer');

// 让 recipe 里所有 artisan:* 任务都走这个命令
set('bin/artisan', '{{bin/php}} {{artisan}}');

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

// 你已经切到 chmod 模式了，继续保持
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

// ========= 关闭 view cache（API 项目不需要 views，避免 view path not found） =========
task('artisan:view:cache', function () {
    writeln('Skip artisan:view:cache (API project, no views)');
});
task('artisan:view:clear', function () {
    writeln('Skip artisan:view:clear (API project, no views)');
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

// ========= 自定义 deploy 流：不跑 view:cache =========
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'artisan:storage:link',
    'artisan:config:cache',
    'artisan:route:cache',
    'artisan:event:cache',
    'artisan:migrate',
    'deploy:publish',
]);

after('artisan:migrate', 'reload:php-fpm');
after('deploy:symlink', 'reload:nginx');
after('deploy:symlink', 'healthcheck');

after('deploy:failed', 'deploy:unlock');