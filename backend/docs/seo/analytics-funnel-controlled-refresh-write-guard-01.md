# ANALYTICS-FUNNEL-CONTROLLED-REFRESH-WRITE-GUARD-01

## Purpose

Add a fail-closed write guard around `analytics:refresh-funnel-daily` before any real production refresh is approved.

The prior production R3 dry-run completed successfully for `2026-05-25` through `2026-05-31`, `org=0`, with `attempted_rows=38`, `deleted_rows=0`, and `upserted_rows=0`. This PR does not perform the real refresh. It only makes the command safe enough for a later human-approved controlled write.

## Guard Behavior

Dry-run mode remains the default safe review path:

```bash
php artisan analytics:refresh-funnel-daily \
  --from=2026-05-25 \
  --to=2026-05-31 \
  --org=0 \
  --dry-run \
  --no-ansi
```

Non-dry-run refresh is blocked unless all write boundaries are explicit:

- `--from` and `--to` must both be supplied.
- At least one `--org` scope must be supplied.
- The inclusive date range must be 31 days or less.
- `--confirm-write` must exactly match the generated confirmation token.

For the R3 production scope, the later write command would require:

```bash
php artisan analytics:refresh-funnel-daily \
  --from=2026-05-25 \
  --to=2026-05-31 \
  --org=0 \
  --confirm-write=analytics_funnel_daily:write:2026-05-25:2026-05-31:org=0:scale=all \
  --no-ansi
```

## Audit Output

The command now prints:

- `before_count`
- `after_count`
- `attempted_rows`
- `deleted_rows`
- `upserted_rows`
- `write_guard`
- blocked guard reason and expected confirmation token when applicable

This makes the write boundary reviewable before and after a later production refresh.

## Explicit Non-actions

- No production refresh write was run.
- No production DB mutation was performed.
- No migration was added.
- No scheduler was enabled.
- No GA/Baidu setting was changed.
- No CMS mutation was performed.
- No Search Channel enqueue or URL submission was performed.
- No frontend repository was modified.

## Next Task

`ANALYTICS-FUNNEL-CONTROLLED-REFRESH-WRITE-PREFLIGHT-01`

Run one more production dry-run with the deployed write guard, verify the expected confirmation token and counts, then ask for explicit human approval before a real write.
