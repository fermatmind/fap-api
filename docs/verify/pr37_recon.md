# PR37 Recon

- Keywords: PaymentWebhookProcessor|Stripe-Signature|webhook_tolerance

- Related entry files:
  - backend/app/Services/Commerce/PaymentWebhookProcessor.php
  - backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php
  - backend/config/services.php
  - backend/.env.example

- Related tests:
  - backend/tests/Unit/API/V0_3/Webhooks/PaymentWebhookStripeSignatureTest.php
  - backend/tests/Unit/Services/Commerce/PaymentWebhookProcessorLockTest.php

- Required change points:
  - PaymentWebhookProcessor: `Cache::lock` wrapping `DB::transaction`; keep deterministic idempotency via `insertOrIgnore`
  - PaymentWebhookController: Stripe signature tolerance with `services.stripe.webhook_tolerance_seconds`
  - config/services.php + .env.example: `STRIPE_WEBHOOK_TOLERANCE_SECONDS`

- Risks and mitigation:
  - Lock release point: transaction runs inside lock closure, lock is released after closure exits
  - CI without Redis: use default cache driver and explicit `WEBHOOK_BUSY` timeout response
