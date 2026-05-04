# Big Five V2 Source Authority Map v0.1

Status: governance-only
Scope: Big Five Result Page V2 source authority, mapping boundary, and import sequencing
Runtime impact: none

## Purpose

This file fixes the current identity of each Big Five V2 source layer before any B5-B1 core body import work starts.

It does not import prose, wire runtime, mutate selector assets, or change frontend rendering.

## Source Authority

| Source | Role | Authority decision |
| --- | --- | --- |
| `FermatMind_BigFive_新版结果页_正式上线V2.0.docx` | module master | Primary upstream source for module 00-10 intent, module naming, and module-level responsibilities. Not a direct runtime file. |
| `FermatMind_BigFive_正式上线结果页全文_两万字最终稿.docx` | narrative / canonical body master | Primary upstream source for longform narrative density, sequencing, and the O59 / C32 / E20 / A55 / N68 canonical body. Cannot be generalized to all users. |
| Current 8 section skeleton | runtime skeleton | Current live compatibility surface for Big Five report rendering: `hero_summary`, `domains_overview`, `domain_deep_dive`, `facet_details`, `core_portrait`, `norms_comparison`, `action_plan`, `methodology_and_access`. |
| `backend/content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json` | selector candidates / staging | 325 selector-ready staging assets. Verified staging-only batch. Not runtime-ready. |
| Golden Cases + Selection Policy + Conflict Resolution package | selector QA / policy pack | External QA and selection governance package. Not user-facing body assets. Not B5-B1 core body import material. |
| Current compact online Big Five page | anti-target | Existing rendered output that still exposes compact English subtitles, facet percentile-led reading, short action fallback, and placeholder leakage patterns. |
| Frontend fallback copy | must_not_be_long_term_content_owner | Transitional compatibility layer only. Must not remain the long-term owner of Big Five interpretation content. |

## Fixed Decisions

1. The V2.0 formal doc is the module master for Big Five V2.
2. The two万字 final doc is the narrative / canonical body master.
3. The current 8 section surface remains the runtime skeleton until a later runtime migration explicitly changes it.
4. The 325 selector-ready assets are staging selector candidates only.
5. The QA / Policy package is a selector QA / policy pack only and is not a prose asset pack.
6. The current compact online page is an anti-target, not a fallback baseline to preserve.
7. Frontend fallback copy must not become the long-term owner of Big Five interpretation or section narrative.

## Non-Authority Clarifications

- The V2.0 formal doc is not a direct runtime import file.
- The two万字 final doc is not a universal runtime narrative for every Big Five user.
- The selector-ready asset batch is not production-ready because it remains `runtime_ready = false` and `runtime_use = staging_only`.
- The QA / Policy package must not be reclassified as canonical body content.
- The current compact online page must not be used as a writing, section, or render-quality target.

## B5-B1 Boundary

The next canonical body import target is:

`backend/content_assets/big5/result_page_v2/core_body/v0_1/`

This governance PR does not create `core_body/` and does not import any body assets into it.

## Relationship To Existing Layers

- `backend/content_packs/BIG5_OCEAN/v1/**`: current live legacy or compatibility content pack surface.
- `backend/content_packs/BIG5_OCEAN/v2/registry/**`: engine-ready or gated registry inputs, not the V2.0 module master.
- `backend/content_assets/big5/result_page_v2/**`: planning and staging area for V2 result page governance and selector-ready assets.
- External QA / Policy bundle: staging-only selector QA package that should stay separate from body import work.

## Deferred Work

- B5-B1 O59 canonical core body asset import into `core_body/v0_1/`
- B5-B1 rendered preview harness
- P0 selector QA fix
- B5-H rendered preview QA pack
- B5-B2 canonical profile expansion
