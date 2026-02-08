# PR62 Verify

## Before
- 锁 key 为 `payment_webhook:{provider}:{providerEventId}` 且锁参数硬编码（180/10）：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php`（改造前）
- payment_events 查询/更新主要按 `provider_event_id` 单列，缺少 provider 作用域（改造前）。
- 缺少 `payment_webhook_skip_already_processed` 日志分支（改造前）。

## After
- 锁 key 固定为 `webhook_pay:{provider}:{providerEventId}`，并改为配置化：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:78`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/config/services.php:55`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/.env.example:128`
- 事务内第一条落库为 `insertOrIgnore`，并按 `(provider, provider_event_id)` 维度查询/更新事件：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:156`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:158`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:224`
- 订单终态 double-check + skip 日志：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:243`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php:245`
- 新增回归测试：
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PaymentWebhookProcessorAtomicityTest.php`
  - `/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PaymentWebhookProcessorLockKeyTest.php`

## Vulnerability Repro (Test-based)
1. 运行 `PaymentWebhookProcessorAtomicityTest`。
2. 首次 webhook 处理在事件落库后、权益发放前抛异常，断言 `payment_events` 不残留 `evt_atomic_1`。
3. 第二次同 `provider_event_id` 重试成功，断言订单推进成功且 `benefit_wallet_ledgers` 仅 1 条。

## Step Verification Commands
1. routes
   - `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list | grep -E "v0.3.webhooks.payment|webhooks/payment"`
2. migrations
   - `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan migrate --force`
   - `cd /Users/rainie/Desktop/GitHub/fap-api/backend && rm -f /tmp/pr62_step2.sqlite && touch /tmp/pr62_step2.sqlite && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr62_step2.sqlite php artisan migrate:fresh --force`
3. FmTokenAuth
   - `php -l /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php`
   - `grep -n -E "DB::table\('fm_tokens'\)|attributes->set\('fm_user_id'" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php`
4. service/tests
   - `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan test tests/Feature/Commerce/PaymentWebhookProcessorAtomicityTest.php tests/Feature/Commerce/PaymentWebhookProcessorLockKeyTest.php`
   - `cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan test --filter PaymentWebhook`
5. scripts/CI
   - `bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr62_verify.sh`
   - `bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr62_accept.sh`
   - `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr62_accept.sh`
   - `bash /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/ci_verify_mbti.sh`

## PASS Keywords
- `backend/artifacts/pr62/verify.log` 包含 `PASS` 或 `OK (`。
- `bash backend/scripts/pr62_accept.sh` 退出码为 0。
- `bash backend/scripts/ci_verify_mbti.sh` 退出码为 0。

## Artifacts
- `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/pr62/verify.log`
- `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/pr62/summary.txt`
