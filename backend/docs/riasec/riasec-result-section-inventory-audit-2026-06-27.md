# RIASEC Result Section Inventory Audit

Task: `RIASEC-RESULT-SECTION-INVENTORY-AUDIT-01`
Date: 2026-06-27
Repository: `fap-api`
Branch: `codex/riasec-result-section-inventory-audit-01`
Base: `origin/main` at `f4205961ca25c220e8624eab5be5f61117d67fa0`

## Scope

This is a docs-only, read-only audit of the Holland/RIASEC result page content-asset chain.

Allowed:

- inventory current result-page sections;
- inventory backend RIASEC content assets and Result Page V2 artifacts;
- map live/PDF/rendered-preview surfaces to backend assets;
- identify content gaps, repetition, over-thick sections, thin sections, and safety risks;
- propose section-first follow-up PRs for content thickening and repair.

Not allowed in this PR:

- runtime changes;
- CMS writes or imports;
- production import or rollout;
- environment changes;
- frontend fallback copy;
- marking `staging_only` assets as production-ready;
- generating new formal content assets.

## Evidence Inputs

### Live Result Page Observation

Chrome read-only inspection covered a current RIASEC result page:

- URL: `https://fermatmind.com/zh/result/6440ed3f-f07e-46fc-9232-fca3a5ceb541`
- Result code shown: `RIA`
- Form shown: `60题标准版 · 60题 · 约8分钟`
- Score bars shown: `R=100, I=100, A=100, S=100, E=100, C=100`
- Private result SEO boundary: `robots` meta was `noindex, nofollow, noarchive, nocache`
- Visible section headings included:
  - `你的测评结果`
  - `保存邮箱，随时找回这份结果`
  - `RIA`
  - `六维兴趣地图`
  - `深度内容`
  - `实作型`
  - `研究型`
  - `艺术型`
  - `社会型`
  - `企业型`
  - `常规型`
  - `你喜欢的是任务本身，还是这份工作的真实日常？`
  - `60Q 最小质量边界`
  - `把你原本想探索的方向放到旁边看`
  - `输入边界`
  - `不改写测评结果`
  - `你可以不认同这个结果`
  - `反馈不改分`
  - `下一步`
  - `职业活动探索`

Safety text observed on the page included `不测能力`, `不输出具体职业结论`, `不代表职业数据库匹配或推荐`, `不是人格标签`, and `不证明能力`.

Share button observation: clicking `分享结果` changed the button state to `重试分享`, with no visible share modal. This is recorded as a share-surface follow-up finding only; this audit does not assign root cause.

### PDF Observation

The local PDF at `/Users/rainie/Desktop/霍兰德.pdf` was reviewed as rendered evidence.

- The PDF has 6 pages.
- Page 1 contains the result card, six bars, and the start of `深度内容`.
- Pages 1-3 carry the six single-dimension deep sections.
- Page 4 contains pair/combination cards and the sections `你喜欢的是任务本身，还是这份工作的真实日常？`, `60Q 最小质量边界`, and `把你原本想探索的方向放到旁边看`.
- Page 6 closes with reflection-style content and footer material.

PDF evidence indicates the rendered result is already content-heavy. The next work should not simply add more prose everywhere; it should thicken thin sections, route and selectorize existing large assets, reduce repeated labels, and improve PDF/readability density.

### Backend Asset Inventory

Top-level RIASEC content assets under `backend/content_assets/riasec`:

| Asset family | File(s) | Count / shape | Audit note |
| --- | --- | ---: | --- |
| Dimension deep copy | `dimension_deep_copy_v1.zh-CN.r3.json` | 6 dimensions | Strong section content exists; likely over-thick in rendered/PDF view if all dimensions are expanded. |
| Top-code confidence | `top_code_confidence_copy_v1.zh-CN.json` | structured states | Supports result-card nuance but is not enough by itself for all-high/near-tie situations. |
| Profile shape | `profile_shape_copy_v1.zh-CN.json` | structured states | Useful for result summary and all-high/flat-profile states. |
| Top-3 strategy | `top3_code_chain_strategy_v1.zh-CN.jsonl` | 20 rows | Useful, but not enough for full route/addressable top-code combinations. |
| Pair blend | `pair_blend_15_pairs_v1.zh-CN.jsonl` | 15 rows | Strong pair layer exists; needs route/selector projection and conflict rules. |
| Activity examples | `activity_task_examples_v1.zh-CN.jsonl` | 360 rows | Large asset pool; must be projected into safe examples, not deterministic recommendations. |
| Occupation examples | `occupation_examples_boundary_v1.zh-CN.jsonl` | 360 rows | Large career bridge pool; requires examples-only and no-match/no-success-claim enforcement. |
| Next exploration nodes | `next_exploration_nodes_v1.zh-CN.jsonl` | 270 rows | Useful next-step pool; needs result-section routing and public/private surface split. |
| Feedback action lab | `feedback_action_lab_v1.zh-CN.jsonl` | 204 rows | Useful for feedback/disagree section; not a score rewrite mechanism. |
| 140Q task/environment/role | `140q_task_environment_role_v1.zh-CN.jsonl` | 126 rows | Strong candidate for 140Q-only deeper layers. |
| Aspirations calibration | `aspirations_calibration_v1.zh-CN.jsonl` | 70 rows | Directly maps to "put original direction aside" section. |
| Disagree path | `disagree_path_v1.zh-CN.jsonl` | 45 rows | Directly maps to "you may disagree" section. |
| Low quality cautious reading | `low_quality_cautious_reading_v1.zh-CN.json` | structured states | Needs fail-closed route binding. |
| Near tie alternate code | `near_tie_alternate_code_copy_v1.zh-CN.json` | structured states | Needs result-card and route-matrix binding. |
| Share/PDF/history | `share_pdf_history_v1.en.json`, `share_pdf_history_v1.zh-CN.json` | EN/ZH surfaces | Exists, but live share flow needs a follow-up behavior audit. |
| Method / FAQ / technical notes | EN/ZH JSON and MD files | structured | Safety boundary exists; should remain backend-authoritative. |

The raw asset count is high: the 9 JSONL files alone contain 1470 rows. The main gap is not "no content"; the gap is section-level operating structure: which page section consumes which asset, when it appears, how it fails closed, how it is made share-safe, and how it renders in PDF/history/compare without leaking private fields.

### Result Page V2 Chain Inventory

Current `backend/content_assets/riasec/result_page_v2` chain includes:

- source ledger: `source_ledger/v0_1`
- agent run protocol and artifacts:
  - `agent_runs/share_safety_pilot_20260621T1555Z`
  - `agent_runs/selector_coverage_batch_20260622T0948Z`
- selector-ready staging candidates:
  - `share_safety_pilot_20260621T1555Z`: 1 `share_safety_registry` asset
  - `selector_coverage_batch_20260622T0948Z`: 5 assets across `profile_signature_registry` and `method_boundary_registry`
- selector QA policy: `selector_qa_policy/v0_1`
- route matrix/golden QA: `route_matrix/staging_qa_v0_1`
  - 7 route rows
  - 7 canonical profiles
  - 7 golden cases
- rendered preview handoff: `rendered_preview/handoff_v0_1`
  - 8 fixture cases
  - 7 covered surfaces: result page, PDF, share, history, compare, locked/free redaction, low-quality, fallback
- staging/import/production governance artifacts:
  - staging import handoff
  - all-surface pilot QA
  - post-deploy staging/pilot evidence
  - manual production gate docs
  - production import gate dry-run and execution evidence
  - production rollout gate and rollout approval packet

Every audited Result Page V2 artifact remains bounded by no CMS write, no frontend fallback copy, and no production rollout from this audit.

### Frontend / Runtime Chain Read-Only Notes

The fap-web read-only scan confirms RIASEC is one flagship scale with two supported public forms:

- canonical landing: `/tests/holland-career-interest-test-riasec`
- forms: `riasec_60`, `riasec_140`
- share fallback path: `/tests/holland-career-interest-test-riasec/take`
- tracking events include result view, share view, PDF view, activity explorer view, and feedback overlay view.

The frontend result assembler recognizes these module keys:

- `hero_activity_chain`
- `six_dimension_map`
- `pair_blend`
- `activity_explorer`
- `occupation_examples`
- `140q_cta`
- `140q_context_cards`
- `share_card`
- `pdf`
- `history`
- `feedback_overlay`

It also recognizes deep slot groups:

- `dimension_deep_copy`
- `pair_blend_copy`
- `140q_layer_copy`
- `quality_copy`
- `structural_difference_copy`
- `aspirations_copy`
- `feedback_response_copy`

This means the correct content-asset thickening model is section-first: each section should have asset inventory, selector projection, safety QA, and rendered/PDF QA.

## Section Inventory

| Result page section | Existing content | Gaps | Repetition / density | Safety risks | Recommended next work |
| --- | --- | --- | --- | --- | --- |
| Result retrieval / email save | Live page has save-email card. Backend result/report-access routes exist. | Not a content-thickening priority. Need ensure no private identifiers leak in any email/retrieval copy. | Thin by design. | Private path or attempt id leakage if copy references retrieval internals. | Keep outside content-thickening train unless copy changes. |
| Hero / result summary card | Live page shows `RIA`; backend has top-code confidence/profile-shape assets. | Needs better handling for all-high, flat, near-tie, and low-quality profiles; current live all-100 case makes `RIA` feel too deterministic. | Too thin relative to deep content below. | Fixed type-label risk if summary says "you are RIA" without enough boundary. | `RIASEC-RESULT-SUMMARY-CARD-ASSET-THICKENING-01`. |
| Six-dimension interest map | Live page shows six bars; current example has all six at 100. | Needs structural explanation for "relative shape vs absolute score", all-high/flat/near-tie states, and form limitations. | Thin explanatory layer; bars alone are not enough. | Ability/quality/fit claims if high scores are treated as proof. | `RIASEC-RESULT-DIMENSION-MAP-ASSET-THICKENING-01`. |
| Single-dimension deep content | Six dimension sections are visible and PDF-heavy. Backend deep copy covers all 6. | Needs selector slot clarity by form/quality/profile state, not more generic prose. | Likely over-thick when all dimensions are expanded; repeated labels like core drive, action advice, common misread appear across all dimensions. | Over-reading each dimension as identity or capability. | `RIASEC-RESULT-SINGLE-DIMENSION-DEEP-ASSET-DENSITY-REPAIR-01`. |
| Pair / top-2 / top-3 combination | Live/PDF show pair cards. Backend has 15 pair blends and 20 top3 strategy rows. | Needs route-addressable selector refs, priority rules, conflict resolution, and coverage beyond small route QA set. | Medium-heavy. Some label repetition is useful, but PDF layout can feel dense. | Deterministic "career type" risk if pair/top3 copy overstates stability. | `RIASEC-RESULT-COMBINATION-ASSET-THICKENING-01`. |
| Task validation / activity validation | Live/PDF include "activities to validate" and "what the work day is like" framing. Backend has 360 activity examples and 270 exploration nodes. | Needs section-level selection rules and dedupe across activity examples, next nodes, and career bridge. | Potentially over-thick if activity examples and next steps repeat similar ideas. | Recommendation and success-prediction risk if activities are framed as matched outcomes. | `RIASEC-RESULT-ACTION-VALIDATION-ASSET-THICKENING-01`. |
| Career bridge / occupation examples | Backend has 360 occupation example rows and career display bridge references in fap-web. | Needs examples-only projection: no ranking, no hiring/admissions/salary/performance claims, no raw score input. | Not fully visible in current live/PDF evidence; likely under-integrated. | Highest safety risk: deterministic recommendation, fit claim, occupational ranking, salary/success inference. | `RIASEC-RESULT-CAREER-BRIDGE-ASSET-THICKENING-01`. |
| 60Q / 140Q form boundary | Live page shows `60题标准版`; backend has 140Q task/environment/role rows. | Needs clear section behavior when 140Q layer is unavailable, and when 60Q should not upsell 140Q as "repair". | Thin in live result. | Implying 140Q is more valid for everyone, or that 60Q result is defective. | Include in quality/form-boundary PR. |
| Low-quality / cautious reading / norm unavailable | Live page has `60Q 最小质量边界`; backend has low-quality and method-boundary assets. | Needs route binding, fail-closed selector policy, and rendered preview cases. | Thin but intentionally cautious. | Blaming user, overstating invalidity, or leaking QA flags. | `RIASEC-RESULT-QUALITY-BOUNDARY-ASSET-THICKENING-01`. |
| Aspirations calibration | Live page shows `把你原本想探索的方向放到旁边看`; backend has 70 aspiration rows. | Needs targeted routing to user-intent clusters and dedupe with career bridge. | Medium; can be valuable if concise. | Could look like advice to abandon a goal. | Fold into action/career bridge or dedicated aspiration PR. |
| Disagree / feedback | Live page shows `你可以不认同这个结果`, `反馈不改分`; backend has 45 disagree rows and 204 feedback-action rows. | Needs feedback overlay contracts and no-score-rewrite enforcement. | Good safety framing; could be made more actionable. | User feedback must not mutate score, type, or canonical result. | `RIASEC-RESULT-FEEDBACK-DISAGREE-ASSET-THICKENING-01`. |
| Next step / exploration nodes | Live page includes `下一步` and `职业活动探索`; backend has 270 next exploration rows. | Needs clear split between owner-only next steps and public/share-safe summary. | Potential overlap with activity and career bridge sections. | Recommendation/success claim risk if next steps imply guaranteed fit. | Pair with action validation and career bridge PRs. |
| Share / PDF / history / compare | Backend has EN/ZH share/PDF/history assets, rendered-preview assertions, and API routes for report PDF/share. | Live share click showed retry state without visible modal; root cause unknown. Compare/history content routes need section-level payload rules. | PDF is already heavy; share should be intentionally thin. | Public share leak risk: raw score, vector, attempt id, private URL, selector trace. | `RIASEC-RESULT-SHARE-PDF-HISTORY-ASSET-THICKENING-01` plus separate share behavior audit if needed. |
| Method boundary / FAQ / technical notes | Backend has EN/ZH method, FAQ, technical summary assets. | Needs consistent placement and public/private split across result, PDF, share, and history. | Can become repetitive if every section repeats the same boundary. | Overclaiming official type, diagnostic, hiring, ability, or norm claims. | Include in every section QA; avoid a standalone prose dump. |
| Production/runtime/CMS/search gates | Multiple governance artifacts exist. | Not part of section thickening. | N/A. | Accidentally treating staging-only assets as production-ready. | Keep HOLD unless separately authorized. |

## Whole-Chain Map

```text
source ledger
  -> v1 content assets
  -> agent run artifacts
  -> selector-ready staging candidates
  -> selector QA policy
  -> route matrix / canonical profiles / golden cases
  -> rendered preview handoff assertions
  -> staging/import/governance evidence
  -> backend report/report-access/PDF/share APIs
  -> fap-web result assembler and surfaces
  -> live result page / PDF / share / history / compare
```

The weak link is not only content volume. The weak link is section ownership:

- Which asset family owns the section?
- Which selector or route state triggers it?
- What is omitted when selector/state is missing?
- What text is allowed in public share?
- What text is allowed in PDF/history/compare?
- What claims are forbidden?
- How is repetition managed across adjacent sections?

## Findings

### F1: The result page is already content-heavy, but not yet section-complete.

The live page and PDF show substantial deep content. Adding more text globally would likely reduce readability. The correct next step is section-by-section optimization and projection, not broad prose expansion.

### F2: The backend has a large raw content pool, but V2 selector-ready coverage is still narrow.

The top-level JSONL assets contain 1470 rows, but current selector-ready staging candidates include only:

- 1 `share_safety_registry` asset from the share-safety pilot;
- 5 selector coverage assets across `profile_signature_registry` and `method_boundary_registry`.

The route QA package currently records 7 rows/profiles/cases, not a complete operational route matrix.

### F3: The hero/result-card section is too thin for edge cases.

The observed all-100 score vector still collapses to a `RIA` code on the result card. That may be acceptable as a compact display, but the page needs a safer summary layer that says the code is a reading lens, not a fixed identity or occupational verdict.

### F4: The six-dimension map needs shape interpretation, not just bars.

When all scores are high or close, the map should explain profile shape, relative contrast, and form boundaries. It must not imply higher scores equal ability, career success, or stronger employment fit.

### F5: Single-dimension deep content is the section most at risk of over-thickening.

Each dimension has many repeated fields. The improvement should be hierarchy, collapse, dedupe, and PDF density repair. This section should not be the first target for adding more words.

### F6: Career bridge is the highest-safety section.

Occupation examples and career exploration nodes are valuable, but must stay examples-only. They must not become deterministic recommendations, hiring/admissions guidance, salary/performance claims, or raw-score-driven matching.

### F7: Share/PDF/history should be thinner and stricter than owner-only result.

The public share surface needs the strongest allowlist. It should not expose raw scores, vectors, attempt ids, private URLs, selector traces, internal QA state, or production gate metadata.

### F8: Current production/runtime/CMS/search posture must remain separate.

This audit does not change runtime, import CMS content, submit search, or approve rollout. Content-asset thickening should continue as staging/read-only PRs until a later explicit authorization opens import or rollout.

## Recommended Section-First PR Train

These PRs should run after this audit only with explicit manifest/state authorization if they are executed as a train.

| Proposed PR id | Title | Likely scope | Local checks | Depends on |
| --- | --- | --- | --- | --- |
| `RIASEC-RESULT-SUMMARY-CARD-ASSET-THICKENING-01` | Thicken result-card and top-code summary states | `backend/content_assets/riasec/result_page_v2/agent_runs/*`, selector-ready candidate docs/artifacts | `jq empty`, selector asset validator, leak scan, `git diff --check` | this audit |
| `RIASEC-RESULT-DIMENSION-MAP-ASSET-THICKENING-01` | Add six-dimension map shape interpretation assets | map/shape assets and QA report | `jq empty`, selector asset validator, leak scan, `git diff --check` | summary-card PR |
| `RIASEC-RESULT-SINGLE-DIMENSION-DEEP-ASSET-DENSITY-REPAIR-01` | Repair deep-section density, dedupe, and PDF hierarchy | dimension deep copy projection/QA docs or staging artifacts | `jq empty`, rendered preview/PDF assertions, leak scan, `git diff --check` | dimension-map PR |
| `RIASEC-RESULT-COMBINATION-ASSET-THICKENING-01` | Route pair/top3 combination assets | pair/top3 selector projection and conflict QA | `jq empty`, selector conflict QA, route ref checks, `git diff --check` | density-repair PR |
| `RIASEC-RESULT-ACTION-VALIDATION-ASSET-THICKENING-01` | Route activity validation and next exploration assets | activity/next-step staging assets and QA | `jq empty`, leak scan, public/private split checks, `git diff --check` | combination PR |
| `RIASEC-RESULT-CAREER-BRIDGE-ASSET-THICKENING-01` | Route examples-only career bridge assets | occupation examples projection and safety QA | `jq empty`, forbidden-claim scan, no recommendation/ranking checks, `git diff --check` | action-validation PR |
| `RIASEC-RESULT-QUALITY-BOUNDARY-ASSET-THICKENING-01` | Bind low-quality, norm-unavailable, 60Q/140Q boundaries | quality/form-boundary assets and route QA | `jq empty`, fail-closed checks, leak scan, `git diff --check` | career-bridge PR |
| `RIASEC-RESULT-FEEDBACK-DISAGREE-ASSET-THICKENING-01` | Thicken feedback/disagree without score rewrite | feedback/disagree staging assets and QA | `jq empty`, feedback no-mutation assertions, leak scan, `git diff --check` | quality-boundary PR |
| `RIASEC-RESULT-SHARE-PDF-HISTORY-ASSET-THICKENING-01` | Split owner/PDF/share/history/compare content rules | share/PDF/history/compare assets and rendered assertions | `jq empty`, public allowlist checks, rendered preview assertions, `git diff --check` | feedback-disagree PR |
| `RIASEC-RESULT-SECTION-RENDER-PREVIEW-QA-01` | Validate section-level rendered output after thickening | rendered preview report and expected assertions | rendered preview contract tests, `jq empty`, `git diff --check` | share/PDF/history PR |

Follow-up execution prompt:

```text
在 fap-api 干净 main 上，授权更新 docs/codex/pr-train.yaml 和 docs/codex/pr-train-state.json，按 RIASEC-RESULT-SECTION-INVENTORY-AUDIT-01 审计建议新增 section-first RIASEC result content thickening PR train。先执行 RIASEC-RESULT-SUMMARY-CARD-ASSET-THICKENING-01。只做 staging/content-asset artifacts，不改 runtime，不写 CMS，不导入 production，不打开 production gate。
```

## Agent Operating Model

The Holland/RIASEC result asset agent should be reorganized around result-page sections.

Recommended sub-agents:

| Sub-agent | Responsibility | Output |
| --- | --- | --- |
| Section Inventory Agent | Maintains section map, ownership, duplicate/over-thin/over-thick register. | section inventory report and gap register |
| Section Asset Factory Agent | Generates only the declared section's staging assets. | raw draft, repaired draft, final staging candidate |
| Selector Projection Agent | Converts section assets into route/selector-ready records. | selector refs, slot refs, route coverage report |
| Claim / Safety QA Agent | Blocks deterministic type, diagnosis, ability, hiring, salary, performance, success, raw-score, percentile, and share leaks. | safety report |
| Render / PDF Density QA Agent | Checks result page, PDF, share, history, compare, locked/free, low-quality, fallback. | rendered preview fixture/assertion report |
| Career Bridge Boundary QA Agent | Allows public projection inputs and examples-only career bridge; blocks deterministic recommendation. | career bridge policy report |

## Go / No-Go

| Surface | Status | Reason |
| --- | --- | --- |
| Section inventory | GO | Current sections and asset families are mapped. |
| Content thickening planning | GO | Next work should be section-first. |
| Staging asset generation | HOLD | Requires explicit follow-up PR scope. |
| Runtime wrapper changes | NO-GO | Out of scope. |
| CMS import/write | NO-GO | Out of scope. |
| Production import | NO-GO | Out of scope. |
| Production rollout | NO-GO | Out of scope. |
| Search / SEO runtime changes | NO-GO | Out of scope. |

## Acceptance Commands

```bash
git diff --check
```

Scope validation for this audit:

```bash
git diff --name-only
```

Expected changed file:

```text
backend/docs/riasec/riasec-result-section-inventory-audit-2026-06-27.md
```
