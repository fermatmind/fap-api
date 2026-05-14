# Progressive live verification scaling

`career:plan-canonical-progressive-live-verification` creates a read-only chunk plan for large Career cohort live verification.

Supported target totals:

- 300: 300 slugs, 600 locale rows for `en,zh`
- 800: 800 slugs, 1600 locale rows for `en,zh`
- 2786: 2786 slugs, 5572 locale rows for `en,zh`

The command plans chunk output files, final merged result paths, request method limits, and resume state. It does not execute HTTP requests and does not mutate the database.

Guard defaults:

- methods: `GET`, `HEAD`
- request rate: `<= 1` request per second
- timeout: `<= 20` seconds
- retries: `<= 1`
- output paths under `/tmp` by default
- private endpoints are not allowed

Example:

```bash
php artisan career:plan-canonical-progressive-live-verification \
  --target-public-total=300 \
  --slugs=/tmp/career_300_total_slugs.txt \
  --chunk-size=100 \
  --json \
  --output=/tmp/career_300_live_verification_scaling_plan.json
```

Resume planning can consume a partial artifact:

```json
{
  "completed_chunks": [1, 2]
}
```

Then:

```bash
php artisan career:plan-canonical-progressive-live-verification \
  --target-public-total=300 \
  --slugs=/tmp/career_300_total_slugs.txt \
  --chunk-size=100 \
  --resume-from-chunk=3 \
  --partial=/tmp/career_300_live_verification_partial.json \
  --json \
  --output=/tmp/career_300_live_verification_scaling_plan_resume.json
```

Non-goals:

- no live crawl in the planning command
- no rollout dry-run or apply
- no candidate preparation apply
- no backfill, rollback, or quarantine
- no deploy
- no fap-web change

The generated scaling plan feeds later guarded read-only live verification runs and the progressive live acceptance artifact consumed by closeout.
