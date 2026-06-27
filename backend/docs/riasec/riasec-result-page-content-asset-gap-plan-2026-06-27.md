# RIASEC Result Page Content Asset Gap Plan

Task: `RIASEC-RESULT-PAGE-CONTENT-ASSET-GAP-PLAN-01`
Date: 2026-06-27
Repository: `fap-api`
Branch: `codex/riasec-result-page-content-asset-gap-plan-01`
Base: `origin/main` at `d75a104a4fb96be871cd6a39751336c082569f65`

## Scope

This is a docs/artifact-only planning package for Holland/RIASEC result page content assets.

Allowed:

- align the full result-page section map;
- reconcile current asset inventory with the section-first audit;
- define one editable workbench file per result-page section;
- describe how GPT/Codex should thicken, repair, and polish each section before later asset conversion.

Not allowed:

- runtime changes;
- CMS writes or imports;
- production import or rollout;
- environment changes;
- frontend fallback copy;
- new formal selector assets;
- new formal runtime content assets.

## Why This Plan Exists

The previous section inventory audit showed that the RIASEC result page has a large content pool, but the content is not yet organized as section-level editable workbench files.

Current situation:

- Existing v1 content assets are large enough for editorial work.
- Rendered result/PDF evidence shows some sections are already content-heavy.
- Result Page V2 selector-ready assets are still narrow.
- The next practical step is not to generate formal JSONL selector assets immediately.
- The next practical step is to create editable section workbench files that the operator can hand to GPT/Codex for content thickening, comparison, rewriting, and safety repair.

This plan turns the RIASEC result page into a set of editorial work units.

## Current Asset Inventory

Top-level RIASEC content assets under `backend/content_assets/riasec`:

| Asset family | Current file | Count / shape | Primary section use |
| --- | --- | ---: | --- |
| Top-code confidence | `top_code_confidence_copy_v1.zh-CN.json` | 5 states | Summary card |
| Profile shape | `profile_shape_copy_v1.zh-CN.json` | 6 shapes | Summary card, dimension map |
| Near-tie alternate code | `near_tie_alternate_code_copy_v1.zh-CN.json` | 4 states | Summary card, combination |
| Low-quality cautious reading | `low_quality_cautious_reading_v1.zh-CN.json` | 5 states + 4 slots | Quality boundary, summary card |
| Dimension deep copy | `dimension_deep_copy_v1.zh-CN.r3.json` | 6 dimensions | Dimension deep |
| Pair blend | `pair_blend_15_pairs_v1.zh-CN.jsonl` | 15 rows | Combination |
| Top-3 chain strategy | `top3_code_chain_strategy_v1.zh-CN.jsonl` | 20 rows | Summary card, combination, action validation |
| Activity examples | `activity_task_examples_v1.zh-CN.jsonl` | 360 rows | Activity validation |
| Occupation examples | `occupation_examples_boundary_v1.zh-CN.jsonl` | 360 rows | Career bridge |
| Next exploration nodes | `next_exploration_nodes_v1.zh-CN.jsonl` | 270 rows | Activity validation, next steps |
| Feedback action lab | `feedback_action_lab_v1.zh-CN.jsonl` | 204 rows | Feedback/disagree |
| 140Q task/environment/role | `140q_task_environment_role_v1.zh-CN.jsonl` | 126 rows | Quality/form boundary, 140Q context |
| Aspirations calibration | `aspirations_calibration_v1.zh-CN.jsonl` | 70 rows | Activity validation, aspirations |
| Disagree path | `disagree_path_v1.zh-CN.jsonl` | 45 rows | Feedback/disagree |
| Share/PDF/history copy | `share_pdf_history_v1.en.json`, `share_pdf_history_v1.zh-CN.json` | 7 surfaces each | Share/PDF/history/compare |
| FAQ | `faq_v1.en.json`, `faq_v1.zh-CN.json` | 20 questions each | Method boundary |
| Method boundary | `professional_method_boundary_v1.en.json`, `professional_method_boundary_v1.zh-CN.json` | 8 sections each | Method boundary, safety |
| Technical note summary | `technical_note_user_summary_v1.en.json`, `technical_note_user_summary_v1.zh-CN.json` | 6 sections each | Method boundary |

The 9 JSONL files contain 1470 rows. The content problem is not raw volume. The problem is editorial shape, section ownership, public/private surface split, and final conversion into validated assets after operator review.

## Current Result Page V2 State

Current Result Page V2 artifacts under `backend/content_assets/riasec/result_page_v2`:

| Area | Current coverage | Readiness meaning |
| --- | --- | --- |
| Agent runs | 2 runs: `share_safety_pilot_20260621T1555Z`, `selector_coverage_batch_20260622T0948Z` | Useful examples; not a full editorial workbench. |
| Selector-ready staging assets | 6 assets total | Narrow staging candidates only. |
| Selector registries present | `share_safety_registry`, `profile_signature_registry`, `method_boundary_registry` | Does not cover all section editorial needs. |
| Route matrix QA | 7 rows | QA sample, not full operational matrix. |
| Canonical profiles | 7 profiles | QA sample. |
| Golden cases | 7 cases | QA sample. |
| Rendered preview handoff | 8 fixture cases, 7 surfaces | Good QA skeleton, not full edited content. |
| Governance | staging/import/production gate docs exist | Must remain separated from editorial workbench generation. |

All current V2 assets remain bounded by:

- `runtime_use=staging_only` or non-runtime governance state;
- `production_use_allowed=false`;
- `ready_for_runtime=false`;
- `ready_for_production=false`;
- no CMS write;
- no frontend fallback copy.

## Benchmark Interpretation

123test and Truity are useful as structure benchmarks, not as copy sources.

What to learn:

- mature result pages explain the code or interest areas immediately;
- they expose six interest areas in a scannable way;
- they connect the result to exploration actions;
- they avoid making users read all deep content before understanding the first screen.

What not to copy:

- deterministic career matching language;
- "ideal career" or "best fit" claims;
- occupational ranking;
- success, salary, admissions, hiring, or ability claims;
- any public result payload that leaks raw scores, private attempt state, or selector traces.

## Section Gap Plan

| Section | Current asset basis | Current condition | Gap type | Next workbench file |
| --- | --- | --- | --- | --- |
| Summary card | Top-code confidence 5 states; profile shape 6; near-tie 4; low-quality 5; top3 20 | Has components but not one editable first-screen workbench | Thin / fragmented | `summary_card_content_workbench.zh-CN.md` |
| Dimension map | Profile shape 6; dimension labels from projection; dimension deep copy 6 | Bars exist, explanation thin for all-high/flat/near-tie | Thin / safety | `dimension_map_content_workbench.zh-CN.md` |
| Dimension deep | Dimension deep copy covers 6 dimensions | Strong content, likely over-dense in PDF if fully expanded | Over-thick / repetition | `dimension_deep_content_workbench.zh-CN.md` |
| Combination | Pair blend 15; top3 chain 20; near-tie 4 | Strong raw content, weak route/priority editorial organization | Needs editorial routing | `combination_content_workbench.zh-CN.md` |
| Activity validation | Activity examples 360; next nodes 270; top3 action fields | Large pool, needs dedupe and first-action hierarchy | Fragmented / repetitive | `activity_validation_content_workbench.zh-CN.md` |
| Career bridge | Occupation examples 360; career display bridge exists in frontend | High value but highest safety risk | Safety / examples-only | `career_bridge_content_workbench.zh-CN.md` |
| Quality/form boundary | Low-quality 5; 140Q rows 126; method boundary 8 | Has copy, but first-screen behavior and 60Q/140Q boundary need editorial consolidation | Thin / fail-closed | `quality_boundary_content_workbench.zh-CN.md` |
| Feedback/disagree | Disagree 45; feedback action lab 204 | Good pool, needs user-facing tone and no-score-rewrite framing | Thin / scattered | `feedback_disagree_content_workbench.zh-CN.md` |
| Share/PDF/history/compare | Share/PDF/history EN/ZH 7 surfaces; rendered preview assertions exist | Needs public/private split and density policy | Surface QA / safety | `share_pdf_history_content_workbench.zh-CN.md` |
| Method boundary / FAQ | Method boundary 8 sections each locale; FAQ 20 each locale; technical note 6 each locale | Strong, but can become repetitive if pasted everywhere | Placement / repetition | handled inside every workbench plus optional method appendix |

## Workbench File Rules

Each section workbench file should be Markdown first, not formal JSONL. It is a human-editable content asset staging surface.

Each workbench must include:

- section purpose;
- current source assets;
- current content excerpts or summarized source rows;
- benchmark structure target;
- missing slots;
- over-thick / repetitive slots;
- forbidden claims;
- public/private surface rules;
- GPT editing prompt;
- Codex conversion notes for later JSONL/selector asset conversion.

Each workbench must not include:

- production-ready flags;
- CMS import instructions;
- runtime wrapper enablement;
- raw user score vectors;
- private attempt ids;
- share selector traces;
- invented O*NET/SOC source references;
- copied competitor text.

## Recommended Workbench PR Train

These are workbench-generation PRs, not final asset-import PRs.

| Order | PR id | Scope | Output |
| ---: | --- | --- | --- |
| 1 | `RIASEC-RESULT-SUMMARY-CARD-EDITORIAL-WORKBENCH-01` | first-screen result card | `summary_card_content_workbench.zh-CN.md` |
| 2 | `RIASEC-RESULT-DIMENSION-MAP-EDITORIAL-WORKBENCH-01` | six-dimension map | `dimension_map_content_workbench.zh-CN.md` |
| 3 | `RIASEC-RESULT-DIMENSION-DEEP-EDITORIAL-WORKBENCH-01` | six dimension deep sections | `dimension_deep_content_workbench.zh-CN.md` |
| 4 | `RIASEC-RESULT-COMBINATION-EDITORIAL-WORKBENCH-01` | top2/top3/pair blend | `combination_content_workbench.zh-CN.md` |
| 5 | `RIASEC-RESULT-ACTIVITY-VALIDATION-EDITORIAL-WORKBENCH-01` | activities, small experiments, next steps | `activity_validation_content_workbench.zh-CN.md` |
| 6 | `RIASEC-RESULT-CAREER-BRIDGE-EDITORIAL-WORKBENCH-01` | occupation examples and career bridge | `career_bridge_content_workbench.zh-CN.md` |
| 7 | `RIASEC-RESULT-QUALITY-BOUNDARY-EDITORIAL-WORKBENCH-01` | low-quality, 60Q/140Q, norm unavailable | `quality_boundary_content_workbench.zh-CN.md` |
| 8 | `RIASEC-RESULT-FEEDBACK-DISAGREE-EDITORIAL-WORKBENCH-01` | disagree path and feedback | `feedback_disagree_content_workbench.zh-CN.md` |
| 9 | `RIASEC-RESULT-SHARE-PDF-HISTORY-EDITORIAL-WORKBENCH-01` | share/PDF/history/compare | `share_pdf_history_content_workbench.zh-CN.md` |

## Per-Section Acceptance Definition

A workbench PR is accepted when:

- it is editable by GPT without reading runtime code;
- it lists the source assets and current counts;
- it states what must be thickened, shortened, deduped, or safety-repaired;
- it includes a section-specific editing prompt;
- it explicitly blocks deterministic career recommendation language;
- it does not generate final selector assets;
- it does not alter runtime, CMS, production import, or production rollout state.

## Later Conversion Path

Only after a workbench section is edited and operator-approved should Codex convert it into formal artifacts:

```text
workbench markdown
  -> raw draft JSONL
  -> repaired draft JSONL
  -> final staging assets
  -> validation report
  -> safety report
  -> rendered preview assertions
  -> staging-only selector-ready candidate
```

The conversion path remains fail-closed:

- missing source -> omit section;
- missing public payload allowlist -> omit public/share block;
- safety issue -> block staging candidate;
- unresolved route conflict -> block selector-ready package;
- no human approval -> no production import.

## Immediate Next Step

Start with:

```text
RIASEC-RESULT-SUMMARY-CARD-EDITORIAL-WORKBENCH-01
```

Expected scope:

```text
backend/content_assets/riasec/result_page_v2/editorial_workbench/summary_card/v0_1/summary_card_content_workbench.zh-CN.md
```

This first workbench should consolidate:

- `top_code_confidence_copy_v1.zh-CN.json`;
- `profile_shape_copy_v1.zh-CN.json`;
- `near_tie_alternate_code_copy_v1.zh-CN.json`;
- `low_quality_cautious_reading_v1.zh-CN.json`;
- `top3_code_chain_strategy_v1.zh-CN.jsonl`;
- selected safety boundaries from method/technical notes.

The output should be a human-editable first-screen result-card content file for GPT/Codex revision, not a runtime asset.

## Go / No-Go

| Surface | Status | Reason |
| --- | --- | --- |
| Gap plan | GO | Current section map and asset inventory are aligned. |
| Workbench generation | GO | Next work can create one editable Markdown file per section. |
| Formal selector generation | HOLD | Requires edited and approved workbench content. |
| Runtime wrapper changes | NO-GO | Out of scope. |
| CMS import/write | NO-GO | Out of scope. |
| Production import | NO-GO | Out of scope. |
| Production rollout | NO-GO | Out of scope. |

## Acceptance Commands

```bash
git diff --check
```

Scope validation:

```bash
git diff --name-only
```
