# Big Five Scientific Repair PR Breakdown

| # | PR id | Block | Scope | Goal | Dependencies | Human review |
|---:|---|---|---|---|---|---|
| 0 | `BIG5-CONTENT-ASSET-SCIENTIFIC-AUDIT-MAPPING-01` | `all` | generated/big5_content_asset_scientific_audit/** only | Do not repair content; lock evidence and scoped sequencing. | none | False |
| 1 | `BIG5-SELECTOR-PUBLIC-PAYLOAD-HYGIENE-01` | `cross_block_selector` | backend/content_assets/big5/result_page_v2/agent_runs/**/selector_asset_candidates.jsonl and related tests | Move runtime/production/readiness flags out of public_payload; preserve provenance/internal metadata only. | BIG5-CONTENT-ASSET-SCIENTIFIC-AUDIT-MAPPING-01 | False |
| 2 | `BIG5-METHOD-BOUNDARY-SCIENTIFIC-REPAIR-01` | `method_boundary` | method_boundary agent/staging artifacts and method/privacy tests | Clarify scale source, 90/120 forms, norm boundaries, measurement error, non-diagnostic/non-hiring/non-predictive boundaries without repetitive boilerplate. | BIG5-SELECTOR-PUBLIC-PAYLOAD-HYGIENE-01 | True |
| 3 | `BIG5-DOMAIN-BANDS-SCIENTIFIC-REPAIR-01` | `domain_bands` | domain_bands agent/staging artifacts and tests | Make domain bands concrete but bounded; avoid fixed personality or occupational/life conclusions. | BIG5-METHOD-BOUNDARY-SCIENTIFIC-REPAIR-01 | True |
| 4 | `BIG5-FACETS-30-SCIENTIFIC-REPAIR-01` | `facets_30` | facets_30 agent/staging artifacts and tests | Localize facet wording; handle competence, extremes, and conflicting facets with psychometric caution. | BIG5-DOMAIN-BANDS-SCIENTIFIC-REPAIR-01 | True |
| 5 | `BIG5-COUPLING-VARIANTS-SCIENTIFIC-REPAIR-01` | `coupling_variants` | coupling_variants agent/staging artifacts and tests | Frame pairings as current structure clues and tradeoffs, not identities. | BIG5-FACETS-30-SCIENTIFIC-REPAIR-01 | True |
| 6 | `BIG5-SHARE-SAFETY-SCIENTIFIC-REPAIR-01` | `share_safety` | share_safety agent/staging artifacts and tests | Ensure all share-safe content is summary-safe and boundary-first. | BIG5-COUPLING-VARIANTS-SCIENTIFIC-REPAIR-01 | True |
| 7 | `BIG5-LOW-QUALITY-SCIENTIFIC-REPAIR-01` | `low_quality` | low_quality agent/staging artifacts and tests | Keep low-quality warnings scoped to low-quality states; soften/suppress strong claims when triggered. | BIG5-SHARE-SAFETY-SCIENTIFIC-REPAIR-01 | True |
| 8 | `BIG5-NORM-UNAVAILABLE-SCIENTIFIC-REPAIR-01` | `norm_unavailable` | norm_unavailable agent/staging artifacts and tests | Prevent external comparisons and precise ranking when norms are unavailable. | BIG5-LOW-QUALITY-SCIENTIFIC-REPAIR-01 | True |
| 9 | `BIG5-RENDERED-SURFACE-QA-CONTENT-REPAIR-01` | `rendered_surface_qa` | rendered_surface_qa backend artifacts and rendered QA tests | Fix content-authoritative surface guidance; record renderer-only issues separately. | BIG5-NORM-UNAVAILABLE-SCIENTIFIC-REPAIR-01 | True |
| 10 | `BIG5-CANONICAL-PROFILES-SCIENTIFIC-REPAIR-01` | `canonical_profiles` | canonical_profiles agent/staging artifacts and tests | Reframe profiles as reading entrances/current score structure clues. | BIG5-RENDERED-SURFACE-QA-CONTENT-REPAIR-01 | True |
| 11 | `BIG5-SCENARIO-ACTION-SCIENTIFIC-REPAIR-01` | `scenario_action` | scenario_action agent/staging artifacts and tests | Keep advice low-risk, optional, scenario-specific; no hiring, partner matching, success, diagnosis, or treatment claims. | BIG5-CANONICAL-PROFILES-SCIENTIFIC-REPAIR-01 | True |
| 12 | `BIG5-CONTENT-ASSET-SCIENTIFIC-REPAIR-FULL-QA-01` | `all` | qa artifacts/tests only | Prove all repaired blocks pass before any future staging/runtime discussion. | all repair PRs above | False |

| 13 | `BIG5-RESULT-PAGE-RENDERER-HYGIENE-FOLLOWUP-01` | `fap_web_renderer` | lib/big5/sectionBlueprint.ts; lib/big5/microcopy.ts; lib/big5/resultAssembler.ts; components/big5/**; tests/contracts/*big5* | Render backend-authoritative Big Five content cleanly without adding frontend editorial copy. | BIG5-CONTENT-ASSET-SCIENTIFIC-REPAIR-FULL-QA-01 | False |

## Required local checks

- `php artisan big5:result-page-v2-agent audit --strict --json --no-ansi`
- `php artisan test --filter=BigFiveResultPageV2AssetAgentTest --no-ansi`
- `php artisan test --filter=BigFiveResultPageV2SelectorAssetValidatorTest --no-ansi`
- `php artisan test --filter=BigFiveResultPageV2ContentAssetLookupTest --no-ansi`
- `JSON/JSONL parse check`
- `forbidden token scan`
- `scientific boundary scan`
- `repetition scan`
- `git diff --check`
