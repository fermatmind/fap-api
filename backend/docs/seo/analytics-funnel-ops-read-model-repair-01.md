# ANALYTICS-FUNNEL-OPS-READ-MODEL-REPAIR-01

## Purpose

Connect the Ops 7-day funnel snapshot to the unified analytics funnel read model introduced by `ANALYTICS-FUNNEL-EVENT-TAXONOMY-01`.

## Root Cause

The full `/ops/funnel-conversion` page already used `analytics_funnel_daily`, but several display labels still exposed legacy stage names. The Ops dashboard `FunnelWidget` still read old `v_funnel_daily` or raw `events` rows with hardcoded legacy event names, so the 7-day snapshot could not be treated as the unified business funnel.

## Repair

- `FunnelWidget` now uses `analytics_funnel_daily` as the 7-day read model truth.
- `v_funnel_daily` and raw `events` are no longer primary Ops 7-day funnel authority.
- Widget labels now use canonical taxonomy stage names:
  - `test_start`
  - `test_submit`
  - `result_view`
  - `order_created`
  - `payment_success`
  - `report_unlock`
  - `report_ready`
  - `pdf_download`
  - `share_generate`
  - `share_click`
- `/ops/funnel-conversion` keeps its existing read model query but displays canonical labels for `test_submit`, `result_view`, and `report_unlock`.

## Empty State

When the selected org has no `analytics_funnel_daily` rows in the 7-day range, the widget reports:

`No analytics_funnel_daily rows for this range. Run analytics:refresh-funnel-daily in a controlled task.`

It does not silently fall back to raw events and present them as canonical funnel data.

## Deferred Stages

The following funnel stages remain future or unavailable until the read model supports them explicitly:

- `page_view` / visit
- `checkout_start`
- `membership_start`
- `retest_start`
- `historical_report_revisit`

## Safety

- No production analytics refresh was run.
- No production DB mutation occurred.
- No migration was added.
- No scheduler behavior changed.
- No GA or Baidu Tongji admin setting changed.
- No Search Channel action or URL submission occurred.
- `fap-web` was not modified.

## Next Task

`ANALYTICS-FUNNEL-CONTROLLED-REFRESH-PREFLIGHT-01`
