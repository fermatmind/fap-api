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
