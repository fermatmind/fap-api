# IQ_BETA_30_ORIGINAL Bank Specification

Date: 2026-05-31

Scope: backend-authoritative planning contract for the first original 30-item FermatMind IQ beta bank. This document defines the item-bank target, metadata contract, copyright boundary, review gates, and launch constraints. It does not import final questions, answer keys, scoring norms, CMS copy, frontend runtime behavior, or commerce unlock.

## 1. Bank identity

| Field | Value |
| --- | --- |
| Bank id | `IQ_BETA_30_ORIGINAL` |
| Scale code | `IQ_INTELLIGENCE_QUOTIENT` |
| Legacy compatibility | `IQ_RAVEN` remains an input alias only; it is not user-facing copy. |
| Package family | `IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO` until a production package cutover is explicitly approved. |
| Item count target | `30` |
| Option count target | `6` options per item, `A` through `F` |
| Timing target | `20` minutes beta target; final timing requires pilot evidence. |
| Runtime status | `planned_spec_only` in this PR |
| Commerce requirement | None for beta launch; commerce unlock remains deferred. |

## 2. Dimension distribution

| Dimension | Count | Purpose |
| --- | --- | --- |
| `VSPR` | `14` | Visual-spatial pattern reasoning, especially matrix and sequence rules. |
| `VSI` | `10` | Visual-spatial insight, rotation, overlay, symmetry, and visual grouping. |
| `NPR` | `6` | Numerical pattern reasoning using language-light symbol, count, or recurrence logic. |

## 3. Item-family plan

| Family | Count | Primary dimension | Notes |
| --- | --- | --- | --- |
| `matrix_3x3` | `10` | `VSPR` | Missing-tile progressive matrices with original diagrams. |
| `matrix_2x2` | `4` | `VSPR` | Warm-up transformation and analogy items. |
| `series` | `4` | `VSPR` | Figure sequence continuation. |
| `odd_one_out` | `4` | `VSI` | Shared-rule exception detection. |
| `rotation` | `3` | `VSI` | Pure rotation or mirror-rejection tasks. |
| `overlay` | `3` | `VSI` | Union, intersection, subtraction, or XOR-style spatial composition. |
| `numeric_pattern` | `2` | `NPR` | Count-based or symbolic recurrence items. |

The distribution intentionally prioritizes nonverbal reasoning while keeping a small numerical-pattern signal. It must not become a school-math test.

## 4. Required item metadata

Each final item in later implementation PRs must provide:

| Field | Requirement |
| --- | --- |
| `item_id` | Stable uppercase id such as `IQB30_ITEM_001`. |
| `question_id` | Stable public question id; usually same semantic identity as item id. |
| `scale_code` | `IQ_INTELLIGENCE_QUOTIENT`. |
| `bank_id` | `IQ_BETA_30_ORIGINAL`. |
| `dimension` | One of `VSPR`, `VSI`, `NPR`. |
| `difficulty_level` | One of `L1`, `L2`, `L3`, `L4`, `L5`. |
| `item_family` | One of the approved item-family values in this specification. |
| `solution_rule` | Internal-only rule description; must not be exposed in public question payloads. |
| `distractor_logic` | Internal-only explanation of plausible wrong options. |
| `correct_answer` | One of `A` through `F`; backend-only. |
| `raw_points` | Usually `1.0`. |
| `assets` | Structured SVG stem and option payloads, not raw SVG strings. |
| `asset_hashes` | Hashes for stem and every option. |
| `generator_metadata` | Generator version, seed, template key, author/reviewer, and source mode. |
| `review_status` | `draft`, `technical_reviewed`, `psychometric_reviewed`, or `approved_beta`. |

## 5. Public payload constraints

| Constraint | Rule |
| --- | --- |
| Structured SVG only | Public question payloads must use structured SVG objects compatible with the frontend IQ renderer. |
| No raw SVG HTML | Do not introduce raw SVG string rendering or `innerHTML` payloads. |
| No answer key leakage | Public payloads must omit `correct_answer`, `answer_key`, `solution_rule`, and equivalent fields. |
| No frontend scoring | Frontend must submit selected option identifiers only; backend remains scoring authority. |
| Six options | Final beta bank should use `A` through `F`; renderer support must be verified before runtime enablement. |
| Language-light prompts | Prompt text should be minimal and not required to solve the item. |

## 6. Copyright and provenance boundary

FermatMind may reproduce item archetypes, not third-party items.

| Source class | Allowed | Forbidden |
| --- | --- | --- |
| Mensa / 123test / Cambridge / Creyos | Use high-level public structure such as matrix reasoning, time limits, and disclaimer patterns. | Copy, trace, paraphrase visually, transform, or reorder their questions, diagrams, options, answer keys, reports, or explanations. |
| SEO-led free IQ sites | Use as market caution examples. | Copy claims, item pools, diagrams, reports, or scoring copy. |
| Open-source candidates | Import only after a separate license verification gate. | Treat homepage marketing claims as license evidence. |

Every final item must be marked with `source_mode: repo_generated` unless a separate license-verification PR explicitly authorizes a different source mode.

### V1 transition strategy: competitor structure benchmarking, no copied item assets

Current phase allows:

- Benchmark 123test, Mensa, Cambridge, Creyos, and similar sites for item-type structure, item count, difficulty gradient, interaction flow, and report module boundaries.
- Record competitor item-family categories such as matrix reasoning, series, rotation, overlay, odd-one-out, and numeric pattern.
- Generate FermatMind-owned items from abstract rule grammars.
- Record internal "competitor structure observations" in research documentation, but do not save, reproduce, transcribe, or rewrite concrete competitor items.

Current phase forbids:

- Copying competitor questions, diagrams, options, answer keys, explanations, or report copy.
- Lightly rewriting competitor items, swapping visuals, changing order, or changing answers.
- Feeding competitor screenshots, item text, answer keys, or explanations into the item-bank generator.
- Using any third-party item bank before the license verification gate is complete.

V2 staffing iteration:

- Hire psychometrics / cognitive assessment advisors and item-design reviewers.
- Establish the FermatMind original item grammar.
- Record generator seed, rule, reviewer, ambiguity check, and copyright check for every item.
- Run beta pilot analysis, difficulty calibration, CTT/IRT review, and backend norm authority gating.

## 7. Review gates

| Gate | Required result before runtime enablement |
| --- | --- |
| `copyright_gate` | Reviewer confirms no item is copied, traced, or lightly transformed from third-party tests. |
| `technical_svg_gate` | Structured SVG renders in existing IQ frontend components. |
| `answer_key_gate` | Public endpoint redaction proves answer keys and rule explanations are absent. |
| `ambiguity_gate` | At least two reviewers agree each item has one best answer. |
| `difficulty_gate` | Internal pilot confirms intended level is plausible. |
| `claim_gate` | Result and landing copy avoid unsupported claims such as real IQ diagnosis or certified IQ. |
| `provenance_gate` | `asset_hashes` and `generator_metadata` are complete. |
| `contract_gate` | Backend import, frontend renderer, and report contracts pass before launch. |

## 8. Norm and report boundary

`IQ_BETA_30_ORIGINAL` may support raw score, dimension scores, quality flags, and stability status. It must not make normed IQ estimate, percentile, or confidence interval production-authoritative until a later backend norm table PR provides evidence and contracts.

## 9. Deferred items

The following are intentionally out of scope for this PR:

| Deferred item | Target phase |
| --- | --- |
| Final 30 generated items | `IQ-BANK-30-02` |
| Import/provenance/redaction runtime gate | `IQ-BANK-30-03` |
| 30-item scoring hardening | `IQ-SCORE-30-01` |
| SEO/claim launch guards | `IQ-CLAIM-SEO-01` |
| CMS landing placement | `IQ-CMS-LANDING-01` |
| Production-like live smoke | `IQ-LIVE-SMOKE-01` |
| Commerce unlock | Deferred until backend commerce authority exists |
