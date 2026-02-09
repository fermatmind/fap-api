# 回滚 Runbook（Production）

> 本文档仅使用占位符，不包含真实 token/secret/连接串。

## 1) 回滚前检查清单
- [ ] 确认当前版本与目标回滚版本号
- [ ] 确认变更范围（代码/配置/数据）
- [ ] 确认是否涉及数据库迁移
- [ ] 确认回滚窗口、负责人、审批

```bash
set -euo pipefail
DEPLOY_PATH="/var/www/fap-api"
TARGET_RELEASE="<target_release_number>"

echo "[current]"
readlink -f "${DEPLOY_PATH}/current"

echo "[recent releases]"
ls -1 "${DEPLOY_PATH}/releases" | tail -n 10

echo "[target exists]"
test -d "${DEPLOY_PATH}/releases/${TARGET_RELEASE}"
```

```bash
set -euo pipefail
DEPLOY_PATH="/var/www/fap-api"
TARGET_RELEASE="<target_release_number>"
CURRENT_RELEASE="$(basename "$(readlink -f "${DEPLOY_PATH}/current")")"

echo "[migration file diff]"
comm -3 \
  <(ls -1 "${DEPLOY_PATH}/releases/${CURRENT_RELEASE}/backend/database/migrations" | sort) \
  <(ls -1 "${DEPLOY_PATH}/releases/${TARGET_RELEASE}/backend/database/migrations" | sort) || true
```

## 2) 数据保护动作（先备份、再回滚）

```bash
set -euo pipefail
TS="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="/var/backups/fap-api"
DB_HOST="<db_host>"
DB_PORT="<db_port>"
DB_NAME="<db_name>"
DB_USER="<db_user>"
DB_PASSWORD="<db_password>"

mkdir -p "${BACKUP_DIR}"
mysqldump --single-transaction --quick --routines --triggers \
  -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASSWORD}" \
  "${DB_NAME}" > "${BACKUP_DIR}/fap_api_${TS}.sql"

gzip -f "${BACKUP_DIR}/fap_api_${TS}.sql"
sha256sum "${BACKUP_DIR}/fap_api_${TS}.sql.gz" > "${BACKUP_DIR}/fap_api_${TS}.sql.gz.sha256"
sha256sum -c "${BACKUP_DIR}/fap_api_${TS}.sql.gz.sha256"
```

```bash
set -euo pipefail
TS="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="/var/backups/fap-api"
DB_HOST="<db_host>"
DB_PORT="<db_port>"
DB_NAME="<db_name>"
DB_USER="<db_user>"
DB_PASSWORD="<db_password>"

mkdir -p "${BACKUP_DIR}"
mysqldump --single-transaction --quick \
  -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" -p"${DB_PASSWORD}" \
  "${DB_NAME}" attempts results events orders order_items > "${BACKUP_DIR}/fap_key_tables_${TS}.sql"
gzip -f "${BACKUP_DIR}/fap_key_tables_${TS}.sql"
```

## 3) 应用回滚步骤（构建/发布回滚 + 配置回滚）

```bash
set -euo pipefail
DEPLOY_PATH="/var/www/fap-api"
TARGET_RELEASE="<target_release_number>"

echo "[before rollback]"
readlink -f "${DEPLOY_PATH}/current"

ln -nfs "${DEPLOY_PATH}/releases/${TARGET_RELEASE}" "${DEPLOY_PATH}/current"
sudo -n /usr/bin/systemctl reload php8.4-fpm
sudo -n /usr/bin/systemctl reload nginx

echo "[after rollback]"
readlink -f "${DEPLOY_PATH}/current"
```

```bash
set -euo pipefail
cd /var/www/fap-api/current/backend
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

## 4) 数据库迁移处理口径（严格）

```bash
set -euo pipefail
cd /var/www/fap-api/current/backend
php artisan migrate:status --no-ansi
```

```bash
# 禁止在生产执行（可能导致删表/数据丢失）:
# php artisan migrate:rollback --force
# php artisan migrate:fresh --force
```

```bash
set -euo pipefail
cd /var/www/fap-api/current/backend
php artisan migrate --force
php artisan migrate:status --no-ansi
```

## 5) 回滚后 Smoke（必须包含 v0.3 boot 与 /healthz）

```bash
set -euo pipefail
BASE_URL="https://<domain>"

curl -fsS "${BASE_URL}/api/v0.3/boot" | grep -q '"ok":true' && echo "v0.3 boot OK"

curl -fsS "${BASE_URL}/healthz" | grep -q '"ok":true' && echo "healthz OK" \
  || curl -fsS "${BASE_URL}/api/healthz" | grep -q '"ok":true' && echo "api/healthz OK"
```

## 6) 常见故障排障

### 迁移失败
```bash
set -euo pipefail
cd /var/www/fap-api/current/backend
php artisan migrate:status --no-ansi
tail -n 200 storage/logs/laravel.log
```

### 队列堆积
```bash
set -euo pipefail
cd /var/www/fap-api/current/backend
php artisan queue:failed
php artisan queue:restart
```

### Webhook 失败
```bash
set -euo pipefail
cd /var/www/fap-api/current/backend
php artisan route:list --path=api/v0.3/webhooks/payment
tail -n 200 storage/logs/laravel.log | grep -i webhook || true
```
