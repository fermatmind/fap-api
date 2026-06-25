# EQ Agent Runtime Normal-Confidence Production Acceptance Report

Date: 2026-06-25

## 1. Executive Summary

Verdict: **Normal-confidence Agent runtime accepted: yes.**

This report verifies the EQ Agent deterministic/read-only runtime using a new production EQ-60 attempt with normal response quality. The earlier runtime smoke used a low-confidence attempt; this run specifically verifies the same Agent context and runtime path with `quality.level=A` and no quality flags.

Key result:

- A new anonymous EQ-60 production attempt was started, held for real elapsed time, and submitted after 372 server-measured seconds.
- The submitted result returned `quality.level=A`, `confidence_label=high`, and no quality flags.
- `/report-access` returned ready/full/all-free with no locked, blur, paywall, SKU, or offers.
- `/report` returned EQ v1.6 payload in both `en` and `zh-CN`.
- `/eq/agent-context` returned `ready=true` in both locales.
- `/eq/agent-runtime/messages` returned `200` deterministic read-only runtime responses in both locales.
- Both paid-language guardrail aliases are present and false in context/runtime: `can_create_paid_unlock_language=false` and `can_use_paid_unlock_language=false`.
- Production result pages in `en` and `zh` opened, did not auto-fetch Agent context/runtime, showed the Agent entry, lazy-fetched context after click, and posted runtime messages only after user send.
- No visible paywall, SKU, unlock, raw technical tags, or clickable SJT take entry appeared.

The accepted scope remains limited: deterministic/read-only runtime shell only. Live LLM/provider integration remains out of scope.

## 2. Deployment Evidence

| Surface | Evidence | Status |
|---|---:|---|
| Backend production revision | `950b81cd23df85fb1de4ada2dfc5205f758f3284` | Verified by read-only production `REVISION` |
| Backend deployed PR for production revision | PR #2418 `BIG5-COMMERCIAL-FIELD-BACKEND-AUTHORITY-FIX-01: Reconcile Big Five public commercial lookup state` | This SHA contains EQ Agent hardening commit |
| EQ Agent hardening PR | PR #2416 `PR-EQ-AGENT-HARDEN-01 EQ Agent guardrail alias normalization` | Merged as `37f8423392628068eecf952aaf918c5a73a9939d`; included in production SHA |
| Frontend production SHA | Not exposed by public headers in this smoke | Runtime UI evidence verified instead |
| Web | `https://www.fermatmind.com` | Production |
| API | `https://api.fermatmind.com` | Production |

Note: frontend SSH verification was not part of this docs-only smoke PR. The frontend SHA was therefore not asserted from server state. The report relies on production UI behavior and network evidence for the Agent drawer path.

## 3. Smoke Attempt

| Field | Value |
|---|---|
| Attempt ID | `6ffd0531-fa93-496e-aadc-03a9e3870623` |
| Anonymous ID | `eq-agent-smoke-normal-20260625-1h1z5mn3` |
| Submission method | Public guest auth + production EQ start/submit API |
| Locale used at start | `en` |
| Region used at start | `CN_MAINLAND` |
| Question count at start | `60` |
| Server-measured completion time | `372` seconds |
| Client duration sent | `485000` ms |
| Quality level | `A` |
| Quality flags | `[]` |
| Confidence label | `high` |
| Final score | `189` |

A separate immediate API submit attempt was intentionally not accepted as normal-confidence evidence because production quality rules correctly marked it `D/SPEEDING`. The accepted attempt above waited for real elapsed time before submit.

## 4. API Checks

### 4.1 Questions

| Check | Result |
|---|---|
| `GET /api/v0.3/scales/EQ_60/questions?locale=en` | `200` |
| Question items | `60` via `questions.items` |
| 50-question regression | Not observed |

### 4.2 Report Access

`GET /api/v0.3/attempts/6ffd0531-fa93-496e-aadc-03a9e3870623/report-access?locale=en`

```json
{
  "status": 200,
  "access_state": "ready",
  "report_state": "ready",
  "variant": "full",
  "access_level": "full",
  "locked": false,
  "upgrade_sku": null,
  "offers": [],
  "blur_others": false
}
```

### 4.3 Report Payload

`GET /api/v0.3/attempts/6ffd0531-fa93-496e-aadc-03a9e3870623/report?locale=en`

```json
{
  "status": 200,
  "generating": false,
  "eq_report_mode": "self_report",
  "measurement_type": "self_report_trait_mixed_ei",
  "report_version": "eq_report_v5_assets_commercial_ready_v1_6",
  "quality": {
    "level": "A",
    "confidence_label": "high",
    "flags": []
  },
  "dimension_keys": ["EM", "ER", "RM", "SA"],
  "has_assets": true,
  "next_module": {
    "status": "planned",
    "available": false,
    "module_code": "EQ_SJT_16",
    "cta_asset_id": "eq.sjt_bridge.planned"
  }
}
```

`GET /api/v0.3/attempts/6ffd0531-fa93-496e-aadc-03a9e3870623/report?locale=zh-CN`

```json
{
  "status": 200,
  "generating": false,
  "eq_report_mode": "self_report",
  "measurement_type": "self_report_trait_mixed_ei",
  "report_version": "eq_report_v5_assets_commercial_ready_v1_6",
  "quality": {
    "level": "A",
    "confidence_label": "high",
    "flags": []
  },
  "dimension_keys": ["SA", "ER", "EM", "RM"],
  "has_assets": true,
  "core_title": "有觉察，调节不足",
  "next_module": {
    "available": false,
    "module_code": "EQ_SJT_16",
    "status": "planned",
    "cta_asset_id": "eq.sjt_bridge.planned"
  }
}
```

## 5. Agent Context Checks

Endpoint:

```text
GET /api/v0.3/attempts/6ffd0531-fa93-496e-aadc-03a9e3870623/eq/agent-context?locale={locale}&intent=understand_my_result
```

| Locale | HTTP status | `ready` | Context locale | SJT state |
|---|---:|---:|---|---|
| `en` | `200` | `true` | `en` | `planned`, `available=false` |
| `zh-CN` | `200` | `true` | `zh-CN` | `planned`, `available=false` |

Guardrails in both locales:

```json
{
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
```

## 6. Agent Runtime Message Checks

Endpoint:

```text
POST /api/v0.3/attempts/6ffd0531-fa93-496e-aadc-03a9e3870623/eq/agent-runtime/messages
```

| Locale | HTTP status | `ok` | `ready` | Schema |
|---|---:|---:|---:|---|
| `en` | `200` | `true` | `true` | `eq.agent_runtime_response.v1` |
| `zh-CN` | `200` | `true` | `true` | `eq.agent_runtime_response.v1` |

Runtime response evidence:

```text
I will explain the core judgment already in your report; I will not rescore or replace it.
```

The runtime response cites stable report/content assets and keeps the same read-only guardrails as context. It does not mutate the report, scores, formulation, sections, SJT state, or commerce state.

## 7. Frontend Page Smoke

Production URLs:

- `https://www.fermatmind.com/en/result/6ffd0531-fa93-496e-aadc-03a9e3870623`
- `https://www.fermatmind.com/zh/result/6ffd0531-fa93-496e-aadc-03a9e3870623`

The browser smoke injected the same anonymous ID and guest token into local storage so the result page used the same owner context as the submitted attempt. Tokens were not written to this report or committed.

| Check | en | zh |
|---|---:|---:|
| Result page opens | Pass | Pass |
| EQ content visible | Pass | Pass |
| Agent entry guard visible | Pass | Pass |
| Context requests before click | `0` | `0` |
| Runtime requests before click | `0` | `0` |
| Click Agent entry | Pass | Pass |
| Context lazy fetch after click | `GET 200` | `GET 200` |
| Agent ready panel visible | Pass | Pass |
| Send user message | Pass | Pass |
| Runtime lazy POST after send | `POST 200` | `POST 200` |
| Runtime response visible | Pass | Pass |
| SJT remains planned/unavailable | Pass | Pass |
| No paywall/SKU/unlock visible text | Pass | Pass |
| No raw technical tags visible text | Pass | Pass |

Network evidence after user send:

```json
[
  {"method":"GET","status":200,"path":"/eq/agent-context"},
  {"method":"POST","status":200,"path":"/eq/agent-runtime/messages"}
]
```

## 8. Negative Checks

No user-visible occurrences were observed in the production pages:

- `解锁`
- `购买`
- `付费`
- `premium`
- `upgrade`
- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `profile:*`
- `quality_level:*`
- `focus:*`
- `bucket:*`

The API summary contains `upgrade_sku: null` as a compatibility field in `report-access`; this is not user-facing paywall language and is explicitly null.

## 9. Artifacts

Screenshots and raw smoke summaries are stored under `/tmp` and are not committed:

- `/tmp/eq_agent_smoke_attempt_normal.json`
- `/tmp/eq_agent_smoke_api_summary.json`
- `/tmp/eq_agent_normal_confidence_smoke_auth_2026-06-25T07-36-35-031Z/ui-summary.json`
- `/tmp/eq_agent_normal_confidence_smoke_auth_2026-06-25T07-36-35-031Z/result-en-before-agent.png`
- `/tmp/eq_agent_normal_confidence_smoke_auth_2026-06-25T07-36-35-031Z/result-en-after-agent.png`
- `/tmp/eq_agent_normal_confidence_smoke_auth_2026-06-25T07-36-35-031Z/result-zh-before-agent.png`
- `/tmp/eq_agent_normal_confidence_smoke_auth_2026-06-25T07-36-35-031Z/result-zh-after-agent.png`

## 10. Risks and Follow-Ups

- Frontend production SHA was not exposed by public headers and was not reverified through frontend SSH in this docs-only smoke. Runtime UI behavior passed and proves the deployed frontend includes the Agent drawer path.
- The smoke used one new anonymous production EQ attempt. This is an intentional production QA side effect and should be retained as audit evidence.
- Live LLM/provider integration remains deferred. This acceptance only covers deterministic read-only runtime shell behavior.
- Future Agent runtime v2 should add provider-level refusal/eval gates before any model call is enabled.

## 11. Final Decision

`Normal-confidence Agent runtime accepted: yes`

Allowed next phase: planning for controlled Agent runtime v2/provider integration may start only as a separate PR train with explicit safety, eval, telemetry, and rollback gates.
