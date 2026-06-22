# RIASEC Result Page V2 Post-Deploy Staging/Pilot Evidence

Date: 2026-06-22

Task: `RIASEC-RESULT-V2-STAGING-PILOT-EVIDENCE-PACK-01`

Scope: docs/artifact-only evidence packaging for post-deploy verify-only and staging/pilot dry-run results.

This report does not modify runtime code, environment variables, CMS data, production imports, production rollout gates, or frontend fallback behavior. It does not mark staging-only assets as production-ready.

## Deployment Evidence

- Deployed backend SHA: `ce029ebf6baf8a1eb7dc3164d7ad121ea91054e9`
- RIASEC production rollout gate PR: `#2276`
- PR `#2276` merge commit: `fb6e64468bc33063b229ca8ff5ced8076ddae088`
- Verification result: the deployed SHA contains the PR `#2276` merge commit in the local and `origin/main` history checked during post-deploy verification.

## Production Gate State

Read-only remote config verification reported the RIASEC Result Page V2 production controls as disabled:

| Key | Observed value |
| --- | --- |
| `enabled` | `false` |
| `staging_runtime_enabled` | `false` |
| `pilot_runtime_enabled` | `false` |
| `pilot_production_allowlist_enabled` | `false` |
| `production_runtime_enabled` | `false` |
| `production_rollout_enabled` | `false` |
| `production_rollout_configured` | `false` |
| `production_rollout_manual_approval_granted` | `false` |
| `production_import_gate_passed` | `false` |
| `production_release_snapshot_id` | empty string |
| `production_rollout_mode` | `disabled` |
| `production_rollout_percentage` | `0` |
| `production_rollout_max_percentage` | `0` |
| `production_post_deploy_smoke_procedure_id` | empty string |

Conclusion: production runtime, production import, and production rollout remain fail-closed.

## Public API Smoke

The public RIASEC question endpoints remained healthy after deployment:

| Endpoint | Status | Observed count |
| --- | ---: | ---: |
| `/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60` | `200` | `60` |
| `/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_140` | `200` | `140` |

The public `/api/v0.3/flags` endpoint returned `200` and did not expose RIASEC Result Page V2 related flag keys.

Residual note: `/api/healthz` and `/api/v0.3/healthz` returned `404` during this verification pass and were not used as RIASEC Result Page V2 evidence sources.

## Staging Agent Audit Summary

Command:

```bash
cd backend
php artisan riasec:result-page-v2-agent audit \
  --run-id=post-deploy-riasec-v2-20260622T082750Z-audit \
  --artifact-dir=artifacts/riasec_result_page_v2_agent/post-deploy-riasec-v2-20260622T082750Z \
  --json --no-ansi
```

Result summary:

- status: `success`
- asset file count: `68`
- source ledger valid: `true`
- asset inventory valid: `true`
- validation error count: `0`
- leak hit count: `0`
- `ready_for_runtime=false`
- `ready_for_production=false`

Negative guarantees:

- `runtime_use=staging_only`
- `production_use_allowed=false`
- `cms_write_performed=false`
- `runtime_change_performed=false`
- `frontend_fallback_allowed=false`
- `private_payload_exported=false`

## Staging Import Dry-Run Summary

Command:

```bash
cd backend
php artisan riasec:result-page-v2-agent staging-import-dry-run \
  --run-id=post-deploy-riasec-v2-20260622T082750Z-staging-import \
  --artifact-dir=artifacts/riasec_result_page_v2_agent/post-deploy-riasec-v2-20260622T082750Z \
  --json --no-ansi
```

Result summary:

- status: `success`
- selector-ready package count: `2`
- selector-ready asset count: `6`
- checksum inventory valid: `true`
- leak hit count: `0`
- errors: `[]`
- `ready_for_runtime=false`
- `ready_for_production=false`

Negative guarantees:

- `runtime_use=staging_only`
- `production_use_allowed=false`
- `cms_write_performed=false`
- `runtime_change_performed=false`
- `frontend_fallback_allowed=false`
- `private_payload_exported=false`

## Ops Staging Runner Summary

Command:

```bash
cd backend
php artisan riasec:result-page-ops-runner staging-dry-run \
  --run-id=post-deploy-riasec-v2-20260622T082750Z-ops-staging \
  --artifact-dir=artifacts/riasec_result_page_v2_agent/post-deploy-riasec-v2-20260622T082750Z \
  --mode=auto-to-staging \
  --scope-id=post-deploy-riasec-v2-staging-pilot \
  --simulate-external-blocker \
  --json --no-ansi
```

Result summary:

- status: `success`
- staging dry-run report created: `true`
- rendered preview smoke report created: `true`
- API smoke report created: `true`
- `production_execution_allowed_for_agent=false`
- `production_manual_gate_required=true`
- `cms_write_performed=false`
- `runtime_change_performed=false`

Negative guarantees:

- `bulk_content_generation_happened=false`
- `candidate_import_happened=false`
- `production_activation_happened=false`
- `runtime_switch_happened=false`
- `production_write_happened=false`
- `frontend_change_happened=false`

## Surface Coverage

The all-surface pilot QA evidence covered the expected result-page surfaces:

| Surface | QA decision | Expected payload state | Redaction state |
| --- | --- | --- | --- |
| `result_page` | `pass_staging_only` | `result_page_v2_wrapper_attached` | `full_only` |
| `pdf` | `pass_deferred_to_render_preview` | `payload_omitted_fail_closed` | `no_pdf_payload_export` |
| `share` | `pass_deferred_to_render_preview` | `payload_omitted_fail_closed` | `no_share_block_leak` |
| `history` | `pass_deferred_to_render_preview` | `payload_omitted_fail_closed` | `no_raw_history_vector` |
| `compare` | `pass_deferred_to_render_preview` | `payload_omitted_fail_closed` | `no_raw_compare_vector` |
| `locked` | `pass_fail_closed` | `payload_omitted_fail_closed` | `locked_payload_allowed_false` |
| `free` | `pass_fail_closed` | `payload_omitted_fail_closed` | `free_payload_allowed_false` |
| `low_quality` | `pass_staging_guarded` | `selector_inputs_quality_state_only` | `no_raw_quality_flags_export` |
| `fallback` | `pass_fail_closed` | `payload_omitted_fail_closed` | `frontend_fallback_forbidden` |

The selector coverage batch and golden-case evidence include `low_quality`, `fallback`, and `norm_unavailable` coverage markers.

## Validation

Backend validation:

```bash
cd backend
php artisan test --filter='RiasecResultPage(V2RuntimeWrapper|AllSurfacePilotQa|StagingImportHandoff|ProductionRuntime|ImportGate|SelectorCoverageBatch|RouteMatrixQaReport|AssetAgent)Test' --no-ansi
```

Result: `26 passed`, `623 assertions`.

Frontend rendered preview validation:

```bash
cd /Users/rainie/Desktop/GitHub/fap-web
./node_modules/.bin/vitest run \
  tests/contracts/riasec-result-rendered-preview-qa.contract.test.tsx \
  tests/contracts/riasec-trusted-result-v15-smoke-acceptance.contract.test.ts \
  tests/contracts/riasec-lifecycle-feedback-boundary.contract.test.tsx
```

Result: `3 passed` test files, `14 passed` tests.

## Non-Goals

This evidence package does not authorize or perform:

- CMS writes;
- production CMS import;
- production runtime enablement;
- staging or production environment flag mutation;
- frontend fallback content;
- public payload expansion;
- selector trace, source, QA, editor metadata, private score, raw score, vector, percentile, share block, attempt id, user id, or private URL exposure;
- production rollout.

## Go / No-Go

Current state: staging/pilot evidence is suitable for review and later manual gate preparation.

Production state: `NO-GO` for automatic production rollout. Any production import or rollout remains behind a separate manual approval gate with release snapshot, approval evidence, kill switch, rollback procedure, and post-deploy smoke requirements.
