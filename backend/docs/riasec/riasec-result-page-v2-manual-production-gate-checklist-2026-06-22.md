# RIASEC Result Page V2 Manual Production Gate Checklist

Date: 2026-06-22

Task: `RIASEC-RESULT-V2-MANUAL-GATE-PREP-PACK-01`

Scope: docs/artifact-only manual gate preparation package. This checklist does not approve, import, publish, enable runtime, mutate environment variables, write CMS data, open production gates, or mark staging-only assets as production-ready.

## Current State

Current decision remains `NO-GO` until explicit human approval is recorded.

| Gate | Current state | Required next step |
| --- | --- | --- |
| Production import | `NO-GO` | Human import approval must reference an approved release snapshot and passing evidence. |
| Production rollout | `NO-GO` | Human rollout approval must reference exact scope, flags, kill switch, rollback, and smoke procedure. |
| Automatic rollout | `BLOCKED` | The ops agent must not execute automatic production rollout. |
| CMS write/import | `BLOCKED` | No production CMS write or import is authorized by this package. |

Reference inputs:

- PR `#2285`: post-deploy staging/pilot evidence package.
- PR `#2286`: manual production gate readiness audit.
- Staging/pilot evidence file: `backend/content_assets/riasec/result_page_v2/qa/post_deploy_staging_pilot_evidence/v0_1/riasec_result_page_v2_post_deploy_staging_pilot_evidence_v0_1.json`
- Readiness audit file: `backend/content_assets/riasec/result_page_v2/qa/manual_production_gate_readiness_audit/v0_1/riasec_result_page_v2_manual_production_gate_readiness_audit_v0_1.json`

## Required Exact Approval Evidence Fields

Any future approval record must include all fields below. Missing values keep the gate at `NO-GO`.

| Field | Required value / format | Notes |
| --- | --- | --- |
| `approval_type` | `production_import` or `production_rollout` | Separate approvals are required. |
| `approved_by` | human operator id/name | Must not be an agent-only approval. |
| `approved_at` | ISO-8601 timestamp | UTC preferred. |
| `release_snapshot_id` | exact approved snapshot id | Current `riasec_result_page_v2_rc_0_1` is not approved yet. |
| `release_snapshot_sha256` | exact snapshot hash | Must match immutable snapshot evidence. |
| `deployed_backend_sha` | exact deployed SHA | Must match server revision at approval time. |
| `evidence_refs` | PR/report paths | Must include PR `#2285` and PR `#2286` or superseding evidence. |
| `scope.tenant_ids` | explicit list or documented non-tenant scope decision | Required if tenant scope remains enforced. |
| `scope.form_codes` | explicit list | Expected values: `riasec_60`, `riasec_140`, or narrower. |
| `scope.locales` | explicit list | Expected starting value: `zh-CN`, unless separately justified. |
| `scope.allowlist` | attempt/user/anon/org ids as applicable | Empty global rollout is not allowed. |
| `scope.percentage` | integer percent | Must be `0` until a rollout approval is granted. |
| `scope.max_percentage` | integer percent cap | Must be greater than or equal to percentage and approved. |
| `post_deploy_smoke_procedure_id` | `riasec_result_page_v2_post_deploy_smoke_v0_1` | Proposed procedure id for future rollout. |
| `rollback_plan_ref` | checklist/report path | Must include kill switch and disable procedure. |
| `kill_switch_ref` | config/control reference | Must include `riasec_result_page_v2.production_emergency_disabled`. |
| `no_private_payload_leak_ack` | boolean true | Required before any public traffic. |
| `staging_only_rejection_ack` | boolean true | Must acknowledge staging-only assets cannot be imported as production. |

## Production Import Approval Checklist

Production import remains blocked unless every item below is checked by a human operator:

- [ ] Confirm the release snapshot is a production release candidate.
- [ ] Confirm `production_use_allowed=true` on the approved snapshot.
- [ ] Confirm `ready_for_production=true` on the approved snapshot.
- [ ] Confirm the snapshot SHA256 matches the immutable release snapshot manifest.
- [ ] Confirm rendered preview evidence passes and references the approved snapshot.
- [ ] Confirm all-surface pilot QA passes for the approved snapshot.
- [ ] Confirm public payload leak scan has zero private payload leaks.
- [ ] Confirm staging-only candidate assets are not being imported as production-ready assets.
- [ ] Confirm production import gate policy remains fail-closed before import.
- [ ] Confirm CMS write/import command and target are separately reviewed.
- [ ] Confirm no production rollout flag is enabled as part of import.
- [ ] Record exact human approval evidence fields.

Import approval result if any item is unchecked: `NO-GO`.

## Production Rollout Approval Checklist

Production rollout remains blocked unless every item below is checked by a human operator:

- [ ] Confirm production import gate passed for the exact approved snapshot.
- [ ] Confirm explicit human rollout approval exists and is separate from import approval.
- [ ] Confirm `production_runtime_enabled` and rollout flags are intentionally scoped for the approved rollout.
- [ ] Confirm rollout mode is not `automatic_production_rollout`.
- [ ] Confirm tenant scope is present or a documented human exception exists.
- [ ] Confirm form scope is explicit.
- [ ] Confirm locale scope is explicit.
- [ ] Confirm allowlist scope is explicit for the first rollout.
- [ ] Confirm percentage and max percentage are explicit and approved.
- [ ] Confirm kill switch is reachable and documented.
- [ ] Confirm rollback plan is documented and executable by an operator.
- [ ] Confirm post-deploy smoke procedure id is configured.
- [ ] Confirm post-deploy smoke surfaces are complete.
- [ ] Confirm no frontend fallback content is enabled.
- [ ] Confirm no CMS production write is bundled into rollout.

Rollout approval result if any item is unchecked: `NO-GO`.

## Proposed Post-Deploy Smoke Procedure

Procedure id proposal: `riasec_result_page_v2_post_deploy_smoke_v0_1`

Required smoke surfaces:

| Surface | Smoke objective |
| --- | --- |
| `result_page` | Confirm approved payload renders only for approved scope. |
| `pdf` | Confirm PDF payload is omitted or redacted according to policy and does not leak private fields. |
| `share` | Confirm share payload uses public allowlist only and does not leak score/vector/share block internals. |
| `history` | Confirm history does not expose raw history vectors or private deltas. |
| `compare` | Confirm compare does not expose raw compare vectors or percentile/rank internals. |
| `locked` | Confirm locked state omits Result Page V2 payload. |
| `free` | Confirm free state omits private measurements and share summary internals. |
| `low_quality` | Confirm cautious copy and no overclaiming; no raw quality flags exported. |
| `fallback` | Confirm fail-closed omission and no frontend fallback copy. |

Minimum smoke assertions:

- [ ] Public payload contains no `attempt_id`, `user_id`, private URL/path, raw score, score vector, dimension vector, percentile, editor notes, QA notes, selector trace, source refs, or internal metadata.
- [ ] Result Page V2 payload is unavailable outside approved tenant/form/locale/allowlist/percentage scope.
- [ ] Kill switch disables the rollout path.
- [ ] Rollback or release disable removes the approved snapshot from runtime eligibility.
- [ ] Post-disable smoke confirms the old fail-closed behavior.

## Scoped Rollout Template

Use this template for future approval evidence. It is intentionally non-executable.

```json
{
  "rollout_scope": {
    "release_snapshot_id": "",
    "tenant_ids": [],
    "form_codes": ["riasec_60"],
    "locales": ["zh-CN"],
    "allowed_attempt_ids": [],
    "allowed_user_ids": [],
    "allowed_anon_ids": [],
    "allowed_org_ids": [],
    "percentage": 0,
    "max_percentage": 0,
    "mode": "disabled"
  },
  "required_flags_for_future_operator_review": {
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_RUNTIME_ENABLED": false,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ENABLED": false,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_CONFIGURED": false,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_MANUAL_APPROVAL_GRANTED": false,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_IMPORT_GATE_PASSED": false,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_RELEASE_SNAPSHOT_ID": "",
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MODE": "disabled",
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_PERCENTAGE": 0,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MAX_PERCENTAGE": 0,
    "RIASEC_RESULT_PAGE_V2_PRODUCTION_POST_DEPLOY_SMOKE_PROCEDURE_ID": "riasec_result_page_v2_post_deploy_smoke_v0_1"
  }
}
```

This template does not authorize changing these flags.

## Rollback And Kill-Switch Checklist

- [ ] Confirm the kill switch config reference is `riasec_result_page_v2.production_emergency_disabled`.
- [ ] Confirm release snapshot disable mechanism is documented.
- [ ] Confirm disabled snapshot ids can block the approved snapshot.
- [ ] Confirm rollout percentage can be reset to `0`.
- [ ] Confirm rollout mode can be reset to `disabled`.
- [ ] Confirm production runtime can be disabled independently from import evidence.
- [ ] Confirm post-disable smoke repeats the required smoke surfaces.
- [ ] Confirm operator has rollback ownership and access before rollout.
- [ ] Confirm no agent is authorized to perform production rollback without explicit approval.

Rollback readiness result until checked by a human operator: `NOT_READY_FOR_PRODUCTION_ROLLOUT`.

## Non-Goals

This package does not:

- approve production import;
- approve production rollout;
- write CMS data;
- import assets into production;
- enable runtime wrappers;
- mutate environment variables;
- create production release approval evidence;
- convert staging-only assets into production-ready assets;
- add frontend fallback content;
- expose private payload fields.

## Go / No-Go

| Decision | Result |
| --- | --- |
| Use this package as manual gate preparation material | `YES` |
| Treat this package as production approval | `NO` |
| Allow production import now | `NO` |
| Allow production rollout now | `NO` |
| Allow automatic production rollout | `NO` |
| Allow CMS production write | `NO` |
| Allow runtime production flag mutation | `NO` |

Next safe step: human review of this checklist and, if desired, a separate approval-evidence drafting PR that still does not mutate runtime, CMS, or production flags.
