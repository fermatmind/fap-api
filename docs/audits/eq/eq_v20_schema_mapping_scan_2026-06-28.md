# EQ v2.3 Content Assets Schema Mapping Scan

## 1. Executive Summary

- Scan target: `/Users/rainie/Desktop/eq_content_assets_v2_3_proposal.zip`.
- Backend target: `backend/content_packs/EQ_60/v1/raw/report_assets` and `backend/content_packs/EQ_60/v1/raw/personalization_routes/route_matrix.json`.
- Current repo state at scan time: `fap-api` `main` clean, `HEAD == origin/main`, SHA `c4e56e3068ec0aed0a4803869b84bc6423e8ba2a`.
- `PR-EQ-V20-SCAN-01` is not currently present in `docs/codex/pr-train.yaml` or `docs/codex/pr-train-state.json`; implementation PRs require explicit manifest/state authorization.
- v2.3 is a strong proposal package, but it is not directly importable as-is.
- Existing backend already has most receiving packs: core formulations, mechanisms, scene variants, career environment, action prescriptions, conversion assets, SEO/GEO, depth modules, agent schema/playbooks, and v2 personalization routes.
- The import path should be adapter-based: transform v2.3 flat arrays into the current backend object maps keyed by stable IDs.
- The safest first import is a staged/pilot import, not a full 602-asset cutover.
- Hard blockers before direct import:
  - v2.3 file names and root shapes do not match current backend raw asset file shapes.
  - `action_prescriptions_v2.json` contains nested locale objects inside localized `seven_day_plan.practice`; this can leak zh-CN fields into English payloads unless normalized.
  - v2.3 route keys use `id`, `trigger`, and `asset_selection`; current backend expects `route_id`, `match`, and `selected_asset_ids`.
  - v2.3 references 30 formulation IDs and 32 action IDs, while current composer allowlists only 10 formulation IDs and 12 action IDs.
  - v2.3 career overlays such as `collaboration_complexity_high.balanced_integrated` are not resolvable by the current `resolveCareerAsset()` implementation, which expects IDs ending in `_low`, `_medium`, or `_high`.
  - v2.3 `result_snapshot_id` points to `eq.depth.*` in route samples; current composer expects result snapshot IDs like `eq.snapshot.<formulation_id>`.
- No hard paid/paywall/SKU/MSCEIT/clinical/hiring forbidden claim hit was found in a mechanical scan of the package, but SJT and Agent language should still remain under lint because the package is generated.
- Recommendation: split v2.3 productization into six PRs: adapter/direct mapped assets, new depth/claim packs, scene/career resolver work, route matrix v2 selection, frontend consumption, fixtures/smoke.

## 2. Current Backend Asset Contract

Current compiler only collects these fixed report asset files under `EQ_60/v1/raw/report_assets`:

- `scientific_contract.json`
- `score_system.json`
- `core_formulations.json`
- `mechanism_map.json`
- `reality_translation.json`
- `reality_scene_variants.json`
- `career_environment.json`
- `action_prescriptions.json`
- `cross_assessment_context.json`
- `seo_geo_authority.json`
- `sjt_bridge.json`
- `result_snapshot.json`
- `commercial_conversion_assets.json`
- `quality_confidence.json`
- `psychometric_evidence_status.json`
- `result_page_depth_modules.json`
- `agent_knowledge_base_schema.json`
- `agent_dialogue_playbooks.json`
- `backend_integration_contract.json`

The compiler also attaches:

- `personalization_routes/route_matrix.json`

This means v2.3 files cannot be copied into `report_assets` using their proposal names. They must be transformed into the current canonical file names and current internal schema.

## 3. v2.3 Package Inventory

v2.3 package manifest reports:

| File | Count / Purpose | Direct Backend Receiver |
| --- | ---: | --- |
| `core_formulations_v2.json` | 30 formulations | `core_formulations.json` |
| `mechanism_map_v2.json` | 48 mechanisms | `mechanism_map.json` |
| `reality_scene_variants_v2.json` | 180 scene variants | `reality_scene_variants.json` |
| `career_environment_v2.json` | 18 base + 60 overlays | `career_environment.json`, but overlay resolver changes needed |
| `action_prescriptions_v2.json` | 32 prescriptions | `action_prescriptions.json`, but locale normalization needed |
| `personalization_route_matrix_v2.json` | 60 routes | `personalization_routes/route_matrix.json`, adapter required |
| `result_page_depth_modules_v2.json` | 60 modules | `result_page_depth_modules.json`, composer selection changes needed |
| `conversion_actions_v2.json` | 12 actions | `commercial_conversion_assets.json`, composer selection changes needed for 5 new actions |
| `cross_assessment_context_v2.json` | 48 assets | `cross_assessment_context.json` |
| `quality_confidence_v2.json` | 4 assets | `quality_confidence.json` |
| `faq_seo_geo_v2.json` | 24 FAQ + 20 SEO + 16 GEO | `seo_geo_authority.json`, SEO governance review needed |
| `agent_knowledge_base_v2.json` | 16 intent playbooks | do not overwrite schema; map to `agent_dialogue_playbooks` or new pack |
| `claim_boundary_v2.json` | 10 boundaries | new pack recommended |

Reference integrity from package review:

- `reference_errors`: none
- `routes_checked`: 60
- Quality C/D routes resolve only to `low_confidence_result`
- Scenes cover 6 families across formulations

## 4. Direct Mapping Candidates

These assets can be imported after mechanical adapter transformation. They should not require new product semantics.

### 4.1 Core Formulations

Source:

- `core_formulations_v2.json`

Target:

- `report_assets/core_formulations.json`
- Current target shape: `{ schema, pack_id, formulations: { [id]: { zh-CN, en, meta? } } }`

Required transformations:

- Convert flat list to keyed object under `formulations`.
- Move `copy.zh-CN` and `copy.en` to locale root nodes.
- Preserve `share_line`, `trigger_summary`, `recommended_*`, `claim_risk`, and `risk_notes` under `meta`.
- Do not replace scoring or formulation selection logic in the same PR.

Blocking issue:

- Current composer allowlist only recognizes 10 formulation IDs in `coreFormulationIds()`. v2.3 has 30. New IDs will not be selected until composer allowlist/route selection is expanded.

Recommendation:

- Import all 30 only if lint is updated to validate 30, but do not activate all via routes until route PR.
- Alternatively import a pilot subset plus existing 10 first.

### 4.2 Mechanism Map

Source:

- `mechanism_map_v2.json`

Target:

- `report_assets/mechanism_map.json`
- Current target shape: `{ schema, pack_id, states, pairs: { [pair]: { [state]: { zh-CN, en } } } }`

Required transformations:

- Convert flat list IDs like `SA_ER_high_high` into `pairs.SA_ER.high_high`.
- Move `copy.zh-CN` / `copy.en` to locale root nodes.
- Preserve `claim_risk` and `risk_notes`.

Compatibility:

- Current `resolveMechanismAsset()` already resolves IDs like `SA_ER_high_high`.
- Existing lint currently requires only the original five pairs and four states; it does not block extra pairs, but extra pairs should get a broader lint pass.

Recommendation:

- Directly map, but add route-reference lint so every selected mechanism exists.

### 4.3 Reality Scene Variants

Source:

- `reality_scene_variants_v2.json`

Target:

- `report_assets/reality_scene_variants.json`
- Current target shape: `{ schema, pack_id, assets: { [id]: { scene_family, variant, zh-CN, en, meta? } } }`

Required transformations:

- Convert flat list to keyed object under `assets`.
- Move `copy.zh-CN` / `copy.en` to locale root nodes.
- Preserve `scene_family`, `formulation_id`, `priority`, `evidence_signals`, `do_not_overread`, `claim_risk`, and `risk_notes`.

Compatibility:

- Current composer can consume explicit `eq.scene.*` variant IDs via route `selected_asset_ids.scenes`.
- Current backend already has `primary_scene_variant_ids` and resolved `assets.reality_scenes`.

Recommendation:

- Directly map, but do not activate all 180 until route matrix import is validated.

### 4.4 Quality Confidence

Source:

- `quality_confidence_v2.json`

Target:

- `report_assets/quality_confidence.json`

Required transformations:

- Convert flat list to keyed object under `assets`.
- Ensure four IDs remain:
  - `eq.quality.level.A`
  - `eq.quality.level.B`
  - `eq.quality.level.C`
  - `eq.quality.level.D`

Compatibility:

- Current composer resolves by `quality.explanation_asset_id`.

Recommendation:

- Safe direct mapping candidate.

### 4.5 Cross Assessment Context

Source:

- `cross_assessment_context_v2.json`

Target:

- `report_assets/cross_assessment_context.json`

Required transformations:

- Convert flat list to keyed object under `assets`.
- Ensure existing boundary IDs are preserved:
  - `eq.cross_context.boundary.default`
  - `eq.cross_context.mbti.available`
  - `eq.cross_context.big_five.available`
  - `eq.cross_context.enneagram.available`

Compatibility:

- Current composer resolves cards from `crossAssessmentContext.context_asset_ids`.

Recommendation:

- Direct mapping, but activation depends on existing cross-assessment availability logic.

## 5. Direct Mapping With Required Normalization

### 5.1 Action Prescriptions

Source:

- `action_prescriptions_v2.json`

Target:

- `report_assets/action_prescriptions.json`
- Current target shape: `{ schema, pack_id, prescriptions: { [id]: { zh-CN, en, meta? } } }`

Required transformations:

- Convert flat list to keyed object under `prescriptions`.
- Move `copy.zh-CN` / `copy.en` to locale root nodes.
- Normalize `seven_day_plan[*].practice` to a locale-specific string.

Current v2.3 issue:

- `seven_day_plan[*].practice` contains nested `{ zh-CN, en }` inside both localized nodes.
- Mechanical scan found 448 nested-locale paths in `action_prescriptions_v2.json`.
- If imported as-is, the English resolved asset can expose Chinese child fields or an object where the frontend expects a string.

Composer blocker:

- Current composer allowlist only accepts 12 action IDs in `actionPrescriptionIds()`. v2.3 has 32. New IDs will fall back unless this allowlist and route tests are updated.

Recommendation:

- First import normalized existing 12 plus any new actions that route matrix actually selects.
- Expand composer allowlist in the route-selection PR, not in a pure copy PR.

### 5.2 Career Environment

Source:

- `career_environment_v2.json`

Target:

- `report_assets/career_environment.json`

Current target shape:

- `{ schema, pack_id, variables: { [variable]: { low|medium|high: { zh-CN, en } } } }`

v2.3 source shape:

- `base_assets`: 18 assets such as `interpersonal_density_low`
- `formulation_overlays`: 60 assets such as `collaboration_complexity_high.balanced_integrated`

Direct mapping:

- Base assets map directly into `variables.<variable>.<level>`.

Current blocker:

- `resolveCareerAsset()` resolves only IDs ending in `_low`, `_medium`, or `_high`.
- It cannot resolve overlay IDs like `collaboration_complexity_high.balanced_integrated`, because they do not end with `_high`.

Required backend change:

- Either add `career_environment.overlays` and teach resolver to resolve overlay IDs first, then base IDs.
- Or flatten overlay IDs into a current-compatible shape, but that loses the formulation-aware distinction.

Recommendation:

- Treat base assets as directly mappable.
- Treat formulation overlays as requiring a schema/resolver PR.

### 5.3 Commercial Conversion Assets

Source:

- `conversion_actions_v2.json`

Target:

- `report_assets/commercial_conversion_assets.json`

Current target:

- 7 fixed actions.

v2.3:

- 12 actions.

Compatibility:

- Current composer hardcodes the 7 IDs in both `asset_refs.commercial_conversion_ids` and resolved `commercial_conversion_actions`.
- Extra v2.3 actions can be stored but will not render until composer/frontend selection expands.

Safety:

- Mechanical scan found no blocked paid terms in v2.3.
- `requires_payment` must remain false during transformation.

Recommendation:

- Directly map 12 actions, but keep current 7 selected until frontend contract is updated.

### 5.4 Result Page Depth Modules

Source:

- `result_page_depth_modules_v2.json`

Target:

- `report_assets/result_page_depth_modules.json`

Current target:

- 3 defaults:
  - `eq.depth.how_to_read.default`
  - `eq.depth.evidence_stack.default`
  - `eq.depth.reality_check.default`

v2.3:

- 60 formulation-specific modules such as `eq.depth.evidence_stack.balanced_integrated`.

Compatibility:

- Current compiler can store them after transformation.
- Current composer resolves only the 3 hardcoded defaults.

Required backend change:

- Add route/formulation-based depth module selection.
- Decide whether route `result_snapshot_id` should point to result snapshots or depth modules. Current v2.3 sample sets `result_snapshot_id` to `eq.depth.evidence_stack.balanced_integrated`, which is semantically mismatched with current `eq.snapshot.<formulation>` behavior.

Recommendation:

- Add/keep the 60 modules as assets, but do not rely on them until composer selection is added.

## 6. Assets Requiring New or Expanded Packs

### 6.1 Claim Boundary

Source:

- `claim_boundary_v2.json`

Current equivalent:

- Partial boundary metadata exists in:
  - `agent_knowledge_base_schema.json`
  - `scientific_contract.json`
  - `seo_geo_authority.json`
  - Agent runtime guardrails

Problem:

- There is no dedicated `claim_boundaries.json` report asset pack in the compiler allowlist.

Recommendation:

- Add a new `claim_boundaries.json` pack only if the product wants centralized boundary metadata for result page, Agent, and SEO.
- Otherwise map these boundaries into `agent_knowledge_base_schema.forbidden_claims` and `seo_geo_authority` manually.

Preferred path:

- Add new pack in a separate PR:
  - update `Eq60ContentCompileService::compileReportAssets`
  - update `Eq60ContentLintService::lintReportAssets`
  - expose in Agent context if needed

### 6.2 Agent Knowledge Base v2

Source:

- `agent_knowledge_base_v2.json`

Current backend split:

- `agent_knowledge_base_schema.json`: authority, taxonomy, forbidden claims, intent map
- `agent_dialogue_playbooks.json`: five localized dialogue playbooks

Problem:

- v2.3 file is a list of 16 intent playbooks. It should not overwrite `agent_knowledge_base_schema.json`, because the current schema file is the authority/guardrail contract.

Mapping choices:

1. Map v2.3 entries into `agent_dialogue_playbooks.json`.
2. Add a new `agent_intent_playbooks.json` pack.
3. Merge retrieval tags and intent metadata into `agent_knowledge_base_schema.user_intent_map`.

Recommendation:

- Do not import this file in the same PR as result-page assets.
- Treat as Agent content OS work. If imported, use a new or expanded playbook pack and add tests for read-only guardrails.

### 6.3 FAQ / SEO / GEO

Source:

- `faq_seo_geo_v2.json`

Target:

- `seo_geo_authority.json`

Problem:

- Current target has `assets`, `faq_assets`, and `seo_geo_assets`, while v2.3 has `faq_items`, `seo_snippets`, and `geo_answer_snippets`.
- This is technically mappable but touches SEO/GEO authority surfaces.

Recommendation:

- Keep out of the first backend report import PR unless the PR explicitly includes SEO/GEO content authority changes.
- If imported, add noindex/sitemap/llms/canonical scope review separately if public SEO surfaces consume it.

## 7. Personalization Route Matrix Mapping

Source:

- `personalization_route_matrix_v2.json`

Target:

- `personalization_routes/route_matrix.json`

Current backend accepts:

- `schema = eq60.personalization_routes.route_matrix.v2`
- routes as list
- route fields:
  - `route_id`
  - `formulation_id`
  - `priority`
  - `match`
  - `selected_asset_ids`
  - `locales`
  - `claim_risk`

v2.3 source uses:

- `id`
- `trigger`
- `asset_selection`
- `copy`

Required transformations:

| v2.3 | backend route matrix |
| --- | --- |
| `id` | `route_id` |
| `trigger.quality_levels` | `match.quality_levels` |
| `trigger.dimension_pattern` | `match.dimension_pattern` |
| `trigger.score_gap_pattern` | `match.score_gap_pattern` |
| `trigger.confidence` if present | `match.confidence` |
| `asset_selection.core_formulation_id` | `selected_asset_ids.core_formulation` or `core_formulation_id` |
| `asset_selection.mechanism_ids` | `selected_asset_ids.mechanisms` |
| `asset_selection.scene_variant_ids` | `selected_asset_ids.scenes` |
| `asset_selection.career_environment_ids` | `selected_asset_ids.career_environment` |
| `asset_selection.action_prescription_id` | `selected_asset_ids.action_prescription` |
| `copy.zh-CN/en` | `locales.zh-CN/en` |

Current selection limitations:

- `matchesDimensionPattern()` supports `high`, `mid_high`, `mid`, `mid_low`, `low`, `low_or_mid_low`, `low_or_mid`, `mid_or_high`.
- It does not support `any`.
- v2.3 sample route uses `dimension_pattern: { SA: "any", ER: "any", EM: "any", RM: "any" }`.
- Therefore route matching will fail unless adapter removes `any` keys or composer supports `any`.
- `matchesScoreGapPattern()` supports object keys like `EM_minus_ER: >=1_band`. v2.3 sample uses string `balanced_strength`; this will be ignored or misinterpreted unless transformed.

Recommendation:

- Add route adapter and route lint before replacing the current route matrix.
- For v2.3 route activation, implement `any` and named gap patterns or normalize them away at import time.

## 8. Safety and Claim Boundary Scan

Mechanical scan over v2.3 assets found no hard hits for:

- `购买`
- `解锁`
- `付费`
- `paywall`
- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `true emotional ability`
- `MSCEIT-like`
- `clinical diagnosis`
- `hiring screening`
- `certified emotional intelligence`

Still required:

- Lint `requires_payment === false` for all conversion actions.
- Lint SJT copy for `planned/unavailable`.
- Lint no start/take SJT entry before SJT route exists.
- Lint no ability-test, hiring, diagnosis, or job-performance claims in SEO/GEO and Agent playbook assets.

## 9. Recommended PR Split

### PR-EQ-V20-SCAN-01: v2.3 Schema Mapping Scan

Scope:

- Docs-only scan report.
- No content import.
- No composer changes.
- No frontend changes.

Status:

- This report is the output.

### PR-EQ-V20-01: Backend Adapter for Direct-Mapped Assets

Scope:

- Add an import/transform script or one-off adapter process for direct mapping.
- Map:
  - core formulations
  - mechanism map
  - quality confidence
  - cross-assessment context
  - base career environment
  - commercial conversion assets
- Keep composer behavior unchanged.
- Do not activate new formulation/action IDs unless tests prove fallback safety.

Checks:

- `php artisan content:lint --pack=EQ_60 --pack-version=v1`
- `php artisan content:compile --pack=EQ_60 --pack-version=v1`
- `php artisan test --filter=Eq60ContentGateTest`

### PR-EQ-V20-02: Normalize Actions, Career Overlays, and Claim Boundaries

Scope:

- Normalize `seven_day_plan` locale fields.
- Add or model career overlays.
- Decide whether to add `claim_boundaries.json`.
- Extend lint for nested locale leakage and claim boundaries.

Checks:

- `php artisan content:lint --pack=EQ_60 --pack-version=v1`
- `php artisan test --filter=EqAgent`
- `php artisan test --filter=Eq60V5ReportContractTest`

### PR-EQ-V20-03: Route Matrix v2.3 Import and Composer Selection

Scope:

- Transform v2.3 `personalization_route_matrix_v2.json` into backend route matrix shape.
- Support or normalize `any` dimension patterns.
- Support or normalize named score gap patterns.
- Expand composer allowlists for approved new formulation/action IDs.
- Use route-selected scene variants, career overlays, action prescriptions, and depth modules.

Checks:

- `php artisan test --filter=Eq60GoldenCasesTest`
- `php artisan test --filter=Eq60V5ReportContractTest`
- `php artisan test --filter=Eq60ReportPaywallTest`

### PR-EQ-V20-04: Backend Canonical Fixtures

Scope:

- Regenerate canonical fixtures for:
  - balanced
  - high empathy / low recovery
  - low confidence
  - zh-CN
  - en
- Assert:
  - no paywall
  - no raw technical tags
  - SJT planned/unavailable
  - localized resolved assets
  - v2.3 route assets selected correctly

Checks:

- `php artisan test --filter=Eq60V5ReportContractTest`
- `php artisan test --filter=Eq60GoldenCasesTest`
- `php artisan test --filter=Eq60ReportPaywallTest`

### PR-EQ-V20-05: Frontend Contract and Renderer Consumption

Scope:

- Consume route headline, richer depth modules, scene variants, career overlays, and conversion assets.
- Do not hardcode official report prose in frontend.
- Do not add SJT entry.
- Do not add paywall semantics.

Checks:

- `pnpm typecheck`
- `pnpm test:contract`
- `pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts`

### PR-EQ-V20-06: Production Smoke and Acceptance

Scope:

- Docs-only smoke acceptance after deploy.
- Verify production EQ result page shows v2.3-derived v2.0/v2.3 assets.
- Verify no paywall, no raw tags, SJT still planned/unavailable.

Checks:

- `git diff --check`

## 10. Final Recommendation

Do not import v2.3 as a single content drop.

The correct path is:

1. Keep v2.3 as proposal input.
2. Build a backend adapter that converts proposal files into current authoritative `EQ_60/v1/raw/report_assets` shapes.
3. Start with direct-mapped assets and strict lint.
4. Then activate route matrix v2.3 through composer selection.
5. Only after backend canonical fixtures pass should frontend consume the richer content.

The strongest immediate next PR is not frontend work. It is:

`PR-EQ-V20-01: Backend Adapter for Direct-Mapped Assets`

But before implementation, add the V20 PRs to the correct `docs/codex/pr-train.yaml` and `docs/codex/pr-train-state.json`, or explicitly authorize manifest-external docs/import PRs.
