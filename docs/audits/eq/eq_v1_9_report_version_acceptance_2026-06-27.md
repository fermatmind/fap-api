# EQ v1.9 Report Version Production Acceptance

Date: 2026-06-27

## 1. Scope

This lightweight acceptance record verifies the production EQ-60 result report after the report-version marker fix.

Validation target:

- Web: https://fermatmind.com
- API: https://api.fermatmind.com
- Backend SHA: `b58fb9754a079913ef50123abfddaf2ba9fc40ef`
- Release: `fap-api-20260627-b58fb975`
- Expected report version: `eq_report_v5_assets_commercial_ready_v1_9`

This check did not deploy, import content, modify scoring, modify questions, enable SJT, or change production configuration.

## 2. Smoke Attempt

- Attempt ID: `0f15fb0c-0286-40f3-917a-f7e38fcd85df`
- Attempt type: anonymous production EQ-60 attempt
- Locale used for submit: `zh-CN`
- Evidence directory: `/tmp/eq_v1_9_report_version_prod_smoke_20260627114446`
- Token handling: auth token used only locally for smoke requests and not recorded in this report

Question delivery:

- Endpoint: `GET /api/v0.3/scales/EQ_60/questions?locale=zh-CN&region=CN_MAINLAND`
- Result: `60` questions returned

## 3. API Acceptance

### report-access

Endpoint:

`GET /api/v0.3/attempts/0f15fb0c-0286-40f3-917a-f7e38fcd85df/report-access?locale=zh-CN`

Observed:

- `access_state=ready`
- `report_state=ready`
- `payload.access_level=full`
- `payload.variant=full`
- `payload.locked=false`
- `payload.upgrade_sku=null`
- `payload.upgrade_sku_effective=null`
- `payload.offers=[]`
- `payload.view_policy.blur_others=false`
- `payload.access.all_results_free=true`
- `payload.access.paywall=false`
- `paywall_suppressed=true`

### report

Endpoint:

`GET /api/v0.3/attempts/0f15fb0c-0286-40f3-917a-f7e38fcd85df/report?locale=zh-CN`

Observed:

- `ok=true`
- `generating=false`
- `report.eq_report_mode=self_report`
- `report.measurement_type=self_report_trait_mixed_ei`
- `report.methodology.report_version=eq_report_v5_assets_commercial_ready_v1_9`
- `report.access.all_results_free=true`
- `report.access.locked=false`
- `report.access.blur=false`
- `report.access.paywall=false`
- `report.next_module.module_code=EQ_SJT_16`
- `report.next_module.status=planned`
- `report.next_module.available=false`
- `report.scores.dimensions` contains `SA`, `ER`, `EM`, `RM`

Resolved v1.9 asset keys present:

- `result_snapshot`
- `result_page_depth_modules`
- `personalization_route`
- `reality_scenes`
- `career_environment`
- `action_prescription`
- `commercial_conversion_actions`
- `quality_confidence`
- `psychometric_evidence_status`
- `agent_dialogue_playbooks`
- `scientific_contract`
- `score_system`
- `sjt_bridge`

Locale check:

- `zh-CN` report returned the expected v1.9 report version.
- `en` report returned the expected v1.9 report version.

## 4. Page Acceptance

Authenticated result pages were opened for both locales using the same anonymous attempt.

Pages:

- `https://fermatmind.com/zh/result/0f15fb0c-0286-40f3-917a-f7e38fcd85df`
- `https://fermatmind.com/en/result/0f15fb0c-0286-40f3-917a-f7e38fcd85df`

Screenshot evidence:

- `/tmp/eq_v1_9_report_version_prod_smoke_20260627114446/result_zh.png`
- `/tmp/eq_v1_9_report_version_prod_smoke_20260627114446/result_en.png`

Visible checks:

- EQ result page rendered the v5/v1.9 EQ result experience.
- Core report structure rendered: result hero, evidence snapshot, quality/confidence, emotional matrix, action prescription, scientific boundary.
- Conversion/recovery elements rendered as free report actions.
- SJT remained planned/unavailable.
- No visible paywall, SKU, purchase, unlock, or raw technical tag leakage was found.

Note: the English page text contains the phrase `blurred boundaries` inside a career-environment explanation. This is ordinary relationship-boundary copy, not the legacy paywall `blur` state.

## 5. Payload Scan Notes

The `/report` payload still includes legacy compatibility fields such as `report_tags` and old report blocks. These are not part of the EQ v1.9 user-facing renderer path and were not visible on the result page during this smoke.

Important visible-surface result:

- No visible `profile:*`
- No visible `quality_level:*`
- No visible `focus:*`
- No visible `bucket:*`
- No visible `SKU_EQ_60_FULL_299`
- No visible `EQ_60_FULL`
- No visible paywall / purchase / unlock CTA

## 6. Risks and Follow-Ups

P0/P1 findings:

- None for the report-version acceptance target.

Residual risks:

- This smoke used an automated API submission pattern, so the result quality was low-confidence (`quality.level=D`, flags included `SPEEDING`, `LONGSTRING`, and `NEUTRAL_RESPONSE_BIAS`). This is acceptable for report-version validation but not a normal-confidence UX validation.
- The payload still carries legacy compatibility fields. Current frontend rendering hides them, but long-term cleanup should remove or isolate legacy report blocks from the EQ v1.9 report contract.
- Production anonymous attempt created a real analytics/result artifact. No payment, SJT, or Agent runtime mutation was performed.

## 7. Decision

Result: **accepted for report-version fix**

The production `/report` endpoint now returns:

`methodology.report_version=eq_report_v5_assets_commercial_ready_v1_9`

The all-free contract remains intact, SJT remains planned/unavailable, and no user-visible paywall/raw-tag leakage was observed.
