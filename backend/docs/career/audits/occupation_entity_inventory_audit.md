# Career Occupation Entity Inventory Audit

AUDIT-3 adds the read-only entity inventory layer for the Career 2786 Full Public Resolution program.

The auditor consumes canonical slugs from AUDIT-2 public-resolution plan rows and checks whether matching `occupations` entities exist with the entity-level fields needed by later canonical eligibility rows. It emits stable JSON and AUDIT-1-compatible `entity` layer statuses.

## Scope

AUDIT-3 is entity-inventory-only.

It does:

- count expected canonical slugs from a normalized plan or explicit slug list
- query `occupations` by `canonical_slug`
- report missing occupation entities
- detect duplicate canonical slugs in the input
- defensively report duplicate entity rows if the database ever returns them
- report missing entity-level fields required by future audit rows
- emit `CareerCanonicalEligibilityLayerStatus` for the `entity` layer

It does not:

- create the `career:audit-canonical-eligibility` command
- inspect baseline/display metadata
- inspect index state
- inspect runtime projection/truth state
- inspect SEO/GEO or live surface HTML
- backfill, create, update, or delete occupations
- run production validation or deployment

## Input Contract

The primary input is `CareerPublicResolutionPlan`, produced by AUDIT-2:

```php
(new CareerOccupationEntityInventoryAuditor())->auditPlan($plan);
```

The auditor also accepts a direct slug list:

```php
(new CareerOccupationEntityInventoryAuditor())->auditSlugs(['actuaries']);
```

Slugs are normalized by trimming whitespace and lowercasing. Empty or non-scalar slugs are reported as `input_slug_missing`.

The real 2786 planner artifact is not required for AUDIT-3 tests; synthetic plan rows are enough to prove the contract.

## Entity Checks

For each unique canonical slug, AUDIT-3 reads from `occupations` only. The required entity-level fields are:

- `id`
- `canonical_slug`
- `family_id`
- `entity_level`
- `truth_market`
- `display_market`
- `crosswalk_mode`
- `canonical_title_en`
- `canonical_title_zh`
- `search_h1_zh`

These fields are intentionally limited to entity structure. Baseline metadata, display assets, index state, runtime projection/truth, and surface checks are reserved for later PRs.

## Issue Reasons

AUDIT-3 issue reasons are:

- `occupation_missing`
- `canonical_slug_duplicate_in_input`
- `canonical_slug_duplicate_in_entities`
- `entity_field_missing`
- `occupation_query_failed`
- `input_slug_missing`

The `occupations.canonical_slug` column is unique in the current schema. Duplicate entity detection remains in the result contract as a defensive guard for schema drift, alternate stores, or future repository adapters.

## Entity Layer Status

Found occupation:

```json
{
    "layer": "entity",
    "status": "pass",
    "reasons": [],
    "evidence": [
        {
            "occupation_id": "..."
        }
    ],
    "source": "occupations"
}
```

Missing occupation:

```json
{
    "layer": "entity",
    "status": "blocked",
    "reasons": [
        "occupation_missing"
    ],
    "evidence": [
        {
            "canonical_slug": "..."
        }
    ],
    "source": "occupations"
}
```

Rows with missing entity fields or duplicate entity rows are also `blocked`. Rows that only have a duplicate input slug are marked `warning` at the layer level, while the result remains blocked because input duplication makes the inventory unreliable.

## Result JSON

The result contains:

- `status`
- `expected_count`
- `found_count`
- `missing_count`
- `duplicate_input_count`
- `duplicate_entity_count`
- `missing_entity_field_count`
- `by_reason`
- `rows`
- `issues`
- `sidecars`

Sidecars are available in the schema, but AUDIT-3 does not need sidecars for normal in-scope missing occupation rows. Missing baseline, index, runtime, SEO/GEO, or surface evidence belongs to AUDIT-4 through AUDIT-8.

## Future Consumption

AUDIT-4+ should consume AUDIT-3 output as the entity layer authority:

- use `rows[*].entity_status` for AUDIT-1 layer status composition
- use `occupation_id` only when `occupation_exists=true`
- keep baseline/index/runtime/surface checks outside AUDIT-3
- preserve `issues` and `by_reason` in later aggregate reports

AUDIT-3 does not claim 2786 readiness. It only proves the read-only entity inventory contract for future canonical eligibility audits.
