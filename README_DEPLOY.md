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
- Node 20 LTS+（仓库未锁定 engines，按 Vite 7 生产构建建议）
- Composer 2.x
- NPM 10+

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
- Supervisor（驻留 queue workers）

## 4. 配置清单（上线必填）
以 `backend/.env.example` 为模板，生产必须由密钥系统注入。

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

# 2) 安装后端依赖
composer install --no-dev --optimize-autoloader

# 3) 构建前端资源（若发布工件不含构建产物）
npm ci
npm run build

# 4) 数据库迁移
php artisan migrate --force

# 5) 缓存与路由缓存
php artisan config:cache
php artisan route:cache

# 6) 基线校验
php artisan fap:schema:verify
```

### 5.1 Ops Theme Build in Deploy Pipeline
- 当前 Filament Ops Panel 通过 `->theme(asset('css/filament/ops/theme.css'))` 注册自定义主题。
- Deployer 发布时会在 `{{release_path}}/backend` 内执行：
  - `npm ci --no-audit --no-fund`
  - `npm run build:ops-theme`
- Deployer 还会显式执行 `php artisan filament:assets`，确保 Filament 核心 CSS/JS 被复制到当前 release 的 `backend/public`。
- 发布链会在切换 symlink 前校验 `backend/public/css/filament/ops/theme.css` 已生成且非空；若缺失，部署会直接失败。
- 发布链也会校验关键 Filament 资源存在且非空；缺失这些资源会导致 `/ops` 的样式缺失、脚本 404 或 Alpine 初始化报错。
- 这一步是 release 产物构建，不是 shared 配置，也不是将编译产物提交进 git。
- 若目标主机缺少 `node` / `npm`，或版本低于当前仓库基线（Node 20.19+ / NPM 10+），部署会 fail fast。

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

## 7. 发布后验收
```bash
cd /opt/fap-api/backend
php artisan route:list
php artisan migrate

curl -i http://127.0.0.1:8000/api/healthz
curl -i http://127.0.0.1:8000/api/v0.4/boot
curl -i -X POST http://127.0.0.1:8000/api/v0.3/auth/guest -H 'Content-Type: application/json' -d '{"anon_id":"deploy_contract_probe"}'
curl -i -X POST http://127.0.0.1:8000/api/v0.3/attempts/start -H 'Content-Type: application/json' -d '{"scale_code":"MBTI"}'

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
