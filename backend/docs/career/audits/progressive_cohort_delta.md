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

## Candidate Preparation Handoff

The runtime candidate preparation planner can consume this artifact directly:

```bash
php artisan career:plan-canonical-runtime-candidate-prep \
  --target-delta=/tmp/career_300_target_delta.json \
  --target-total=300 \
  --cohort=career_80_to_300_delta \
  --locales=en,zh \
  --json \
  --output=/tmp/career_300_runtime_candidate_prep_plan.json
```

For progressive cohorts, the guarded preparation dry-run/apply command must use the reviewed artifact with explicit count guards. The `--max-slugs` value must exactly match the cohort delta:

| Cohort | `--expect-slug-count` | `--max-slugs` |
| --- | ---: | ---: |
| 80 -> 300 | 220 | 220 |
| 300 -> 800 | 500 | 500 |
| 800 -> 2786 | 1986 | 1986 |

The preparation commands still do not select slugs implicitly and have no wildcard or all-2786 mode before the explicit 2786 phase artifact exists.
