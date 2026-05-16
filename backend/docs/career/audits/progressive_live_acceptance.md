# Progressive live acceptance

`career:validate-canonical-progressive-live-acceptance` creates a read-only acceptance accounting artifact for progressive Career cohorts after a rollout has been applied and independently verified.

Supported target totals:

- 300: 600 locale rows for `en,zh`
- 800: 1600 locale rows for `en,zh`
- 2786: 5572 locale rows for `en,zh`

The command consumes a progressive target-delta artifact and optional rollout manifest and live acceptance artifact. It validates that the current public cohort plus the explicit delta cohort equals the target total and that any supplied live acceptance artifact reports the expected locale row count.

For the final `2786` target, the command also enforces the product-visible publication gate. A supplied live acceptance artifact cannot pass by partition accounting alone. It must prove:

- public career directory `member_count=2786`
- career jobs index item count `2786`
- detail-ready / `public_detail_indexable_count=2786`
- published locale rows and release-gate pass count `5572`

CN proxy public-owner accounting and software manual-hold accounting may be recorded as context, but they are not accepted as evidence that 2786 public detail pages are visible.

Example:

```bash
php artisan career:validate-canonical-progressive-live-acceptance \
  --target-delta=/tmp/career_80_to_300_delta_plan.json \
  --delta-manifest=/tmp/career_80_to_300_rollout_manifest.json \
  --live-acceptance=/tmp/career_300_live_acceptance.json \
  --json \
  --output=/tmp/career_300_progressive_live_acceptance_plan.json
```

This command does not execute a live crawl, rollout dry-run, rollout apply, candidate preparation apply, backfill, rollback, quarantine, deploy, or database mutation. Large-cohort HTTP verification remains a later guarded run that supplies the live acceptance artifact consumed here.

The command keeps `writes_database=false`, `apply_allowed=false`, `rollout_allowed=false`, and `live_crawl_executed=false`. Passing the accounting artifact means the supplied evidence is internally consistent; it does not replace final live HTML verification.
