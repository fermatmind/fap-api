# Career Entity and Index Context Artifacts

CTX-CONSUME-1 adds consumer-only context artifact inputs to `career:audit-canonical-eligibility`.

The command can now consume:

```bash
--entity-context=/tmp/career_2786_entity_context.json
--index-state-context=/tmp/career_2786_index_state_context.json
```

These options are read-only. They do not export production context, query production DB, mutate DB state, apply rollout, backfill data, or publish occupations.

## Entity Context

Expected shape:

```json
{
  "schema_version": "career_entity_context.v1",
  "source": {
    "type": "read_only_db_export",
    "generated_at": "2026-05-13T00:00:00Z",
    "environment": "production"
  },
  "rows": [
    {
      "canonical_slug": "actuaries",
      "occupation_exists": true,
      "occupation_id": 123,
      "title_en": "Actuaries",
      "title_zh": "精算师",
      "family": "math",
      "crosswalks": [],
      "missing_entity_fields": [],
      "evidence": {}
    }
  ]
}
```

Required fields:

- `canonical_slug`
- `occupation_exists` as a boolean

Issue reasons:

- `entity_context_file_missing`
- `entity_context_json_invalid`
- `entity_context_rows_missing`
- `entity_context_row_malformed`
- `entity_context_slug_missing`
- `entity_context_slug_duplicate`
- `entity_context_required_field_missing`

## Index-State Context

Expected shape:

```json
{
  "schema_version": "career_index_state_context.v1",
  "source": {
    "type": "read_only_db_export",
    "generated_at": "2026-05-13T00:00:00Z",
    "environment": "production"
  },
  "rows": [
    {
      "canonical_slug": "actuaries",
      "latest_index_state": "indexed",
      "public_facing_state": "indexed",
      "index_eligible": true,
      "changed_at": "2026-05-13T00:00:00Z",
      "reason_codes": [],
      "evidence": {}
    }
  ]
}
```

Required fields:

- `canonical_slug`

Issue reasons:

- `index_context_file_missing`
- `index_context_json_invalid`
- `index_context_rows_missing`
- `index_context_row_malformed`
- `index_context_slug_missing`
- `index_context_slug_duplicate`
- `index_context_required_field_missing`

## Runtime Behavior

If valid artifacts are supplied:

- `context_summary.entity_db_context` becomes `supplied`
- `context_summary.index_state_context` becomes `supplied`
- row-level `entity_db_context_missing` and `index_state_context_missing` disappear

If an explicit artifact path is missing or malformed, the command reports the artifact issue and does not silently query DB as a fallback.

## Non-Goals

CTX-CONSUME-1 does not:

- produce the entity or index artifacts
- approve or run production read-only export
- mutate DB state
- backfill Occupations
- apply index-state changes
- run 80 readiness or rollout
