# RIASEC Result Page V2 Production-Approved Snapshot Operating Model

Date: 2026-06-22

Task: `RIASEC-RESULT-V2-PRODUCTION-APPROVED-SNAPSHOT-OPERATING-MODEL-01`

Scope: docs/artifact-only operating model for long-term production-approved snapshot handling. This model does not approve production import, generate a production-approved snapshot, import CMS data, execute production import, open production rollout, mutate runtime configuration, or reclassify staging-only assets as production-ready.

## Purpose

RIASEC Result Page V2 production import requires a durable, human-approved snapshot artifact. The repository already contains a review candidate snapshot, but it is not production-approved:

| Field | Current review candidate value |
| --- | --- |
| Source snapshot id | `riasec_result_page_v2_rc_0_1` |
| Source snapshot SHA256 | `4e5b7a3c356324bbd854ad2a3c8586caf07f0e05fee6bb26ab56af5c29f4b853` |
| `release_candidate` | `false` |
| `production_use_allowed` | `false` |
| `ready_for_production` | `false` |
| `production_rollout_enabled` | `false` |

This operating model defines how a future human approval can create a new production-approved snapshot artifact without modifying the original RC snapshot and without performing production import.

## Hard Rules

1. The original RC snapshot must not be edited in place.
2. A production-approved snapshot must be a new artifact under `backend/content_assets/riasec/result_page_v2/releases/production_approved/v0_1/`.
3. A matching human approval evidence artifact must be written under `backend/content_assets/riasec/result_page_v2/governance/production_approval_evidence/v0_1/`.
4. Approval evidence must be human-authored, auditable, single-valued, and free of placeholders.
5. Codex may validate and package approval evidence, but Codex must not invent or fill human approval facts.
6. Production import approval is not production rollout approval.
7. Production rollout requires a separate rollout approval packet and must remain disabled after import approval.
8. Staging-only artifacts must not be renamed, reclassified, or treated as production-ready by this model.
9. CMS production import remains blocked until a separate import gate dry-run and a separate import execution authorization pass.
10. Missing approval evidence, placeholder approval evidence, multi-option approval evidence, or Codex-authored approval facts force `NO-GO`.

## Artifact Contract

### Production-Approved Snapshot Artifact

Future approved snapshot artifacts must use this shape:

```json
{
  "schema_version": "fap.riasec.result_page_v2.production_approved_snapshot.v0.1",
  "snapshot_id": "riasec_result_page_v2_prod_approved_<date_or_sequence>",
  "source_snapshot_id": "riasec_result_page_v2_rc_0_1",
  "source_snapshot_sha256": "4e5b7a3c356324bbd854ad2a3c8586caf07f0e05fee6bb26ab56af5c29f4b853",
  "deployed_backend_sha": "ce029ebf6baf8a1eb7dc3164d7ad121ea91054e9",
  "production_use_allowed": true,
  "ready_for_production_import": true,
  "ready_for_production_rollout": false,
  "production_rollout_enabled": false,
  "runtime_use": "production_import_candidate",
  "cms_write_performed": false,
  "approval_evidence_ref": "backend/content_assets/riasec/result_page_v2/governance/production_approval_evidence/v0_1/<approval_id>.json",
  "evidence_refs": [
    "PR #2285",
    "PR #2286",
    "PR #2288",
    "PR #2289",
    "PR #2290"
  ]
}
```

The artifact is an import candidate only. It does not write CMS data, enable runtime, or approve rollout.

### Human Approval Evidence Artifact

Future approval evidence artifacts must use this shape:

```json
{
  "schema_version": "fap.riasec.result_page_v2.production_import_human_approval.v0.1",
  "approval_type": "production_import",
  "decision": "GO",
  "approved_by": "<human operator id/name>",
  "approved_at": "<ISO-8601 timestamp>",
  "approval_channel": "<durable source>",
  "approval_evidence_url_or_ref": "<durable approval evidence>",
  "deployed_backend_sha": "ce029ebf6baf8a1eb7dc3164d7ad121ea91054e9",
  "source_release_snapshot_id": "riasec_result_page_v2_rc_0_1",
  "source_release_snapshot_sha256": "4e5b7a3c356324bbd854ad2a3c8586caf07f0e05fee6bb26ab56af5c29f4b853",
  "approved_release_snapshot_id": "riasec_result_page_v2_prod_approved_<date_or_sequence>",
  "scope": {
    "tenant_ids": [],
    "form_codes": ["riasec_60", "riasec_140"],
    "locales": ["zh-CN"],
    "allowlist": [],
    "percentage": 0,
    "max_percentage": 0
  },
  "rollback_kill_switch_confirmed": true,
  "kill_switch_ref": "riasec_result_page_v2.production_emergency_disabled",
  "post_deploy_smoke_procedure_id": "riasec_result_page_v2_post_deploy_smoke_v0_1",
  "private_payload_leak_ack": true,
  "staging_only_rejection_ack": true
}
```

The example above is a schema example, not approval. Any literal placeholder value in a real approval artifact is invalid.

## Approval Block Validation

An approval block is valid only when all conditions are true:

| Field group | Requirement |
| --- | --- |
| Identity | `approved_by`, `approver_role`, and `approval_channel` identify a human authority. |
| Time | `approved_at` is a complete ISO-8601 timestamp, not a blank or template value. |
| Decision | `decision` is exactly `GO` for production import. |
| Snapshot | source snapshot id and SHA256 match the reviewed source snapshot. |
| Approved snapshot | approved snapshot id is a new id and does not overwrite `riasec_result_page_v2_rc_0_1`. |
| Scope | tenant/form/locale/allowlist/percentage fields are explicit and single-valued. |
| Safety | rollback, kill switch, post-deploy smoke, private payload, and staging-only rejection acknowledgements are true. |
| Separation | import approval explicitly says rollout remains disabled and requires separate approval. |

## NO-GO Conditions

The production-approved snapshot artifact task must produce `NO-GO` sidecar evidence, not an approved snapshot, when any condition below is true:

- `approved_by` is absent, generic, or agent-authored.
- `approved_at` is absent, partial, or contains placeholder characters.
- Any field contains `<...>`, `...`, `T__:__:__Z`, `or`, `或`, `TBD`, `TODO`, `REQUIRED_HUMAN_INPUT`, or similar placeholders.
- Snapshot id contains multiple options.
- Release snapshot SHA256 is absent or does not match the reviewed source snapshot.
- Scope is absent, open-ended, or contains placeholder arrays.
- Rollback or kill-switch confirmation is absent.
- `private_payload_leak_ack` is not true.
- `staging_only_rejection_ack` is not true.
- Approval evidence claims rollout approval in the same import approval packet.
- Codex filled human identity, timestamp, scope, or approval channel.

## Train Behavior

The downstream PR train must behave as follows:

| Step | Behavior |
| --- | --- |
| Approved snapshot artifact PR | Generate a new approved snapshot only when a complete human approval block exists; otherwise generate `NO-GO` sidecar/report. |
| Import gate dry-run PR | If no approved snapshot exists, record external `NO-GO` and continue without CMS writes. |
| Import execution PR | Execute real import only with separate second authorization; otherwise generate execution `NO-GO` readiness artifact. |
| Rollout approval PR | Require separate rollout approval and never reuse import approval. |

## Current Verdict

| Decision | Result |
| --- | --- |
| Operating model ready for future approval packaging | `YES` |
| Production-approved snapshot generated by this PR | `NO` |
| Production import allowed by this PR | `NO` |
| CMS write allowed by this PR | `NO` |
| Production rollout allowed by this PR | `NO` |
| Runtime flag mutation allowed by this PR | `NO` |

Next step: apply this model in `RIASEC-RESULT-V2-PRODUCTION-APPROVED-SNAPSHOT-ARTIFACT-01`. If no complete human approval block exists, that task must emit `NO-GO` evidence instead of a production-approved snapshot.
