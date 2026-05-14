# Career 80 Target Delta Decomposition

The Career 80 target is not 80 new promotion candidates. It is the sum of:

- 29 already-published baseline slugs.
- 51 delta promotion slugs.

`career:plan-canonical-80-target-delta` produces a read-only artifact that makes this accounting explicit before runtime candidate preparation, delta manifests, rollout dry-runs, or live acceptance.

## Usage

```bash
php artisan career:plan-canonical-80-target-delta \
  --readiness=/tmp/career_2786_80_readiness_plan.json \
  --delta-slugs=/tmp/career_2786_minimum_index_state_slugs_for_80.json \
  --runtime-pool=/tmp/career_2786_80_runtime_candidate_pool_plan.json \
  --target=80 \
  --json \
  --output=/tmp/career_80_target_delta_plan.json
```

## Output

The output schema is `career_80_target_delta.v1` and includes:

- `target_public_total`
- `published_baseline_count`
- `delta_promotion_count`
- `previous_80_selected_count`
- `published_baseline_slugs`
- `delta_promotion_slugs`
- `recommended_rollout_delta_slugs`
- validation evidence and blockers

The command is read-only. It never queries production, mutates the database, generates a manifest, runs rollout dry-run, or applies rollout.

## Gates

A passing target decomposition only allows the next read-only planning steps: runtime candidate preparation planning and 51-delta rollout manifest generation. The manifest promotes only the 51 delta slugs and keeps the 29 already-published slugs as baseline accounting. It does not approve candidate preparation apply, rollout dry-run, rollout apply, deploy, live crawl, or publication expansion.

## Non-goals

- No runtime candidate preparation.
- No database writes.
- No manifest generation.
- No rollout dry-run or rollout apply.
- No fap-web changes.
