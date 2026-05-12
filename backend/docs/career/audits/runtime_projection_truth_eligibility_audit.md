# Career Runtime Projection / Truth Eligibility Audit

AUDIT-6 adds a read-only runtime eligibility layer for the Career 2786 canonical eligibility audit train. It checks whether normalized public-resolution plan rows can resolve through optional full release ledger membership, runtime publish projection rows, and canonical runtime truth rows.

AUDIT-6 does not run rollout apply, publish rows, backfill data, mutate runtime state, inspect SEO/GEO surfaces, validate live HTML, or claim 2786 readiness.

## Inputs

The auditor accepts synthetic or artifact-derived arrays:

- plan rows from AUDIT-2 normalized rows, array rows, or slug lists
- expected locales such as `["en", "zh"]`
- runtime publish projection arrays with rows under `items`
- canonical runtime truth arrays with rows under `items`
- optional full release ledger arrays with rows under `public_resolution.rows`, `members`, `items`, or `rows`

Tests use synthetic arrays only. The real 2786 production planner, projection, truth, or ledger artifacts are not required.

## Matching Rules

Rows are matched by normalized lowercase `slug` or `canonical_slug` plus lowercase `locale`.

Projection rows may expose either:

- `runtime_publish_state`
- `projection_state`

Truth rows may expose:

- `projection_state`
- `truth_state`
- `state`
- `status`

For AUDIT-6 eligibility, exposed runtime/truth state must be `published`. If a ledger artifact is provided, the slug must also exist in that ledger. If no ledger artifact is provided, ledger membership is not audited.

The canonical public type is accepted only when it is absent or `public_canonical_job`. Any exposed non-canonical type is reported as an audit issue.

## Result Shape

The result reports:

- `status`
- `expected_rows`
- `found_projection_rows`
- `found_truth_rows`
- `found_published`
- `missing_projection_rows`
- `missing_truth_rows`
- `not_published_rows`
- `invalid_public_type_rows`
- `ledger_missing_rows`
- `by_reason`
- `rows`
- `issues`
- `sidecars`

Each row includes an AUDIT-1-compatible runtime layer status:

```json
{
  "layer": "runtime",
  "status": "pass",
  "reasons": [],
  "evidence": [
    {
      "slug": "actuaries",
      "locale": "en",
      "runtime_publish_state": "published",
      "truth_state": "published"
    }
  ],
  "source": "runtime_projection_truth"
}
```

Blocked rows use `status=blocked`, include reason codes, and keep the same `source`.

## Issue Reasons

- `ledger_member_missing`
- `projection_row_missing`
- `projection_state_not_published`
- `runtime_publish_state_not_published`
- `truth_row_missing`
- `truth_state_not_published`
- `canonical_public_type_invalid`
- `locale_row_missing`

## Non-Goals

AUDIT-6 is runtime projection/truth only. It does not:

- implement `career:audit-canonical-eligibility`
- audit baseline metadata
- audit index-state authority
- audit SEO/GEO readiness
- audit API or live HTML surfaces
- generate manifests
- apply rollout
- backfill or mutate data
- run production validation
- deploy

## Consumption By AUDIT-7+

AUDIT-7 should consume AUDIT-6 output as the runtime layer prerequisite before checking SEO/GEO readiness. AUDIT-8 should treat AUDIT-6 runtime failures as upstream blockers rather than attempting to prove live surface readiness for rows that are missing projection or truth eligibility.
