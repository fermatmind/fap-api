# Career Public Resolution Source Resolver

AUDIT-2 adds a read-only resolver contract for the external Career 2786 public-resolution planner JSON. The resolver is intended for future AUDIT-3+ inventory checks and AUDIT-9 command integration. It does not run the canonical eligibility audit, query the database, backfill occupations, modify rollout state, write files, deploy, or claim 2786 readiness.

## Purpose

The Career full release ledger pipeline already accepts an external planner artifact for the 2786-row public-resolution model. AUDIT-2 makes that planner input explicit and reusable by validating the planner shape, normalizing rows, preserving raw row data, and returning structured issues instead of command-only exception strings.

## Resolver Contract

Primary API:

```php
CareerPublicResolutionPlanResolver::fromPath(
    string $path,
    ?int $expectedRows = null,
): CareerPublicResolutionPlanValidationResult
```

The resolver:

- accepts an explicit file path
- reads JSON from that path only
- validates that the file exists and JSON is parseable
- extracts planner rows from a supported row path
- normalizes rows into `CareerPublicResolutionPlanRow`
- validates canonical slug presence and uniqueness
- validates required planner fields used by the existing full release ledger planner path
- validates expected row count when the caller supplies `expectedRows`
- returns `pass`, `fail`, or `blocked`
- does not query DB, write files, mutate rollout state, or require the production planner artifact in tests

## Supported Input Shapes

AUDIT-2 supports these row-list locations:

- `$.rows`
- `$.workbook.rows` when it is a JSON array
- `$.occupations`
- `$.assets`

The current full release ledger command uses this production-oriented shape:

```json
{
  "workbook": {
    "path": "/absolute/path/to/career_full_upload_repaired.xlsx",
    "sha256": "source-workbook-sha",
    "sheet": "Career_Assets_v4_1",
    "rows": 2786
  },
  "rows": []
}
```

In that shape, `workbook.rows` is a declared row count and `rows` is the actual planner row list. If `workbook.rows` is present as a count and does not match the normalized row count, the resolver emits `expected_row_count_mismatch`.

## Normalized Row Schema

Each planner row is normalized to:

```json
{
  "row_number": 2,
  "canonical_slug": "actuaries",
  "public_resolution_state": "upload_candidate",
  "canonical_public_type": "public_canonical_job",
  "rollout_state": null,
  "projection_state": null,
  "index_state_hint": null,
  "title_en": "Actuaries",
  "title_zh": "Jing suan shi",
  "source_code": "15-2011.00",
  "family": "math",
  "batch_id": "batch-001",
  "locales": ["en", "zh-CN"],
  "raw": {}
}
```

Field aliases:

- `canonical_slug` falls back to `slug` and then `source_slug`.
- `public_resolution_state` falls back to `status` and then `current_status`.
- `canonical_public_type` falls back to `public_resolution_type` and then `public_type`.
- `index_state_hint` falls back to `indexability` and then `index_state`.
- `title_en` and `title_zh` may come from direct fields or a nested `title` object.

Rows with missing fields are preserved in `raw` and reported through structured issues. The resolver does not silently drop malformed planner data.

## Required Planner Fields

The existing full release ledger planner path currently requires:

- `row_number`
- `slug` or `canonical_slug`
- `status`

AUDIT-2 keeps that requirement at the resolver layer while allowing future planner variants to supply equivalent normalized aliases.

## Validation Reasons

Structured issue reasons are stable:

- `plan_file_missing`
- `plan_json_invalid`
- `plan_rows_missing`
- `plan_row_malformed`
- `canonical_slug_missing`
- `canonical_slug_duplicate`
- `expected_row_count_mismatch`
- `required_field_missing`
- `unsupported_plan_shape`

`plan_file_missing` returns `blocked`. Other validation issues return `fail`. A resolver result with no issues returns `pass`.

## Expected Row Count Semantics

The resolver only requires 2786 rows when the caller passes `expectedRows: 2786` or when the planner itself declares a mismatching `workbook.rows` count. Tests use small synthetic fixtures and prove that 2786 can be represented as an expected count without requiring the real external planner artifact.

## Consumption By AUDIT-3+

Future AUDIT PRs should consume `CareerPublicResolutionPlanValidationResult` instead of rereading planner JSON:

- AUDIT-3 should use normalized `canonical_slug` values for occupation entity inventory.
- AUDIT-4 should join normalized slugs to baseline/display metadata checks.
- AUDIT-9 should integrate the resolver into `career:audit-canonical-eligibility` without changing resolver behavior.

Consumers should fail closed on `blocked` or `fail`, preserve `issues`, and avoid turning missing external planner input into a production mutation.

## Non-Goals

AUDIT-2 does not:

- add the canonical eligibility audit command
- implement DB inventory
- backfill occupations
- modify rollout state
- generate a manifest train
- run production validation
- deploy
- require the real 2786 planner artifact in tests

AUDIT-2 does not claim 2786 readiness.
