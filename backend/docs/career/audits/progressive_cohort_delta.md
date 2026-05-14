# Career Progressive Cohort Delta Plan

`career:plan-canonical-progressive-cohort-delta` is a read-only planner for the post-80 expansion path:

- 80 -> 300
- 300 -> 800
- 800 -> 2786

It consumes an accepted current cohort closeout artifact plus an explicit target selection artifact, then emits the delta slugs needed to move from the current public total to the target public total.

Example:

```bash
php artisan career:plan-canonical-progressive-cohort-delta \
  --current-closeout=/tmp/career_80_closeout.json \
  --target-selection=/tmp/career_300_target_selection.json \
  --target=300 \
  --locales=en,zh \
  --json \
  --output=/tmp/career_300_target_delta.json
```

The output schema is `career_progressive_cohort_delta_plan.v1` and includes:

- `current_public_total`
- `target_public_total`
- `delta_slug_count`
- `expected_delta_locale_rows`
- `expected_total_locale_rows`
- `current_public_slugs`
- `target_public_slugs`
- `delta_promotion_slugs`
- `recommended_rollout_delta_slugs`
- `writes_database=false`
- `rollout.apply_allowed=false`

Expected cohort arithmetic:

| Current | Target | Delta slugs | Delta locale rows | Total locale rows |
| --- | --- | ---: | ---: | ---: |
| 80 | 300 | 220 | 440 | 600 |
| 300 | 800 | 500 | 1000 | 1600 |
| 800 | 2786 | 1986 | 3972 | 5572 |

This command does not run candidate preparation, rollout dry-run, rollout apply, artifact export, deploy, rollback, quarantine, or DB mutation. Later steps must use the emitted explicit delta slug list and pass their own dry-run/apply guards.
