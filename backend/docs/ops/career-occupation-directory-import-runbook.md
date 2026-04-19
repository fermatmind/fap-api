# Career Occupation Directory Import Runbook

This runbook covers the China 2026 occupation directory and US O*NET occupation directory candidate package before any CMS or authority-row write.

The package is intentionally a dry-run package. Do not import it as published content, and do not use it to replace backend career authority logic.

## Inputs

Expected package files:

- `career_create_import.jsonl`
- `career_alias_review.csv`
- `career_child_role_review.csv`
- `import_manifest.json`

The package should come from the frontend/offline comparison workflow and must keep these safety fields:

- `import_action=create`
- `dry_run_only=true`
- `governance.publish_state=draft`
- `governance.requires_backend_truth_compute=true`
- `governance.requires_editorial_review=true`

## Dry-Run Validation

Run the backend validator before any CMS upload or database import:

```bash
php artisan career:import-occupation-directory-dry-run \
  --input=/absolute/path/to/career_create_import.jsonl \
  --alias-review=/absolute/path/to/career_alias_review.csv \
  --child-role-review=/absolute/path/to/career_child_role_review.csv \
  --manifest=/absolute/path/to/import_manifest.json \
  --json
```

The command must report:

- `writes_database=false`
- `dry_run_only=true`
- `gate_failure_count=0`
- `authority_duplicate_count=0`
- `proposed_slug_duplicate_count=0`

Stop if any gate, authority-key duplicate, or slug duplicate is reported.

## Draft Staging Plan

After dry-run validation passes, inspect the draft-only staging plan:

```bash
php artisan career:import-occupation-directory-drafts \
  --input=/absolute/path/to/career_create_import.jsonl \
  --alias-review=/absolute/path/to/career_alias_review.csv \
  --child-role-review=/absolute/path/to/career_child_role_review.csv \
  --manifest=/absolute/path/to/import_manifest.json \
  --json
```

This command is also a dry-run by default and must report `writes_database=false`.

The staged destination is limited to backend authority draft tables:

- `occupation_families`
- `occupations`
- `occupation_aliases`
- `occupation_crosswalks`
- `source_traces`

Staged rows use `entity_level=dataset_candidate` and `crosswalk_mode=directory_draft`. They are not compiled into recommendation snapshots and must not appear in public career jobs/search APIs until the normal backend compile and publish-readiness gates approve them.

## Review Queues

Handle these files before a real import command is added or enabled:

- `career_alias_review.csv`: review existing-site matches that may be aliases rather than new occupations.
- `career_child_role_review.csv`: review nested CN new-work-type rows that may belong under a parent occupation rather than as top-level jobs.
- `career_create_import.jsonl`: review every non-`from_existing_match` translation before publishable content generation.

Alias and child-role decisions must be recorded in backend-owned CMS/authority data, not in frontend fallback content.

Export editable review queues after draft staging:

```bash
php artisan career:export-occupation-directory-review-queues \
  --input=/absolute/path/to/career_create_import.jsonl \
  --alias-review=/absolute/path/to/career_alias_review.csv \
  --child-role-review=/absolute/path/to/career_child_role_review.csv \
  --import-run=/staged/import/run/id \
  --output-dir=/absolute/path/to/review-queues \
  --json
```

The generated files are:

- `translation_review_queue.csv`
- `alias_review_decisions.csv`
- `child_role_review_decisions.csv`
- `review_manifest.json`

Reviewers must fill the `review_decision` columns before any publishable compile/import continuation.

Validate review queue progress:

```bash
php artisan career:validate-occupation-directory-review-queues \
  --queue-dir=/absolute/path/to/review-queues \
  --json
```

Use `--allow-pending` only for progress checks while review is still in progress. A publishable continuation must pass without `--allow-pending`.

Apply reviewed decisions only after the CSVs have valid `review_decision` values:

```bash
php artisan career:apply-occupation-directory-review-decisions \
  --queue-dir=/absolute/path/to/review-queues \
  --import-run=/staged/import/run/id \
  --json
```

The command is dry-run by default. Add `--apply` only after the dry-run summary is clean.

Safe apply effects are intentionally narrow:

- `translation_review_queue.csv` with `review_decision=edit` updates the staged occupation's approved title fields.
- `alias_review_decisions.csv` with `review_decision=alias_existing` creates reviewed aliases on the approved target occupation.
- `child_role_review_decisions.csv` with `review_decision=alias_parent` or `child_role` creates reviewed aliases on the approved parent occupation.
- `reject`, `needs_research`, `merge_alias`, `create_separate`, and `create_top_level` are counted but not published by this command.

This command must not compile recommendation snapshots or expose public career pages.

## Write Gate

The write-capable draft staging command must remain blocked unless the operator explicitly passes `--apply`.

If translation, alias, or child-role queues are still pending, `--apply` is blocked unless the operator also passes `--allow-pending-review`. Use that override only to stage draft dataset records for CMS/backend review; it is not publication approval.

A future publishable import or compile step must remain blocked until all of these are true:

1. Dry-run validation passes against the exact package to import.
2. Alias review decisions are complete.
3. Child-role review decisions are complete.
4. Translation/editorial review is complete.
5. Backend truth computation can generate canonical slugs, localized titles, searchable aliases, SEO state, and publication state.
6. Imported records enter as draft or dataset-only records first; public pages must not appear until normal career publish readiness gates pass.

## Repository Rule Impact

This runbook is backend import governance for a CMS/backend-authoritative career content surface. It does not add frontend fallback content, public editorial copy, public API behavior, sitemap enumeration, or runtime page-rendering authority.
