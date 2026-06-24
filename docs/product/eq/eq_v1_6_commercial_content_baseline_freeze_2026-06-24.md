# EQ v1.6 Commercial Content Baseline Freeze

Date: 2026-06-24

Status: Frozen baseline for Agent-ready content operations

Source acceptance audit:

- `docs/audits/eq/eq_v1_6_productization_acceptance_2026-06-24.md`

## 1. Baseline Decision

EQ v1.6 is frozen as FermatMind's first commercial-ready EQ-60 content baseline.

This freeze applies to the EQ-60 self-report result experience only. It does not implement EQ-SJT, does not enable an Agent runtime, and does not change scoring, norms, questions, options, reverse keys, or report access semantics.

Production acceptance gates passed:

- EQ question delivery returns 60 items for `en` and `zh-CN`.
- `/report-access` resolves ready/full/all-free with no user-visible paid, locked, blur, SKU, or offer path.
- `/report` returns `eq_report_v5_assets_commercial_ready_v1_6`.
- `/report?locale=en` returns English resolved assets.
- `/report?locale=zh-CN` returns Chinese resolved assets.
- `next_module.available=false` and `next_module.status=planned`.
- Low-confidence result handling routes to cautious interpretation and retest reflection.

Agent phase gate:

- Agent phase allowed: yes.
- Agent implementation may start only on top of this frozen structured asset baseline.

## 2. Frozen Authority Layer

The backend content pack and report composer remain the report authority.

Frozen authority surfaces:

- `backend/content_packs/EQ_60/v1/raw/report_assets/*.json`
- `backend/content_packs/EQ_60/v1/compiled/report_assets.compiled.json`
- `backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/**` mirror assets
- `Eq60ReportComposer` output contract
- EQ canonical fixtures and report contract tests

Frontend and future Agent surfaces must consume backend-provided payloads and resolved assets. They must not recreate final report prose, scoring labels, formulation interpretations, or claim boundaries from local copy.

## 3. Frozen Asset Packs

These v1.6 asset packs are baseline-stable:

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

Each pack is governed as structured data, not free-form copy. Future edits must preserve stable IDs, locale coverage, trigger semantics, risk notes, and claim boundaries.

## 4. Stable ID Rules

Stable IDs are part of the product contract.

Do not rename IDs without a migration plan:

- formulation IDs, for example `balanced_integrated`, `high_empathy_low_recovery`, `low_confidence_result`
- mechanism IDs, for example `EM_ER_high_low`, `ER_RM_low_low`
- scene IDs, for example `feedback`, `conflict`, `relationship_boundary`
- career environment IDs, for example `emotional_labor_high`, `autonomy_recovery_medium`
- action prescription IDs, for example `emotion_labeling`, `empathy_boundary`, `retest_reflection`
- SJT bridge ID, for example `eq.sjt_bridge.planned`
- quality/evidence IDs
- Agent playbook IDs

Allowed future changes:

- Add new IDs behind tests.
- Deprecate old IDs with compatibility mapping.
- Improve localized wording while preserving the ID and claim boundary.

Blocked changes:

- Removing an ID that canonical fixtures or frontend contracts still reference.
- Changing a trigger rule without updating golden cases and report fixtures.
- Reusing an old ID for a different meaning.

## 5. Trigger Rule Governance

Trigger rules determine which asset is selected for a user result.

Frozen trigger responsibilities:

- Scorer: raw scores, quality, norms, validity signals.
- Composer: formulation, strongest dimension, development lever, mechanisms, scenes, career environment IDs, action prescription, SJT planned bridge.
- Content assets: human-readable interpretation, claim boundary, risk notes, actions, and localized text.
- Frontend: deterministic rendering only.
- Agent: explanation and guided reflection over selected assets only.

Trigger changes require:

- backend contract test coverage
- golden case coverage when applicable
- zh-CN/en fixture coverage
- no-paywall/no-SJT-entry invariant coverage
- low-confidence path coverage when quality logic is affected

## 6. Claim Boundaries

The frozen baseline allows:

- self-report emotional and relational pattern interpretation
- subjective self-perception reflection
- workplace and relationship scenario translation
- career environment variable discussion
- coaching-style action suggestions
- cautious Agent dialogue grounded in resolved assets

The frozen baseline forbids:

- claiming EQ-60 measures true emotional ability
- claiming EQ-SJT is MSCEIT or MSCEIT-like
- claiming certified emotional intelligence measurement
- clinical diagnosis or treatment guidance
- hiring, promotion, or screening suitability
- guaranteed outcomes
- job performance prediction
- specific career recommendations as a deterministic result of EQ
- paid/locked/upgrade language for EQ v1.6

All future Agent prompts, retrieval payloads, UI entries, and generated responses must inherit these boundaries.

## 7. Risk Notes

Known accepted risks:

- EQ-60 is self-report and can reflect self-perception bias.
- Low-confidence results need cautious interpretation and retest guidance.
- Career environment recommendations must stay variable-based, not occupation-prescriptive.
- SJT remains planned/unavailable until the SJT train implements content, scorer, route, and validation.
- Agent output can overclaim if not constrained by asset IDs and forbidden-claim metadata.

Open governance risks:

- Legacy report payloads may contain diagnostic fields that should remain hidden from user-facing and Agent-facing surfaces.
- Report-access projection can briefly lag immediately after submit; report delivery fallback currently protects user-visible report access.
- English and Chinese wording quality must be reviewed as product copy evolves, but review must keep stable IDs and claim boundaries intact.

## 8. Agent Maintenance Rules

The future EQ Agent may maintain:

- retrieval tags
- intent mappings
- user question routing
- asset summaries
- contraindication and escalation metadata
- forbidden-claim metadata
- evaluation fixtures
- content improvement proposals tied to stable asset IDs

The future EQ Agent must not:

- mutate report scores
- override formulation selection
- rewrite final report prose at runtime
- create new report authority outside backend assets
- expose raw diagnostic tags as user-facing content
- turn SJT planned state into an available entry
- introduce paid/locked copy
- answer as if the test were clinical, hiring-grade, certified, or ability-based

Agent content edits must be proposed as structured asset updates through the backend content pack workflow, not as ad-hoc prose patches in frontend code or runtime prompts.

## 9. Freeze Validation

Freeze evidence:

- production retest attempt `1f869cf8-027b-4298-ab5a-a368a4c53fb9`
- `/report?locale=en`: `locale=en`, English resolved assets
- `/report?locale=zh-CN`: `locale=zh-CN`, Chinese resolved assets
- `methodology.report_version=eq_report_v5_assets_commercial_ready_v1_6`
- `next_module.available=false`

Local validation for this freeze PR:

- YAML manifest parse
- JSON state parse
- `git diff --check`
- `git diff --cached --check`

Runtime tests are not required for this docs-only freeze PR because runtime behavior was verified by PR-EQ-ASSET-04 and production smoke.

## 10. Next PRs

The Agent-ready content operating system can now proceed:

- PR-EQ-AGENT-01: EQ Agent knowledge base schema, retrieval tags, and intent map
- PR-EQ-AGENT-02: backend EQ Agent context payload and retrieval API
- PR-EQ-AGENT-03: frontend EQ Agent entry guard
- PR-EQ-AGENT-04: Agent safety and forbidden-claims eval fixtures

These PRs must preserve this baseline unless a later PR explicitly changes the frozen baseline with fixtures, tests, and governance notes.
