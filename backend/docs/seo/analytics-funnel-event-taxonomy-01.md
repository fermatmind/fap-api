# ANALYTICS-FUNNEL-EVENT-TAXONOMY-01

## Purpose

This package defines the canonical FermatMind funnel event taxonomy and the backward-compatible legacy aliases required for `analytics_funnel_daily`.

The implementation keeps production analytics rows untouched. It does not configure GA4 key events, does not change Baidu Tongji settings, does not run a production refresh, and does not mutate CMS or production data.

## Canonical Events

| Event | Definition | Source of truth |
| --- | --- | --- |
| `page_view` | Consented page view. | Browser analytics. |
| `test_start` | A test attempt exists. | `attempts.created_at`. |
| `question_answer` | A user answers a question. | Frontend event stream. |
| `test_submit` | A test submit succeeds. | `attempts.submitted_at`, `attempt_submissions`, or result fallback. |
| `result_view` | A result/report is viewed. | Backend result read events plus accepted legacy frontend aliases. |
| `checkout_start` | User begins checkout flow. | Frontend checkout CTA / provider launch event. |
| `order_created` | Backend order record exists. | `orders.created_at`. |
| `payment_success` | Backend/payment provider confirms paid. | `orders.paid_at` and `payment_events`. |
| `report_unlock` | Benefit/report access is granted. | Active `benefit_grants`. |
| `report_ready` | Report snapshot is ready/readable. | `report_snapshots`. |
| `pdf_download` | User downloads or opens a report PDF. | Report/PDF event aliases. |
| `share_generate` | Share object is created. | `shares`. |
| `share_click` | Share link is clicked. | `events.share_click`. |
| `membership_start` | Membership starts or is attached. | Future membership fact source. |
| `retest_start` | A real retest starts. | Future attempt relation/fact source. |
| `historical_report_revisit` | Saved result/report is revisited. | Future result/history read event. |
| `source_attribution` | Source/channel attribution is attached. | Sanitized tracking payload and backend dimensions. |

## Legacy Alias Mapping

| Legacy event | Canonical event | Handling |
| --- | --- | --- |
| `start_attempt` | `test_start` | Kept for existing frontend dispatch; canonical source remains `attempts.created_at`. |
| `submit_attempt` | `test_submit` | Kept for existing frontend dispatch; canonical source remains backend submit facts. |
| `view_result` | `result_view` | Added to `analytics_funnel_daily` first-view aliases. |
| `create_order` | `order_created` | Kept as legacy order-created observation; do not collapse with checkout start. |
| `begin_checkout` | `checkout_start` | GA4/browser alias only. |
| `payment_confirmed` | `payment_success` | Treat as paid-state observation only when backend confirms paid. |
| `purchase_success` | `payment_success` | GA purchase alias; backend payment facts remain authoritative. |
| `pay_success` | `payment_success` | Big Five alias. |
| `unlock_success` | `report_unlock` | Legacy event alias; canonical fact source is active `benefit_grants`. |
| `clinical_unlock_success` | `report_unlock` | Clinical report unlock alias, scoped to report access. |
| `report_pdf_view` | `pdf_download` | Legacy backend PDF event alias. |
| `pdf_download` | `pdf_download` | Canonical PDF event. |
| `revisit_result` | `historical_report_revisit` | Legacy MBTI result revisit alias. |

## AnalyticsFunnelDailyBuilder Mapping

`AnalyticsFunnelDailyBuilder` preserves the backend fact sources:

- `test_start`: `attempts.created_at`
- `test_submit`: `attempts.submitted_at`, successful `attempt_submissions`, and `results` fallback
- `result_view`: `events.result_view`, `events.view_result`, and report-view variants
- `order_created`: `orders.created_at`
- `payment_success`: `orders.paid_at` and successful `payment_events`
- `report_unlock`: active `benefit_grants`
- `report_ready`: ready/readable `report_snapshots`
- `pdf_download`: `events.pdf_download` and `events.report_pdf_view`
- `share_generate`: `shares`
- `share_click`: `events.share_click`

## Deferred Items

- GA4 key event configuration is deferred until the taxonomy is deployed and verified.
- Production `analytics:refresh-funnel-daily` is deferred because it writes `analytics_funnel_daily`.
- Membership, retest, historical revisit, and source-attribution dashboard metrics need future scoped implementation after this taxonomy baseline.

## Next Task

`ANALYTICS-FUNNEL-OPS-READ-MODEL-REPAIR-01`
