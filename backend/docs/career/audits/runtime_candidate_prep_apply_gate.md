# Career Runtime Candidate Preparation Apply Gate

`career:prepare-canonical-runtime-candidates` is the guarded dry-run/apply command for preparing the 51 Career 80 delta slugs as runtime `published_candidate` inventory.

The command consumes either:

- `--plan=/tmp/career_80_delta_runtime_candidate_prep_plan.json`
- `--slug-artifact=/tmp/<reviewed-explicit-slugs>.json`

It never selects slugs implicitly and has no all-2786 mode. The source artifact is hashed with SHA-256 and apply requires that hash to be confirmed.

## Usage

Dry-run:

```bash
php artisan career:prepare-canonical-runtime-candidates \
  --plan=/tmp/career_80_delta_runtime_candidate_prep_plan.json \
  --dry-run \
  --batch-id=career_80_delta_candidate_prep_001 \
  --reason=career_80_delta_runtime_candidate_prep \
  --expect-slug-count=51 \
  --json \
  --output=/tmp/career_80_delta_runtime_candidate_prep_dry_run.json
```

Apply, only after explicit production approval:

```bash
php artisan career:prepare-canonical-runtime-candidates \
  --plan=/tmp/career_80_delta_runtime_candidate_prep_plan.json \
  --apply \
  --batch-id=career_80_delta_candidate_prep_001 \
  --reason=career_80_delta_runtime_candidate_prep \
  --expect-slug-count=51 \
  --confirm-artifact-sha256=<reviewed_sha256> \
  --max-slugs=100 \
  --json \
  --output=/tmp/career_80_delta_runtime_candidate_prep_apply.json
```

## Guards

- Exactly one of `--plan` or `--slug-artifact` is required.
- Exactly one of `--dry-run` or `--apply` is required.
- `--batch-id` and `--reason` are required.
- `--apply` requires `--confirm-artifact-sha256`.
- `--apply` requires `--expect-slug-count`.
- `--max-slugs` defaults to 100.
- Duplicate, empty, wildcard, or missing slug lists block.
- Missing `Occupation` rows block before writes.

## Output

Dry-run outputs:

- `status`
- `dry_run=true`
- `writes_database=false`
- `slug_count`
- `expected_locale_rows`
- `planned_writes`
- `planned_write_count`
- `blockers`
- `artifact_sha256`
- `approval_phrase_template`

Apply outputs:

- `status`
- `dry_run=false`
- `writes_database=true`
- `write_verified`
- `created_count`
- `verified_count`
- `failures`
- `artifact_sha256`

The apply path writes `promotion_candidate` index-state rows with `index_eligible=true`. In the current runtime projection semantics, that is the persisted authority that prepares a hidden pre-route `published_candidate` row. It does not promote the slug to `published`.

## Non-goals

- No rollout dry-run.
- No rollout apply.
- No backfill.
- No rollback or quarantine.
- No promotion to `published`.
- No deploy.
- No fap-web change.
