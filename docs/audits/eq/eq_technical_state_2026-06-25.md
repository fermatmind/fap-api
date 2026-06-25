# EQ Technical State, Commercial Baseline, and Agent Roadmap

Date: 2026-06-25

Status: consolidated technical source document

This document consolidates the previous EQ scan, productization, acceptance, Agent-ready, Agent runtime, personalization, and SJT planning documents into one maintained technical record. The older source documents were removed after their durable findings were merged here.

## 1. Executive Summary

- EQ-60 is the current production EQ core: a 60-item self-report emotional and relational pattern assessment.
- The active dimensions are `SA`, `ER`, `EM`, and `RM`: self-awareness, emotion regulation, empathy, and relationship management.
- The 50/60 question-count mismatch was fixed; registry and runtime now expect 60 items.
- EQ report access is all-free: no user-facing locked sections, blur, paywall, paid offers, or SKU path.
- The active report payload version is `eq_report_v5_assets_commercial_ready_v1_6`.
- EQ v1.6 is frozen as the first commercial-ready EQ-60 content baseline.
- Backend content pack and `Eq60ReportComposer` are the authority layer for report interpretation.
- Frontend uses the EQ-specific `EQResultV5` renderer instead of generic `RichResultReport` fallback.
- Production smoke verified 60 questions, report delivery, v1.6 resolved assets, localized `en` and `zh-CN` assets, and no paywall/SKU leakage.
- Agent-ready context is production accepted: the Agent may read EQ report context and assets, but cannot mutate scores, formulations, report sections, SJT state, or commerce state.
- Deterministic read-only Agent runtime shell exists and is the accepted runtime direction for now.
- LLM/provider work is deferred. `EQ_AGENT_LLM_ENABLED` should remain `false`; do not pursue live LLM smoke until a separate future decision reopens that lane.
- EQ-SJT remains planned/unavailable. It must not be exposed as a clickable production take entry until its content, scorer, frontend flow, integrated report, and validation gates are complete.
- The next commercial growth focus should be richer result-page content assets, stronger personalization, English copy quality, SEO/GEO authority, retention actions, and Agent-maintainable structured content.

## 2. Consolidated Source Documents

The following source documents were merged into this document and removed:

- `docs/audits/eq/eq_v5_result_page_pr_split_scan_2026-05-21.md`
- `docs/audits/eq/eq_v5_smoke_qa_2026-05-21.md`
- `docs/audits/eq/eq_sjt_16_module_design_2026-05-21.md`
- `docs/audits/eq/eq_sjt_16_validation_telemetry_qa_2026-05-31.md`
- `docs/audits/eq/eq_personalization_first_tier_pr_split_2026-05-31.md`
- `docs/audits/eq/eq_v1_6_productization_acceptance_2026-06-24.md`
- `docs/product/eq/eq_v1_6_commercial_content_baseline_freeze_2026-06-24.md`
- `docs/audits/eq/eq_agent_ready_content_os_acceptance_2026-06-25.md`
- `docs/audits/eq/eq_agent_runtime_acceptance_2026-06-25.md`
- `docs/audits/eq/eq_agent_runtime_normal_confidence_acceptance_2026-06-25.md`
- `docs/audits/eq/eq_agent_runtime_v2_provider_design_2026-06-25.md`
- `docs/audits/eq/eq_agent_runtime_v2_staging_llm_smoke_2026-06-25.md`

## 3. Product Positioning

FermatMind EQ is not positioned as a generic "high EQ / low EQ" quiz. The product positioning is:

> Emotional and Relational Pattern Report

The report explains how a user notices, regulates, responds to, and manages emotions across self, relationships, feedback, conflict, recovery, and work environments.

Current product structure:

- `EQ_60`: required self-report core, 60 items, production active.
- `EQ_EMOTIONAL_INTELLIGENCE`: v2 identity/alias path for the same EQ-60 authority layer.
- `EQ_SJT_16`: future scenario-based emotional judgment module, planned/unavailable.
- Integrated EQ report: future `EQ_60 + EQ_SJT_16` interpretation, not currently user-visible.

Commercial principle:

- All current EQ results are free.
- Report depth is determined by completed data, not payment.
- SJT is a future data-completeness upgrade, not a paid unlock.

## 4. Backend Architecture

Primary backend surfaces:

- `backend/content_packs/EQ_60/v1`
- `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1`
- `backend/app/Services/Assessment/Drivers/Eq60Driver.php`
- `backend/app/Services/Assessment/Scorers/Eq60ScorerV1NormedValidity.php`
- `backend/app/Services/Psychometrics/Eq60/NormGroupResolver.php`
- `backend/app/Services/Report/Eq60ReportComposer.php`
- `backend/app/Services/Report/ReportGatekeeper.php`
- `backend/app/Services/Report/ReportAccess.php`
- `backend/app/Services/Report/Resolvers/AccessResolver.php`
- `backend/app/Services/Report/ReportSnapshotStore.php`
- `backend/app/Services/Eq/EqAgentContextBuilder.php`
- `backend/app/Services/Eq/EqAgentRuntimeResponder.php`

Current backend contract:

- EQ requires exactly 60 answers.
- Dimension codes are `SA`, `ER`, `EM`, `RM`.
- Report access returns ready/full/all-free once result/report is deliverable.
- EQ has synchronous/live report build fallback when snapshot delivery is behind, preventing long-lived `generating=true, report=[]` after report-access says ready.
- `next_module.available=false` and `next_module.status=planned`.
- Report payload must not expose user-facing SKU, purchase, paywall, locked, blur, or upgrade CTA.

## 5. Scoring and Measurement Model

EQ-60 is a self-report trait/mixed EI-style instrument. It should not be described as an ability test, MSCEIT-like measure, clinical diagnostic test, certification instrument, or hiring/screening tool.

Core score objects:

- `scores.global`
- `scores.dimensions.SA`
- `scores.dimensions.ER`
- `scores.dimensions.EM`
- `scores.dimensions.RM`
- `dimension_summary`
- `quality`
- `interpretation`
- `methodology`

Band display mapping uses product-safe language:

- `foundational`
- `developing`
- `stable`
- `proficient`
- `integrated`

Quality handling:

- Low-confidence results route to `low_confidence_result`.
- Low-confidence results should emphasize cautious interpretation and retest reflection.
- Low-confidence paths must not force strong personality judgments.

Norm status:

- EQ v1.6 uses provisional/versioned norm language and must keep scientific boundaries visible.

## 6. Report Access and Free Strategy

EQ runtime should resolve to:

- `locked=false`
- `variant=full`
- `access_level=full`
- `upgrade_sku=null`
- `offers=[]`
- `blur_others=false`
- `access.all_results_free=true`
- `paywall_suppressed=true`

Legacy commerce/SKU seed data may exist for historical compatibility, but EQ runtime must not depend on it or show it to users.

Important invariant:

- Keeping the current all-free contract is a product rule, not just a UI choice.
- The frontend must not reintroduce unlock, purchase, premium, upgrade, SKU, or blur language for EQ.

## 7. v1.6 Content Asset Baseline

The v1.6 commercial content baseline is frozen as the first production-ready EQ content baseline.

Authority surfaces:

- `backend/content_packs/EQ_60/v1/raw/report_assets/*.json`
- `backend/content_packs/EQ_60/v1/compiled/report_assets.compiled.json`
- `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/**`
- `Eq60ReportComposer`
- backend canonical fixtures and contract tests

Active asset packs:

- `scientific_contract`
- `score_system`
- `core_formulations`
- `mechanism_map`
- `reality_translation`
- `career_environment`
- `action_prescriptions`
- `sjt_bridge`
- `result_snapshot`
- `commercial_conversion_assets`
- `quality_confidence`
- `psychometric_evidence_status`
- `agent_dialogue_playbooks`
- `backend_integration_contract`
- `seo_geo_authority`
- `cross_assessment_context`
- `personalization_route_matrix`

Current content scale:

- 10 core formulations.
- 5 mechanism pairs with 4 states each.
- 6 reality scene families.
- 12 action prescriptions.
- 6 career environment variables.
- Dual locale coverage: `zh-CN` and `en`.

Stable ID rules:

- Do not rename formulation, mechanism, scene, career, action, SJT, quality, evidence, or Agent playbook IDs without migration.
- Trigger changes require golden cases, canonical fixtures, contract tests, and locale coverage.
- Future content edits should improve structured assets, not introduce runtime ad-hoc prose.

## 8. Result Page Architecture

Frontend result renderer:

- `components/result/eq/EQResultV5.tsx`

Main sections:

1. Core Insight Hero
2. Evidence Snapshot
3. Quality Banner
4. Emotional Matrix
5. Pattern Mechanism
6. Reality Translation
7. Career Environment Lens
8. Action Prescription
9. SJT Bridge
10. Scientific Boundary
11. Save / Share / Related Tests / Agent entry

Renderer rules:

- Use `EQResultV5` when report payload has `eq_report_mode=self_report` and `measurement_type=self_report_trait_mixed_ei`.
- Do not let generic `RichResultReport` take over valid EQ v5/v1.6 payloads.
- Prefer backend resolved assets.
- Do not hardcode final report interpretation prose in frontend code.
- Display only safe UI chrome locally, such as save, share, retry, unavailable, and read-only labels.
- Hide or fail closed on locked/paywall/SKU/raw-tag anomalies.

## 9. Production Acceptance Summary

Production EQ v1.6 acceptance confirmed:

- `/api/v0.3/scales/EQ_60/questions?locale=en` returns 60 items.
- `/api/v0.3/scales/EQ_60/questions?locale=zh-CN` returns 60 items.
- Anonymous EQ submit works.
- `/report-access` converges to ready/full/all-free.
- `/report` returns `eq_report_v5_assets_commercial_ready_v1_6`.
- `/report?locale=en` returns English resolved assets.
- `/report?locale=zh-CN` returns Chinese resolved assets.
- Frontend result pages use `EQResultV5`.
- No visible paywall, SKU, locked, blur, unlock, purchase, paid CTA, or raw technical tag leakage was observed in the accepted smoke.
- SJT remains planned/unavailable.

Accepted production attempts recorded in the prior source documents included:

- Low-confidence path attempt: `29e0804e-36b9-4426-be69-2094612557f9`
- Normal-quality path attempt: `adbc27ed-f1f4-47be-8548-e69cc54bbd47`
- Locale retest attempt after locale fix: `1f869cf8-027b-4298-ab5a-a368a4c53fb9`
- Agent-ready smoke attempt: `8d4c971b-7a84-45e6-9c7f-3431a13ea210`
- Agent report-access recheck attempt: `a383cde0-eb1d-43bd-96a2-6f6be89e3f9d`

## 10. Agent-Ready Content OS

Agent-ready content operations are allowed on top of the v1.6 frozen baseline.

Agent may read:

- report context
- selected formulations
- selected mechanisms
- selected scenes
- selected career environment variables
- selected action prescription
- quality/confidence assets
- scientific boundary assets
- SJT bridge status
- conversion/Agent entry assets
- retrieval tags
- intent map
- forbidden-claim metadata

Agent must not:

- mutate report scores
- override formulation selection
- rewrite report sections
- create a parallel report authority
- expose raw diagnostic tags
- enable SJT
- introduce paid/locked/unlock language
- answer as if EQ-60 were clinical, hiring-grade, certified, or ability-based

Current Agent context endpoint:

- `GET /api/v0.3/attempts/{id}/eq/agent-context?locale=...&intent=...`

Required guardrails:

- `read_only=true`
- `can_mutate_report=false`
- `can_mutate_scores=false`
- `can_override_formulation=false`
- `can_enable_sjt=false`
- `can_create_paid_unlock_language=false`
- `can_use_paid_unlock_language=false`
- `can_expose_raw_technical_tags=false`
- `content_authority=backend_content_pack_and_report_composer`

## 11. Agent Runtime Current State

The deterministic read-only Agent runtime shell exists and remains the accepted runtime direction.

Runtime endpoint:

- `POST /api/v0.3/attempts/{id}/eq/agent-runtime/messages`

Runtime response schema:

- `eq.agent_runtime_response.v1`

Runtime mode:

- `deterministic_read_only`

Runtime behavior:

- Reuses Agent context and report access guards.
- Produces deterministic responses from selected assets, intent context, playbooks, and forbidden-claim boundaries.
- Does not call an external model by default.
- Does not persist chat history.
- Does not mutate score/report/formulation/content/SJT/commerce state.
- Fails closed when context or guardrails are unsafe.

Important note:

- Earlier production runtime smoke found route/deploy and guardrail alias issues; subsequent hardening normalized paid-language aliases and accepted normal-confidence deterministic smoke.
- The maintained direction is deterministic/read-only unless a future PR explicitly reopens live provider work.

## 12. LLM / Provider V2 Decision

The LLM/provider V2 lane is deferred and not being pursued now.

Current policy:

- Keep `EQ_AGENT_LLM_ENABLED=false`.
- Do not continue EQ Agent Runtime V2 live LLM smoke.
- Do not request or store additional OpenAI keys for this EQ Agent lane.
- Do not connect production or staging EQ Agent runtime to live LLM calls.
- Keep deterministic fallback as the accepted product path.

Reason:

- The current product value is better served by structured content assets, deterministic guardrails, and result-page richness.
- Live LLM integration adds operational, safety, latency, cost, credential, and outbound-connectivity complexity without being necessary for the current commercial EQ page.

If reopened later, the LLM lane must be treated as a separate train with:

- provider design
- prompt boundary
- schema validation
- forbidden-claim evals
- staging-only flag
- cost/latency/error monitoring
- no production enablement without explicit approval

## 13. EQ-SJT Status

EQ-SJT remains planned/unavailable.

Design intent:

- Scenario-based emotional judgment module.
- Complements EQ-60 self-report.
- Does not replace EQ-60.
- Does not claim MSCEIT equivalence.
- Does not claim certified emotional ability measurement.
- Does not support hiring, clinical, or high-risk decision use.

Planned structure:

- 16 items.
- 8 scenario domains, 2 items each.
- Response options with strategy tags.
- 0-3 partial-credit rubric.
- Applied strategy scores such as `CUE`, `PAUSE`, `EMP`, `BND`, `REPAIR`, and `INFL`.

SJT exposure rules:

- Before SJT content/scorer/take flow/report integration are complete, `next_module.available` must remain `false`.
- Before frontend SJT take flow is merged, no clickable SJT entry.
- Before integrated report composer is merged, no integrated EQ report visible to users.
- Before validation/telemetry/QA is complete, no stable-validation claim.

## 14. Testing and Fixtures

Backend EQ test coverage includes:

- `Eq60StartSubmitTest`
- `Eq60SubmitQualityContractTest`
- `Eq60ContentGateTest`
- `Eq60GoldenCasesTest`
- `Eq60V5ReportContractTest`
- `Eq60V5ReportDeliveryTest`
- `Eq60ReportPaywallTest`
- `EqAgentContextApiTest`
- `Eq60JourneyStateContractTest`
- `Eq60NormsDriftCheckTest`
- `Eq60PsychometricsReportTest`
- `EqSjt16ContentPackSkeletonTest`
- `EqSjt16ScorerTest`

Canonical fixtures include:

- `eq60_v5_balanced_integrated_en.json`
- `eq60_v5_balanced_integrated_zh.json`
- `eq60_v5_high_empathy_low_recovery_en.json`
- `eq60_v5_high_empathy_low_recovery_zh.json`
- `eq60_v5_low_confidence_en.json`
- `eq60_v5_low_confidence_zh.json`

Frontend EQ coverage includes:

- `tests/contracts/eq-result-v5-renderer.contract.test.tsx`
- `tests/contracts/eq60-result-page-agent-readiness-proposal.contract.test.ts`
- `tests/contracts/result-smoke-eq-option-anchors.contract.test.ts`
- `tests/e2e/iq-eq-result-regression.spec.ts`
- `tests/fixtures/eq/v5/*.json`

## 15. Known Risks

P0/P1:

- None open in the consolidated technical state.

P2:

- Report-access can briefly lag immediately after submit. Report delivery fallback protects the result page, but smoke scripts should poll readiness before asserting final access state.
- Production runtime Git SHA may not always be exposed through public headers, so deployment evidence sometimes relies on deploy records plus feature evidence.
- Low-confidence live behavior is covered by fixtures/contracts and prior smoke attempts; do not force abnormal production attempts solely to test low-confidence.
- English copy is functional but should continue moving toward native consumer psychology product quality.
- Some legacy block/free/paid structures remain in the content pack for compatibility. v1.6 assets and all-free runtime are the current authority.

P3:

- Screenshot evidence from smoke reports was stored in `/tmp` and intentionally not committed.
- SJT design exists, but SJT is not product-ready.
- Agent runtime exists as deterministic shell, but real Agent product value still depends on richer content assets and playbook coverage.

## 16. Commercialization Gap

The technical chain is substantially complete. The main commercial gap is content depth and operating-system quality.

To approach MBTI-level commercial operation and first-tier English-market quality, focus on:

1. Richer `core_formulations`.
2. Stronger mechanism writing with less template feel.
3. More realistic reality scenes.
4. Career environment assets that feel like a decision tool, not just general advice.
5. More precise action prescriptions with save/share/retest/email/PDF/Agent follow-through.
6. Native-quality English copy.
7. FAQ, SEO, GEO, comparison, and methodology assets.
8. Agent playbooks that route user intent to stable asset IDs.
9. Cross-assessment context with MBTI, Big Five, Enneagram, and RIASEC boundaries.
10. Better production observability for result/readiness/Agent interactions.

## 17. Recommended Next Work

Recommended next line:

- EQ result-page content assets v1.7/v2.0.

Scope:

- Expand and refine formulations, mechanisms, scenes, career variables, action prescriptions, FAQ/SEO/GEO, and Agent playbooks.
- Preserve stable IDs unless intentionally migrating.
- Keep backend content pack as authority.
- Keep frontend deterministic.
- Keep SJT planned/unavailable.
- Keep LLM disabled/deferred.

Suggested PR sequence:

1. EQ content asset v1.7 editorial expansion.
2. EQ personalization trigger matrix enhancement.
3. EQ English-native copy pass.
4. EQ SEO/GEO authority pack expansion.
5. EQ Agent playbook and retrieval tag expansion.
6. EQ production smoke/report refresh.

## 18. Final Current-State Verdict

EQ-60 is technically production-ready as a free self-report emotional and relational pattern report with a v1.6 commercial content baseline and a guarded deterministic Agent-ready path.

The next meaningful commercialization step is not more infrastructure. It is richer, more differentiated, more native-quality, and more conversion-aware EQ result content assets.
