# EQ v1.9 Production Smoke Acceptance Template

Date: 2026-06-26

Status: template only. Production smoke was not executed in this PR.

## 1. Purpose

This document is the acceptance template for the EQ v1.9 commercial content-depth rollout after PR-EQ-V19-01 through PR-EQ-V19-05.

It is intentionally docs-only and must not be treated as production verification evidence until an operator performs the smoke run after explicit deployment approval.

## 2. Scope

Validate that production EQ-60 result delivery renders the v1.9 backend-authoritative content assets end to end:

- v1.9 canonical report payload fields
- personalization route matrix v2 route selection
- formulation-aware reality scene variants
- result page depth modules
- cross-assessment context
- no paywall, no SJT entry, no raw technical tags
- zh-CN and en locale rendering

## 3. Required Deployment Facts

Fill in during the production smoke run:

| Item | Value |
| --- | --- |
| Backend production SHA | TBD |
| Frontend production SHA | TBD |
| Backend latest merged EQ PR | PR-EQ-V19-04 / PR-EQ-V19-06 if deployed |
| Frontend latest merged EQ PR | PR-EQ-V19-05 |
| Deployment window | TBD |
| Operator | TBD |

## 4. Smoke Attempts

Use anonymous production attempts only. Do not create paid orders, do not enable SJT, and do not call any production import or CMS mutation job.

| Locale | Attempt ID | Quality Level | Result URL | Notes |
| --- | --- | --- | --- | --- |
| en | TBD | TBD | TBD | TBD |
| zh-CN | TBD | TBD | TBD | TBD |

Low-confidence behavior should be verified with an existing safe fixture or known attempt if available. Do not intentionally create abnormal production answers just to force low confidence.

## 5. API Checks

### 5.1 Questions

Endpoint:

```text
GET /api/v0.3/scales/EQ_60/questions?locale=<locale>
```

Expected:

- `questions.items` count is 60.
- Dimension codes are `SA`, `ER`, `EM`, `RM`.
- No visible 50-question metadata.

### 5.2 Report Access

Endpoint:

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
- `payload.view_policy.free_sections` contains all EQ section keys

### 5.3 Report

Endpoint:

```text
GET /api/v0.3/attempts/{attempt_id}/report?locale=<locale>
```

Expected:

- `report.eq_report_mode=self_report`
- `report.measurement_type=self_report_trait_mixed_ei`
- `report.scores.global` exists
- `report.scores.dimensions.SA/ER/EM/RM` exist
- `report.dimension_summary` has 4 rows
- `report.quality.confidence_label` exists
- `report.interpretation.route_id` starts with `route.eq.`
- `report.interpretation.primary_scene_variant_ids` is present for normal confidence routes
- `report.asset_refs.result_page_depth_module_ids` includes:
  - `eq.depth.how_to_read.default`
  - `eq.depth.evidence_stack.default`
  - `eq.depth.reality_check.default`
- `report.assets.result_page_depth_modules` has 3 modules
- `report.assets.personalization_route.route_headline` is localized
- `report.assets.reality_scenes[0].id` matches the first selected scene variant id when variants are present
- `report.assets.reality_scenes[*].micro_script` is present for selected variants
- `report.assets.cross_assessment_context` is present
- `report.next_module.available=false`
- `report.next_module.status=planned`

## 6. Frontend Checks

Routes:

```text
https://fermatmind.com/en/result/{attempt_id}
https://fermatmind.com/zh/result/{attempt_id}
```

Expected visible sections:

- Core Insight
- Evidence Snapshot
- Result Depth / 深读路径
- Quality / Interpretation Confidence
- Emotional Matrix
- Mechanism
- Reality scenes with micro-script or equivalent v1.9 scene variant detail
- Career Environment
- Action Prescription
- SJT Bridge
- Scientific Boundary
- Cross-Assessment Context / 跨测评上下文
- Save / Share / Related Tests

Expected renderer:

- EQ result page uses `EQResultV5`.
- It must not fall back to generic `RichResultReport` for v1.9 payloads.

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

- None of the above appears in user-visible page text.
- Raw ids may exist in developer payload fields, but must not be rendered as public copy.

## 8. SJT Boundary

Expected:

- SJT remains planned/unavailable.
- No clickable SJT take entry appears.
- The page must not claim EQ-SJT is MSCEIT, an ability test, a certified emotional intelligence measure, a hiring assessment, or a clinical tool.

## 9. Screenshot Evidence

Save screenshots outside the repo, for example:

```text
/tmp/eq_v1_9_prod_smoke_<timestamp>/
```

Recommended screenshots:

- en result hero + evidence
- en result depth + reality scene variants
- en cross-assessment + SJT bridge
- zh result hero + evidence
- zh result depth + reality scene variants
- zh cross-assessment + SJT bridge

Do not commit screenshots unless separately authorized.

## 10. Decision

Fill in after execution:

| Gate | Result |
| --- | --- |
| Backend v1.9 payload present | TBD |
| Frontend consumes v1.9 assets | TBD |
| No paywall/SKU/raw tags | TBD |
| SJT unavailable | TBD |
| zh-CN localized payload and page | TBD |
| en localized payload and page | TBD |
| Low-confidence path checked by fixture/known attempt | TBD |
| EQ v1.9 production smoke accepted | TBD |

If any P0/P1 issue appears, mark accepted as `no`, stop rollout, and file a follow-up fix PR.
