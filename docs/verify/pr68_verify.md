# PR68 Verify

## Step 1: routes/api.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list
```

## Step 2: migrations (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
```

## Step 3: FmTokenAuth.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
php -l backend/app/Http/Middleware/FmTokenAuth.php
grep -n -E "DB::table\\('fm_tokens'\\)|attributes->set\\('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php
```

## Step 4: PR68 static gate
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pr68_verify.sh
```

## Step 5: acceptance + CI chain
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pr68_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Curl examples
```bash
curl -sS -i http://127.0.0.1:1868/api/v0.2/healthz || true
curl -sS -i http://127.0.0.1:1868/api/v0.3/scales/MBTI/questions || true
```

## PASS Criteria
- 无 php-version 非 8.4
- 任意出现 composer install 的 workflow，同文件必含：
  - composer validate --strict
  - composer audit --no-interaction
- 本地验收链全绿

## 执行记录
- Commands:
  - `php artisan route:list`
  - `php artisan migrate --force`
  - `php -l backend/app/Http/Middleware/FmTokenAuth.php`
  - `grep -n -E "DB::table\\('fm_tokens'\\)|attributes->set\\('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php`
  - `bash backend/scripts/pr68_accept.sh`
  - `bash backend/scripts/ci_verify_mbti.sh`
- Key assertion outputs:
  - route list: `api/healthz`、`api/v0.2/admin/agent/disable-trigger` 等路由存在。
  - migrate: `INFO  Nothing to migrate.`
  - FmTokenAuth: `No syntax errors detected`，并命中 `DB::table('fm_tokens')` 与 `attributes->set('fm_user_id', ...)`。
  - PR68 gate artifacts:
    - `backend/artifacts/pr68/workflow_php_version_non84.txt` 为空（PASS）
    - `backend/artifacts/pr68/workflow_php_version_84.txt` 命中 14 行（PASS）
    - `backend/artifacts/pr68/workflow_composer_gate_report.txt` 中 14 个 workflow 全部 `install=1 validate=1 audit=1`（PASS）
  - CI chain: `bash backend/scripts/ci_verify_mbti.sh` 返回 0（PASS）
- PASS: 无 php-version 漂移、所有 composer install 之后必含 validate+audit
