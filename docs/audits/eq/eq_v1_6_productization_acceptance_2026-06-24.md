# EQ v1.6 Productization Acceptance Audit

Date: 2026-06-24

Environment:

- Web: https://fermatmind.com
- API: https://api.fermatmind.com
- Audit type: production smoke QA, docs-only
- Mutation boundary: created anonymous production EQ attempts only; no deploy, no code changes, no frontend changes, no manifest/state changes

## 1. Executive Summary

EQ v1.6 production smoke confirms that the core backend and frontend delivery path is live:

- Production EQ question delivery returns 60 EQ_60 items in both `en` and `zh-CN`.
- Production EQ submit works through the public anonymous guest-token flow.
- `/report-access` can converge to ready/full/all-free with no locked, blur, paywall, SKU, or paid offers.
- `/report` returns `eq_report_v5_assets_commercial_ready_v1_6`.
- Frontend result pages use `EQResultV5` for both English and Chinese routes.
- v1.6 resolved assets are present, including result snapshot, conversion actions, quality confidence, psychometric evidence, career environment, Agent playbook assets, and SJT bridge.
- SJT remains planned/unavailable and no clickable SJT take entry was found.
- No visible paywall/SKU/raw technical tag leakage was found on the checked result pages.
- The previous Chinese locale P1 was retested after PR-EQ-ASSET-04 reached production: `/report?locale=zh-CN` now returns `locale=zh-CN`, Chinese score labels, and Chinese resolved assets.

Final gate:

- Agent phase allowed: **Yes**
- Reason: EQ v1.6 all-free report delivery, resolved assets, no-paywall boundary, SJT planned boundary, and locale-specific resolved assets are production-verified.

## 2. Deployed SHAs And PR Coverage

Backend production:

- Initial smoke SHA: `8969804e481b3aeb271ed34b8ea80f26cba0213a`
- Locale retest SHA: `a189285dc43977e0a185a1c72857423f69b6e07e`
- Release/revision: production `REVISION` verified at `a189285dc43977e0a185a1c72857423f69b6e07e`
- Contains PR-EQ-ASSET-01: yes, previously verified in deploy readiness/deploy output
- Contains PR-EQ-ASSET-02: yes, previously verified in deploy readiness/deploy output
- Contains PR-EQ-ASSET-04: yes, `27f5b37dff2f8330d08fe382d3f46bf5fe9b3727` is included in deployed main history

Frontend production:

- SHA: `764263e98a4a0f311bff89a00bf744dab82df579`
- Contains PR-EQ-ASSET-03: yes, previously verified in deploy readiness/deploy output

## 3. Smoke Attempts / Sessions

Two production anonymous attempts were used because the first direct API smoke submitted too quickly and intentionally triggered the quality system's low-confidence path.

### Attempt A: Low-Confidence Path

- Attempt ID: `29e0804e-36b9-4426-be69-2094612557f9`
- Session marker: `codex_eq_v1_6_prod_smoke_20260624_1782304443180`
- Locale path tested: `en`, then result route also checked under `zh`
- Purpose: validates low-confidence routing and retest guidance behavior
- Quality result: level `D`, confidence `low`, flag `SPEEDING`
- Interpretation: `low_confidence_result`
- Action prescription: `retest_reflection`

### Attempt B: Normal-Quality Path

- Attempt ID: `adbc27ed-f1f4-47be-8548-e69cc54bbd47`
- Session marker: `codex_eq_v1_6_prod_smoke_20260624_1782304582614`
- Locale path tested: `en`, then result route also checked under `zh`
- Purpose: validates normal v1.6 commercial-ready result path
- Quality result: level `A`, confidence `high`
- Completion time: 129 seconds
- Interpretation: `developing_foundation`
- Mechanism IDs: `ER_RM_low_low`
- Action prescription: `emotion_labeling`

### Attempt C: Locale Retest After PR-EQ-ASSET-04

- Attempt ID: `1f869cf8-027b-4298-ab5a-a368a4c53fb9`
- Session marker: `codex_eq_v16_locale_retest_20260624135749_lcbyq8`
- Evidence directory: `/tmp/eq_v16_locale_retest_20260624135749/`
- Purpose: verifies `/report?locale=zh-CN` resolves Chinese EQ v1.6 assets after PR-EQ-ASSET-04.
- Question count: 60 in `en`, 60 in `zh-CN`
- `report-access`: ready/full/all-free, `locked=false`, `offers=[]`, `upgrade_sku=null`, `blur_others=false`
- `/report?locale=en`: `locale=en`, global label `Emotional & Relational Functioning Index`
- `/report?locale=zh-CN`: `locale=zh-CN`, global label `情绪与关系综合指数`
- Chinese resolved assets: present
- English global label leakage in Chinese assets: not observed
- `methodology.report_version`: `eq_report_v5_assets_commercial_ready_v1_6`
- `next_module.available`: `false`

Screenshots and raw JSON evidence are stored locally under:

- `/tmp/eq_v1_6_prod_smoke_20260624/01_take_en_initial.png`
- `/tmp/eq_v1_6_prod_smoke_20260624/02_result_en.png`
- `/tmp/eq_v1_6_prod_smoke_20260624/03_result_zh.png`
- `/tmp/eq_v1_6_prod_smoke_20260624/04_normal_result_en.png`
- `/tmp/eq_v1_6_prod_smoke_20260624/05_normal_result_zh.png`
- `/tmp/eq_v1_6_prod_smoke_20260624/report_en.json`
- `/tmp/eq_v1_6_prod_smoke_20260624/normal_report_en.json`
- `/tmp/eq_v1_6_prod_smoke_20260624/normal_report_zh.json`
- `/tmp/eq_v1_6_prod_smoke_20260624/normal_report_access_en_recheck.json`

The screenshots are not committed to the repository.

## 4. Question Delivery

Production API endpoints checked:

- `GET /api/v0.3/scales/EQ_60/questions?locale=en`
- `GET /api/v0.3/scales/EQ_60/questions?locale=zh-CN`

Result:

- HTTP status: 200 for both locales
- `scale_code`: `EQ_60`
- English item count: 60
- Chinese item count: 60
- Dimension codes observed: `EM`, `ER`, `RM`, `SA`
- No 50-question regression observed.

Example first question metadata:

- English: question `1`, dimension `SA`, text starts with "I am very good at identifying..."
- Chinese: question `1`, dimension `SA`, text starts with "我能精准捕捉..."

## 5. Report Access Summary

Attempt B final recheck:

```json
{
  "ok": true,
  "attempt_id": "adbc27ed-f1f4-47be-8548-e69cc54bbd47",
  "access_state": "ready",
  "report_state": "ready",
  "pdf_state": "ready",
  "reason_code": "projection_missing_result_ready",
  "payload": {
    "access_level": "full",
    "variant": "full",
    "access": {
      "all_results_free": true,
      "locked": false,
      "blur": false,
      "paywall": false
    },
    "locked": false,
    "upgrade_sku": null,
    "offers": [],
    "modules_allowed": [
      "eq_core",
      "eq_full",
      "eq_cross_insights",
      "eq_growth_plan"
    ],
    "access_source": "free_full_report_mode",
    "paywall_suppressed": true
  }
}
```

Notes:

- The first report-access read for Attempt B briefly returned `access_state=locked`, `report_state=pending`, `access_level=free`, `variant=free`.
- A later authenticated recheck converged to ready/full/all-free.
- This was not page-visible in the final result route, but it should be tracked as a P2 projection-latency concern.

## 6. Report Payload Summary

Attempt B `/report?locale=en`:

- HTTP status: 200
- `generating`: false
- `eq_report_mode`: `self_report`
- `measurement_type`: `self_report_trait_mixed_ei`
- `methodology.report_version`: `eq_report_v5_assets_commercial_ready_v1_6`
- `next_module.status`: `planned`
- `next_module.available`: false

Global score:

```json
{
  "band": "developing",
  "label": "Emotional & Relational Functioning Index",
  "raw_score": 198,
  "percentile": 29.69,
  "standard_score": 92
}
```

Dimension summary:

- `SA`: standard score 89, percentile 23, band `developing`
- `ER`: standard score 79, percentile 8, band `foundational`
- `EM`: standard score 113, percentile 81, band `proficient`
- `RM`: standard score 87, percentile 19, band `developing`

Resolved v1.6 asset keys present:

- `action_prescription`
- `agent_dialogue_playbooks`
- `backend_integration_contract`
- `career_environment`
- `commercial_conversion_actions`
- `core_formulation`
- `cross_assessment_context`
- `mechanisms`
- `personalization_route`
- `psychometric_evidence_status`
- `quality`
- `quality_confidence`
- `reality_scenes`
- `result_snapshot`
- `scientific_contract`
- `score_system`
- `sjt_bridge`

Asset references present:

- `core_formulation_id`
- `result_snapshot_id`
- `commercial_conversion_ids`
- `quality_confidence_id`
- `psychometric_evidence_ids`
- `mechanism_ids`
- `scene_ids`
- `career_environment_ids`
- `action_prescription_id`
- `agent_playbook_ids`
- `sjt_bridge_id`
- `scientific_contract_id`
- `score_system_id`

## 7. Frontend Renderer And Page Checks

Result pages checked:

- `https://fermatmind.com/en/result/adbc27ed-f1f4-47be-8548-e69cc54bbd47`
- `https://fermatmind.com/zh/result/adbc27ed-f1f4-47be-8548-e69cc54bbd47`

Renderer:

- English route: `data-testid="eq-result-v5"` found
- Chinese route: `data-testid="eq-result-v5"` found
- No evidence of `RichResultReport` fallback takeover.

English visible sections found:

- Evidence Snapshot
- Interpretation Confidence
- Emotional Matrix
- Mechanism
- Reality
- Career Environment
- Action Prescription
- Future scenario module
- Scientific Boundary
- Save / Email / PDF / Share / Retake
- Agent entry text

Chinese route visible sections found:

- 证据
- 置信
- 情绪
- 机制
- 现实
- 职业
- 行动
- 科学
- 保存
- 分享
- Agent entry text

Important caveat:

- The initial smoke found English resolved assets on the Chinese route. The PR-EQ-ASSET-04 production retest closed this issue at the API payload layer. A separate browser visual smoke can still be used before a major launch campaign, but it is no longer a P1 blocker for the Agent-ready baseline.

## 8. Forbidden Terms / Fields Check

Visible page text checked for:

- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `paywall`
- `locked`
- `blur`
- `profile:`
- `quality_level:`
- `focus:`
- `bucket:`
- raw IDs such as `high_empathy_low_recovery`, `EM_ER_high_low`, `eq60.signal_signature.v1`
- `解锁`
- `购买`
- `付费`

Result:

- Attempt A English page: none found
- Attempt A Chinese page: none found
- Attempt B English page: none found
- Attempt B Chinese page: none found

Report payload note:

- Raw backend report JSON still contains internal `report_tags` and some legacy/diagnostic fields. These were not visible on the page.
- `report-access` still contains legacy `invite_unlock_*` diagnostic fields even though `access_source=free_full_report_mode`; this is P2 governance risk, not an observed user-visible paywall.

## 9. SJT Bridge

Attempt B report payload:

```json
{
  "status": "planned",
  "available": false,
  "module_code": "EQ_SJT_16",
  "cta_asset_id": "eq.sjt_bridge.planned"
}
```

Page result:

- Future scenario module is visible.
- No clickable SJT take entry was found.
- No MSCEIT / certified ability-test claim was observed.

## 10. Low-Confidence Path

Attempt A verifies the low-confidence path in production:

- Quality level: `D`
- Confidence: `low`
- Flag: `SPEEDING`
- Core formulation: `low_confidence_result`
- Action prescription: `retest_reflection`
- Page still renders through `EQResultV5`
- No visible raw tags, paywall, SKU, locked, blur, or paid CTA found

The low-confidence attempt was created by the audit's fast API submission and should be treated as a smoke artifact, not as a normal user-like result.

## 11. Issues And Risks

### Closed P1: Chinese Result Path Previously Received English Resolved Assets

Evidence:

- `GET /api/v0.3/attempts/adbc27ed-f1f4-47be-8548-e69cc54bbd47/report?locale=zh-CN` returned `locale=en`.
- `GET /api/v0.3/attempts/adbc27ed-f1f4-47be-8548-e69cc54bbd47/report?locale=zh` also returned `locale=en`.
- Chinese result route displayed Chinese UI chrome, but core resolved report assets such as `core_formulation`, `result_snapshot`, `quality_confidence`, and `action_prescription` were English.

Original impact:

- Blocks Chinese commercial-quality acceptance.
- Blocks Agent phase because the Agent should not inherit unresolved locale authority behavior.

Resolution:

- PR-EQ-ASSET-04 fixed report locale resolution so explicit report locale requests are passed through report delivery and composer resolution.
- Production retest attempt `1f869cf8-027b-4298-ab5a-a368a4c53fb9` confirmed `/report?locale=zh-CN` returns `locale=zh-CN`, Chinese score labels, and Chinese resolved assets.
- The report snapshot remains attempt-locale-bound; localized live render does not overwrite the original attempt-locale snapshot.

Status:

- P1 closed.
- Chinese commercial-quality API payload path accepted.
- Agent phase gate can open after the v1.6 content baseline is frozen.

### P2: report-access Projection Latency

Evidence:

- Attempt B first report-access read briefly returned pending/free/locked after `/report` was already deliverable.
- Later authenticated recheck returned ready/full/all-free.

Impact:

- Not observed as a final user-visible failure in this smoke.
- Could create brief readiness ambiguity immediately after submit.

Recommended follow-up:

- Re-check report-access projection refresh timing for EQ.
- Ensure report-access ready/full/all-free aligns with `/report` fallback delivery as soon as result exists.

### P2: Legacy invite_unlock Diagnostic Fields Remain In EQ report-access

Evidence:

- `report-access` includes `invite_unlock_v1` and `invite_unlock_diag_v1` while also returning `access_source=free_full_report_mode`.

Impact:

- Not page-visible in this smoke.
- Future frontend or Agent consumers could mistakenly read these fields.

Recommended follow-up:

- Mark these as deprecated diagnostics for EQ or suppress them for all-free EQ report-access payloads.

## 12. Final Acceptance Decision

Production EQ v1.6 is accepted as the first commercial-ready content baseline:

- Backend v1.6 payload delivery: pass
- Frontend EQResultV5 rendering: pass
- All-free/no-paywall runtime: pass
- SJT planned/unavailable boundary: pass
- Low-confidence path safety: pass
- English commercial result path: pass
- Chinese commercial result path: pass after PR-EQ-ASSET-04 production retest

Agent phase allowed: **Yes**

Reason:

- EQ v1.6 content authority, resolved assets, locale-specific report delivery, no-paywall contract, low-confidence safety path, and SJT planned boundary are now production-verified.

Next recommended task:

- PR-EQ-ASSET-05: Freeze EQ v1.6 as the first commercial content baseline, then proceed to the Agent-ready content operating system train.
