# PR57 Verify

- Date: 2026-02-08
- Commands:
  - `php artisan route:list`
  - `php artisan migrate`
  - `php artisan test --filter BillingWebhookSignatureTest`
  - `php artisan test --filter PaymentWebhookRouteWiringTest`
  - `php artisan test --filter BillingWebhookMisconfiguredSecretTest`
  - `bash backend/scripts/pr57_accept.sh`
  - `bash backend/scripts/ci_verify_mbti.sh`
- Result:
  - `php artisan route:list` 通过（v0.3 webhook route wiring 可见）
  - `php artisan migrate` 通过（sqlite fresh + migrate 可重复）
  - 三个 webhook 相关测试通过
  - `bash backend/scripts/pr57_accept.sh` 通过
  - `bash backend/scripts/ci_verify_mbti.sh` 通过
- Artifacts:
  - `backend/artifacts/pr57/summary.txt`
  - `backend/artifacts/pr57/verify.log`
  - `backend/artifacts/pr57/server.log`
  - `backend/artifacts/pr57/route_webhook.json`
  - `backend/artifacts/pr57/phpunit_billing_signature.txt`
  - `backend/artifacts/pr57/phpunit_route_wiring.txt`
  - `backend/artifacts/pr57/phpunit_misconfigured_secret.txt`
  - `backend/artifacts/verify_mbti/summary.txt`

Step Verification Commands

1. Step 1 (routes): `cd backend && php artisan route:list --path=api/v0.3/webhooks/payment --json | grep -E "v0.3.webhooks.payment|PaymentWebhookController@handle"`
2. Step 2 (migrations): `rm -f /tmp/pr57.sqlite && touch /tmp/pr57.sqlite && cd backend && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr57.sqlite php artisan migrate:fresh --force && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr57.sqlite php artisan migrate --force`
3. Step 3 (middleware): `php -l backend/app/Http/Middleware/FmTokenAuth.php && grep -n "DB::table('fm_tokens')" backend/app/Http/Middleware/FmTokenAuth.php && grep -n "attributes->set('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php`
4. Step 4 (controller/config/tests): `php -l backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php && php -l backend/config/services.php && php -l backend/tests/Feature/V0_3/PaymentWebhookRouteWiringTest.php && php -l backend/tests/Feature/V0_3/BillingWebhookMisconfiguredSecretTest.php && cd backend && php artisan test --filter BillingWebhookSignatureTest && php artisan test --filter PaymentWebhookRouteWiringTest && php artisan test --filter BillingWebhookMisconfiguredSecretTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr57_verify.sh && bash -n backend/scripts/pr57_accept.sh && bash backend/scripts/pr57_accept.sh && bash backend/scripts/ci_verify_mbti.sh`

Curl Smoke Examples

- `curl -sS "http://127.0.0.1:1857/api/v0.2/healthz"`
- `curl -sS -X POST -H "Content-Type: application/json" -H "Accept: application/json" --data '{}' "http://127.0.0.1:1857/api/v0.3/webhooks/payment/stub"`
