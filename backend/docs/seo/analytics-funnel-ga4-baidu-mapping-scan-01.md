# ANALYTICS-FUNNEL-GA4-BAIDU-MAPPING-SCAN-01

## Executive Summary

This artifact freezes the analytics alignment boundary between FermatMind backend funnel truth, GA4 key events, and Baidu Tongji observability.

Backend `analytics_funnel_daily` is the authority for payment, unlock, and report readiness. GA4 and Baidu are reporting surfaces only. They must not be used to decide whether an order is paid, whether a benefit grant exists, whether a result access projection is ready, or whether a user can enter a paid report.

The post-repair backend truth currently expects the paid funnel to be interpreted through:

- `payment_success`: paid order/payment truth.
- `report_unlock`: active paid benefit grant truth.
- `report_ready`: ready unified access projection plus required receipt/result readiness.

GA4 can be configured as a key-event reporting surface for public and privacy-safe funnel stages. Baidu Tongji can support page/source/entrance visibility and selected public-page conversion reporting, but it must not become purchase/report truth.

## Authority Boundary

| Surface | Role | May decide payment/unlock/report truth? | Notes |
| --- | --- | --- | --- |
| Backend orders/payment events/provider reconciliation | Payment truth | yes | Includes Alipay return/webhook/provider-query compensation and order state. |
| Backend benefit grants | Unlock truth | yes | Active grant is the unlock authority. |
| Backend unified access projections and attempt receipts | Report entry truth | yes | `access_state=ready` and `report_state=ready` define report-ready access. |
| `analytics_funnel_daily` | Analytics read-model truth | yes for Ops analytics | Derived from backend-owned facts after controlled refresh. |
| GA4 browser events | Reporting/attribution | no | Consent-gated and privacy-route constrained. |
| Baidu Tongji | Reporting/traffic observation | no | Public traffic/source/entrance only; do not use for private payment chain truth. |
| Frontend local state | UX state | no | May render based on backend API response but must not be authority. |

## Canonical Event Mapping

| Backend canonical event | Backend truth source | GA4 event target | GA4 key event recommendation | Baidu treatment | Notes |
| --- | --- | --- | --- | --- | --- |
| `page_view` | Public runtime page view | `page_view` | no | page PV only | Suppress private routes and sensitive query params. |
| `test_start` | Attempt creation / accepted start | `test_start` | yes | public event observation only | Legacy frontend alias: `start_attempt`. |
| `question_answer` | Answer interaction | `question_answer` | no | no conversion | High-volume diagnostic event. |
| `test_submit` | Attempt submitted/scored | `test_submit` | yes | public event observation only | Legacy frontend alias: `submit_attempt`. |
| `result_view` | First result or report view | `result_view` | yes | public page observation only | Legacy frontend alias: `view_result`. |
| `checkout_start` | User begins checkout flow | `checkout_start` | yes | no private conversion | UI intent; not payment truth. |
| `order_created` | Backend order exists | `order_created` | optional | no private conversion | Backend truth begins here. |
| `payment_success` | Paid order/payment event/provider confirmation | `payment_success` | backend/Ops truth; GA4 only if privacy-safe bridge exists | no | Do not trust browser-only GA4 for paid state. |
| `report_unlock` | Active benefit grant | `report_unlock` | backend/Ops truth; GA4 only if privacy-safe bridge exists | no | Do not infer from frontend click. |
| `report_ready` | Ready projection/receipt/result access | `report_ready` | backend/Ops truth; GA4 only if privacy-safe bridge exists | no | Current paid report access truth. |
| `pdf_download` | Report PDF/download action | `pdf_download` | optional | no private conversion | Must avoid exposing private identifiers. |
| `share_generate` | Share link generated | `share_generate` | optional | no private conversion | Privacy-gated. |
| `share_click` | Share link clicked | `share_click` | optional | public/share only | Avoid token leakage. |

## GA4 Key Event Setup

Recommended GA4 key events:

- `test_start`
- `test_submit`
- `result_view`
- `checkout_start`
- `order_created`

Conditional GA4 key events:

- `payment_success`
- `report_unlock`
- `report_ready`

The conditional events should be enabled only after a privacy-safe bridge is approved. Until then, Ops/`analytics_funnel_daily` remains the reporting truth for paid, unlocked, and report-ready counts.

## Baidu Tongji Boundary

Baidu Tongji is suitable for:

- Public page PV/UV/IP observation.
- Source, entrance page, and visited-page diagnostics.
- Public landing/test-page conversion observation where no private route or identifier is exposed.

Baidu Tongji is not suitable for:

- Payment success truth.
- Unlock truth.
- Report-ready truth.
- Result access token truth.
- Email lookup success truth.
- Private route conversion tracking.

Existing browser inspection also showed Baidu basic conversion settings state that new TrackEvent event conversions are no longer supported after 2022-04-12. This further limits Baidu to traffic and public-page observation for this funnel.

## Privacy And Suppression Requirements

GA4/Baidu collection must preserve the current privacy contract:

- Suppress private route families such as result, orders, pay, payment, share, and history where configured.
- Suppress sensitive query keys such as order, transaction, payment, result, attempt, report, and token identifiers.
- Do not output raw order numbers, result IDs, attempt IDs, emails, phone numbers, provider payloads, or user IDs into third-party analytics.
- Do not use browser analytics to repair orders, grants, or projections.

## Current Gap

Backend analytics is now aligned around canonical events and repaired report-ready truth. Frontend outbound events still include legacy names such as `start_attempt`, `submit_attempt`, `view_result`, `click_unlock`, and `create_order`. Those aliases are accepted by the backend, but GA4 dashboards should receive stable canonical names.

The next frontend PR should align browser-dispatched event names to the backend taxonomy while keeping backend aliases for compatibility.

## Recommended Next PRs

1. `ANALYTICS-FUNNEL-WEB-EVENT-NAME-ALIGNMENT-02`
   - Repository: `fap-web`
   - Scope: align outbound browser analytics event names to backend canonical taxonomy.
   - Do not change backend payment truth.

2. `ANALYTICS-FUNNEL-PURCHASE-REPORT-TRUTH-BRIDGE-03`
   - Repository: `fap-api`
   - Scope: freeze the paid/report truth bridge policy.
   - Recommended policy: backend Ops remains purchase/report truth; GA4 remains public-funnel auxiliary unless a separate privacy-safe server-side export is explicitly approved.

## What Was Not Done

- No GA4 admin setting was changed.
- No Baidu Tongji setting was changed.
- No production analytics refresh was run.
- No production database mutation was performed.
- No payment repair or provider call was performed.
- No CMS mutation, Search Channel action, URL submission, or deploy was performed.

## Final Decision

`analytics_funnel_ga4_baidu_mapping_scan_completed_ready_for_web_event_alignment`

## Next Task

`ANALYTICS-FUNNEL-WEB-EVENT-NAME-ALIGNMENT-02`
