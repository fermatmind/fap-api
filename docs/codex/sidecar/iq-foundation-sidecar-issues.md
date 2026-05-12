# IQ Foundation Sidecar Issues

## IQ-SIDECAR-COMMERCE-DEFERRED-001

| Field | Value |
|---|---|
| sidecar_id | `IQ-SIDECAR-COMMERCE-DEFERRED-001` |
| title | `defer(iq): commerce unlock ¥1.99 / ¥5 implementation` |
| owner_repo | `fap-api` |
| scope_relation | `external_to_current_pr` |
| introduced_by_current_pr | `false` |
| affected_area | `commerce_unlock` |
| affected_files | `docs/codex/pr-train.yaml`, `docs/codex/pr-train-state.json`, `docs/codex/sidecar/iq-foundation-sidecar-issues.md` |
| affected_scale_codes | `IQ_INTELLIGENCE_QUOTIENT` |
| affected_routes | `api/v0.3/attempts/{id}/report-access`, `api/v0.3/orders/*`, `api/v0.3/webhooks/payment/*` |
| severity | `medium` |
| proposed_owner_pr | `iq-commerce-unlock-199-500` |
| next_goal | `Implement deferred IQ commerce unlock after identity, scoring, report, provenance, and item bank import are stable.` |
| may_continue_train | `true` |
| resume_condition | `Re-enable iq-commerce-unlock-199-500 when IQ scored runtime, report contract, SVG provenance, and item bank import are stable.` |

### Evidence

- User intentionally deferred the `¥1.99 / ¥5` commerce PR.
- Paid unlock is not required for identity/scoring/report/SVG provenance foundation.
- The train can continue without checkout/SKU/webhook changes.

## IQ-SIDECAR-NORM-TABLE-DEFERRED-001

| Field | Value |
|---|---|
| sidecar_id | `IQ-SIDECAR-NORM-TABLE-DEFERRED-001` |
| title | `defer(iq): production norm table and calibrated IQ estimate remain unavailable` |
| owner_repo | `fap-api` |
| scope_relation | `external_to_current_pr` |
| introduced_by_current_pr | `false` |
| affected_area | `norms_and_calibration` |
| affected_files | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/scoring_spec.json`, `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/scoring_spec.json` |
| affected_scale_codes | `IQ_INTELLIGENCE_QUOTIENT`, `IQ_RAVEN` |
| affected_routes | `api/v0.3/attempts/{id}/result`, `api/v0.3/attempts/{id}/report` |
| severity | `medium` |
| proposed_owner_pr | `iq-showcase12-beta50-item-bank-import` |
| next_goal | `Import production-ready item banks and attach calibrated norm tables before claiming IQ estimate validity.` |
| may_continue_train | `true` |
| resume_condition | `Re-enable calibrated IQ estimate once a norm_table_version and runtime calibration policy are available for the production IQ bank.` |

### Evidence

- PR2 formalizes a scored contract and explicit answer-key gate, but no validated `norm_table_version` exists yet.
- Runtime now reports `unavailable_without_norm_table` instead of fabricating IQ estimates or percentiles.
- Identity/scoring/report/SVG provenance work can continue without a production norm table.
