# Career Salary 1046 Production Import Readiness Runbook

This runbook is the production-readiness gate for FermatMind career salary assets v3.6.

Decision: **READY_FOR_PRODUCTION_IMPORT_APPROVAL** when every checklist item below is satisfied.

This document does not approve or execute production import. Production import requires a later explicit operator approval that names the final asset SHA-256.

## Scope

In scope:

- Read-only production dry-run.
- Compare staging approved rows with the production import target.
- Confirm approval manifest, QA reports, row counts, slug counts, and SHA-256 digests.
- Define database backup, rollback, API canary, frontend canary, and monitoring steps.

Out of scope:

- No production import.
- No salary asset JSONL changes.
- No evidence or estimate changes.
- No frontend fallback content.
- No sitemap, `llms.txt`, canonical, noindex, or JSON-LD changes.

## Required Inputs

Use these artifacts from the completed staging/editorial gates:

- Final reader-safe asset JSONL:
  `career_job_salary_assets_1046_v3_6_reader_repaired.jsonl`
- Final source asset SHA-256:
  `c62c3c5b515034cebcec1a7429b82309092664d6615b01ce64cd02e798ff9dd4`
- Editorial approval manifest:
  `generated/career-salary-v3-6-1046-editorial-review-package/approval_manifest.json`
- Independent QA conclusion:
  `READY_FOR_EXPANDED_STAGING_PREVIEW`
- Staging API smoke:
  `2092/2092` rows readable
- Approved import gate:
  staging/editorial rows may transition to `approved`; production remains untouched

Expected counts:

- row count: `2092`
- slug count: `1046`
- locales: `zh-CN=1046`, `en=1046`
- rejected slugs: `0`

## Stop-Ship Rules

Stop before production import approval if any item is true:

- Approval manifest SHA does not match the retained artifact.
- Final asset SHA is not `c62c3c5b515034cebcec1a7429b82309092664d6615b01ce64cd02e798ff9dd4`.
- Approved staging rows are fewer than `2092`.
- Production target dry-run reports duplicate rows, missing slugs, unsupported status transitions, or source SHA drift.
- Any row has status other than `approved` before production import.
- Any production row would be updated without matching `asset_version=v3.6`, slug, locale, and source artifact SHA.
- Staging approved API differs from the final JSONL projection.
- Production backup is missing or unverified.
- API canary or frontend canary plan is not assigned to an operator.
- The operator has not explicitly approved: `批准 production import 1046 salary assets, using SHA c62c3c5b515034cebcec1a7429b82309092664d6615b01ce64cd02e798ff9dd4`.

## Pre-Approval Checks

Run from the deployed backend checkout after staging approval has completed:

```bash
cd /var/www/fap-api/current/backend

php artisan career:salary-assets-import-preview \
  --approve-staging-preview \
  --approval-manifest=/absolute/path/to/approval_manifest.json \
  --expected-approval-manifest-sha256=<approval_manifest_sha256> \
  --output=/tmp/career-salary-1046-approved-dry-run.json \
  --json
```

Expected:

- `decision=pass`
- `mode=approve_staging_preview_dry_run`
- `approved_slug_count=1046`
- `database_gate.matching_row_count=2092`
- `production_import_allowed=false`
- `production_rows_touched=0`

If approval has not yet been applied, the next approved-state command is:

```bash
php artisan career:salary-assets-import-preview \
  --approve-staging-preview \
  --approval-manifest=/absolute/path/to/approval_manifest.json \
  --expected-approval-manifest-sha256=<approval_manifest_sha256> \
  --confirm-approval-transition \
  --output=/tmp/career-salary-1046-approved-apply.json \
  --json
```

Expected:

- `decision=pass`
- `mode=approve_staging_preview`
- `updated_count=2092`
- `status_policy=approved`
- `production_import_allowed=false`
- `production_rows_touched=0`

## Production Dry-Run Contract

Before a future production importer command is enabled, its dry-run must prove:

- Source asset SHA matches the approved manifest.
- QA report SHA and approval manifest SHA match the retained artifacts.
- The production target operation would affect exactly `2092` rows.
- Every target row has an approved staging source row.
- Every target key is unique by `career_job_slug`, `locale`, and `asset_version`.
- No production row is inserted or updated in dry-run mode.
- Rollback metadata includes import run id, source SHA, approval manifest SHA, and previous production status.

Required dry-run output fields:

- `decision`
- `mode=production_import_dry_run`
- `source_asset_sha256`
- `approval_manifest_sha256`
- `expected_row_count=2092`
- `validated_target_row_count=2092`
- `duplicate_target_key_count=0`
- `approved_source_row_count=2092`
- `production_rows_to_insert`
- `production_rows_to_update`
- `production_rows_touched=0`
- `rollback_plan_available=true`
- `manual_operator_approval_required=true`

## Backup Plan

Before any production write:

1. Capture the deployed Git SHA and release directory.
2. Run a database backup scoped to production.
3. Export current production salary asset rows:

```sql
select *
from career_job_salary_assets
where asset_version = 'v3.6'
  and status = 'production_imported';
```

4. Store backup file SHA-256 in the import report.
5. Confirm rollback owner and communication channel.

The backup must be restorable before import proceeds.

## Rollback Plan

Rollback must be run only by an operator.

Rollback boundaries:

- Rows imported by the future production command must be scoped by a production `import_run_id`.
- Rollback should restore previous production rows from the retained export or move the imported run back out of `production_imported`.
- Staging/editorial/approved rows must not be deleted during production rollback.

Rollback evidence to retain:

- production import run id
- pre-import export path and SHA-256
- post-rollback row counts
- API smoke after rollback
- frontend canary after rollback

## API Canary Plan

Run canary after production import on a small representative set before broad smoke:

- `accountants-and-auditors`
- `air-traffic-controllers`
- `athletes-and-sports-competitors`
- `command-and-control-center-officers`
- `registered-nurses`
- `writers-and-authors`
- `zoologists-and-wildlife-biologists`
- `wind-turbine-technicians`
- `woodworking-machine-setters-operators-and-tenders-except-sawing`
- one final-batch slug from the last 46 rows

For each slug and locale:

```bash
curl -fsS "https://api.fermatmind.com/api/v0.5/career/jobs/${SLUG}/salary-asset?locale=${LOCALE}" \
  | jq '.slug, .locale, .status, .china_recruitment_reference.data_boundary'
```

Expected:

- HTTP 200 for production-imported rows.
- `slug` and `locale` match request.
- No raw enum or internal lineage leaks in reader-facing payload.
- China salary boundary does not state official Chinese occupation wage.
- English locale contains no Chinese text in reader-facing fields.

## Frontend Canary Plan

After API canary passes, run fap-web canary on the same slug set:

- desktop and mobile screenshots
- zh-CN and en pages
- salary block visible only when API returns allowed production status
- fail-closed behavior for 404 or disabled status
- no sitemap, `llms.txt`, canonical, noindex, or JSON-LD changes unless separately approved

## Monitoring Checklist

Monitor for at least the first hour after production import:

- API 5xx rate for career salary endpoint
- API 404 rate for known imported slugs
- frontend render errors on career detail pages
- cache error rate
- database slow queries on `career_job_salary_assets`
- content safety spot checks for China official wage wording, English/Chinese locale leakage, raw enum leakage, and unsupported income claims

## Required Approval Text

Production import must not run until the operator sends this explicit approval:

```text
批准 production import 1046 salary assets, using SHA c62c3c5b515034cebcec1a7429b82309092664d6615b01ce64cd02e798ff9dd4
```

Any different SHA, missing SHA, or ambiguous approval text must stop the import.

## Final Readiness Decision

`READY_FOR_PRODUCTION_IMPORT_APPROVAL`

This means the next step is operator approval for a separate production import execution. It is not itself production import approval.
