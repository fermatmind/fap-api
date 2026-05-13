# Career Canonical Eligibility DB Context Export

CTX-EXPORT-1 adds a producer command for the read-only entity and index-state context artifacts consumed by `career:audit-canonical-eligibility`.

Command:

```bash
php artisan career:export-canonical-eligibility-db-context \
  --public-resolution-plan=/path/to/public-resolution-plan.json \
  --entity-output=/tmp/career_2786_entity_context.json \
  --index-state-output=/tmp/career_2786_index_state_context.json \
  --json
```

The command is read-only. It loads the planner through the AUDIT-2 resolver, extracts canonical slugs, queries the current configured DB for matching `occupations` and latest `index_states`, and writes JSON artifacts. It does not insert, update, delete, backfill, apply rollout, publish occupations, or deploy.

## Entity Artifact

`--entity-output` writes `career_entity_context.v1`:

```json
{
  "schema_version": "career_entity_context.v1",
  "source": {
    "type": "read_only_db_export",
    "generated_at": "2026-05-13T00:00:00.000000Z",
    "environment": "production",
    "planner_path": "/tmp/career_2786_public_resolution_plan_from_d23b.json"
  },
  "rows": [
    {
      "canonical_slug": "actuaries",
      "occupation_exists": true,
      "occupation_id": "uuid",
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

The export includes one row per planner canonical slug. Missing occupations are represented with `occupation_exists=false` and `occupation_id=null`; rows are not omitted.

## Index-State Artifact

`--index-state-output` writes `career_index_state_context.v1`:

```json
{
  "schema_version": "career_index_state_context.v1",
  "source": {
    "type": "read_only_db_export",
    "generated_at": "2026-05-13T00:00:00.000000Z",
    "environment": "production",
    "planner_path": "/tmp/career_2786_public_resolution_plan_from_d23b.json"
  },
  "rows": [
    {
      "canonical_slug": "actuaries",
      "latest_index_state": "indexed",
      "public_facing_state": "indexable",
      "index_eligible": true,
      "changed_at": "2026-05-13T00:00:00.000000Z",
      "reason_codes": [],
      "evidence": {}
    }
  ]
}
```

Latest index-state selection follows the audit layer order: `changed_at desc`, `created_at desc`, `updated_at desc`. The `public_facing_state` value uses `IndexStateValue::publicFacing`.

## Approval Boundary

This PR adds producer support only. Running the command against production requires a later explicit approval gate for read-only production context export. The approved run should write to `/tmp` or another approved artifact location and then rerun:

```bash
php artisan career:audit-canonical-eligibility \
  --scope=all \
  --public-resolution-plan=/tmp/career_2786_public_resolution_plan_from_d23b.json \
  --entity-context=/tmp/career_2786_entity_context.json \
  --index-state-context=/tmp/career_2786_index_state_context.json \
  --projection=/tmp/career_2786_runtime_projection.json \
  --truth=/tmp/career_2786_runtime_truth.json \
  --locales=en,zh \
  --json \
  --output=/tmp/career_2786_canonical_eligibility_audit_with_context.json \
  --context-output=/tmp/career_2786_audit_run_context_requirements.json
```

## Non-Goals

CTX-EXPORT-1 does not:

- run production export
- mutate DB state
- backfill Occupations
- apply index-state changes
- apply rollout
- run 80 readiness
- deploy
