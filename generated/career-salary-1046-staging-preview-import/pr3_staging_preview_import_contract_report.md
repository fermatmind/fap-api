# Career Salary 1046 Staging Preview Import Contract

Generated at: 2026-06-17T04:10:10Z

## Scope

This PR adds a guarded path for importing every slug present in the PASS v3.6 salary asset JSONL into `staging_preview`.

It does not import production data, does not modify the salary asset artifact, and does not create new evidence or estimates.

## Behavior

- `--all-slugs-from-file` remains available for full-file dry-run validation.
- `--force --all-slugs-from-file` is blocked unless `--confirm-full-staging-preview` is also supplied.
- Confirmed full-file staging writes still use `status = staging_preview`, `production_import_allowed = false`, SHA/idempotency reporting, authority gates, and reader-safe projection gates.
- Runtime preview reads are gated by `FAP_CAREER_SALARY_ASSET_PREVIEW_ENABLED` and stored `staging_preview` rows, rather than the static config preview slug list.
- If the preview flag is disabled or no staging row exists, the salary asset endpoint fails closed.

## Local Validation

- `php artisan test tests/Feature/Career/CareerSalaryAssetPreviewImportTest.php` PASS, 18 tests.
- `./vendor/bin/pint --dirty` PASS.
- `php artisan route:list --path=api/v0.5/career/jobs` PASS, 4 routes.
- `php artisan migrate --pretend` PASS, nothing to migrate.
- `bash scripts/ci_verify_mbti.sh` PASS.

## Deferred

The actual 2092-row `--force --all-slugs-from-file --confirm-full-staging-preview` run must execute on staging after this PR is deployed, because local DB fixtures are not the staging career job bundle authority source.
