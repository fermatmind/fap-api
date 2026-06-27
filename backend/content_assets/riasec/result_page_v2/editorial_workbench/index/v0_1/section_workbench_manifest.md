# RIASEC Result Page Editorial Workbench Manifest

Task: `RIASEC-RESULT-PAGE-CONTENT-ASSET-GAP-PLAN-01`
Date: 2026-06-27
Runtime status: not runtime
CMS write: none
Production status: no production import, no production rollout

## Purpose

This manifest defines the editable content workbench files for the Holland/RIASEC result page.

The workbench layer is intentionally between raw existing assets and formal selector-ready assets:

```text
existing v1 assets
  -> section editorial workbench Markdown
  -> operator/GPT/Codex editorial repair
  -> later formal staging JSONL assets
```

This manifest does not create runtime assets and does not authorize import.

## Global Rules

Every workbench file must:

- be human-editable Markdown;
- use current backend assets as source material;
- allow GPT to thicken, rewrite, and compare structure without touching runtime code;
- preserve `RIASEC` as an interest-structure result, not a fixed identity;
- keep public/share copy thinner and stricter than owner-only result copy;
- mark all later conversion as staging-only until separately approved.

Every workbench file must forbid:

- deterministic career recommendation;
- career match / occupation match / job fit / fit score;
- success, salary, admissions, hiring, or performance predictions;
- ability, talent, intelligence, personality identity, diagnosis, treatment, or therapy claims;
- raw score, percentile, private attempt id, user id, private URL, selector trace, or QA metadata exposure;
- copied competitor text.

## Workbench Index

| Order | Workbench id | Planned file | Source assets | Main editorial action |
| ---: | --- | --- | --- | --- |
| 1 | `summary_card` | `summary_card/v0_1/summary_card_content_workbench.zh-CN.md` | top-code confidence, profile shape, near-tie, low-quality, top3 | Build mature first-screen result-card explanation. |
| 2 | `dimension_map` | `dimension_map/v0_1/dimension_map_content_workbench.zh-CN.md` | profile shape, dimension labels, dimension deep copy | Explain score shape, all-high, near-tie, flat profile, and boundaries. |
| 3 | `dimension_deep` | `dimension_deep/v0_1/dimension_deep_content_workbench.zh-CN.md` | six dimension deep copy | Repair density, dedupe repeated labels, create collapsed/expanded hierarchy. |
| 4 | `combination` | `combination/v0_1/combination_content_workbench.zh-CN.md` | pair blend 15, top3 20, near-tie | Organize top2/top3 reading, alternate-code boundary, conflict priority. |
| 5 | `activity_validation` | `activity_validation/v0_1/activity_validation_content_workbench.zh-CN.md` | activity examples, next exploration nodes, top3 experiments | Turn large activity pools into small validation actions. |
| 6 | `career_bridge` | `career_bridge/v0_1/career_bridge_content_workbench.zh-CN.md` | occupation examples, career display bridge policy | Keep occupation examples examples-only and non-deterministic. |
| 7 | `quality_boundary` | `quality_boundary/v0_1/quality_boundary_content_workbench.zh-CN.md` | low-quality, 140Q task/environment/role, method boundary | Clarify 60Q/140Q, low-quality, retake, and norm-unavailable behavior. |
| 8 | `feedback_disagree` | `feedback_disagree/v0_1/feedback_disagree_content_workbench.zh-CN.md` | disagree path, feedback action lab | Explain disagreement and feedback without mutating scores/results. |
| 9 | `share_pdf_history` | `share_pdf_history/v0_1/share_pdf_history_content_workbench.zh-CN.md` | share/PDF/history copy, rendered preview assertions | Split owner-only, PDF, share, history, compare, locked/free rules. |

## Template For Each Workbench File

Each future workbench file should use this structure:

```text
# <Section Name> Content Workbench

## Section Purpose

## Current Source Assets

## Current Content Summary

## Current Gaps

## Too Thin

## Too Thick / Repetitive

## Safety Risks

## Benchmark Structure Target

## Editable Draft Slots

## Forbidden Copy

## GPT Editing Prompt

## Codex Conversion Notes

## Go / No-Go
```

## Conversion Guard

The workbench layer is not allowed to claim:

```text
runtime_use=staging_only
production_use_allowed=true
ready_for_runtime=true
ready_for_production=true
cms_write_performed=true
runtime_change_performed=true
frontend_fallback_allowed=true
```

If a later PR converts a workbench into JSONL assets, that later PR must create its own validation and safety reports.

## Next PR

Start with:

```text
RIASEC-RESULT-SUMMARY-CARD-EDITORIAL-WORKBENCH-01
```

Expected output:

```text
backend/content_assets/riasec/result_page_v2/editorial_workbench/summary_card/v0_1/summary_card_content_workbench.zh-CN.md
```
