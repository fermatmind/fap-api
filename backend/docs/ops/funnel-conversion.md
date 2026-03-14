# Funnel & Conversion

This page is the first aggregated commerce-growth insight surface for AIC v1. It is intentionally attempt-led, daily, and operational rather than a full BI or attribution system.

## First-phase stage definitions

Main funnel stages:

- `test_start`
  Source: `attempts.created_at`
  Note: `events.test_start` stays a mirror only.
- `test_submit_success`
  Source priority: `attempts.submitted_at` -> successful `attempt_submissions` -> first `results` timestamp
- `first_result_or_report_view`
  Source: normalized `events`
  Included aliases: `result_view`, `report_view`, and legacy report-view variants such as `report_viewed`
- `order_created`
  Source: earliest `orders.created_at` by `target_attempt_id`
- `payment_success`
  Source priority: `orders.paid_at` -> successful `payment_events` handled/processed timestamp
- `unlock_success`
  Source: active `benefit_grants`
  Rule: paid order status alone does not count as unlock
- `report_ready`
  Source: ready/readable `report_snapshots`
  Rule: `report_jobs` are process telemetry only and never the authority fact

Trailing panels only:

- `pdf_download`
  Source: `events.report_pdf_view`
- `share_generate`
  Source priority: `shares`
- `share_click`
  Source: `events.share_click`, resolved with `share_id`

## Hard facts vs approximate metrics

Hard-fact stages:

- `test_start`
- `order_created`
- `payment_success` when `orders.paid_at` exists
- `unlock_success`
- `report_ready`

Behavioral / approximate stages:

- `first_result_or_report_view`
- `payment_success` when falling back to payment-event handled/processed time
- trailing PDF/share metrics

## Read model refresh

The page reads from `analytics_funnel_daily`.

Refresh command:

```bash
php artisan analytics:refresh-funnel-daily --from=2026-01-01 --to=2026-01-07
```

Supported scope flags:

- `--org=*`
- `--scale=*`
- `--dry-run`

Refresh behavior:

- recompute earliest reliable stage timestamps per attempt
- normalize them into the fixed first-phase funnel order
- aggregate into daily rows by `day`, `org_id`, `scale_code`, and `locale`
- store counts plus `paid_revenue_cents`

## Explicitly out of scope in v1

- `landing_view`
- `paywall_view` as a main-funnel authority stage
- channel attribution
- region comparison as a main control surface
- share-to-purchase attribution
- MBTI overview / type / axis / question analytics
- extra read models outside `analytics_funnel_daily`

## Likely next extensions

- richer locale / region breakouts once taxonomy stabilizes
- separate unlock/share read models if operational demand justifies them
- attribution and paywall analysis only after event taxonomy is unified
