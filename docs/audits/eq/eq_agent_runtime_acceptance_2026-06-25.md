# EQ Agent Runtime Production Acceptance Report

## 1. Executive Summary

Verdict: **P0 blocked. Agent runtime phase allowed: no.**

The EQ v1.6 report path and read-only Agent context path are live in production, but the new deterministic runtime message endpoint is not deliverable in production:

- EQ question delivery returns 60 items for both `en` and `zh-CN`.
- Anonymous EQ attempt creation, submit, `report-access`, and `/report` delivery succeeded.
- `/report` returns the v1.6 payload with `eq_report_mode=self_report`, `measurement_type=self_report_trait_mixed_ei`, `scores.dimensions`, resolved assets, and `next_module.available=false`.
- `/eq/agent-context` returns `ready=true`, `schema=eq.agent_context.v1`, `read_only=true`, and mutation/SJT guardrails disabled for both `en` and `zh-CN`.
- Production frontend has the EQ Agent entry and opens the runtime drawer.
- **P0:** `POST /api/v0.3/attempts/{id}/eq/agent-runtime/messages` returns `404 NOT_FOUND` in both `en` and `zh-CN`.
- **P1:** production Agent context uses `can_create_paid_unlock_language=false`, while the frontend runtime drawer guard introduced by PR-EQ-AGENT-RUNTIME-03 expects `can_use_paid_unlock_language=false`; the drawer therefore fails closed before a message can be sent from the UI.

Do not proceed to live Agent runtime rollout until backend runtime route delivery and guardrail field compatibility are fixed and re-smoked.

## 2. Deployment Evidence

| Surface | SHA / evidence | Status |
|---|---:|---|
| Backend main repo at report time | `0d159265ec9c42198c062d32c65ce951101faab6` | Current `origin/main` in clean worktree |
| Backend production revision | Not exposed by public health/version endpoint | Unknown |
| Frontend production deploy | `fca1b41153709c39580ee3a1f9f5e939ebce3c16` | GitHub `Deploy Web Production` succeeded |
| Frontend PR | `PR-EQ-AGENT-RUNTIME-03`, fap-web PR #1433 | Merged and deployed |
| Backend runtime PRs | PR-EQ-AGENT-RUNTIME-01 / 02 | Merged to backend main, production route not observed |

Production URLs:

- Web: `https://fermatmind.com`
- API: `https://api.fermatmind.com`

## 3. Smoke Attempt

| Field | Value |
|---|---|
| Artifact directory | `/tmp/eq_agent_runtime_prod_smoke_20260625_112156` |
| Anonymous ID | `anon_eq_runtime_smoke_20260625_112156` |
| Attempt ID | `f5b08634-0322-4509-b4c4-b1652189496a` |
| Locale used for submit | `en` |
| Submission method | Anonymous production API attempt using a guest token |
| Answer pattern | Existing EQ golden-case answer pattern, submitted once |
| Low-confidence note | Production scored this automated submit as `quality.level=D` with `SPEEDING` because elapsed completion time was recorded as 1 second. This was not used to force abnormal answer content, but it does verify low-confidence fail-safe rendering. |

## 4. API Checks

### 4.1 Questions

| Endpoint | Result |
|---|---|
| `GET /api/v0.3/scales/EQ_60/questions?locale=en&region=GLOBAL` | 60 items |
| `GET /api/v0.3/scales/EQ_60/questions?locale=zh-CN&region=CN_MAINLAND` | 60 items |

### 4.2 Report Access

`GET /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/report-access?locale=en`

Observed:

```json
{
  "ok": true,
  "access_state": "ready",
  "report_state": "ready",
  "payload_locked": false,
  "payload_variant": "full",
  "payload_access_level": "full",
  "offers": [],
  "upgrade_sku": null,
  "blur_others": false
}
```

### 4.3 Report Payload

`GET /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/report?locale=en`

Observed:

```json
{
  "ok": true,
  "generating": false,
  "variant": "full",
  "access_level": "full",
  "locked": false,
  "report_version": "eq_report_v5_assets_commercial_ready_v1_6",
  "eq_report_mode": "self_report",
  "measurement_type": "self_report_trait_mixed_ei",
  "has_scores_dimensions": true,
  "has_assets": true,
  "next_module": {
    "status": "planned",
    "available": false,
    "module_code": "EQ_SJT_16",
    "cta_asset_id": "eq.sjt_bridge.planned"
  }
}
```

`GET /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/report?locale=zh-CN`

Observed:

```json
{
  "ok": true,
  "generating": false,
  "locale": "zh-CN",
  "report_version": "eq_report_v5_assets_commercial_ready_v1_6",
  "next_module": {
    "available": false,
    "module_code": "EQ_SJT_16",
    "status": "planned",
    "cta_asset_id": "eq.sjt_bridge.planned"
  }
}
```

### 4.4 Agent Context

`GET /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/eq/agent-context?locale=en&intent=understand_my_result`

Observed:

```json
{
  "ok": true,
  "ready": true,
  "schema": "eq.agent_context.v1",
  "locale": "en",
  "guardrails": {
    "read_only": true,
    "can_mutate_report": false,
    "can_mutate_scores": false,
    "can_override_formulation": false,
    "can_enable_sjt": false,
    "can_create_paid_unlock_language": false,
    "can_expose_raw_technical_tags": false,
    "content_authority": "backend_content_pack_and_report_composer"
  }
}
```

`zh-CN` returned the same read-only guardrails with `locale=zh-CN`.

### 4.5 Agent Runtime Message

`POST /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/eq/agent-runtime/messages`

Observed for both `en` and `zh-CN`:

```json
{
  "ok": false,
  "error_code": "NOT_FOUND",
  "message": "Not Found"
}
```

This is a P0 blocker for runtime acceptance.

## 5. Frontend Checks

Production result URLs:

- `https://fermatmind.com/en/result/f5b08634-0322-4509-b4c4-b1652189496a`
- `https://fermatmind.com/zh/result/f5b08634-0322-4509-b4c4-b1652189496a`

Observed:

| Check | Result |
|---|---|
| EQ result page opens | Pass |
| EQ v1.6 report sections visible | Pass |
| Agent entry visible | Pass |
| Drawer opens on click | Pass |
| Agent context auto-fetch before click | Not observed |
| Runtime response visible after message | Fail |
| Runtime unavailable UI visible | Not observed in the script summary |
| No paywall / SKU / locked / blur text | Pass |
| No raw technical tags | Pass |
| SJT remains planned/unavailable | Pass |
| English route | Pass with low-confidence report |
| Chinese route | Pass; Chinese text visible |

UI smoke summary:

```json
{
  "hasEqAgentEntry": true,
  "drawerVisible": true,
  "runtimeUnavailable": false,
  "runtimeResponse": false,
  "enForbiddenVisible": false,
  "zhContainsChinese": true,
  "zhForbiddenVisible": false
}
```

Screenshots saved under `/tmp` and not committed:

- `/tmp/eq_agent_runtime_prod_smoke_20260625_112156/result_en_before_agent.png`
- `/tmp/eq_agent_runtime_prod_smoke_20260625_112156/result_en_agent_drawer.png`
- `/tmp/eq_agent_runtime_prod_smoke_20260625_112156/result_zh_before_agent.png`

## 6. Issues

### P0: Agent runtime endpoint is not available in production

Evidence:

- Direct production API call to `POST /api/v0.3/attempts/{id}/eq/agent-runtime/messages` returns `404 NOT_FOUND`.
- UI drawer cannot produce a runtime response.

Likely cause:

- Production backend does not yet include or expose the PR-EQ-AGENT-RUNTIME-01 route, or route/cache/deploy state is behind backend main.

Impact:

- PR-EQ-AGENT-RUNTIME-03 frontend drawer is deployed, but the real runtime message path is unavailable.
- Agent runtime phase cannot be accepted.

Required next action:

- Run backend production deploy readiness for the backend SHA that contains PR-EQ-AGENT-RUNTIME-01/02, deploy if needed after explicit approval, then re-run this smoke.

### P1: Agent context guardrail field mismatch blocks frontend ready state

Evidence:

- Production context returns `can_create_paid_unlock_language=false`.
- Frontend guard from PR-EQ-AGENT-RUNTIME-03 expects `can_use_paid_unlock_language=false`.
- The drawer opens, but the script did not observe message input / runtime unavailable / runtime response state.

Impact:

- Even after backend runtime route is deployed, the frontend may continue to fail closed unless the guardrail alias is normalized.

Required next action:

- Either backend should also return `can_use_paid_unlock_language=false`, or frontend should accept the existing `can_create_paid_unlock_language=false` as the equivalent no-paid-language guard.

### P2: Low-confidence path was triggered by automated submit timing

Evidence:

- Report quality: `level=D`, `flags=["SPEEDING"]`, `completion_time_seconds=1`.

Impact:

- This is acceptable for this smoke because it verifies the low-confidence fail-safe path does not display strong personality claims.
- Future production smoke should use the real browser take flow or a delayed API submit if a normal-confidence report is required.

## 7. Forbidden Language / Boundary Checks

Observed user-visible result page and payload summaries did not expose:

- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `profile:*`
- `quality_level:*`
- `focus:*`
- `bucket:*`
- `locked`
- `blur`
- `paywall`
- `解锁`
- `购买`
- `付费`

SJT remains:

```json
{
  "available": false,
  "module_code": "EQ_SJT_16",
  "status": "planned"
}
```

## 8. Final Decision

Agent runtime phase allowed: **no**.

Reason:

1. P0 production runtime endpoint returns `404 NOT_FOUND`.
2. P1 frontend/backend guardrail field naming mismatch prevents confident drawer ready-state acceptance.

Recommended next PR:

- Backend/deploy gate: confirm backend production deploy contains PR-EQ-AGENT-RUNTIME-01/02 route.
- If backend is current but route still 404, fix backend route/cache exposure.
- Frontend/backend contract fix: normalize `can_create_paid_unlock_language` vs `can_use_paid_unlock_language`.
- Re-run production smoke and update this acceptance report or create a follow-up acceptance report once P0/P1 are closed.
