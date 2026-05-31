# ANALYTICS-FUNNEL-REFRESH-DRY-RUN-SQL-FIX-02

## Purpose

Production dry-run for `analytics:refresh-funnel-daily` advanced beyond the earlier benefit grant unlock blocker after SQL-FIX-01, then failed in the `share_click` stage with:

`SQLSTATE[42S22]: Unknown column 'events.attempt_id' in 'having clause'`

The dry-run was no-write, and `analytics_funnel_daily` before/after counts remained unchanged.

## Fix

`AnalyticsFunnelDailyBuilder::collectShareClickMap()` now resolves the share click attempt relationship in a subquery before aggregation.

The source subquery selects:

- resolved `attempt_id` from `events.attempt_id` or `shares.attempt_id`;
- `events.occurred_at` as `stage_at`;
- only share click aliases;
- the requested org scope.

The outer query then groups by the resolved `attempt_id` alias and filters on `MIN(stage_at)`. It no longer references `events.attempt_id`, `shares.attempt_id`, or `COALESCE(events.attempt_id, shares.attempt_id)` directly in `HAVING`.

## Similar Query Sweep

The focused regression coverage captures generated builder SQL and asserts that table-qualified attempt-id expressions are not emitted in `HAVING` for the known compatibility-sensitive paths:

- `events.attempt_id`
- `shares.attempt_id`
- `benefit_grants.attempt_id`
- `orders.target_attempt_id`

Existing SQL-FIX-01 coverage for `benefit_grants` remains in place.

## Business Semantics

- `share_click` still counts valid share click events tied to a valid attempt.
- If `events.attempt_id` is present, it is used.
- If `events.attempt_id` is absent but `shares.attempt_id` is available through `events.share_id`, fallback remains valid.
- Unrelated share click events without a valid attempt relationship do not count.
- Org/date scope remains preserved.
- No analytics schema change was made.

## Explicit Non-actions

- No production refresh was run.
- No non-dry-run refresh was run in production.
- No production DB mutation was performed.
- No migration was added.
- No scheduler change was made.
- No GA/Baidu setting was changed.
- No Search Channel action or URL submission was performed.
- No write guard behavior was added; that remains a later PR.

## Next Task

After deployment, rerun the bounded production dry-run only:

`ANALYTICS-FUNNEL-CONTROLLED-REFRESH-PROD-DRY-RUN-01-R3`
