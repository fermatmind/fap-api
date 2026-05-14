# Career 80 Total Live Acceptance Report

`career:validate-canonical-80-total-live-acceptance` is a read-only planning/report command for the final Career 80 total acceptance step.

It models the target as:

- 29 already-published baseline slugs
- 51 newly promoted delta slugs
- 80 total public slugs
- 160 expected locale rows for `en,zh`

The command does not execute a live crawl. It reads supplied artifacts and emits the acceptance accounting that a later read-only live acceptance run must satisfy.

## Usage

```bash
php artisan career:validate-canonical-80-total-live-acceptance \
  --target-delta=/tmp/career_80_target_delta_plan.json \
  --delta-manifest=/tmp/career_80_delta_rollout_manifest.json \
  --live-acceptance=/tmp/career_80_total_live_acceptance_run.json \
  --json \
  --output=/tmp/career_80_total_live_acceptance_report.json
```

`--live-acceptance` is optional before the live acceptance run exists. Without it, the report stays `planned` and `accepted=false`.

## Output

The output schema is `career_80_total_live_acceptance.v1`.

Important fields:

- `target_public_total`: expected to be `80`
- `baseline_count`: expected to be `29`
- `delta_count`: expected to be `51`
- `expected_locale_rows`: expected to be `160`
- `accepted`: true only when a supplied live acceptance artifact is accepted and row counts match
- `read_only`: always true
- `writes_database`: always false
- `apply_allowed`: always false
- `live_crawl_executed`: always false

## Non-goals

- No live crawl execution
- No rollout dry-run
- No rollout apply
- No candidate preparation apply
- No DB mutation
- No deployment
- No fap-web changes

The next operational step remains separately gated: run runtime candidate prep dry-run, then approved candidate prep apply, artifact refresh, 51-delta rollout dry-run, approved 51-delta rollout apply, and only then the 80-total live acceptance run.
