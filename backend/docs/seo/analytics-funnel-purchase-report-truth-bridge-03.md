# ANALYTICS-FUNNEL-PURCHASE-REPORT-TRUTH-BRIDGE-03

## Executive Summary

This PR freezes the purchase and paid-report analytics truth policy after the backend payment unlock repairs.

The decision is conservative: backend Ops remains the source of truth for `payment_success`, `report_unlock`, and `report_ready`. GA4 is used only as a public funnel reporting auxiliary unless a separate privacy-safe server-side bridge is explicitly approved later. Baidu Tongji remains limited to public traffic and page observation.

Final decision:

`analytics_funnel_purchase_report_truth_bridge_completed_backend_ops_truth_ga4_public_auxiliary`

## Current Gap

The repaired backend funnel can now represent paid report access correctly through:

- orders/payment event/provider reconciliation for payment truth.
- active benefit grants for unlock truth.
- unified access projections plus attempt receipts/results for report-ready truth.
- `analytics_funnel_daily` as the Ops read model after controlled refresh.

GA4 and Baidu can observe parts of the public journey, but neither surface can safely decide whether a user paid, was granted access, or can enter a full paid result. Browser-only analytics can be blocked by consent, route suppression, client failure, duplicate tabs, or network behavior.

## Truth Boundary

| Stage | Backend authority | GA4 role | Baidu role |
| --- | --- | --- | --- |
| `payment_success` | orders/payment events/provider reconciliation | not truth; optional future server-side reporting only | not truth |
| `report_unlock` | active benefit grants | not truth; optional future server-side reporting only | not truth |
| `report_ready` | ready unified access projection with receipt/result | not truth; optional future server-side reporting only | not truth |
| public funnel steps | public runtime and backend events | reporting auxiliary | public traffic/page observation |

Backend `analytics_funnel_daily` remains the Ops reporting truth for paid, unlocked, and report-ready counts.

## GA4 Strategy

GA4 key events should remain focused on public, privacy-safe funnel events:

- `test_start`
- `test_submit`
- `result_view`
- `checkout_start`
- `order_created`

`payment_success`, `report_unlock`, and `report_ready` are conditional only. They must not be treated as browser event truth. If the business later wants these visible in GA4, the next step is a separate server-side Measurement Protocol design and implementation with:

- explicit user approval.
- dry-run and smoke validation.
- privacy-safe payloads only.
- no raw order numbers, result IDs, attempt IDs, user IDs, email, phone, or provider payloads.
- deterministic dedupe event IDs.
- environment gates and rollback instructions.

This PR does not implement Measurement Protocol export.

## Baidu Strategy

Baidu Tongji can observe:

- public page PV/UV/IP.
- public source and entrance pages.
- public landing or test-page element observation where no private identifier is exposed.

Baidu Tongji must not be used for:

- payment truth.
- unlock truth.
- report-ready truth.
- email lookup success truth.
- result access token truth.
- private result/order/pay/share/history route conversion tracking.

## Read Model Policy

`analytics_funnel_daily` remains backend-owned and should continue to map:

- `payment_success` from backend payment truth.
- `report_unlock` from active grant truth.
- `report_ready` from projection-ready report access truth.

No production analytics refresh is run by this PR.

## What Was Not Done

- No production DB mutation.
- No analytics refresh.
- No payment repair.
- No benefit grant creation.
- No payment provider call.
- No CMS mutation.
- No GA4 admin change.
- No Baidu admin change.
- No Measurement Protocol export.
- No Search Channel action.
- No URL submission.
- No deploy.
- No fap-web change.

## Validation

Required focused validation:

```bash
cd backend && php artisan test --filter=AnalyticsFunnelPurchaseReportTruthBridge03 --no-ansi
cd backend && php artisan route:list --no-ansi
cd backend && vendor/bin/pint --test tests/Feature/SeoIntel/AnalyticsFunnelPurchaseReportTruthBridge03Test.php
cd backend && composer validate --strict
cd backend && composer audit --locked --no-interaction --ignore-unreachable
python3 -m json.tool backend/docs/seo/generated/analytics-funnel-purchase-report-truth-bridge-03.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check
git diff --cached --check
```

## Final Decision

`analytics_funnel_purchase_report_truth_bridge_completed_backend_ops_truth_ga4_public_auxiliary`

## Next Task

`ANALYTICS-FUNNEL-GA4-PUBLIC-EVENT-SMOKE-01`
