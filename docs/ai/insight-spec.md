# AI Insight Spec (PR12)

## Overview
AI Insights provide a summarized, explainable insight payload for a given time period. Outputs are traceable via `evidence_json`, `input_hash`, `prompt_version`, and model metadata.

## API Endpoints
### POST /api/v0.3/insights/generate
Request JSON:
```json
{
  "period_type": "week",
  "period_start": "2026-01-21",
  "period_end": "2026-01-28",
  "anon_id": "anon_abc" 
}
```
Notes:
- `period_type`: `week` or `month`
- `period_start` / `period_end`: date strings (YYYY-MM-DD)
- `anon_id` required if no authenticated user

Response JSON:
```json
{
  "ok": true,
  "id": "<uuid>",
  "status": "queued"
}
```

### GET /api/v0.3/insights/{id}
Response JSON:
```json
{
  "ok": true,
  "id": "<uuid>",
  "status": "succeeded",
  "output_json": { "summary": "...", "strengths": [], "risks": [], "actions": [], "disclaimer": "..." },
  "evidence_json": [
    {
      "type": "type_code",
      "source": "attempt",
      "pointer": "attempts.type_code",
      "quote": "type_code=INTJ",
      "hash": "<sha256>",
      "created_at": "2026-01-28T12:34:56+00:00"
    }
  ],
  "tokens_in": 120,
  "tokens_out": 160,
  "cost_usd": 0.0006,
  "prompt_version": "v1.0.0",
  "model": "mock-model",
  "provider": "mock",
  "error_code": ""
}
```

### POST /api/v0.3/insights/{id}/feedback
Request JSON:
```json
{
  "rating": 4,
  "reason": "helpful",
  "comment": "Clear summary."
}
```
Response JSON:
```json
{ "ok": true, "id": "<uuid>" }
```

## output_json Structure
- `summary` (string)
- `strengths` (array of strings)
- `risks` (array of strings)
- `actions` (array of strings)
- `disclaimer` (string, required)

## evidence_json Structure
Array of evidence objects:
- `type` (string) — evidence type (e.g., `type_code`, `score_pct`, `axis_state`)
- `source` (string) — origin (e.g., `attempt`, `result`, `psychometrics`)
- `pointer` (string) — canonical field pointer
- `quote` (string) — short, safe excerpt of structured data
- `hash` (string) — sha256 of `{type,source,pointer,quote}`
- `created_at` (ISO 8601 string)

## Evidence & Privacy Guardrails
Forbidden in evidence:
- Raw user answers
- Free-form user text
- Email/phone/PII
- Tokens or billing secrets

Allowed:
- Derived, structured fields (e.g., type_code, norm version, score percentiles)
- Non-identifying metadata (pack/version pointers)

## Status Values
- `queued`, `running`, `succeeded`, `failed`

## Error Codes
- `AI_DISABLED`
- `AI_BUDGET_EXCEEDED`
- `AI_BUDGET_LEDGER_UNAVAILABLE`
- `AI_DISPATCH_FAILED`
- `AI_INSIGHT_FAILED`
