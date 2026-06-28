# EQ v2.3 Production Smoke Acceptance Template

Date: 2026-06-28

Status: template only. Production smoke is not executed in PR-EQ-V20-06.

## 1. Purpose

This document is the operator checklist for the EQ v2.3 commercial content rollout after PR-EQ-V20-01 through PR-EQ-V20-06 are deployed.

It is not production evidence until an operator runs the smoke after explicit backend and frontend deployment approval.

## 2. Scope

Validate that production EQ-60 result delivery exposes and renders the v2.3 backend-authoritative payload:

- `methodology.report_version=eq_report_v5_assets_commercial_ready_v2_3`
- v2.3 personalization route selection
- formulation-aware reality scene variants
- result page depth modules
- route headline, evidence label, and next best action
- no paywall, locked state, blur, SKU, raw technical tags, or clickable SJT entry
- zh-CN and en localized resolved assets

## 3. Required Deployment Facts

| Item | Value |
| --- | --- |
| Backend production SHA | TBD |
| Frontend production SHA | TBD |
| Backend latest merged EQ PR | PR-EQ-V20-06 |
| Frontend latest merged EQ PR | PR-EQ-V20-05 |
| Deployment window | TBD |
| Operator | TBD |

## 4. Smoke Attempts

Use anonymous production attempts only. Do not create paid orders, do not enable SJT, and do not call CMS/import/search/deploy jobs from this smoke.

| Locale | Attempt ID | Quality Level | Result URL | Notes |
| --- | --- | --- | --- | --- |
| en | TBD | TBD | TBD | TBD |
| zh-CN | TBD | TBD | TBD | TBD |

Low-confidence behavior should be verified with canonical fixtures or a known safe attempt. Do not intentionally create abnormal production answers just to force low confidence.

## 5. API Checks

### 5.1 Questions

```text
GET /api/v0.3/scales/EQ_60/questions?locale=<locale>
```

Expected:

- 60 questions.
- Dimension codes are `SA`, `ER`, `EM`, `RM`.
- No visible 50-question metadata.

### 5.2 Report Access

```text
GET /api/v0.3/attempts/{attempt_id}/report-access
```

Expected:

- `access_state=ready`
- `report_state=ready`
- `payload.variant=full`
- `payload.access_level=full`
- `payload.locked=false`
- `payload.offers=[]`
- `payload.upgrade_sku=null`
- `payload.view_policy.blur_others=false`

### 5.3 Report

```text
GET /api/v0.3/attempts/{attempt_id}/report?locale=<locale>
```

Expected:

- `report.eq_report_mode=self_report`
- `report.measurement_type=self_report_trait_mixed_ei`
- `report.methodology.report_version=eq_report_v5_assets_commercial_ready_v2_3`
- `report.scores.global` exists
- `report.scores.dimensions.SA/ER/EM/RM` exist
- `report.dimension_summary` has 4 rows
- `report.quality.confidence_label` exists
- `report.interpretation.route_id` starts with `route.eq.`
- `report.asset_refs.personalization_route_id` matches `report.interpretation.route_id`
- `report.assets.personalization_route.route_headline` is localized
- `report.interpretation.primary_scene_variant_ids` is present for normal-confidence routes
- `report.assets.reality_scenes[0].id` matches the first selected scene variant when variants are present
- `report.asset_refs.result_page_depth_module_ids` is present
- `report.assets.result_page_depth_modules` is present
- `report.next_module.available=false`
- `report.next_module.status=planned`

## 6. Frontend Checks

Routes:

```text
https://fermatmind.com/en/result/{attempt_id}
https://fermatmind.com/zh/result/{attempt_id}
```

Expected:

- EQ result page uses `EQResultV5`.
- It does not fall back to generic `RichResultReport` for v2.3 payloads.
- Core Insight, Evidence Snapshot, Result Depth, Quality, Emotional Matrix, Mechanism, Reality, Career Environment, Action Prescription, SJT Bridge, Scientific Boundary, conversion actions, and Agent entry render from backend resolved assets.
- Route headline / evidence label / next best action are visible when provided.
- Scene variants render user-facing copy, not raw ids.

## 7. Forbidden Output Scan

Search visible page text and user-visible payload for:

- `locked`
- `blur`
- `paywall`
- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `解锁`
- `购买`
- `付费`
- `premium`
- `upgrade`
- `profile:*`
- `quality_level:*`
- `focus:*`
- `bucket:*`
- raw formulation ids
- raw mechanism ids
- raw scene variant ids

Expected:

- None appears in user-visible page text.
- Raw ids may exist in developer payload fields only.

## 8. SJT Boundary

Expected:

- SJT remains planned/unavailable.
- No clickable SJT take entry appears.
- The page does not describe EQ-SJT as MSCEIT, an ability test, a certified emotional intelligence measure, a hiring assessment, or a clinical tool.

## 9. Screenshot Evidence

Save screenshots outside the repo, for example:

```text
/tmp/eq_v2_3_prod_smoke_<timestamp>/
```

Recommended screenshots:

- en hero + evidence
- en depth + scene variants
- en action + SJT bridge
- zh hero + evidence
- zh depth + scene variants
- zh action + SJT bridge

Do not commit screenshots unless separately authorized.

## 10. Decision

| Gate | Result |
| --- | --- |
| Backend v2.3 payload present | TBD |
| Frontend consumes v2.3 assets | TBD |
| No paywall/SKU/raw tags | TBD |
| SJT unavailable | TBD |
| zh-CN localized payload and page | TBD |
| en localized payload and page | TBD |
| Low-confidence path checked by fixture/known attempt | TBD |
| EQ v2.3 production smoke accepted | TBD |

If any P0/P1 issue appears, mark accepted as `no`, stop rollout, and file a follow-up fix PR.
