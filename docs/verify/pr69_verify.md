# PR69 Verify

## Step 1: routes/api.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list
```

## Step 2: migrations (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
ls -1 database/migrations/*failed_jobs* || true
```

## Step 3: FmTokenAuth.php (verify only)
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
php -l backend/app/Http/Middleware/FmTokenAuth.php
grep -n -E "DB::table\\('fm_tokens'\\)|attributes->set\\('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php
```

## Step 4: PR69 static gate
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pr69_verify.sh
```

## Step 5: acceptance + CI chain
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pr69_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Curl examples
```bash
curl -sS -i http://127.0.0.1:1869/api/v0.2/healthz || true
curl -sS -i \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer fm_00000000-0000-4000-8000-000000000000" \
  -d '{"event_code":"pr69_probe","attempt_id":"11111111-1111-4111-8111-111111111111"}' \
  http://127.0.0.1:1869/api/v0.2/events || true
```

## PASS Criteria
- queue failed.driver 固定 `database-uuids`，`database/redis/beanstalkd.retry_after=90`
- AppServiceProvider 已注册 `pushProcessor` 并使用 `SensitiveDataRedactor` 处理 `context/extra`
- SensitiveDataRedactor 覆盖 `password/token/secret/credit_card/authorization`
- `.env.example` 包含 `APP_DEBUG=false`、`FAP_ADMIN_TOKEN=`、`EVENT_INGEST_TOKEN=`
- EventController 使用 `hash_equals` + `config('fap.events.ingest_token')` + `fm_tokens` 存在性校验
- `failed_jobs` 迁移存在
- `bash backend/scripts/pr69_accept.sh` 与 `bash backend/scripts/ci_verify_mbti.sh` 返回 0

## 执行记录
- Commands:
  - `php artisan route:list`
  - `php artisan migrate --force`
  - `php -l backend/app/Http/Middleware/FmTokenAuth.php`
  - `grep -n -E "DB::table\\('fm_tokens'\\)|attributes->set\\('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php`
  - `bash backend/scripts/pr69_accept.sh`
  - `bash backend/scripts/ci_verify_mbti.sh`
- Key assertion outputs:
  - route list: 共 `136` 行，路由表可正常加载。
  - migrate: `INFO  Nothing to migrate.`
  - FmTokenAuth: `No syntax errors detected`，并命中 `DB::table('fm_tokens')` 与 `attributes->set('fm_user_id', ...)`。
  - PR69 gate: `backend/artifacts/pr69/verify.log` 显示 `[PR69][VERIFY] pass`。
  - PR69 accept: `backend/artifacts/pr69/pr69_accept.log` 执行完成且产出 `backend/artifacts/pr69/summary.txt`。
  - CI chain: `bash backend/scripts/ci_verify_mbti.sh` 返回 0（全链路 PASS）。
