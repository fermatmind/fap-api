---
name: career-occupation-backfill
description: Idempotently backfill missing Occupation records from canonical career baseline metadata. Required prerequisite for canonical rollout promotion when entity gate blocks on missing occupations.
---

# Career Occupation Record Backfill

## Purpose
Create missing `Occupation` records for canonical slugs that exist in career baselines but lack rows in the `occupations` database table. This is a prerequisite for canonical rollout batch promotion — the entity completeness gate blocks any batch with missing Occupation records.

## When to Use
- Entity gate reports `canonical_occupation_records_missing` with specific slugs
- Before running `career:execute-canonical-rollout-batch --apply`
- When new canonical slugs need Occupation records for index state writes

## When Not to Use
- When the baseline files are missing or unreadable — fix the deployment first
- When slugs are not in the zh-CN career baseline (`content_baselines/career_jobs/career_jobs.zh-CN.json`)
- For slugs that should NOT have Occupation records (blocked, quarantined)
- As a general-purpose data import tool — use `career:import-occupation-directory-drafts` instead

## Hard Invariants
- **Do not** create Occupation records for slugs absent from the zh-CN baseline.
- **Do not** fabricate Chinese titles — they must come from the baseline.
- **Do not** write index_states or change publish state.
- **Do not** hardcode per-slug metadata.
- **Do not** publish pages — this only creates entity authority records.
- **Do not** skip `--dry-run` before `--apply`.
- **Do not** use slug-derived English titles when zh-CN baseline has no entry for the slug.

## Standard Workflow

### Step 1 — Dry-Run
```bash
cd backend
php artisan career:backfill-canonical-occupation-records \
  --slugs=<comma_separated_slugs> \
  --dry-run --json
```

Check output:
- `existing_count` — slugs that already have Occupation records
- `creatable_count` — slugs with complete metadata (zh+en titles)
- `missing_required_metadata` — slugs that cannot be created and why

### Step 2 — Apply
```bash
# Only if creatable_count > 0
php artisan career:backfill-canonical-occupation-records \
  --slugs=<comma_separated_slugs> \
  --apply --json
```

Expected: `status=applied`, `created_count=creatable_count`, `failures=[]`.

### Step 3 — Verify
```bash
# Re-run dry-run to confirm idempotency
php artisan career:backfill-canonical-occupation-records \
  --slugs=<same_slugs> \
  --dry-run --json
# Expected: existing_count=all, creatable_count=0
```

## Metadata Source Resolution
English titles are resolved in priority order:
1. `content_baselines/career_jobs/career_jobs.en.json` (only 36 jobs)
2. Batch manifests (`docs/career/batches/batch_*_manifest.json`) `canonical_title_en`
3. Slug-derived: `compliance-officers` → "Compliance Officers"

Slug-derived English is ONLY used when the zh-CN baseline contains the slug.
Output includes `title_en_source` per slug for traceability.

## Acceptance Commands
```bash
cd backend
php artisan career:backfill-canonical-occupation-records --help
php artisan career:backfill-canonical-occupation-records --slugs=<slugs> --dry-run --json
```

## Output Contract
- `status`: `planned` | `applied` | `blocked`
- `requested_count`, `existing_count`, `creatable_count`, `created_count`
- `missing_required_metadata`: list with `slug`, `reason`, source booleans
- `occupation_slugs.existing`, `.creatable`, `.created`, `.failed`
- `title_en_source` and `title_zh_source` per slug

## Stop Conditions
- Dry-run returns `creatable_count=0` with all slugs in `missing_required_metadata`
- Slug not found in zh-CN baseline (`reason: not_found_in_baseline`)
- Slug has zh title but no English source and slug-derivation is unavailable
