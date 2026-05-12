# IQ Item Risks

## Summary

The current 30-item IQ bank is still a `legacy_demo` candidate, not a production-scored item bank.

## Risk Matrix

| Severity | Impacted items | Risk | Evidence | Recommended action |
|---|---|---|---|---|
| high | all 30 | answer key missing | `IqTestDriver` returns `ANSWER_KEY_MISSING`; `scoring_mode=pending_answer_key` | do not score or publish as production until per-item `correct_answer` is bound |
| high | all 30 | no `solution_rule` metadata | items only contain `stem`, `options`, `meta` | add formal solution-rule contract before production reuse |
| high | all 30 | no `distractor_logic` metadata | no distractor rationale is stored in current item payload | add distractor logic before production reuse |
| high | all 30 | no per-item asset hash | inline SVG is embedded in `questions.json`; per-item `asset_hash` is `not found` | add `asset_hashes` for stem and options |
| high | all 30 | provenance depends on external prototype zip | `backend/scripts/iq/build_iq30_questions_from_prototype.php` uses `/Users/rainie/Desktop/iq_ui_prototype_30_svg_grid.zip` | freeze current bank as `legacy_demo`; add formal provenance chain for new bank |
| high | ODD_Q01-10 | unstable dimension binding | current source only says `section_code=odd`; no formal dimension metadata | manual content review before any `VSI` promotion |
| medium | all 30 | difficulty levels are heuristic only | no source-level difficulty metadata exists | recalibrate under formal item bank import |
| medium | all 30 | frontend render component missing in current repo | `fap-web/app` has no IQ page source | locate actual frontend repo before end-to-end rollout |
| medium | production foundation | `NPR` not found | current 30-item bank has only `matrix`, `odd`, `series` sections | build a separate formal NPR bank instead of forcing this demo set |

## Explicit High-Risk Calls

1. `MATRIX_Q01-09`: **high risk** because `correct_answer` is missing.
2. `ODD_Q01-10`: **high risk** because `correct_answer` is missing and dimension binding is unresolved.
3. `SERIES_Q01-11`: **high risk** because `correct_answer` is missing.

## Manual Review Block

These items cannot be stably promoted from current source metadata alone:

- `ODD_Q01`
- `ODD_Q02`
- `ODD_Q03`
- `ODD_Q04`
- `ODD_Q05`
- `ODD_Q06`
- `ODD_Q07`
- `ODD_Q08`
- `ODD_Q09`
- `ODD_Q10`

## Productization Note

`NPR` is `not found` in the current 30-item bank. A formal IQ production bank should not treat the current pack as complete coverage for the intended three-dimension product.
