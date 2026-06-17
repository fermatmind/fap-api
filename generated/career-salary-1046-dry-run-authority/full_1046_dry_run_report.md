# Career Salary 1046 Dry-Run Authority Report

Source artifact:
`/Users/rainie/Desktop/GitHub/fap-web/generated/career-salary-v3-6-1046-reader-repair-final-2092/career_job_salary_assets_1046_v3_6_reader_repaired.jsonl`

Command:

```bash
php artisan career:salary-assets-import-preview \
  --file=/Users/rainie/Desktop/GitHub/fap-web/generated/career-salary-v3-6-1046-reader-repair-final-2092/career_job_salary_assets_1046_v3_6_reader_repaired.jsonl \
  --dry-run \
  --all-slugs-from-file \
  --output=/private/tmp/fap-api-fix-api-user-route/generated/career-salary-1046-dry-run-authority/full_1046_dry_run_report.json
```

## Result

- decision: `fail`
- total_jsonl_lines: `2092`
- target_slug_count: `1046`
- validated_preview_rows: `2092`
- expected_preview_rows: `2092`
- raw_rows_included: `false`
- editorial_quality_gate: `2092 / 2092`
- career_job_bundle_authority: `0 / 1046`
- error_count: `4184`

## Interpretation

The importer can now parse and validate the full 1046 asset JSONL in dry-run mode without relying on the staging preview allowlist. The reader-safe projection/editorial gate passes for all 2092 locale rows.

The remaining failure is authority-only: this local fap-api database/cache does not contain the 1046 career job bundle authority rows or runtime publish projection cache, so the gate correctly reports missing occupation rows, runtime projection items, and zh-CN/en detail API readiness for each slug.

No staging rows were written. No production import was attempted. No salary asset content was modified.
