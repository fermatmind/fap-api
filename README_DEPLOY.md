# README_DEPLOY

## 1) 唯一交付包

唯一允许产物：`dist/fap-api-release.zip`

生成命令：

```bash
bash scripts/release_pack.sh
```

审计命令：

```bash
bash scripts/audit_smoke.sh dist/fap-api-release.zip
bash scripts/release_hygiene_gate.sh ./_audit/fap-api-0212-5/
```

## 2) 交付包内容边界

交付包根目录固定为：

- `backend/`
- `scripts/`
- `docs/`
- `README_DEPLOY.md`

不包含：

- `.env/.env.*`（`backend/.env.example` 允许）
- `.git/`
- `vendor/`
- `node_modules/`
- `backend/storage/logs/`
- `backend/artifacts/`
- `*.sqlite*`
- `backend/storage/app/private/reports/`
- `__MACOSX/`
- `.DS_Store`

## 3) 部署最短路径（不依赖本地 .env，不打包 vendor）

```bash
unzip -q dist/fap-api-release.zip -d /opt/fap-api-release
cd /opt/fap-api-release/backend

cp .env.example .env
# 通过密钥系统注入生产变量（禁止提交真实密钥）
# 例如：DB_*, APP_KEY, STRIPE_WEBHOOK_SECRET, FAP_ADMIN_TOKEN ...

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

说明：

- 交付包不含 `vendor`，必须在部署环境执行 `composer install`。
- 交付包不依赖本地开发机 `.env`。

## 4) 回滚

1. 使用上一个可用 `fap-api-release.zip`。
2. 解压后按同样部署步骤执行。
3. 通过包内 `docs/release/RELEASE_MANIFEST.json` 核对 `commit_sha` 与构建时间。
