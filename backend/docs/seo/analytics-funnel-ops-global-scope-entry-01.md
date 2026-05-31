# ANALYTICS-FUNNEL-OPS-GLOBAL-SCOPE-ENTRY-01

## Summary

This change adds an explicit read-only global scope entry to the Ops funnel conversion page.

The post-deploy smoke found that `analytics_funnel_daily` had valid `org_id=0` rows, but the browser UI was still scoped to the selected tenant organization. The page therefore displayed an empty state even though the global read model was populated.

## Behavior

- `Current organization` keeps the existing behavior and reads `analytics_funnel_daily` for the selected Ops organization.
- `Global org_id=0` reads only `analytics_funnel_daily.org_id = 0`.
- `?scope=global`, `?scope=global_org0`, and `?scope=org0` are normalized to the global read-only scope.
- The global scope does not mutate `ops_org_id` session state or the selected organization cookie.

## Authority Boundary

The page continues to use `analytics_funnel_daily` as the read model source of truth. It does not fall back to raw events, `v_funnel_daily`, frontend state, analytics dashboards, or local files.

## Not Done

- No analytics refresh was run.
- No production DB write was performed.
- No CMS mutation was performed.
- No GA/Baidu setting was changed.
- No Search Channel or URL submission action was performed.
- No deployment was performed in this PR.

## Next Task

Deploy the backend change, then rerun a browser read-only smoke on `/ops/funnel-conversion?scope=global_org0`.
