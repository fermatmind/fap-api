# EQ Agent Runtime V2 Provider Design

## 1. Purpose

EQ Agent Runtime V2 introduces an LLM-provider-ready architecture for the EQ result-page Agent while keeping the current deterministic read-only runtime as the production default.

The first provider strategy is OpenAI-first, but live LLM calls must remain disabled unless a staging or later production rollout explicitly enables the feature flag. The Agent remains an explanation layer over the existing EQ report context and resolved content assets. It must not become the authority for scores, formulations, report sections, SJT availability, commerce state, or content assets.

## 2. Non-Goals

- Do not replace `Eq60ReportComposer`, content packs, or resolved report assets.
- Do not let the provider rescore, reclassify, or override `core_formulation_id`.
- Do not enable EQ-SJT or create any SJT take entry.
- Do not introduce paid unlock language, SKU suggestions, or premium-report claims.
- Do not persist chat history or raw provider prompts/responses as business records.
- Do not enable production LLM traffic in V2-01, V2-02, or V2-03.

## 3. Current Runtime Baseline

Current production behavior is deterministic:

- `GET /api/v0.3/attempts/{id}/eq/agent-context`
- `POST /api/v0.3/attempts/{id}/eq/agent-runtime/messages`
- response schema: `eq.agent_runtime_response.v1`
- mode: `deterministic_read_only`
- context source: report payload, resolved assets, intent map, guardrails, forbidden-claim metadata
- guardrails: read-only, no report mutation, no score mutation, no formulation override, no SJT enablement, no paid-unlock language, no raw technical tags

V2 must preserve this outer contract so the existing frontend drawer continues to work without becoming provider-aware.

## 4. Provider Strategy

### 4.1 Default

OpenAI is the first real provider adapter, but provider traffic is off by default:

- `EQ_AGENT_LLM_ENABLED=false`
- `EQ_AGENT_LLM_PROVIDER=openai`
- `EQ_AGENT_LLM_STAGING_ONLY=true`

The deterministic runtime remains the fallback and production default until a later explicit rollout PR changes the policy.

### 4.2 Provider Abstraction

The backend should introduce a small provider boundary, for example:

- `EqAgentProviderClient`
- `EqAgentProviderRequest`
- `EqAgentProviderResponse`
- `OpenAiEqAgentProviderClient`
- `DeterministicEqAgentRuntimeResponder` remains available as fallback

The runtime endpoint chooses the provider only after context readiness and guardrail validation pass.

## 5. Retrieval Input Contract

The provider request must be built only from already-authoritative, sanitized runtime context:

- `locale`
- `user_message`
- `intent_context`
- `report_context`
- `resolved_assets`
- `agent_knowledge`
- `guardrails`
- selected `source_asset_ids`
- known forbidden claims and replacement boundaries

The request must not include secrets, raw database rows, tokens, private URLs, raw technical tags, or mutable report-authoring controls.

## 6. Prompt Boundary

The provider prompt must enforce:

- Explain only the existing EQ report and resolved assets.
- Do not rescore or reinterpret the assessment beyond provided IDs and assets.
- Do not claim the test measures true emotional ability.
- Do not compare the module to MSCEIT.
- Do not use clinical, hiring, certification, guarantee, or job-performance prediction language.
- Do not offer paid unlocks, premium reports, SKU paths, or purchase prompts.
- Keep SJT as planned/unavailable unless the backend context explicitly says otherwise.
- Preserve low-confidence boundaries and recommend cautious reading or retest when the report context indicates low confidence.

## 7. Response Schema

The public runtime endpoint should continue returning `eq.agent_runtime_response.v1`.

Provider output should be normalized into the existing outer shape:

- `schema`
- `ok`
- `ready`
- `mode`
- `attempt_id`
- `result_id`
- `scale_code`
- `locale`
- `intent`
- `intent_context`
- `assistant_response`
- `safety`
- `guardrails`
- `next_module`
- `context_summary`

When LLM mode is active, `mode` may be `llm_provider_read_only`, but the public fields and guardrail semantics must remain compatible with the deterministic response. The frontend must not consume raw provider output.

## 8. Safety Validation

Before returning an LLM response, the backend must validate:

- response is valid JSON or can be normalized into the response schema
- no forbidden claims appear
- no paid/unlock/SKU language appears
- no raw technical tags appear
- no SJT take entry is created when `next_module.available=false`
- low-confidence reports do not receive strong personality claims
- source asset IDs are stable content-pack/report asset IDs

If validation fails, the endpoint must return deterministic fallback rather than an unsafe provider response.

## 9. Fallback Policy

Fallback to deterministic mode when:

- feature flag is off
- provider config is missing
- context is not ready
- guardrails are unsafe or incomplete
- provider times out
- provider returns an error
- provider output fails schema validation
- provider output violates safety checks

The response should remain successful where possible, but `mode` should clearly indicate deterministic fallback.

## 10. Staging-Only Rollout

V2-04 may run a staging-only smoke after:

- backend provider abstraction is merged
- staging deploy includes the provider code
- staging flag is explicitly enabled
- required provider credentials are configured in staging
- production remains disabled

The smoke must verify:

- en and zh-CN locale behavior
- normal-confidence and low-confidence boundaries
- forbidden-claim prompts
- SJT planned/unavailable state
- no paywall/SKU/unlock/raw-tag language
- latency, error rate, and fallback behavior
- approximate provider cost per message if available

## 11. Future Rollout Boundary

Production LLM rollout is not part of V2-01 through V2-04. A later explicit rollout PR must define production flags, rate limits, monitoring, budget ceilings, incident rollback, logging policy, and acceptance criteria.
