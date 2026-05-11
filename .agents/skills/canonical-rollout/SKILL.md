---
name: canonical-rollout
description: Execute career canonical rollout batch promotions from published_candidate to published state. Covers dry-run, apply, entity gate, backfill, post-promotion validation, release gate, rollback, and quarantine.
---

# Canonical Rollout Batch Promotion

## Purpose
Safely promote career canonical occupation batches from `published_candidate` to `published` state in the runtime publish projection. This skill covers the full lifecycle: entity completeness validation, occupation backfill, dry-run planning, DB-mutating apply, post-promotion validation, release gate verification, and remediation (rollback/quarantine).

## When to Use
- Promoting a canonical rollout batch (e.g. Batch-001 with 29 slugs)
- Running `career:execute-canonical-rollout-batch` with `--dry-run` or `--apply`
- Backfilling missing Occupation records before promotion
- Validating post-promotion release gate acceptance
- Rolling back or quarantining a failed batch promotion

## When Not to Use
- For non-canonical slugs (cn-*, software-developers, family, industry, alias-only)
- For blocked or quarantined slugs — only `published_candidate → published`
- When `backend/scripts/ci_verify_mbti.sh` is failing on paths related to your changes
- For ad-hoc DB updates or manual SQL — always use the executor command

## Hard Invariants
- **Do not** manually modify the database (no SQL, no tinker).
- **Do not** bypass the projection (`CareerRuntimePublishProjectionService`).
- **Do not** hardcode sitemap/LLMS inclusions.
- **Do not** promote `cn-*`, `software-developers`, `family`, `industry`, or `alias-only` slugs.
- **Do not** allow partial promotion — rollback_group must match slugs exactly.
- **Do not** execute `--apply` before `--dry-run` passes.
- **Do not** skip the entity completeness gate — all slugs must resolve to Occupation records.
- **Do not** skip post-promotion release gate validation.

## Standard Workflow

### Phase 1 — Pre-flight
```bash
cd backend

# Verify entity completeness
php artisan career:execute-canonical-rollout-batch \
  --batch-id=<batch_id> \
  --slugs=<comma_separated_slugs> \
  --locales=en,zh \
  --rollback-group=<same_slugs> \
  --dry-run --json
```

Expected: `status=planned`, `writes_database=false`, `failures=[]`.

If `status=blocked` with `canonical_occupation_records_missing`: proceed to Phase 2.

### Phase 2 — Occupation Backfill (if needed)
```bash
# Dry-run first
php artisan career:backfill-canonical-occupation-records \
  --slugs=<missing_slugs> \
  --dry-run --json

# Then apply
php artisan career:backfill-canonical-occupation-records \
  --slugs=<missing_slugs> \
  --apply --json
```

Expected: `creatable_count=N`, `created_count=N`, `failures=[]`. Idempotent — re-run safe.

### Phase 3 — Apply Promotion
```bash
php artisan career:execute-canonical-rollout-batch \
  --batch-id=<batch_id> \
  --slugs=<comma_separated_slugs> \
  --locales=en,zh \
  --rollback-group=<same_slugs> \
  --apply --quarantine-on-failure --json
```

Expected: `status=promoted_success`, `writes_database=true`, `write_verified=true`.

### Phase 4 — Post-Promotion Validation
```bash
php artisan career:export-runtime-publish-projection --json
php artisan career:export-canonical-runtime-truth --json
php artisan career:validate-canonical-runtime-truth --json
php artisan career:finalize-canonical-runtime-truth --json
php artisan career:validate-canonical-batch-live-acceptance \
  --batch-id=<batch_id> --slugs=<slugs> --locales=en,zh --json
```

Expected: `published` increased by batch size, `published_candidate` decreased, `surface_equality=pass`, `release_gate.blocked=0`, `accepted=true`.

### Phase 5 — Rollback (if validation fails)
```bash
# The --quarantine-on-failure flag handles this automatically.
# Manual rollback (if needed):
php artisan career:execute-canonical-rollout-batch \
  --batch-id=<batch_id> \
  --slugs=<slugs> \
  --locales=en,zh \
  --rollback-group=<same_slugs> \
  --apply --json
```

## Acceptance Commands
```bash
cd backend
./vendor/bin/pint --test <changed_files>
php artisan test --filter=CanonicalBatchPromotionExecutor
php artisan career:execute-canonical-rollout-batch --help
php artisan career:backfill-canonical-occupation-records --help
php artisan career:validate-canonical-batch-live-acceptance --help
```

## Output Contract
Every execution must return:
- `status`: `planned` | `promoted_success` | `blocked` | `promotion_write_not_persisted` | `failed_and_quarantined` | `failed_and_rolled_back`
- `writes_database`: boolean (true only when DB mutations verified)
- `write_verified`: boolean
- `failures`: list of failure objects with `reason` and `context`
- `remediation` (if attempted): `mode`, `status`, `succeeded`

## Stop Conditions
- Dry-run returns `status=blocked`
- Entity gate reports `canonical_occupation_records_missing` and backfill dry-run returns `creatable_count=0`
- Post-promotion release gate returns `closeout_allowed=false`
- `promotion_write_not_persisted` — no partial closeout allowed
- Any `quarantine_write_not_persisted` or `rollback_write_not_persisted`
