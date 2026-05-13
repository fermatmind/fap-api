# Career 2786 Audit Run Context

REPAIR-RUN-CTX-1 adds an explicit run-context contract to `career:audit-canonical-eligibility`.

The audit result can still contain row-level `*_context_missing` reasons, but those reasons are now also grouped as top-level context requirements. Operators should read context-level blockers as missing inputs, not as thousands of independent production data defects.

## Command Outputs

Every JSON audit payload includes:

```json
{
  "context_summary": {
    "planner_supplied": true,
    "entity_db_context": "missing",
    "index_state_context": "missing",
    "runtime_projection_context": "missing",
    "runtime_truth_context": "missing",
    "surface_context": "missing",
    "live_html_context": "not_requested",
    "required_next_action": "provide_read_only_context_bundle"
  },
  "run_context": {
    "planner": {},
    "entity": {},
    "index": {},
    "runtime": {},
    "surface": {},
    "static_sources": {},
    "missing_contexts": [],
    "unverified_contexts": [],
    "approval_gates": [],
    "next_required_inputs": [],
    "suggested_rerun_modes": []
  }
}
```

Use `--context-output=/tmp/career_2786_audit_run_context_requirements.json` to write only the context artifact.

## Rerun Modes

- `planner_only`: validates planner rows and static artifact-derived layers only.
- `planner_plus_local_db`: adds local/read-only DB context for Occupation and index-state layers.
- `planner_plus_runtime_artifacts`: adds `--projection`, `--truth`, and optionally `--ledger`.
- `planner_plus_surface_base_url`: adds surface verification context; live HTML requires `--include-live-html` and `--base-url`.
- `full_readonly_context`: planner, read-only DB context, runtime artifacts, and surface context are all supplied.

## Context Interpretation

Context-level reasons are aggregated:

- `entity_db_context_missing`: Occupation entity queries could not be interpreted from the current DB context.
- `index_state_context_missing`: index-state authority could not be interpreted from the current DB context.
- `runtime_projection_context_missing`: `--projection` artifact is absent.
- `runtime_truth_context_missing`: `--truth` artifact is absent.
- `surface_context_missing`: surface artifact mode was not requested.
- `surface_live_html_context_missing`: live HTML verification was requested without a base URL.

These blockers prevent 80-readiness planning from being meaningful. They do not authorize DB mutation, rollout apply, publication, or deployment.

## Approval Gates

The command emits approval gate templates for:

- `production_readonly_db_context`
- `production_runtime_projection_export`
- `production_truth_export`
- `live_html_crawl`
- `db_backfill_apply`
- `index_state_apply`
- `rollout_apply`

Only the read-only context/export gates are relevant for the next RUN-1 rerun. Backfill, index apply, and rollout apply remain forbidden until a later approved remediation/apply goal.

## RUN-1 Rerun Shape

Planner-only rerun:

```bash
php -d memory_limit=512M artisan career:audit-canonical-eligibility \
  --scope=all \
  --public-resolution-plan=/tmp/career_2786_public_resolution_plan_from_d23b.json \
  --locales=en,zh \
  --json \
  --output=/tmp/career_2786_canonical_eligibility_audit_with_context.json \
  --context-output=/tmp/career_2786_audit_run_context_requirements.json
```

Full read-only context rerun, after explicit approvals/artifacts:

```bash
php -d memory_limit=512M artisan career:audit-canonical-eligibility \
  --scope=all \
  --public-resolution-plan=/tmp/career_2786_public_resolution_plan_from_d23b.json \
  --entity-context=/tmp/career_2786_entity_context.json \
  --index-state-context=/tmp/career_2786_index_state_context.json \
  --projection=/tmp/career_2786_runtime_projection.json \
  --truth=/tmp/career_2786_runtime_truth.json \
  --ledger=/tmp/career_2786_full_release_ledger.json \
  --locales=en,zh \
  --include-surfaces \
  --json \
  --output=/tmp/career_2786_canonical_eligibility_audit_with_context.json \
  --context-output=/tmp/career_2786_audit_run_context_requirements.json
```

`--entity-context` and `--index-state-context` consume approved read-only JSON artifacts. They do not export production context; that producer workflow remains approval-gated.

## Non-Goals

This context layer does not:

- mutate DB state
- run backfill/apply/rollback/quarantine
- generate or apply rollout manifests
- deploy
- fetch live HTML unless a later run explicitly supplies live context
- claim 2786 readiness
- run 80 readiness while context blockers remain unresolved
