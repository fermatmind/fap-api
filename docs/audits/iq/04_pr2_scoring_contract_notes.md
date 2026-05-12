# PR2 — IQ Item Schema + Answer Key + Scoring Contract

## Scope

- Formalize IQ scored contract fields in `IqTestDriver`.
- Upgrade IQ legacy demo `scoring_spec.json` files to scored-contract shape without adding fabricated answers.
- Keep legacy 30-item demo runtime explicitly blocked until a complete answer key exists.
- Add focused scoring tests for:
  - complete scored fixture bank
  - missing answer key -> `blocked_unscored`

## What changed

| Area | File(s) | Change |
|---|---|---|
| Runtime contract | `backend/app/Services/Assessment/Drivers/IqTestDriver.php` | Added scored-contract parsing, item-level answer-key validation, raw/dimension scoring, quality, stability, and explicit norm-unavailable handling. |
| Legacy demo spec | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/scoring_spec.json` | Converted from `pending_answer_key` to formal `scored` contract shape with `items: []`, canonical `scale_code`, `answer_key_version`, `norm_table_version`, and `runtime_policy`. |
| Legacy alias spec | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/scoring_spec.json` | Same formal contract shape, still explicit as alias/legacy demo only. |
| Feature regression | `backend/tests/Feature/V0_3/AttemptsStartSubmitTest.php` | Updated legacy IQ expectation from `unscored` to `blocked_unscored`. |
| Unit coverage | `backend/tests/Unit/Assessment/IqTestDriverTest.php` | Added complete-fixture scoring and missing-answer-key coverage. |
| Sidecar | `docs/codex/sidecar/iq-foundation-sidecar-issues.md` | Added norm-table deferred sidecar. |

## Runtime policy after PR2

| Scenario | Result |
|---|---|
| Production-ready bank with complete `items[]` answer key | `status=scored`, raw score and dimension scores available |
| Bank marked `scored` but missing full answer key | `status=blocked_unscored`, explicit `reason_code` |
| Missing norm table | `norms.status=unavailable_without_norm_table` |
| Legacy 30-item demo | remains `legacy_demo`, `items: []`, cannot be silently scored |

## Explicit non-goals

- No fabricated answers for the legacy 30 demo items.
- No payment / unlock / SKU changes.
- No IQ report builder yet.
- No frontend IQ page work.
- No SVG provenance freeze yet.

## Sidecar follow-up

- `IQ-SIDECAR-NORM-TABLE-DEFERRED-001`
  - scored contract exists, but calibrated IQ estimate / percentile / confidence interval remain unavailable until a real norm table ships.
