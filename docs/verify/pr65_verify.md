# PR65 Verify

## Step 1: routes（只校验）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list --path=api/v0.3/webhooks/payment
```

## Step 2: migration（新增后校验）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan migrate --force
rm -f /tmp/pr65_migrate.sqlite
touch /tmp/pr65_migrate.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr65_migrate.sqlite php artisan migrate:fresh --force
```

## Step 3: FmTokenAuth（只校验，不改）
```bash
php -l /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php
grep -n -E "DB::table\\('fm_tokens'\\)|attributes->set\\('fm_user_id'" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php
```

## Step 4: controller/service/tests
```bash
grep -n -E "webhook_pay:\\{\\$provider\\}:\\{\\$providerEventId\\}" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php
grep -n -E "where\\('provider'|where\\('provider_event_id'" /Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/PaymentWebhookProcessor.php
grep -n -E "webhook_tolerance_seconds|BILLING_WEBHOOK_TOLERANCE_SECONDS" /Users/rainie/Desktop/GitHub/fap-api/backend/config/services.php

cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter BillingWebhookReplayToleranceTest
php artisan test --filter PaymentEventUniquenessAcrossProvidersTest
php artisan test --filter BillingWebhookMisconfiguredSecretTest
```

## Step 5: scripts/CI 验收
```bash
bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr65_verify.sh
bash -n /Users/rainie/Desktop/GitHub/fap-api/backend/scripts/pr65_accept.sh

cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pr65_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Smoke（缺 timestamp 应为 404）
```bash
curl -sS -o /tmp/pr65_billing_missing_ts.json -w "%{http_code}\n" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  --data '{"provider_event_id":"evt_pr65_smoke","order_no":"ord_pr65_smoke"}' \
  http://127.0.0.1:1865/api/v0.3/webhooks/payment/billing
```
