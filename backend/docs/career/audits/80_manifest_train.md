# Career 80 Manifest Train

`career:generate-canonical-expansion-manifest-train` is a read-only planning command for turning a passed Career 80 cohort readiness artifact into a manifest train artifact.

It consumes `/tmp/career_2786_80_readiness_plan.json` and emits `/tmp/career_2786_80_manifest_train.json`. It does not query the database, mutate state, run rollout dry-runs, run rollout apply, publish occupations, deploy, or touch `fap-web`.

## Usage

```bash
php artisan career:generate-canonical-expansion-manifest-train \
  --readiness=/tmp/career_2786_80_readiness_plan.json \
  --target=80 \
  --json \
  --output=/tmp/career_2786_80_manifest_train.json
```

Options:

- `--readiness=`: required Career cohort readiness JSON artifact.
- `--target=`: cohort size, default `80`.
- `--batch-id=`: optional batch id. The default for target 80 is `career_80_canonical_001`.
- `--locales=`: comma-separated locale list, default `en,zh`.
- `--json`: emits the manifest train artifact to stdout.
- `--output=`: optional JSON artifact path.
- `--strict`: fails on ambiguous selected rows.

## Output Schema

The artifact schema is `career_canonical_expansion_manifest_train.v1`.

Important fields:

- `status`: `pass` or `blocked`.
- `target`: selected cohort size.
- `source_readiness`: readiness artifact path and readiness pass evidence.
- `manifest_count`: number of generated manifest batches.
- `selected_count`: selected slug count.
- `batch_id`: stable batch id for the first 80 cohort.
- `rollback_group`: explicit selected slug list.
- `batches[].slugs`: explicit selected slug list for the batch.
- `batches[].rollback_group`: explicit slug-list rollback group, never only a batch id.
- `batches[].expected_locale_rows`: selected slug count multiplied by locale count.
- `dry_run_allowed`: true only when the manifest train is valid for the next read-only rollout dry-run step.
- `apply_allowed`: always false.

`status=pass` means the next step can be a separately controlled read-only rollout dry-run. It does not approve rollout apply or publication expansion.

## Safety Rules

The command blocks when:

- the readiness artifact is missing or malformed;
- `readiness_pass` is not true;
- `rollout.manifest_generation_allowed` is not true;
- `rollout_candidate_gate.required=true` and `rollout_candidate_gate.eligible_count` is below the target;
- any selected row is marked `rollout_candidate_eligible=false`;
- `selected_count` is below the requested target;
- selected slugs are missing or duplicated;
- locales are empty;
- the output path is not writable.

The manifest train trusts only selected rows that passed the readiness command's rollout-candidate gate. A slug that is already published, already publicly exposed, missing projection/truth evidence, blocked in runtime projection, or otherwise not in `published_candidate` pre-promotion state must be excluded before manifest generation.

The command always reports:

- `read_only=true`;
- `writes_database=false`;
- `rollout_allowed=false`;
- `rollout_dry_run_executed=false`;
- `rollout_apply_executed=false`;
- `apply_allowed=false`.

## Non-goals

- No DB mutation.
- No rollout dry-run execution.
- No rollout apply.
- No backfill, rollback, or quarantine.
- No deploy.
- No live crawl.
- No fap-web change.
- No 300/800/2786 expansion execution.
