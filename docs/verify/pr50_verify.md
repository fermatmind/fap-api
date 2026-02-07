# PR50 Verify

- Date: 2026-02-08
- Commands:
  - php artisan route:list
  - php artisan migrate
  - php -l backend/app/Http/Middleware/FmTokenAuth.php
  - php artisan test --filter BillingWebhookSignatureTest
  - php artisan test --filter PaymentWebhookStripeSignatureTest
  - bash backend/scripts/pr50_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh
- Result:
  - route:list: PASS
  - migrate: PASS
  - middleware lint/check: PASS
  - BillingWebhookSignatureTest: PASS
  - PaymentWebhookStripeSignatureTest: PASS
  - pr50_accept: PASS
  - ci_verify_mbti: PASS
- Artifacts:
  - backend/artifacts/pr50/summary.txt
  - backend/artifacts/pr50/verify.log
  - backend/artifacts/pr50/phpunit.txt
  - backend/artifacts/pr50/billing_webhook_valid.json
  - backend/artifacts/pr50/billing_webhook_duplicate.json
  - backend/artifacts/verify_mbti/summary.txt

Key Notes

- v0.3 billing webhook 验签升级为 `timestamp + raw_body`，并新增 `BILLING_WEBHOOK_TOLERANCE_SECONDS` 配置。
- 当 billing secret 已配置时，缺失 timestamp、过期 timestamp、签名错误统一返回 404。
- 重复同一 `provider_event_id` 回调返回 200，且 `duplicate=true`。
- `pr50_verify.sh` 继续校验 pack/seed/config 一致性，并动态生成 answers（无题量硬编码）。

Blocker Handling

- 【错误原因】`pr50_verify.sh` 首次改造后，`ORDER_NO` 变量误放在 pack 校验段导致 unbound variable。
- 【最小修复动作】移除错误变量展开，并在订单准备段正确注入 `ORDER_NO` 环境变量。
- 【对应命令】
  - `bash -n backend/scripts/pr50_verify.sh`
  - `bash backend/scripts/pr50_accept.sh`

Step Verification Commands

1. Step 1 (routes): `cd backend && php artisan route:list | grep -E "api/v0.3/webhooks/payment/\{provider\}"`
2. Step 2 (migrations): `cd backend && php artisan migrate --force && php artisan migrate:fresh --force`
3. Step 3 (middleware): `php -l backend/app/Http/Middleware/FmTokenAuth.php && grep -n "DB::table('fm_tokens')" backend/app/Http/Middleware/FmTokenAuth.php && grep -n "attributes->set('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php`
4. Step 4 (controllers/services/tests): `cd backend && php artisan test --filter BillingWebhookSignatureTest && php artisan test --filter PaymentWebhookStripeSignatureTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr50_verify.sh && bash -n backend/scripts/pr50_accept.sh && bash backend/scripts/pr50_accept.sh && bash backend/scripts/ci_verify_mbti.sh`
