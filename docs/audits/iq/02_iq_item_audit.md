# IQ Item Audit

## Summary

| Item | Result |
|---|---|
| Current runtime bank path | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/questions.json` |
| V2 mirror path | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/questions.json` |
| Item count | `30` |
| Answer key | `not found` |
| Render component | `not found` |
| Stable `VSPR` items | `20` |
| Stable `VSI` items | `not found` |
| Stable `NPR` items | `not found` |
| ODD_Q01-10 | `needs_manual_review` |
| Global risk state | all 30 items are `high risk` |

## Shared Field Notes

| Field | Value |
|---|---|
| `source_path` | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/questions.json` |
| `route` | `/zh/tests/iq-test-intelligence-quotient-assessment/take` |
| `stem_asset` pattern | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/questions.json#items[n].stem.svg` |
| `option_assets` pattern | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/questions.json#items[n].options[*].svg` |
| `render_component` | `not found` |
| `generated_or_static` | `static_svg` |
| `has_solution_rule` | `false` |
| `has_distractor_logic` | `false` |
| `has_asset_hash` | `false` |
| `has_generator_params` | `false` |
| `correct_answer` | `missing` |

## Dimension Classification

| Dimension | Count | Status |
|---|---:|---|
| `VSPR` | 20 | stable audit classification for `MATRIX_*` and `SERIES_*` |
| `VSI` | not found | no stable source metadata confirms `ODD_*` as `VSI` |
| `NPR` | not found | no numerical-pattern section exists in current 30-item bank |
| `unknown` | 10 | `ODD_Q01-10` remain unresolved and require manual review |

## Full Item Table

Full per-item field payloads are stored in `docs/audits/iq/02_iq_item_catalog.jsonl`.

| current_id | guessed_item_id | order | section | option_count | correct_answer | dimension_guess | item_family_guess | difficulty_guess | generated_or_static | beta_recommendation | risk |
|---|---|---:|---|---:|---|---|---|---|---|---|---|
| MATRIX_Q01 | FM-IQ-VSPR-MX-L2-0001 | 1 | matrix | 5 | missing | VSPR | matrix_reasoning | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q02 | FM-IQ-VSPR-MX-L2-0002 | 2 | matrix | 5 | missing | VSPR | matrix_reasoning | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q03 | FM-IQ-VSPR-MX-L2-0003 | 3 | matrix | 5 | missing | VSPR | matrix_reasoning | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q04 | FM-IQ-VSPR-MX-L3-0004 | 4 | matrix | 5 | missing | VSPR | matrix_reasoning | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q05 | FM-IQ-VSPR-MX-L3-0005 | 5 | matrix | 5 | missing | VSPR | matrix_reasoning | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q06 | FM-IQ-VSPR-MX-L3-0006 | 6 | matrix | 5 | missing | VSPR | matrix_reasoning | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q07 | FM-IQ-VSPR-MX-L4-0007 | 7 | matrix | 5 | missing | VSPR | matrix_reasoning | L4 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q08 | FM-IQ-VSPR-MX-L4-0008 | 8 | matrix | 5 | missing | VSPR | matrix_reasoning | L4 | static_svg | keep_as_legacy_beta | **high risk** |
| MATRIX_Q09 | FM-IQ-VSPR-MX-L4-0009 | 9 | matrix | 5 | missing | VSPR | matrix_reasoning | L4 | static_svg | keep_as_legacy_beta | **high risk** |
| ODD_Q01 | FM-IQ-VSI-ODD-L2-0010 | 10 | odd | 5 | missing | unknown | odd_one_out | L2 | static_svg | needs_manual_review | **high risk** |
| ODD_Q02 | FM-IQ-VSI-ODD-L2-0011 | 11 | odd | 5 | missing | unknown | odd_one_out | L2 | static_svg | needs_manual_review | **high risk** |
| ODD_Q03 | FM-IQ-VSI-ODD-L2-0012 | 12 | odd | 5 | missing | unknown | odd_one_out | L2 | static_svg | needs_manual_review | **high risk** |
| ODD_Q04 | FM-IQ-VSI-ODD-L2-0013 | 13 | odd | 5 | missing | unknown | odd_one_out | L2 | static_svg | needs_manual_review | **high risk** |
| ODD_Q05 | FM-IQ-VSI-ODD-L3-0014 | 14 | odd | 5 | missing | unknown | odd_one_out | L3 | static_svg | needs_manual_review | **high risk** |
| ODD_Q06 | FM-IQ-VSI-ODD-L3-0015 | 15 | odd | 5 | missing | unknown | odd_one_out | L3 | static_svg | needs_manual_review | **high risk** |
| ODD_Q07 | FM-IQ-VSI-ODD-L3-0016 | 16 | odd | 5 | missing | unknown | odd_one_out | L3 | static_svg | needs_manual_review | **high risk** |
| ODD_Q08 | FM-IQ-VSI-ODD-L4-0017 | 17 | odd | 5 | missing | unknown | odd_one_out | L4 | static_svg | needs_manual_review | **high risk** |
| ODD_Q09 | FM-IQ-VSI-ODD-L4-0018 | 18 | odd | 5 | missing | unknown | odd_one_out | L4 | static_svg | needs_manual_review | **high risk** |
| ODD_Q10 | FM-IQ-VSI-ODD-L4-0019 | 19 | odd | 5 | missing | unknown | odd_one_out | L4 | static_svg | needs_manual_review | **high risk** |
| SERIES_Q01 | FM-IQ-VSPR-SER-L2-0020 | 20 | series | 6 | missing | VSPR | shape_sequence | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q02 | FM-IQ-VSPR-SER-L2-0021 | 21 | series | 6 | missing | VSPR | shape_sequence | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q03 | FM-IQ-VSPR-SER-L2-0022 | 22 | series | 6 | missing | VSPR | shape_sequence | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q04 | FM-IQ-VSPR-SER-L2-0023 | 23 | series | 6 | missing | VSPR | shape_sequence | L2 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q05 | FM-IQ-VSPR-SER-L3-0024 | 24 | series | 6 | missing | VSPR | shape_sequence | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q06 | FM-IQ-VSPR-SER-L3-0025 | 25 | series | 6 | missing | VSPR | shape_sequence | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q07 | FM-IQ-VSPR-SER-L3-0026 | 26 | series | 6 | missing | VSPR | shape_sequence | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q08 | FM-IQ-VSPR-SER-L3-0027 | 27 | series | 6 | missing | VSPR | shape_sequence | L3 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q09 | FM-IQ-VSPR-SER-L4-0028 | 28 | series | 6 | missing | VSPR | shape_sequence | L4 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q10 | FM-IQ-VSPR-SER-L4-0029 | 29 | series | 6 | missing | VSPR | shape_sequence | L4 | static_svg | keep_as_legacy_beta | **high risk** |
| SERIES_Q11 | FM-IQ-VSPR-SER-L4-0030 | 30 | series | 6 | missing | VSPR | shape_sequence | L4 | static_svg | keep_as_legacy_beta | **high risk** |

## ODD_Q01-10 Manual Review Block

| current_id | Current status | Why unresolved |
|---|---|---|
| ODD_Q01 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q02 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q03 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q04 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q05 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q06 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q07 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q08 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q09 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |
| ODD_Q10 | needs_manual_review | no source-level dimension metadata, no answer key, no solution rule |

## Findings

1. All 30 items are `high risk` because the current bank has no answer key.
2. `NPR` is `not found` in the current 30-item bank.
3. `ODD_Q01-10` cannot be stably classified into a formal production dimension from current source metadata alone.
4. Frontend `render_component` is `not found`; the audit only confirms backend payload and content-pack facts.
5. No raster-image-only items were found. The current bank is inline SVG, so the “redraw raster assets” warning does not apply here.
