# Big Five Content Asset Scientific Audit Scan

Run: `20260702T160451Z`

## Total Judgment

Recommended fap-api PRs: **13**. This includes one audit/mapping PR, one cross-block selector public-payload hygiene PR, 10 block-specific scientific/editorial repair PRs, and one final full-QA PR. A separate fap-web renderer follow-up is recommended if current live/result consumer still exposes English/internal labels.

This scan did not modify runtime assets, PR train manifest/state, CMS, SEO, rollout, production import, or final `big5_result_page_v2` payload.

## P0 Must Fix First

1. `selector_asset.public_payload` includes runtime/readiness flags across candidate selectors. Move these to provenance/replacement/internal metadata.
2. Rendered zh consumer/reference still has user-visible English `facet` strings in fap-web reference code; live page evidence also previously showed internal fingerprints. Treat as fap-web renderer follow-up, not backend content repair.

## Sources Used

- IPIP official site: https://ipip.ori.org/
- BFI-2 Colby page: https://www.colby.edu/academics/departments-and-programs/psychology/research-opportunities/personality-lab/the-bfi-2/
- BFI-2 paper: https://pubmed.ncbi.nlm.nih.gov/27055049/
- Costa & McCrae domain/facet paper: https://jenni.uchicago.edu/econ-psych-traits/CostaMcCrae1995.pdf
- IPIP psychometric properties: https://pmc.ncbi.nlm.nih.gov/articles/PMC4768534/
- Big Five stability/change review: https://pmc.ncbi.nlm.nih.gov/articles/PMC8821110/

## Block Inventory

| Block | Content | Selector | Main sections | Key issue counts |
|---|---:|---:|---|---|
| `method_boundary` | 14 | 14 | trust_bar, method_privacy, share_save | {'clinical_or_high_stakes_claim': 1, 'overprescriptive_action': 4, 'percentile_rank_precision': 1, 'quality_warning_spread': 1} |
| `domain_bands` | 25 | 25 | domain_deep_dive | {'ai_tone_marker': 3, 'overprescriptive_action': 4, 'quality_warning_spread': 1, 'selector_public_payload_runtime_flags': 25} |
| `facets_30` | 30 | 30 | facet_details | {'ai_tone_marker': 1, 'overprescriptive_action': 2, 'percentile_rank_precision': 1, 'selector_public_payload_runtime_flags': 30} |
| `coupling_variants` | 50 | 50 | core_portrait | {'overprescriptive_action': 2, 'fixed_type_framing': 2, 'quality_warning_spread': 1, 'ai_tone_marker': 6, 'selector_public_payload_runtime_flags': 50} |
| `share_safety` | 36 | 36 | share_save | {'ai_tone_marker': 1, 'selector_public_payload_runtime_flags': 36} |
| `low_quality` | 14 | 14 | low_quality_state | {'quality_warning_spread': 16, 'selector_public_payload_runtime_flags': 14} |
| `norm_unavailable` | 18 | 18 | method_boundary | {'overprescriptive_action': 1, 'selector_public_payload_runtime_flags': 18} |
| `rendered_surface_qa` | 24 | 24 | rendered_surface_qa, share_save | {'quality_warning_spread': 3, 'overprescriptive_action': 4, 'fixed_type_framing': 1, 'selector_public_payload_runtime_flags': 24} |
| `canonical_profiles` | 64 | 64 | hero_summary, domains_overview, domain_deep_dive, facet_details, core_portrait, norms_comparison, action_plan, methodology_and_access | {'quality_warning_spread': 8, 'ai_tone_marker': 1, 'overstrong_profile_claim': 1, 'overprescriptive_action': 1, 'selector_public_payload_runtime_flags': 64} |
| `scenario_action` | 160 | 160 | action_plan | {'overprescriptive_action': 27, 'selector_public_payload_runtime_flags': 160} |

## Artifacts

- `01_block_inventory.json`
- `02_issue_matrix.json` / `02_issue_matrix.csv`
- `03_pr_breakdown.md` / `03_pr_breakdown.json`
- `04_manifest_state_candidate_entries.md`
- `05_sidecar_followups.md`
- `06_next_execution_prompt.md`

## Current Base Verification

Current PR1 base: `1f78f9b2ed53d22c800c5560f12d381d17754bf3`. Scan baseline: `6e7bb31494f68765f7288a814efedd5d54ad802a`. `git diff --name-only 6e7bb31494f68765f7288a814efedd5d54ad802a..HEAD` showed no Big Five result-page/content-asset/content-pack path changes, so the scan evidence remains applicable to this branch.
