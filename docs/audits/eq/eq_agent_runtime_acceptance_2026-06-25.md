# EQ Agent Runtime Production Acceptance Report

## 1. Executive Summary

Verdict: **P0/P1 closed. Agent runtime phase allowed: yes, with controlled read-only runtime shell only.**

This report updates the original production smoke from 2026-06-25 after the backend production deploy of the runtime route.

Original blocker:

- EQ v1.6 report and `/eq/agent-context` were live.
- Frontend showed the EQ Agent entry and drawer.
- `POST /api/v0.3/attempts/{id}/eq/agent-runtime/messages` returned `404 NOT_FOUND`.
- The acceptance decision was `Agent runtime phase allowed: no`.

Retest result after backend deploy:

- Backend production revision is now `58e642c98cce523eafd14202eec5627d45aa38aa`.
- Production `route:list` now includes `eq/agent-runtime/messages`.
- Runtime message endpoint returns `200` for both `en` and `zh`.
- Result page opens in both locales and uses `EQResultV5`.
- Agent entry does not auto-fetch context/runtime on page load.
- Clicking the Agent entry lazy-fetches `/eq/agent-context`.
- Sending a message lazy-posts `/eq/agent-runtime/messages`.
- Runtime response is deterministic, read-only, low-confidence aware, and bounded by backend content authority.
- No paywall, SKU, unlock language, raw technical tags, or SJT take entry appeared in the visible page smoke.

The first production runtime phase is accepted only as a deterministic/read-only shell. Live LLM/provider integration remains out of scope and requires a separate PR train and safety gate.

## 2. Deployment Evidence

| Surface | SHA / evidence | Status |
|---|---:|---|
| Backend production release | `fap-api-20260625-58e642c9` | Deployed successfully |
| Backend production revision | `58e642c98cce523eafd14202eec5627d45aa38aa` | Verified by `REVISION` |
| Backend latest PR in deployed SHA | PR #2412 `PR-EQ-AGENT-RUNTIME-04 EQ Agent runtime production acceptance report` | Merged |
| Backend route cache | `php artisan route:list --path=agent-runtime` | Runtime route present |
| Backend schema gate | `php artisan fap:schema:verify` | Pass |
| Backend ops health snapshot | `php artisan ops:healthz-snapshot` | Pass |
| Backend public content verification | `php artisan release:verify-public-content` | Pass |
| Backend queues | supervisor queue workers | Running |
| Frontend production | existing deployed EQ Agent drawer | Page smoke pass |

Production URLs:

- Web: `https://fermatmind.com`
- API: `https://api.fermatmind.com`

## 3. Smoke Attempt

| Field | Value |
|---|---|
| Original artifact directory | `/tmp/eq_agent_runtime_prod_smoke_20260625_112156` |
| Retest artifact directory | `/tmp/eq_agent_runtime_prod_smoke_update_20260625_115009` |
| Anonymous ID | `anon_eq_runtime_smoke_20260625_112156` |
| Attempt ID | `f5b08634-0322-4509-b4c4-b1652189496a` |
| Locale used for original submit | `en` |
| Retest method | Reused existing anonymous production attempt and token; no new production attempt was created |
| Quality note | Existing attempt is `quality.level=D` with `SPEEDING`, so the retest also verifies the low-confidence runtime boundary. |

## 4. Backend API Checks

### 4.1 Questions

| Endpoint | Result |
|---|---|
| `GET /api/v0.3/scales/EQ_60/questions` | 200, 60 items |

### 4.2 Report Access

`GET /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/report-access?locale=en`

Previously observed and still required:

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

Previously observed and still required:

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

### 4.4 Agent Context

`GET /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/eq/agent-context?locale=en&intent=understand_my_result`

Retest observed via production page network:

```json
{
  "ready": true,
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

The `zh` result page requested the same context path with localized context and also returned 200.

### 4.5 Agent Runtime Message

`POST /api/v0.3/attempts/f5b08634-0322-4509-b4c4-b1652189496a/eq/agent-runtime/messages`

Retest observed for `en`:

```json
{
  "ok": true,
  "ready": true,
  "guardrails": {
    "read_only": true,
    "can_mutate_report": false,
    "can_mutate_scores": false,
    "can_override_formulation": false,
    "can_enable_sjt": false,
    "can_create_paid_unlock_language": false,
    "can_use_paid_unlock_language": false,
    "can_expose_raw_technical_tags": false,
    "content_authority": "backend_content_pack_and_report_composer"
  }
}
```

Retest observed for `zh`:

```json
{
  "ok": true,
  "ready": true,
  "schema": "eq.agent_runtime_response.v1",
  "guardrails": {
    "read_only": true,
    "can_mutate_report": false,
    "can_mutate_scores": false,
    "can_override_formulation": false,
    "can_enable_sjt": false,
    "can_create_paid_unlock_language": false,
    "can_use_paid_unlock_language": false,
    "can_expose_raw_technical_tags": false,
    "content_authority": "backend_content_pack_and_report_composer"
  }
}
```

The previous `404 NOT_FOUND` P0 is closed.

## 5. Frontend Runtime Drawer Checks

Production result URLs:

- `https://fermatmind.com/en/result/f5b08634-0322-4509-b4c4-b1652189496a`
- `https://fermatmind.com/zh/result/f5b08634-0322-4509-b4c4-b1652189496a`

Automated page-level smoke used Playwright and recorded network calls around the Agent entry.

| Check | en | zh |
|---|---:|---:|
| EQ result page opens | Pass | Pass |
| `EQResultV5` visible | Pass | Pass |
| Agent entry visible before click | Pass | Pass |
| Context requests before click | 0 | 0 |
| Runtime requests before click | 0 | 0 |
| Click Agent entry opens drawer | Pass | Pass |
| Context fetched after click | 200 | 200 |
| Runtime not fetched before send | Pass | Pass |
| Runtime fetched after send | 200 | 200 |
| Runtime response visible | Pass | Pass |
| No paywall / SKU / unlock copy | Pass | Pass |
| No raw technical tags visible | Pass | Pass |
| SJT remains planned/unavailable | Pass | Pass |

Runtime response excerpts:

```text
I will explain the core judgment already in your report; I will not rescore or replace it.
```

```text
我会先按报告里的核心判断解释，不会重新打分或替换你的结果。
```

Screenshots saved under `/tmp` and not committed:

- `/tmp/eq_agent_runtime_prod_smoke_update_20260625_115009/result_en_before_agent.png`
- `/tmp/eq_agent_runtime_prod_smoke_update_20260625_115009/result_en_agent_response.png`
- `/tmp/eq_agent_runtime_prod_smoke_update_20260625_115009/result_zh_before_agent.png`
- `/tmp/eq_agent_runtime_prod_smoke_update_20260625_115009/result_zh_agent_response.png`
- `/tmp/eq_agent_runtime_prod_smoke_update_20260625_115009/ui_runtime_smoke_summary.json`

## 6. Issue Resolution

### P0: Agent runtime endpoint unavailable in production

Status: **Closed.**

Before backend deploy:

```json
{
  "ok": false,
  "error_code": "NOT_FOUND",
  "message": "Not Found"
}
```

Root cause:

- Production backend revision was `a06ac49acb6a06446978f861cb8a9e9126c77436`.
- That revision did not include `eq/agent-runtime/messages`.
- Production `route:list --path=agent-runtime` returned no runtime route.

Fix:

- Backend production deployed `58e642c98cce523eafd14202eec5627d45aa38aa` with release `fap-api-20260625-58e642c9`.

After deploy:

- Production `REVISION` equals `58e642c98cce523eafd14202eec5627d45aa38aa`.
- Production `route:list --path=agent-runtime` includes the runtime route.
- Runtime endpoint returns 200 for `en` and `zh`.

### P1: Guardrail field mismatch could fail closed

Status: **Closed for runtime response path.**

Evidence:

- Runtime response now includes both:
  - `can_create_paid_unlock_language=false`
  - `can_use_paid_unlock_language=false`
- Frontend drawer successfully reaches runtime response state in both `en` and `zh`.

Residual note:

- Agent context still returns `can_create_paid_unlock_language=false` but does not include `can_use_paid_unlock_language=false`.
- This is acceptable in the current deployed drawer because the context ready state passes and runtime response includes the compatibility alias.
- Future cleanup can make context and runtime guardrail names identical, but it is not blocking production acceptance.

## 7. Forbidden Language / Boundary Checks

Observed user-visible result page and Agent drawer did not expose:

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
- `premium`
- `upgrade`

SJT remains unavailable:

```json
{
  "available": false,
  "module_code": "EQ_SJT_16",
  "status": "planned"
}
```

Runtime boundaries:

- Agent does not rescore.
- Agent does not replace formulation.
- Agent does not mutate report sections.
- Agent does not enable SJT.
- Agent does not create paid unlock language.
- Agent does not expose raw technical tags.

## 8. Risks and Follow-Ups

### P2: Smoke attempt is low-confidence

The reused production attempt is `quality.level=D` with `SPEEDING`.

Impact:

- This is acceptable for closing runtime delivery because it verifies low-confidence boundary behavior.
- It does not prove normal-confidence Agent answer quality.

Follow-up:

- Run a future smoke with a normal-speed production attempt if normal-confidence answer tone needs live evidence.

### P2: Context/runtime guardrail naming should be normalized

Runtime response includes both paid-language guardrail names, while context currently uses `can_create_paid_unlock_language`.

Follow-up:

- Normalize both context and runtime payloads to expose both aliases or one documented canonical field.

### P3: Console showed a generic 404 resource

Playwright console captured a generic resource 404 during page load. It did not block EQ result, context, or runtime flows.

Follow-up:

- Inspect separately only if frontend asset logs show user-visible impact.

## 9. Final Decision

Agent runtime phase allowed: **yes, for deterministic/read-only runtime shell.**

Allowed next step:

- Proceed to tightly scoped Agent runtime iteration, analytics, and safety evaluation for the deterministic shell.

Still not allowed:

- Live LLM/provider integration without a separate PR.
- Agent mutation of report, scores, formulations, sections, content assets, or SJT state.
- Any hiring, clinical, certified ability, MSCEIT-like, guaranteed outcome, job-performance prediction, paywall, or unlock claims.
