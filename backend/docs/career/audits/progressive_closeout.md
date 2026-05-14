# Progressive cohort closeout

`career:closeout-canonical-progressive-cohort` creates the read-only closeout record for an accepted Career cohort.

Supported closeout targets:

- 80: next action `300_READINESS_1`
- 300: next action `800_READINESS_1`
- 800: next action `2786_READINESS_1`
- 2786: next action `CAREER_2786_FINAL_CLOSEOUT_COMPLETE`

Example:

```bash
php artisan career:closeout-canonical-progressive-cohort \
  --live-acceptance=/tmp/career_300_progressive_live_acceptance.json \
  --baseline-slugs=/tmp/career_80_total_slugs.txt \
  --delta-slugs=/tmp/career_80_to_300_delta_slugs.txt \
  --total-slugs=/tmp/career_300_total_slugs.txt \
  --json \
  --output=/tmp/career_300_closeout.json
```

The closeout artifact records:

- target public total
- baseline and delta counts
- expected locale rows
- accepted total slug artifact path
- live acceptance artifact path
- release gate and surface summary evidence
- sidecars carried by the acceptance artifact

The command refuses closeout when the live acceptance artifact is not `status=pass`, `accepted=true`, and `writes_database=false`, when failures are present, when baseline plus delta does not equal the target total, or when the total slug artifact path is missing.

It does not run readiness, candidate preparation, rollout dry-run, rollout apply, live crawl, backfill, rollback, quarantine, deploy, or any database mutation.
