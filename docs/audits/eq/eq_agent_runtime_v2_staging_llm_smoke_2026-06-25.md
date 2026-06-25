# EQ Agent Runtime V2 Staging LLM Smoke Report

Date: 2026-06-25

## 1. Executive Summary

Verdict: **LLM mode deferred by product decision; deterministic fallback accepted**.

The EQ Agent Runtime V2 provider code was present on staging and staging had OpenAI provider configuration present, but direct outbound access from staging to the OpenAI Responses API timed out. The runtime correctly failed closed into deterministic read-only mode, kept all report/content authority guardrails intact, and did not expose paywall, SKU, SJT entry, or raw technical tags.

This report was merged as evidence for `PR-EQ-AGENT-RUNTIME-V2-04`, but it does **not** approve live LLM rollout. On 2026-06-25, the product decision changed: keep EQ Agent on deterministic/read-only runtime and do not continue the LLM smoke lane now.

## 2. Scope

This was a staging-only smoke of EQ Agent Runtime V2 provider behavior.

In scope:
- Verify deployed staging backend contains the V2 provider abstraction.
- Verify EQ report, Agent context, and Agent runtime remain deliverable.
- Verify OpenAI provider configuration presence without exposing secrets.
- Verify safe deterministic fallback when provider access fails.
- Record blocker and next actions.

Out of scope:
- No production LLM enablement.
- No frontend changes.
- No report, score, formulation, SJT, commerce, or content mutation.
- No persistent chat history.
- No raw prompt/response storage in business data.

## 3. Environment

| Item | Value |
| --- | --- |
| Web | `https://staging.fermatmind.com` |
| API | `https://staging-api.fermatmind.com` |
| Staging revision observed | `fed95c351d57ed918d9f025100ec693f8fb9ac5d` |
| Required V2-03 merge commit | `c0f0169ca1d61bed029198095c57772b5eb4bebf` |
| V2-03 included in staging revision | Yes |
| Artifact directory | `/tmp/eq_agent_runtime_v2_staging_smoke_20260625_110117` |

## 4. Staging LLM Configuration

The staging environment was configured for an LLM smoke attempt, then returned to a safe disabled state after the connectivity blocker was confirmed.

During smoke:
- `EQ_AGENT_LLM_ENABLED=true`
- `EQ_AGENT_LLM_PROVIDER=openai`
- `EQ_AGENT_LLM_STAGING_ONLY=true`
- `EQ_AGENT_OPENAI_API_KEY` present
- `EQ_AGENT_OPENAI_MODEL=gpt-4.1-mini`

Final safe state after smoke:
- `EQ_AGENT_LLM_ENABLED=false`
- `EQ_AGENT_LLM_PROVIDER=openai`
- `EQ_AGENT_LLM_STAGING_ONLY=true`
- `EQ_AGENT_OPENAI_API_KEY` present
- `EQ_AGENT_OPENAI_MODEL=gpt-4.1-mini`

No secret value is recorded in this report.

Latest closeout state after the 2026-06-25 manual follow-up:
- `EQ_AGENT_LLM_ENABLED=false`
- `EQ_AGENT_LLM_PROVIDER=openai`
- `EQ_AGENT_OPENAI_API_KEY` present in staging configuration
- `EQ_AGENT_OPENAI_MODEL=gpt-4.1-mini`
- `EQ_AGENT_OPENAI_BASE_URL=https://api.openai.com/v1`

The provider configuration may remain present, but live LLM calls must remain disabled unless a later product decision explicitly reopens this lane.

## 5. Credential Hygiene

An initial UI-created key named `eq-agent-staging` was exposed during setup and was treated as compromised.

Final credential status:
- `eq-agent-staging`: **deleted / revoked by user**
- `eq-agent-staging-v2`: retained but not used for active LLM runtime
- `eq-agent-staging-v3`: created by the user for the manual follow-up, written to staging, and kept with `EQ_AGENT_LLM_ENABLED=false`

Required policy going forward:
- Do not use `eq-agent-staging`.
- Do not paste API keys into chat, logs, reports, PR bodies, or repo files.
- Do not re-enable EQ Agent LLM mode without a new explicit product decision and a scoped smoke plan.

## 6. Smoke Attempt

| Item | Value |
| --- | --- |
| Attempt ID | `7024cad3-9414-4921-b7a2-45183850e91b` |
| Anonymous ID | `eq-agent-v2-staging-smoke-20260625-110117` |
| Question count | 60 |
| Submit behavior | Async submit accepted; report became deliverable after polling |
| Quality level | `C` |
| Quality flags | `INCONSISTENT` |
| Confidence | `low` |

The low-confidence result is acceptable for this smoke because the purpose was provider/runtime safety, not normal-confidence acceptance.

## 7. Report And Access Checks

`report-access` summary:
- `access_state=ready`
- `report_state=ready`
- `variant=full`
- `access_level=full`
- `locked=false`
- `upgrade_sku=null`
- `offers=[]`
- `view_policy.blur_others=false`

`report` summary:
- `eq_report_mode=self_report`
- `measurement_type=self_report_trait_mixed_ei`
- `methodology.report_version=eq_report_v5_assets_commercial_ready_v1_6`
- `scores.global` present
- `scores.dimensions` present
- `dimension_summary` present
- `quality` present
- `interpretation` present
- resolved assets present
- `next_module.available=false`
- `next_module.status=planned`

## 8. Agent Context Checks

Both English and Chinese context calls returned ready, localized payloads with read-only guardrails.

Required guardrails passed:
- `read_only=true`
- `can_mutate_report=false`
- `can_mutate_scores=false`
- `can_override_formulation=false`
- `can_enable_sjt=false`
- `can_create_paid_unlock_language=false`
- `can_use_paid_unlock_language=false`
- `can_expose_raw_technical_tags=false`
- `content_authority=backend_content_pack_and_report_composer`

SJT remained:
- `module_code=EQ_SJT_16`
- `status=planned`
- `available=false`

## 9. Runtime Checks

Runtime calls returned successfully, but in deterministic fallback mode:

| Locale / Prompt | HTTP | Ready | Mode | Provider |
| --- | ---: | --- | --- | --- |
| `en` normal runtime prompt | 200 | true | `deterministic_read_only` | null |
| `zh-CN` normal runtime prompt | 200 | true | `deterministic_read_only` | null |
| forbidden ability-test prompt | 200 | true | `deterministic_read_only` | null |

Forbidden prompt handling:
- detected `true_emotional_ability`
- applied boundary metadata
- kept no-paywall, no-SJT-entry, no-raw-tag flags true

## 10. OpenAI Connectivity Blocker

Direct staging diagnostic to the OpenAI Responses API failed:

```text
http_status=0
curl_error=Connection timed out after 60001 milliseconds
json=false
```

Laravel logs also showed provider fallback for OpenAI with a runtime exception. This points to staging outbound connectivity, proxy, firewall, DNS, or network egress policy as the blocker. The runtime code path correctly fell back to deterministic read-only behavior.

Root-cause classification:
- Code guardrail failure: **no**
- Provider configuration missing: **no**
- Staging outbound connectivity failure: **yes**
- Production issue: **not tested / not changed**

Manual follow-up after this report found an additional constraint:
- A temporary local relay plus SSH reverse tunnel proved that staging could reach the OpenAI Responses API through the relay path.
- OpenAI then returned `429 insufficient_quota`.
- This means the original direct staging timeout was not the only blocker; the OpenAI project/key also lacked usable quota for live smoke.
- The product decision is now to stop pursuing LLM mode, so quota remediation is not required for the current EQ Agent path.

## 11. Forbidden Text / Field Scan

The redacted smoke artifacts did not expose these forbidden values:

- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `"locked":true`
- `"paywall":true`
- `"blur_others":true`
- `profile:`
- `quality_level:`
- `focus:`
- `bucket:`
- `MSCEIT-like`
- `certified emotional intelligence`
- `predicts hiring performance`

## 12. Acceptance Decision

| Decision | Result |
| --- | --- |
| Deterministic fallback accepted | yes |
| Live LLM staging smoke accepted | no |
| Proceed to controlled live LLM rollout | no |
| Production LLM enablement allowed | no |
| Continue EQ Agent Runtime V2 LLM smoke | no |
| Keep deterministic/read-only EQ Agent runtime | yes |

Final verdict:

`LLM mode is deferred / not pursuing now. Keep EQ_AGENT_LLM_ENABLED=false. Deterministic/read-only EQ Agent runtime remains the accepted product path.`

## 13. Required Next Actions

1. Keep `EQ_AGENT_LLM_ENABLED=false` on staging and production.
2. Do not continue EQ Agent Runtime V2 live LLM smoke.
3. Treat the LLM provider lane as `deferred / not pursuing now`.
4. Continue improving the deterministic/read-only Agent path through structured assets, intent routing, playbooks, safety fixtures, locale quality, and result-page integration.
5. If a future decision reopens LLM mode, create a new explicit PR train and smoke gate instead of resuming this one implicitly.
