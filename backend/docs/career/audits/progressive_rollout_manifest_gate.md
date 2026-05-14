# Progressive rollout manifest gate

`career:generate-canonical-progressive-rollout-manifest` creates the read-only rollout manifest for progressive Career cohorts after the current cohort has been accepted.

Supported targets:

- 80 to 300: 220 delta slugs, 440 locale rows for `en,zh`
- 300 to 800: 500 delta slugs, 1000 locale rows for `en,zh`
- 800 to 2786: 1986 delta slugs, 3972 locale rows for `en,zh`

The command consumes a progressive target-delta artifact and, optionally, a runtime candidate prep plan artifact. It does not run rollout dry-run, rollout apply, candidate preparation apply, backfill, rollback, quarantine, deploy, or any database mutation.

Example:

```bash
php artisan career:generate-canonical-progressive-rollout-manifest \
  --target-delta=/tmp/career_80_to_300_delta_plan.json \
  --target-public-total=300 \
  --expect-delta-count=220 \
  --json \
  --output=/tmp/career_80_to_300_rollout_manifest.json
```

The generated manifest keeps `apply_allowed=false` and only sets `dry_run_allowed=true` when:

- the source target-delta plan passed
- the current/baseline slug count plus delta slug count equals the target total
- the delta slug count matches the explicit expected count
- no current/baseline slug appears in the delta list
- `rollback_group` is the explicit delta slug list
- expected locale rows equal `delta_slug_count * locale_count`

The manifest remains planning evidence only. A later rollout dry-run must use the explicit `slugs` and `rollback_group` values, and rollout apply remains separately guarded.
