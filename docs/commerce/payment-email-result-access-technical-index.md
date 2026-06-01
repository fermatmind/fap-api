# Payment, Email, and Result Access Technical Index

This document is the canonical technical entry point for the FermatMind result
access chain: result preview, optional email binding, DirectMail delivery,
Alipay payment, paid unlock, compensation, and result re-entry.

It consolidates the former payment, SMTP, email readiness, Alipay compensation,
owner-mismatch, order-state, benefit, webhook-idempotency, reconciliation, and
report-snapshot notes. Keep future payment or email runbooks linked from this
file instead of adding another parallel entry document.

## 1. Overview

The product flow has three separable capabilities:

1. A user completes a test and receives a result page.
2. The user may bind an email address to recover the result or receive a
   tokenized access link. Email binding is optional and must not block the free
   result preview.
3. The user may purchase a paid unlock. A paid order must converge into active
   entitlements and a ready exact result entry, then the frontend must return
   the user to the full paid result page.

The chain intentionally separates payment confirmation, entitlement grant,
email delivery, and frontend navigation. Do not infer one truth from another.

## 2. Authority Boundary

Canonical truths:

- `payment truth = orders.payment_state`
- `unlock truth = benefit_grants.status`
- `order lifecycle truth = orders.status`
- `grant lifecycle truth = orders.grant_state`
- `webhook diagnostics truth = payment_events.status / handle_status / last_error_code`
- `result entry truth = exact_result_entries / unified_access_projections`
- `email delivery truth = email_outbox.status`

Frontend screens are consumers of backend API state. They may improve UX, retry,
or redirect, but must not invent paid access, grant state, or email delivery
state.

The backend must not use frontend fallback content as authority for result
access, payment, email, or CMS-backed content.

## 3. User Paths

### 3.1 Test Completion to Result Preview

1. The frontend submits a completed attempt.
2. The backend creates or updates result/report read models.
3. The result page loads public/free result data.
4. If the result is still generating, the API returns the existing generating
   contract and the frontend polls.
5. Email binding may be shown as an optional recovery module, not as a blocker
   for viewing the free result preview.

Frontend entry:

- `fap-web/app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx`

Backend entries:

- `GET /api/v0.3/attempts/{id}/report-access`
- `GET /api/v0.3/attempts/{id}/report`
- `GET /api/v0.3/attempts/{id}/result`

### 3.2 Optional Email Binding and Access Link

1. The user enters an email from the result page or lookup flow.
2. The backend validates attempt ownership before binding.
3. The backend reuses the existing `result_access_token` system.
4. The backend queues a result access link in `email_outbox`.
5. The scheduler sends queued mail through DirectMail when
   `EMAIL_OUTBOX_SEND=true`.
6. The emailed link opens the result page through the tokenized access path.

Backend entries:

- `POST /api/v0.3/attempts/{id}/email-bind`
- `POST /api/v0.3/results/lookup-by-email`
- `App\Services\Attempts\AttemptEmailBindingService`
- `App\Services\Results\ResultEmailLookupService`
- `App\Services\Results\ResultAccessTokenService`
- `App\Services\Email\EmailOutboxService`

Frontend entries:

- `fap-web/components/support/ResultEmailLookupForm.tsx`
- `fap-web/app/(localized)/[locale]/results/lookup/page.tsx`
- `fap-web/lib/api/v0_3.ts`

### 3.3 Order Lookup and Purchase Email Recovery

1. The user enters an order number and purchase email on the order lookup page.
2. The backend verifies the order lookup context without exposing whether an
   unrelated email exists.
3. If the order has a recoverable report, the order page can show report access,
   delivery status, PDF access, resend delivery email, and a claim/recovery
   action.
4. If delivery email is missing or stale, `claim/report` and order resend paths
   queue transactional email through `email_outbox`.
5. The order lookup path is separate from result email lookup. Result lookup
   starts from an email and finds saved attempts; order lookup starts from an
   order number and purchase email.

Backend entries:

- `POST /api/v0.3/orders/lookup`
- `POST /api/v0.3/orders/{order_no}/resend`
- `GET /api/v0.3/claim/report`
- `POST /api/v0.3/claim/report`
- `App\Services\Commerce\OrderManager`
- `App\Services\Commerce\MbtiAccessHubBuilder`
- `App\Services\Email\EmailOutboxService`

Frontend entries:

- `fap-web/components/support/OrderLookupForm.tsx`
- `fap-web/app/(localized)/[locale]/orders/[orderNo]/OrdersClient.tsx`
- `fap-web/lib/mbti/accessHub.ts`
- `fap-web/lib/api/v0_3.ts`

### 3.4 Email Preferences and Unsubscribe

Every transactional email template that includes recovery or account links must
also carry preferences and unsubscribe links when the backend can mint the
preference token.

Preferences and unsubscribe are email-control surfaces. They must not be used
to prove paid access, result ownership, or order ownership.

Backend entries:

- `POST /api/v0.3/email/capture`
- `GET /api/v0.3/email/preferences`
- `POST /api/v0.3/email/preferences`
- `POST /api/v0.3/email/unsubscribe`
- `App\Services\Email\EmailCaptureService`
- `App\Services\Email\EmailPreferenceService`
- `App\Services\Email\EmailLifecycleRolloutService`

Frontend entries:

- `fap-web/app/(localized)/[locale]/email/preferences/page.tsx`
- `fap-web/app/(localized)/[locale]/email/unsubscribe/page.tsx`
- `fap-web/components/email/EmailPreferencesClient.tsx`
- `fap-web/components/email/EmailUnsubscribeClient.tsx`

### 3.5 Paid Unlock Through Alipay

1. The user starts checkout from a locked result module.
2. The backend creates an order and Alipay payment context.
3. The frontend sends the user to Alipay.
4. Alipay may call the server webhook, return the browser, or both.
5. Webhook, return recovery, or scheduler compensation must converge the order
   into `payment_state=paid`.
6. Existing repair code grants entitlements and prepares result entry/projection.
7. The backend carries a scoped `payment_recovery_token` through checkout,
   return, wait, and order status responses. This token is distinct from
   `result_access_token` and is used only to recover the payment/result entry
   path after browser return.
8. `/pay/wait` must redirect to the full result page once the order is paid and
   exact result entry is ready.

Backend entries:

- `POST /api/v0.3/orders/checkout`
- `GET /api/v0.3/orders/{order_no}/pay/alipay`
- `POST /api/v0.3/webhooks/payment/{provider}`
- `GET /api/v0.3/orders/{order_no}/recover/alipay-return`
- `GET /api/v0.3/orders/{order_no}`
- `POST /api/v0.3/orders/lookup`
- `App\Http\Controllers\API\V0_3\CommerceController`
- `App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController`
- `App\Services\Commerce\OrderManager`
- `App\Services\Commerce\Checkout\AlipayCheckoutService`
- `App\Internal\Commerce\PaymentWebhookHandlerCore`

Frontend entries:

- `fap-web/app/(localized)/[locale]/orders/[orderNo]/OrdersClient.tsx`
- `fap-web/app/(localized)/[locale]/pay/return/alipay/page.tsx`
- `fap-web/app/(localized)/[locale]/pay/wait/page.tsx`
- `fap-web/components/commerce/OrderReturnFallbackClient.tsx`
- `fap-web/lib/commerce/checkoutAction.ts`
- `fap-web/lib/commerce/redirectUrls.ts`

### 3.6 Email Re-entry After Payment

If the user binds an email, the mailed result access link should open the same
authorized result. The email link is a recovery path and must not become a
parallel entitlement system.

Rules:

- Reuse `result_access_token`.
- Do not create a second token family.
- Do not expose raw tokens in logs, docs, or PR output.
- Do not grant paid access from email delivery alone.

## 4. Backend API Map

| Capability | Endpoint | Backend owner |
|---|---|---|
| Result access gate | `GET /api/v0.3/attempts/{id}/report-access` | Attempt/report read layer |
| Full/free report read | `GET /api/v0.3/attempts/{id}/report` | Report gatekeeper |
| Result read | `GET /api/v0.3/attempts/{id}/result` | Attempt read layer |
| Report PDF | `GET /api/v0.3/attempts/{id}/report.pdf` | Attempt read layer |
| Email bind | `POST /api/v0.3/attempts/{id}/email-bind` | `AttemptEmailBindingService` |
| Email lookup | `POST /api/v0.3/results/lookup-by-email` | `ResultEmailLookupService` |
| Email capture | `POST /api/v0.3/email/capture` | `EmailCaptureService` |
| Email preferences read | `GET /api/v0.3/email/preferences` | `EmailPreferenceService` |
| Email preferences update | `POST /api/v0.3/email/preferences` | `EmailPreferenceService` |
| Email unsubscribe | `POST /api/v0.3/email/unsubscribe` | `EmailPreferenceService` |
| Checkout | `POST /api/v0.3/orders/checkout` | `CommerceController` |
| Alipay launch | `GET /api/v0.3/orders/{order_no}/pay/alipay` | `AlipayCheckoutService` |
| Order status | `GET /api/v0.3/orders/{order_no}` | `CommerceController` |
| Order lookup | `POST /api/v0.3/orders/lookup` | `CommerceController` |
| Alipay return recovery | `GET /api/v0.3/orders/{order_no}/recover/alipay-return` | `CommerceController` |
| Payment webhook | `POST /api/v0.3/webhooks/payment/{provider}` | `PaymentWebhookController` |
| Resend order email | `POST /api/v0.3/orders/{order_no}/resend` | Commerce/email service layer |
| Claim report email | `GET /api/v0.3/claim/report`, `POST /api/v0.3/claim/report` | Claim/email service layer |

## 5. Frontend Route and Component Map

| Surface | File | Responsibility |
|---|---|---|
| Result page | `fap-web/app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx` | Public result, paid modules, email binding UI |
| Result email lookup | `fap-web/components/support/ResultEmailLookupForm.tsx` | Lookup UI and access-link handling |
| Lookup page | `fap-web/app/(localized)/[locale]/results/lookup/page.tsx` | User-facing recovery page |
| Order lookup | `fap-web/components/support/OrderLookupForm.tsx` | Order number + purchase email lookup and claim flow |
| Order page | `fap-web/app/(localized)/[locale]/orders/[orderNo]/OrdersClient.tsx` | Order status, delivery email, paid redirect |
| Alipay return | `fap-web/app/(localized)/[locale]/pay/return/alipay/page.tsx` | Browser return recovery entry |
| Alipay return fallback | `fap-web/components/commerce/OrderReturnFallbackClient.tsx` | Client recovery when return context must be rebuilt |
| Pay wait | `fap-web/app/(localized)/[locale]/pay/wait/page.tsx` | Payment polling and paid result redirect |
| Email preferences | `fap-web/app/(localized)/[locale]/email/preferences/page.tsx` | Preference token UI |
| Email unsubscribe | `fap-web/app/(localized)/[locale]/email/unsubscribe/page.tsx` | Unsubscribe token UI |
| API adapter | `fap-web/lib/api/v0_3.ts` | Typed frontend API calls |
| Checkout action | `fap-web/lib/commerce/checkoutAction.ts` | Checkout start contract |
| Redirect URLs | `fap-web/lib/commerce/redirectUrls.ts` | Return/wait URL construction |
| MBTI access hub adapter | `fap-web/lib/mbti/accessHub.ts` | Recovery and claim link normalization |

## 6. Data Tables and State Machines

Core tables:

- `attempts`: submitted test attempt and ownership context.
- `attempt_email_bindings`: optional email binding for result recovery.
- `orders`: payment truth, lifecycle truth, grant lifecycle, order number.
- `payment_attempts`: provider launch/session context.
- `payment_events`: webhook and provider diagnostic events.
- `benefit_grants`: entitlement truth for paid access.
- `unified_access_projections`: paid/free access projection for result entry.
- `exact_result_entries`: result entry target used by payment recovery and wait
  redirect.
- `report_snapshots`: generated report payload/cache state.
- `email_outbox`: queued/sent/failed transactional email truth.
- `email_subscribers`: subscriber lifecycle, status, captured timestamps, and
  lifecycle confirmation send markers.
- `email_preferences`: per-subscriber marketing, report recovery, and product
  update preferences.
- `email_suppressions`: suppression state that blocks or limits future sends.

Token families:

- `result_access_token`: short-lived token for result re-entry. TTL is
  controlled by `FAP_RESULT_ACCESS_TOKEN_TTL_MINUTES` through
  `fap.result_access_tokens.ttl_minutes`.
- `payment_recovery_token`: scoped payment/order recovery token carried through
  checkout, Alipay return, `/pay/wait`, and order status. It must not be treated
  as a result access token.
- Email preference/unsubscribe tokens: scoped to email preference management,
  not result ownership or paid access.

Order states:

- Valid states: `created`, `pending`, `paid`, `fulfilled`, `failed`,
  `canceled`, `refunded`.
- Valid transitions:
  - `created -> pending | paid | failed | canceled | refunded`
  - `pending -> paid | failed | canceled | refunded`
  - `paid -> fulfilled`
  - `fulfilled -> refunded`
- Illegal transitions return `ORDER_STATUS_INVALID`.
- Concurrent changes use locked reads or compare-and-update semantics and may
  return `ORDER_STATUS_CHANGED`.

Webhook idempotency:

- Provider event identity is stored before order mutation.
- Duplicate provider events must return success without issuing duplicate
  grants.
- The webhook diagnostics truth must not be used as payment truth.

Benefits:

- Entitlements must be issued through `benefit_grants`.
- `orders.payment_state=paid` alone is not sufficient for full paid report
  access.
- Anonymous ownership and eventual user ownership must stay separated by
  explicit ownership rules.

Report snapshot:

- Submit and payment flows can seed pending snapshots.
- `GenerateReportSnapshotJob` creates or refreshes snapshot payloads.
- Paid/full access reads prefer ready snapshot payloads when available.
- Pending/failed snapshot states return retryable contracts instead of
  pretending the full report is ready.

## 7. Scheduler

The runtime scheduler source for the current Laravel bootstrap path is
`backend/bootstrap/app.php`.

Required scheduled commands:

```bash
php artisan email:outbox-send --limit=50
php artisan commerce:compensate-pending-orders --provider=alipay --include-created --only-stale --limit=10 --older-than-minutes=60
```

Related command families:

```bash
php artisan email:lifecycle-rollout
php artisan commerce:repair-paid-orders --limit=50
php artisan commerce:repair-post-commit-failed --limit=50
php artisan payments:prune-events --days=90
```

`email:lifecycle-rollout`, `commerce:repair-paid-orders`, and
`commerce:repair-post-commit-failed` appear in the legacy
`App\Console\Kernel` schedule. The production runtime source must still be
verified with `php artisan schedule:list` because Laravel 11 deployments can be
driven by `bootstrap/app.php`.

Operational checks:

```bash
cd backend
php artisan schedule:list --json
```

Production must also have a runner, for example cron invoking
`php artisan schedule:run` every minute or a supervised `schedule:work`
process. A command appearing in `schedule:list` is not enough if no runner is
active.

Notes:

- Alipay pending compensation must not pass `--close-expired` without separate
  review.
- Owner mismatch must not be automatically repaired by the scheduler.
- Email outbox sending is gated by `EMAIL_OUTBOX_SEND=true`.

## 8. DirectMail, SMTP, and DNS

Production sender baseline:

- Sender domain: `mail.fermatmind.com`
- Sender address: `noreply@mail.fermatmind.com`
- SMTP host: `smtpdm.aliyun.com`
- SMTP port: `465`
- SMTP scheme: `smtps`
- EHLO domain: `mail.fermatmind.com`
- Runtime send gate: `EMAIL_OUTBOX_SEND=true`
- DNS gate: `OPS_GATE_SPF_DKIM_DMARC_OK=true`

Production env shape:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtpdm.aliyun.com
MAIL_PORT=465
MAIL_USERNAME=noreply@mail.fermatmind.com
MAIL_PASSWORD=<DirectMail SMTP password>
MAIL_FROM_ADDRESS=noreply@mail.fermatmind.com
MAIL_FROM_NAME=FermatMind
MAIL_EHLO_DOMAIN=mail.fermatmind.com
EMAIL_OUTBOX_SEND=true
OPS_GATE_SPF_DKIM_DMARC_OK=true
```

Do not commit `MAIL_PASSWORD`, SMTP passwords, cookies, session tokens,
provider credentials, or private user data.

DNS records:

- SPF TXT on `mail`: `v=spf1 include:spf1.dm.aliyun.com -all`
- DKIM TXT on `aliyun-cn-hangzhou._domainkey.mail`: provider public key
- DMARC TXT on `_dmarc.mail`:
  `v=DMARC1;p=none;rua=mailto:dmarc_report@service.aliyun.com`
- MX on `mail`: `mx01.dm.aliyun.com`, priority `10`

Controlled smoke evidence already established:

- Command: `php artisan email:outbox-send --limit=1`
- Result: `Mailer smtp: sent 1, blocked 0, failed 0.`
- DirectMail response: `250 Send Mail OK`
- From: `noreply@mail.fermatmind.com`
- Subject: `FermatMind DirectMail smoke test`
- Recipient confirmation: delivered to Outlook inbox.

Future smoke tests must be opt-in, scoped to internal recipients, and recorded
without raw credentials or private user data.

The recorded DirectMail smoke did not mutate code, publish content, deploy, submit URLs, enqueue Search Channel actions, or send to real users.

## 9. Failure Recovery

### 9.1 Webhook Missing or Delayed

Symptoms:

- User paid in Alipay.
- Local order remains `created` or `pending`.
- `/pay/wait` stays in confirming state.

Recovery chain:

1. Browser return calls Alipay return recovery.
2. If return verification and provider query confirm payment, repair the order
   immediately.
3. Scheduled pending compensation later queries stale Alipay orders as a safety
   net.
4. Grant/projection repair must converge to active benefit grants and ready
   result entry.

### 9.2 `paid_no_grant`

Symptoms:

- `orders.payment_state=paid`
- Missing or inactive `benefit_grants`
- Full result still locked.

Recovery:

- Use existing paid-order repair paths only when ownership and semantic guards
  pass.
- Verify `benefit_grants.status=active`.
- Verify `unified_access_projections.access_state=ready`.
- Verify `exact_result_entry.ready_to_enter=true`.

### 9.3 Owner Mismatch

`ATTEMPT_OWNER_MISMATCH` is a trust-boundary block and must not be folded into
automatic compensation.

Classification policy:

- `safe_order_grant_state_sync_after_approval`: active grant and ready
  projection already exist; only stale order lifecycle fields remain.
- `human_ownership_review_required`: paid order has no active grant or ready
  projection; human ownership review is required.
- `automatic_repair_forbidden`: true or unresolved mismatch.

The already completed state-sync-only repair updated only stale order lifecycle
fields for the single approved candidate. It did not create grants, mutate
projections, run pending compensation, read raw logs, deploy, mutate CMS,
submit URLs, or change fap-web.

Generated historical evidence remains in:

- `backend/docs/commerce/generated/alipay-owner-mismatch-review-03.v1.json`
- `backend/docs/commerce/generated/alipay-owner-mismatch-controlled-repair-04.v1.json`

### 9.4 Email Not Sent

Check:

1. `email_outbox.status`
2. `EMAIL_OUTBOX_SEND`
3. SMTP env and DirectMail sender status
4. scheduler runner
5. provider response and retry state

Do not send production test emails without a scoped approval and an internal
recipient.

## 10. Validation Commands

Backend:

```bash
cd backend
php artisan route:list --no-ansi
php artisan schedule:list --no-ansi
php artisan test --filter=DirectMailSmtpReadiness --no-ansi
php artisan test --filter=AlipayPendingCompensationScheduler --no-ansi
php artisan test --filter=AlipayOwnerMismatchReview03 --no-ansi
php artisan test --filter=AlipayOwnerMismatchControlledRepair04 --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable
```

Frontend:

```bash
pnpm test:contract -- result-client-view-state
pnpm test:contract -- result-email-lookup
pnpm test:contract -- orders-client-delivery
pnpm test:contract -- alipay-return-flow
pnpm test:contract -- payment-wait-flow
pnpm test:e2e -- payment-wait-flow
pnpm test:e2e -- alipay-return-recovery
```

Production smoke checklist:

- Backend deployed SHA matches expected release.
- Frontend deployed SHA matches expected release.
- `ALIPAY_RETURN_URL=https://fermatmind.com/zh/pay/return/alipay`
- `FRONTEND_URL=https://fermatmind.com`
- `EMAIL_OUTBOX_SEND=true`
- `MAIL_MAILER=smtp`
- `MAIL_HOST=smtpdm.aliyun.com`
- `MAIL_FROM_ADDRESS=noreply@mail.fermatmind.com`
- `OPS_GATE_SPF_DKIM_DMARC_OK=true`
- `php artisan schedule:list` contains both Alipay compensation and email
  outbox commands.
- Scheduler runner is active.
- Controlled paid order returns from Alipay to `/zh/pay/wait`.
- `/pay/wait` redirects to the full result page after paid + ready result entry.
- Optional email binding queues and sends the result access link.
- Emailed result access link opens the authorized result.
- Order lookup with order number + purchase email reaches the order page.
- Delivery resend/claim email paths enqueue email when allowed.
- Email preferences and unsubscribe token pages load without exposing raw token
  text.

## 11. PR History

Important recent changes:

- `EMAIL-DIRECTMAIL-SMTP-READINESS-01`: DirectMail env template, DNS runbook,
  and outbox readiness coverage.
- `RESULT-EMAIL-GATE-POLICY-02`: email binding became optional recovery/access
  link behavior instead of blocking free result preview.
- `RESULT-EMAIL-ACCESS-LINK-03`: email binding sends a result access link using
  existing `result_access_token`.
- `EMAIL-OUTBOX-SCHEDULER-BOOTSTRAP-04`: `email:outbox-send` registered in the
  runtime scheduler path.
- `PAYMENT-ALIPAY-PENDING-COMPENSATION-SCHEDULER-01`: pending Alipay
  compensation introduced.
- `PAYMENT-ALIPAY-SCHEDULER-BOOTSTRAP-02`: Alipay compensation registered in
  `bootstrap/app.php`.
- `PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03`: owner mismatch classified and
  excluded from automatic repair.
- `PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04`: one state-sync-only
  owner mismatch candidate repaired after explicit approval.
- `RESULT-EMAIL-FIRST-BINDING-UX-05`: result page first-bind email UX.
- `PAYMENT-ALIPAY-RETURN-IMMEDIATE-COMPENSATION-05`: Alipay return recovery now
  immediately queries provider and repairs paid/grant/projection state when
  safe.
- `PAYMENT-WAIT-PAID-REDIRECT-CONTRACT-06`: `/pay/wait` redirects when the order
  is paid and exact result entry is ready.

## 12. Known Sidecars

- Owner-mismatch orders without active grants or ready projections still require
  human ownership review and must not be auto-repaired.
- Pending Alipay orders without sufficient provider identifiers may remain
  unresolved by automatic compensation.
- `schedule:list` must be checked together with the server-side scheduler
  runner; either one alone is insufficient.
- Frontend and backend PR ledgers can lag after emergency or cross-repository
  merges; GitHub merge truth and deployed SHA should be used during production
  verification.

## 13. Archived Documents

The following former documents were consolidated into this index and removed:

- `docs/RUNBOOK_SMTP_DNS.md`
- `docs/payments/webhook-idempotency.md`
- `docs/payments/final-paid-order-closure-matrix.md`
- `docs/payments/reconciliation.md`
- `docs/payments/order-state-machine.md`
- `docs/payments/benefits.md`
- `docs/commerce/order-state-machine-v0.3.md`
- `docs/commerce/report-snapshot-v1.md`
- `backend/docs/runbooks/email-result-readiness.md`
- `backend/docs/runbooks/alipay-pending-compensation.md`
- `backend/docs/runbooks/alipay-owner-mismatch-review.md`
- `backend/docs/runbooks/alipay-owner-mismatch-controlled-repair.md`
- `backend/docs/commerce/alipay-owner-mismatch-review-03.md`
- `backend/docs/commerce/alipay-owner-mismatch-controlled-repair-04.md`
