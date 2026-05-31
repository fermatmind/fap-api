# ANALYTICS-FUNNEL-REFRESH-DRY-RUN-SQL-FIX-01

## Purpose

Production dry-run for `analytics:refresh-funnel-daily` failed during the benefit grant unlock stage with:

`SQLSTATE[42S22]: Unknown column 'benefit_grants.attempt_id' in 'having clause'`

The dry-run was no-write, and before/after `analytics_funnel_daily` counts remained unchanged. The blocker was query compatibility, not an approved production refresh.

## Fix

`AnalyticsFunnelDailyBuilder::collectUnlockSuccessMap()` now resolves the grant-to-attempt relationship in a subquery and aggregates the outer query by the resolved `attempt_id` alias. The outer `HAVING` clause only checks `MIN(stage_at)`, so it no longer references `benefit_grants.attempt_id` directly in an invalid HAVING context.

## Business Semantics

- `report_unlock` / `unlock_success` remains based on active benefit grants.
- Revoked grants are excluded when `revoked_at` exists.
- Expired grants are excluded when `expires_at` exists.
- Grants without a valid attempt relationship are excluded.
- Attempt relationship resolution still prefers `benefit_grants.attempt_id` and falls back to `orders.target_attempt_id` through `source_order_id` or `order_no`.
- Org/date/scale scoping remains owned by the existing analytics builder flow.

## Explicit Non-actions

- No production refresh was run.
- No non-dry-run refresh was run in production.
- No production DB mutation was performed.
- No migration was added.
- No scheduler change was made.
- No GA/Baidu setting was changed.
- No Search Channel action or URL submission was performed.
- No write guard was added in this PR; that remains a separate follow-up after production dry-run succeeds.

## Validation Focus

The focused regression coverage verifies:

- active benefit grants count as `unlocked_attempts`;
- inactive, expired, and unrelated grants do not count;
- dry-run completes without writing `analytics_funnel_daily`;
- the previous invalid `HAVING COALESCE(benefit_grants.attempt_id...)` query shape is no longer emitted.

## Next Task

After deployment, rerun the bounded production dry-run only:

`ANALYTICS-FUNNEL-CONTROLLED-REFRESH-PROD-DRY-RUN-01-R2`
