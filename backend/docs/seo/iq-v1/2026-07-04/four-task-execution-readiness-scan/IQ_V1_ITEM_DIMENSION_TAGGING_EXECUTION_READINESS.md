# IQ V1 Item Dimension Tagging Execution Readiness

## Classification

`READY_FOR_ITEM_TAGGING_PR`

## Current State

- Public `IQ_OWNER_ORIGINAL_30/items.json` has 30 items but no public safe dimension aliases.
- Current top-level public item fields include sequence, title, stem/options media, provenance, answer-key status, and asset hashes.
- The runtime scoring service obtains private dimensions from backend-only answer-key authority and exposes scored dimension summaries through backend report payloads.
- `scoring_spec.json` records private scoring dimension counts: VSPR 14, VSI 13, NPR 3.

## Safe Tag Plan

Allowed tags only:

- `matrix_reasoning`
- `visual_reasoning`
- `pattern_recognition`
- `spatial_reasoning`

Unsupported/forbidden tags for V1:

- `working_memory`
- `processing_speed`
- `quantitative_reasoning`
- `verbal_comprehension`

Safe per-item aliases:

| Item | Safe public aliases | Confidence |
| --- | --- | --- |
| Q01 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q02 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q03 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q04 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q05 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q06 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q07 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q08 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q09 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q10 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q11 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q12 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q13 | `pattern_recognition` | medium |
| Q14 | `pattern_recognition` | medium |
| Q15 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q16 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q17 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q18 | `pattern_recognition` | medium |
| Q19 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q20 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q21 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q22 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q23 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q24 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q25 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q26 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q27 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q28 | `spatial_reasoning`, `visual_reasoning`, `pattern_recognition` | high |
| Q29 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |
| Q30 | `matrix_reasoning`, `pattern_recognition`, `visual_reasoning` | high |

## Implementation Constraints

- Metadata-only.
- Do not change answers, correct answers, scoring formula, item order, assets, hashes, SVG/images, or option payloads.
- Do not output private answer keys, solution rules, distractor logic, asset hashes, generator metadata, raw attempt IDs, URLs, tokens, cookies, credentials, or private payloads.
- Public payload redaction must continue to strip private scoring fields.

## Tests Required

- `cd backend && php artisan test --filter=IqOwnerOriginal30PrivateScoringTest`
- Add/extend a focused content test that asserts safe aliases exist for all 30 public items and forbidden V1 tags do not appear.
- Add/extend a public question payload test to assert safe aliases may be emitted but private answer/scoring fields do not leak.
- `git diff --check`

## Exact Implementation Prompt

Run `IQ-V1-ITEM-DIMENSION-TAGGING-IMPLEMENTATION-01` as a metadata-only backend PR in `/Users/rainie/Desktop/GitHub/fap-api`. Start from latest main. Add safe public dimension aliases for `IQ_OWNER_ORIGINAL_30` using only `matrix_reasoning`, `visual_reasoning`, `pattern_recognition`, and `spatial_reasoning`. Do not change answer keys, correct answers, scoring formulas, item order, image/SVG assets, asset hashes, or option content. Add focused tests proving all 30 items have safe aliases, forbidden V1 tags are absent, public question payloads do not leak answer/scoring private fields, and runtime scoring behavior remains unchanged. Run focused tests and `git diff --check`; then commit, push, and open one scoped PR.
