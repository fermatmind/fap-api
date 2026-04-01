# Fermat 生产环境部署指南 v2.0

Status: Active  
Last Updated: 2026-02-18  
Truth Source: `backend/composer.json` / `backend/package.json` / `backend/config/queue.php` / `backend/.env.example`

## 1. 真理边界
本指南只描述当前仓库真实可执行的生产部署路径。
- 依赖版本以 `composer.json`、`package.json` 为准。
- 队列运行模型以 `config/queue.php` 与 Jobs 实现为准。
- 当前仓库 **无** `config/horizon.php`，不使用 Horizon 作为官方基线。

## 2. 生产环境硬性要求
- PHP 8.2+（`composer.json` 约束：`^8.2`）
- MySQL 8.0+
- Redis 6+
- Composer 2.x

后端 production/staging deploy host 不要求安装 Node / NPM / pnpm。
- Ops 自定义主题当前走 committed fallback CSS + `php artisan filament:assets`
- 只有本地更新 `backend/resources/css/filament/ops/theme.compiled.css` 时才需要 Node

推荐 PHP 扩展（Laravel 生产常规）：
- `pdo_mysql`
- `redis`（phpredis）
- `mbstring`
- `openssl`
- `json`
- `fileinfo`
- `tokenizer`
- `ctype`
- `xml`

## 3. 部署拓扑（简版）
- Nginx/Apache（反向代理）
- PHP-FPM（Laravel 应用）
- MySQL（主库）
- Redis（cache + queue lock）
- Supervisor（唯一驻留 queue workers owner）

### 3.1 Queue owner 单一化（正式基线）
- 正式基线只保留 **Supervisor** 作为 queue worker manager。
- 不允许 production/staging 同时存在：
  - `supervisor` worker
  - `systemd` 的 `fap-queue.service`
- 若历史环境仍保留 `fap-queue.service`，deploy hook 会先执行 `php artisan queue:restart`，再重启 Supervisor programs，并停止/禁用遗留 `systemd` queue service。
- 任何看到 `systemd + supervisor` 双跑的环境，都视为漂移环境，必须先收口再继续上线。

## 4. 配置清单（上线必填）
以 `backend/.env.example` 为模板，生产必须由密钥系统注入。

Deployer SSH 约定：
- 默认 production 仍走 `DEPLOY_HOST_PROD=122.152.221.126`、`DEPLOY_USER_PROD=ubuntu`
- 若本机存在 `~/.ssh/fap_prod` 或 `~/.ssh/fap_api_gha`，`deploy.php` 会自动将其作为 production `IdentityFile`
- staging 若本机存在 `~/.ssh/fap_actions_staging`，会自动作为 staging `IdentityFile`
- 如需显式覆盖，使用：
  - `DEPLOY_IDENTITY_FILE_PROD=/abs/path/to/key`
  - `DEPLOY_IDENTITY_FILE_STG=/abs/path/to/key`

### 4.1 基础与数据库
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY`
- `DB_CONNECTION=mysql`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

### 4.2 Redis / Cache / Queue
- `CACHE_STORE=redis`
- `QUEUE_CONNECTION=redis`（若未覆盖，生产默认也是 redis）
- `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`
- `REDIS_CACHE_CONNECTION` / `REDIS_QUEUE_CONNECTION`（按集群策略可选）

### 4.3 Webhook Secrets
- `STRIPE_WEBHOOK_SECRET`
- `BILLING_WEBHOOK_SECRET`
- `INTEGRATIONS_WEBHOOK_*_SECRET`

### 4.4 Admin/Auth
- `FAP_ADMIN_TOKEN`
- `EVENT_INGEST_TOKEN`
- `FAP_ADMIN_PANEL_ENABLED=true`
- `FAP_ADMIN_GUARD=admin`

### 4.5 Object Storage / 内容包
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_BUCKET`
- `FAP_PACKS_DRIVER`（local/s3）
- `FAP_S3_PREFIX`（若 driver=s3）

### 4.6 Mail
- `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`

### 4.7 Ops 安全
- `OPS_ALLOWED_HOST`
- `OPS_IP_ALLOWLIST`
- `OPS_ADMIN_TOTP_ENABLED=true`

> 禁止将真实密钥写入仓库、压缩包或日志。

## 5. 发布步骤（可执行）
```bash
cd /opt/fap-api/backend

# 1) 拉取代码
# git pull --ff-only origin <release-branch-or-tag>

# 2) 先清理 release-derived bootstrap cache（必须在任何 cache producer 之前）
php -r '
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

# 3) 安装后端依赖
# composer install 会触发 post-autoload-dump -> package:discover，因此它本身就是 bootstrap cache producer
composer install --no-dev --optimize-autoloader

# 4) 发布 Filament assets（包含 Ops 主题与 Filament core CSS / JS）
php artisan filament:assets

# 5) 完成 bootstrap cache rebuild
# composer install 已经触发 package:discover；这里显式再跑一次，确保 active release 的 package/provider manifest 与当前代码一致
php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
php artisan event:cache

# 6) 数据库迁移
php artisan migrate --force

# 6.1) queue worker reload（唯一 owner = supervisor）
php artisan queue:restart
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart fap-queue-default-high:*
sudo supervisorctl restart fap-queue-reports:*
# 可选队列
sudo supervisorctl restart fap-queue-ops:* || true
sudo supervisorctl restart fap-queue-commerce:* || true
sudo supervisorctl restart fap-queue-content:* || true
sudo supervisorctl restart fap-queue-insights:* || true

# 若历史环境存在遗留 systemd queue service，必须停用，避免双跑
sudo systemctl stop fap-queue.service || true
sudo systemctl disable fap-queue.service || true

# 7) Ops 资产验收
test -s public/css/app/ops-theme.css
test -s public/css/filament/filament/app.css
test -s public/css/filament/forms/forms.css
test -s public/css/filament/support/support.css
test -s public/js/filament/filament/app.js
! grep -Eq '@tailwind|@config|resources/css/filament/ops/theme.css|vendor/filament/filament/resources/css/base.css' public/css/app/ops-theme.css

# 7.1) 线上 Ops smoke（production 示例）
curl -I https://ops.fermatmind.com/ops/login
curl -I https://ops.fermatmind.com/css/app/ops-theme.css
curl -I https://ops.fermatmind.com/css/filament/filament/app.css
curl -I https://ops.fermatmind.com/css/filament/forms/forms.css
curl -I https://ops.fermatmind.com/css/filament/support/support.css
curl -I https://ops.fermatmind.com/js/filament/filament/app.js
! curl -s https://ops.fermatmind.com/css/app/ops-theme.css | rg '^@tailwind|@config|resources/css/filament/ops/theme.css|vendor/filament/filament/resources/css/base.css'

# 7.2) 入口契约 smoke
# production: 根入口必须跳到 /ops
curl -sSI --max-redirs 0 https://ops.fermatmind.com/ | rg '^HTTP/[0-9.]+ 30[12] '
curl -sSI --max-redirs 0 https://ops.fermatmind.com/ | rg -i '^Location: (/ops|https://ops\.fermatmind\.com/ops)\r?$'
curl -sSI --max-redirs 0 https://ops.fermatmind.com/admin | rg '^HTTP/[0-9.]+ 30[12] '
curl -sSI --max-redirs 0 https://ops.fermatmind.com/admin | rg -i '^Location: (/ops|https://ops\.fermatmind\.com/ops)\r?$'
curl -sSI --max-redirs 0 https://ops.fermatmind.com/ops | rg '^HTTP/[0-9.]+ 30[12] '
curl -sSI --max-redirs 0 https://ops.fermatmind.com/ops | rg -i '^Location: (/ops/login|https://ops\.fermatmind\.com/ops/login)\r?$'
curl -sSI https://ops.fermatmind.com/ops/login | rg '^HTTP/[0-9.]+ 200 '

# staging: 不强制要求 / -> /ops，只验证 /ops 与 /ops/login
curl -sSI --max-redirs 0 https://staging.fermatmind.com/ops | rg '^HTTP/[0-9.]+ 30[12] '
curl -sSI --max-redirs 0 https://staging.fermatmind.com/ops | rg -i '^Location: (/ops/login|https://staging\.fermatmind\.com/ops/login)\r?$'
curl -sSI https://staging.fermatmind.com/ops/login | rg '^HTTP/[0-9.]+ 200 '

# 7.3) 基线校验
php artisan fap:schema:verify

# 7.4) queue smoke（deploy 后必须通过）
php -r '
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
$queueConnection = (array) config("queue.connections.".$queueConnectionName, []);
$redisConnection = (string) ($queueConnection["connection"] ?? "default");
$redis = Illuminate\Support\Facades\Redis::connection($redisConnection);
$before = (int) $redis->llen("queues:".$queue);
sleep($waitSeconds);
$after = (int) $redis->llen("queues:".$queue);
$recentPending = (int) Illuminate\Support\Facades\DB::table("attempt_submissions")
  ->whereIn("state", ["pending", "running"])
  ->where("updated_at", ">=", now()->subMinutes($pendingWindowMinutes))
  ->count();
dump(compact("queue","before","after","maxDepth","waitSeconds","maxGrowth","recentPending","maxRecentPending"));
if ($after > $maxDepth || ($after - $before) > $maxGrowth || $recentPending > $maxRecentPending) {
    exit(1);
}
'
```

### 5.1 Ops Theme Build in Deploy Pipeline
- 当前 Filament Ops Panel 通过 `->theme('ops-theme')` 注册自定义主题。
- `backend/resources/css/filament/ops/theme.css` 是 Tailwind 源文件，不是可直接在线服务的生产产物。
- `backend/resources/css/filament/ops/theme.compiled.css` 是 committed fallback CSS，server 不需要 Node 也能发布。
- Deployer 只会显式执行 `php artisan filament:assets`，由 Filament 资产系统将 committed fallback CSS 发布到当前 release 的 `backend/public`。
- 当前合法运行时主题文件是 `backend/public/css/app/ops-theme.css`。
- 发布链会在切换 symlink 前校验 `backend/public/css/app/ops-theme.css` 已生成、非空且不包含 raw Tailwind source 签名；若仍含 `@tailwind`、`@config`、源码引用路径或原始 vendor `@import`，部署会直接失败。
- 发布链也会校验关键 Filament 资源存在且非空，包括 `backend/public/css/filament/filament/app.css`、`backend/public/css/filament/forms/forms.css`、`backend/public/css/filament/support/support.css`、`backend/public/js/filament/filament/app.js` 等；缺失这些资源会导致 `/ops` 的样式缺失、脚本 404 或 Alpine 初始化报错。
- production Ops host 的根入口契约是 `https://ops.fermatmind.com/ -> /ops`；这条契约由仓库路由显式表达，并在 deploy/workflow/manual smoke 中验收。
- staging 不共享这条根入口契约；`https://staging.fermatmind.com/` 仍按前台站点处理，entry smoke 只覆盖 `/ops` 与 `/ops/login`。
- 这一步是 release 内的 Laravel/Filament 资产发布，不依赖运行时前端构建。
- 生产/staging backend host 不要求 `node` / `npm` / `pnpm`。
- 禁止继续使用 `cp resources/css/filament/ops/theme.css public/css/filament/ops/theme.css` 作为正式发布方案；这会把源码误当成线上产物。
- GitHub Actions 在 deploy 后会按 `TARGET` 对应域名执行对应的 entry smoke 与 asset smoke；production 额外断言 `/ -> /ops` 与 `/admin -> /ops`，staging 不共享这条根入口契约。

### 5.2 Deployer rerun / failed release hygiene
- Deployer release name 必须唯一；若上一轮失败残留了 `releases/93`，下一次 rerun 必须使用新的 `release_name`，例如：
- `vendor/bin/dep deploy production -o release_name=94`
- 或 `vendor/bin/dep deploy production -o release_name=$(date +%Y%m%d%H%M%S)`
- 如需显式指定 key：
  - `DEPLOY_IDENTITY_FILE_PROD=~/.ssh/fap_prod vendor/bin/dep deploy production -o release_name=$(date +%Y%m%d%H%M%S)`
- `release 93 already exists` 不是成功上线，只表示失败残留目录未被复用。
- 若 `releases/93` 不是 `current` 指向目标，且该 release 从未成功切换为 active release，则可安全清理：
  - `test "$(readlink -f current)" != "$(readlink -f releases/93)"`
  - `rm -rf releases/93`
- 若不确定 `releases/93` 是否曾成为 active release，优先保留目录并直接使用新的唯一 `release_name` rerun。

### 5.3 Bootstrap Cache Lifecycle in Deploy / Rollback
- `backend/bootstrap/cache` 中的 `config.php`、`routes-*.php`、`events.php`、`packages.php`、`services.php` 都是 release-derived bootstrap cache，不应作为跨 release 的 shared state 继承。
- 当前 Deployer 契约是不再共享 `backend/bootstrap/cache`；每个 release 在自己的 `backend/bootstrap/cache` 下生成自身产物。
- deploy 时会在任何 cache producer 之前先清理上述 release-derived 文件，再按以下顺序重建：
  - Composer / `php artisan package:discover --ansi`
  - `php artisan config:cache`
  - `php artisan route:cache`
  - `php artisan event:cache`
- 这样可以避免 stale `config.php` 影响 provider registration / panel enablement，也可以避免 stale `routes-*.php` / `events.php` / `packages.php` / `services.php` 污染当前 release。
- Deployer `rollback` 切回旧 release 后，会在目标 release 上再次执行同一套 bootstrap cache clear + rebuild，然后再做既有 smoke。
- 如果不是用 Deployer，而是手工切 symlink / 手工回退到旧目录，必须在目标 release 上重跑同一组命令：
  - `php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $paths = [$app->getCachedConfigPath(), $app->getCachedEventsPath(), $app->getCachedPackagesPath(), $app->getCachedServicesPath()]; foreach ($paths as $path) { if (is_file($path)) { @unlink($path); } } foreach (glob(dirname($app->getCachedRoutesPath()).DIRECTORY_SEPARATOR."routes-*.php") ?: [] as $path) { @unlink($path); }'`
  - `php artisan package:discover --ansi`
  - `php artisan config:cache`
  - `php artisan route:cache`
  - `php artisan event:cache`
- 手工 rollback 只切换代码目录但不重建 bootstrap cache，不是正式可接受方案。

## 6. Supervisor 队列守护配置（无 Horizon）
当前基线：使用 Supervisor 常驻 `queue:work`。至少部署以下两个 program。

### 6.1 必配 program：default/high
```ini
[program:fap-queue-default-high]
directory=/opt/fap-api/backend
command=/usr/bin/php artisan queue:work redis --queue=high,default --sleep=1 --tries=3 --timeout=120 --max-time=3600
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/fap-queue-default-high.log
stopwaitsecs=360
```

### 6.2 必配 program：reports（报告生成）
```ini
[program:fap-queue-reports]
directory=/opt/fap-api/backend
command=/usr/bin/php artisan queue:work database --queue=reports --sleep=1 --tries=3 --timeout=180 --max-time=3600
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/fap-queue-reports.log
stopwaitsecs=360
```

### 6.3 推荐 program（按业务拆分）
- `fap-queue-ops` -> `--queue=ops`
- `fap-queue-commerce` -> `--queue=commerce`
- `fap-queue-content` -> `--queue=content`
- `fap-queue-insights` -> `--queue=insights`

> 说明：
> - 报告相关作业主队列是 `reports`（`GenerateReportSnapshotJob`、legacy `GenerateReportJob`）。
> - `high/default` 作为通用优先级队列，承接未显式队列任务。

### 6.4 生效命令
```bash
php artisan queue:restart
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart fap-queue-default-high:*
sudo supervisorctl restart fap-queue-reports:*
# 可选
sudo supervisorctl restart fap-queue-ops:*
sudo supervisorctl restart fap-queue-commerce:*
sudo supervisorctl restart fap-queue-content:*
sudo supervisorctl restart fap-queue-insights:*
```

### 6.5 遗留 systemd queue service 处理
如果历史环境里存在 `fap-queue.service`，必须停用：

```bash
sudo systemctl stop fap-queue.service || true
sudo systemctl disable fap-queue.service || true
sudo systemctl is-active fap-queue.service
```

通过标准：
- `systemctl is-active fap-queue.service` 返回非 `active`
- 只保留 `supervisorctl status fap-queue-*` 里的 worker 作为正式 owner

## 7. 发布后验收
```bash
cd /opt/fap-api/backend
php artisan route:list
php artisan migrate

curl -i http://127.0.0.1:8000/api/healthz
curl -i http://127.0.0.1:8000/api/v0.4/boot
curl -i -X POST http://127.0.0.1:8000/api/v0.3/auth/guest -H 'Content-Type: application/json' -d '{"anon_id":"deploy_contract_probe"}'
curl -i -X POST http://127.0.0.1:8000/api/v0.3/attempts/start -H 'Content-Type: application/json' -d '{"scale_code":"MBTI"}'
php artisan ops:attempt-submission-recovery --json=1 --alert=0 --strict=0 --window-hours=1 --limit=50

cd /opt/fap-api
bash backend/scripts/ci_verify_mbti.sh
```

通过标准：
- 路由与迁移命令成功。
- 健康检查接口可达，且 `/api/v0.3/auth/guest` 合约通过。
- MBTI CI 验收链路通过。

## 8. 回滚流程
1. 切换到上一个可用发布版本（代码/工件）。
2. 执行依赖安装与缓存重建（同部署步骤）。
3. 迁移策略：本仓库以 forward-only 迁移为主，回滚优先采用“新修复迁移”而非 destructive rollback。
4. 队列处理顺序：
   - 先 `supervisorctl stop fap-queue-*`（或逐组停）
   - 完成代码切换/配置恢复
   - 再按 `reports -> default/high -> 其他队列` 顺序启动
5. 验收通过后恢复流量。

## 9. Attempt Submission 补偿 Runbook
当线上出现 “submit 返回 202，但结果页长期 pending / 404” 时，先不要直接重试前台页面，按下面顺序处置：

```bash
cd /opt/fap-api/backend

# 1) 精确诊断一条 attempt
php artisan ops:attempt-submission-recovery --json=1 --alert=0 --strict=0 --attempt-id=<attempt_id>

# 2) recent 窗口巡检
php artisan ops:attempt-submission-recovery --json=1 --alert=0 --strict=0 --window-hours=1 --limit=100

# 3) 安全补偿（会重排队 stuck / result-missing submission，并修正 stale projection）
php artisan ops:attempt-submission-recovery --json=1 --alert=0 --strict=0 --repair=1 --window-hours=1 --limit=100
```

适用边界：
- `--repair=1` 只用于当前 release 已确认健康、queue worker 已按本 runbook 收口到 Supervisor 单 owner 后。
- 如果 queue smoke 仍失败，先处理 worker owner/consumer 问题，再做补偿。
