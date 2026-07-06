# OpenCode Eligibility Matrix

## Eligible Now

| task_id | classification | reason |
| --- | --- | --- |
| `SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Public readback only, generated output only, deterministic JSON/diff checks. |
| `SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Public `llms-full` readback only; no mutation. |
| `SIX-TEST-HUB-BACKEND-AUTHORITY-OWNER-MATRIX-01` | `OPENCODE_ELIGIBLE_DOCS_ONLY` | Repository evidence matrix only. |
| `MONEY-INTENT-OWNER-PAGE-RUNTIME-RECONCILE-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Public owner route readback only. |
| `MONEY-INTENT-CANNIBALIZATION-LINK-GRAPH-READONLY-01` | `OPENCODE_ELIGIBLE_DOCS_ONLY` | Link/cannibalization evidence map only. |
| `RESULT-INTERPRETATION-OWNER-ROUTE-MATRIX-01` | `OPENCODE_ELIGIBLE_DOCS_ONLY` | Owner route inventory only. |
| `RESULT-INTERPRETATION-PRIVATE-RESULT-BOUNDARY-GUARD-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Public/private URL boundary readback only. |
| `RIASEC-GAOKAO-CLUSTER-INTERNAL-LINK-PLAN-01` | `OPENCODE_ELIGIBLE_DOCS_ONLY` | Planning map, no copy intended for publish. |
| `ENTITY-GRAPH-ROUTE-INVENTORY-READONLY-01` | `OPENCODE_ELIGIBLE_DOCS_ONLY` | Route inventory only. |
| `MBTI-TYPE-PAGES-ENTITY-GRAPH-RUNTIME-MATRIX-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Runtime matrix only. |
| `BIG-FIVE-DIMENSION-PAGES-ENTITY-GRAPH-MATRIX-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Runtime matrix only, no claim writing. |
| `ENNEAGRAM-TYPE-PAGES-ENTITY-GRAPH-MATRIX-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Runtime matrix only. |
| `RIASEC-SIX-TYPE-PAGES-ENTITY-GRAPH-MATRIX-01` | `OPENCODE_ELIGIBLE_DOCS_ONLY` | Noindex/internal evidence mapping only. |
| `CORE-HUB-FIRST-SCREEN-ANSWER-BLOCK-READBACK-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | Read visible public first-screen evidence only. |
| `FAQ-VISIBLE-PARITY-MATRIX-ALL-HUBS-01` | `OPENCODE_ELIGIBLE_READONLY_RUNTIME` | DOM/schema parity readback only. |

## Not Eligible Now

Cards requiring claim strategy, GSC quality/repair interpretation, competitor/legal review, content handoff, date windows, or operator-held RIASEC parity reauthorization are not OpenCode-eligible until their gates are cleared.
