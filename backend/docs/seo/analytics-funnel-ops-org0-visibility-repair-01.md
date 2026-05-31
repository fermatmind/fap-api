# ANALYTICS-FUNNEL-OPS-ORG0-VISIBILITY-REPAIR-01

## Purpose

The controlled production refresh populated `analytics_funnel_daily` global rows with `org_id=0`, but the Ops 7-day funnel widget still treated every non-positive org id as "no org selected." This blocked the refreshed global read model from appearing in the Ops dashboard.

## Change

- `FunnelWidget` now distinguishes tenant context from public/global context.
- Tenant context with `org_id=0` still renders the "select org" empty state.
- Public/global context with `org_id=0` now queries `analytics_funnel_daily` and renders the global funnel rows when they exist.
- Empty global rows now show a read-model-specific message instead of the tenant org selection warning.

`FunnelConversionPage` already scopes through `OrgContext::orgId()` and can query `org_id=0`; focused coverage now locks that behavior.

## Source Of Truth

The Ops funnel continues to read only from `analytics_funnel_daily`.

The repair does not add fallback reads from raw `events`, `v_funnel_daily`, GA, Baidu, CMS, crawler logs, Search Channel, or frontend analytics payloads.

## Canonical Taxonomy

The visible funnel stage labels remain:

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

Legacy labels remain excluded from the Ops read model:

- `test_submit_success`
- `first_result_or_report_view`
- `unlock_success`

## Production Context

The completed controlled refresh produced 38 `analytics_funnel_daily` rows for `2026-05-25` through `2026-05-31` with `org_id=0`.

Known stage totals from the read-only smoke:

- `test_start=382`
- `test_submit=286`
- `result_view=277`
- `order_created=11`
- `payment_success=8`
- `report_unlock=2`
- `report_ready=0`
- `pdf_download=11`
- `share_generate=12`
- `share_click=5`

## Boundaries

This PR did not run an analytics refresh, mutate production DB state, deploy, change CMS content, change GA/Baidu settings, enqueue Search Channel items, submit URLs, or call external search APIs.

## Validation

- `php artisan test --filter=FunnelWidget --no-ansi`
- `php artisan test --filter=FunnelConversionPage --no-ansi`
- `php artisan test --filter=AnalyticsFunnelDailyBuilder --no-ansi`
- `php artisan route:list --no-ansi`
- `vendor/bin/pint --test`
- `composer validate --strict`
- `composer audit --locked --no-interaction --ignore-unreachable`

## Next Task

`BACKEND-DEPLOY-READINESS｜Deploy Ops org_id=0 funnel visibility repair`
