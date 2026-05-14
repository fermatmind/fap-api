# Career Delta Rollout Gate

`career:plan-canonical-delta-rollout-gate` validates the read-only gate semantics for the 51-delta Career rollout path.

The gate does not run rollout. It converts a passed delta rollout manifest into a checked dry-run plan:

- 29 already-published baseline slugs remain outside the promotion batch.
- 51 delta slugs are the only rollout candidates.
- The total public target remains 80.
- `rollback_group` must be the explicit 51-slug delta list.

## Command

```bash
php artisan career:plan-canonical-delta-rollout-gate \
  --manifest=/tmp/career_80_delta_rollout_manifest.json \
  --target-public-total=80 \
  --expect-delta-count=51 \
  --json \
  --output=/tmp/career_80_delta_rollout_gate.json
```

## Output Shape

```json
{
  "schema_version": "career_delta_rollout_gate.v1",
  "status": "pass",
  "target": "career_80_delta",
  "target_public_total": 80,
  "published_baseline_count": 29,
  "delta_slug_count": 51,
  "expected_delta_locale_rows": 102,
  "validation": {
    "rollback_group_type": "explicit_delta_slug_list",
    "rollback_group_count": 51,
    "rollback_group_matches_delta": true,
    "promotion_scope": "delta_only",
    "total_public_target_validated": true
  },
  "future_rollout_dry_run": {
    "allowed": true,
    "command": "career:execute-canonical-rollout-batch",
    "dry_run_required": true,
    "apply_allowed": false,
    "writes_database": false
  }
}
```

## Blocking Rules

The gate blocks if:

- the manifest is not `career_delta_rollout_manifest.v1`
- the manifest did not pass
- `29 + 51 != 80`
- the delta slug count is not the expected count
- a baseline slug appears in the delta promotion list
- `rollback_group` is missing or does not exactly match the delta slug list
- `expected_delta_locale_rows` does not equal delta slugs times locales
- the manifest does not allow dry-run planning
- `apply_allowed` is anything other than `false`

## Non-goals

- No rollout dry-run execution.
- No rollout apply.
- No DB mutation.
- No candidate preparation apply.
- No export execution.
- No fap-web change.

## Follow-up

The next PR is `REPAIR-80-TOTAL-LIVE-ACCEPTANCE-1`, which models the final 80-total acceptance report for 29 baseline plus 51 promoted delta slugs.
