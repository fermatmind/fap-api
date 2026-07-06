# FAQ Visible Parity Matrix Across All Hubs

Date: 2026-07-06

Source: unauthenticated public HTTP reads from `https://fermatmind.com`.

## Method

For each route:

1. Fetch public HTML.
2. Extract visible FAQ questions from the `section id="faq"` rendered payload.
3. Extract FAQPage JSON-LD `mainEntity[].name`.
4. Compare count, question text, and order.
5. Flag the known generic four-question pattern.

## Route Matrix

| Route | HTTP | Visible FAQ count | JSON-LD FAQ count | Question parity | FAQ quality flag |
| --- | ---: | ---: | ---: | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | 200 | 8 | 8 | pass | scale-specific |
| `/en/tests/mbti-personality-test-16-personality-types` | 200 | 4 | 4 | pass | generic |
| `/zh/tests/big-five-personality-test-ocean-model` | 200 | 4 | 4 | pass | generic |
| `/en/tests/big-five-personality-test-ocean-model` | 200 | 4 | 4 | pass | generic |
| `/zh/tests/enneagram-personality-test-nine-types` | 200 | 4 | 4 | pass | generic |
| `/en/tests/enneagram-personality-test-nine-types` | 200 | 4 | 4 | pass | generic |
| `/zh/tests/holland-career-interest-test-riasec` | 200 | 4 | 4 | pass | generic |
| `/en/tests/holland-career-interest-test-riasec` | 200 | 4 | 4 | pass | generic |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | 4 | 4 | pass | generic |
| `/en/tests/iq-test-intelligence-quotient-assessment` | 200 | 4 | 4 | pass | generic |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | 4 | 4 | pass | generic |
| `/en/tests/eq-test-emotional-intelligence-assessment` | 200 | 4 | 4 | pass | generic |

## Captured Questions

### Chinese MBTI

1. `MBTI 测试免费吗？`
2. `MBTI 完整结果能看到什么？`
3. `MBTI 测试一般多久？`
4. `MBTI 能决定职业吗？`
5. `MBTI 是心理诊断吗？`
6. `16 型人格结果会变吗？`
7. `MBTI 和大五人格有什么区别？`
8. `做完 MBTI 后下一步看什么？`

### Generic Chinese Pattern

Used by Chinese Big Five, Enneagram, RIASEC, IQ, and EQ:

1. `需要多久？`
2. `每道题都要回答吗？`
3. `可以重复测试吗？`
4. `这是诊断吗？`

### Generic English Pattern

Used by English MBTI, Big Five, Enneagram, RIASEC, IQ, and EQ:

1. `How long does it take?`
2. `Do I need to answer every question?`
3. `Can I retake it?`
4. `Is this a diagnosis?`

## Interpretation

FAQ parity is healthy at the structured-data boundary: visible FAQ and FAQPage JSON-LD match on all sampled hub routes.

The growth blocker is FAQ specificity, not parity. Generic FAQ can be claim-safe, but it does not close the P0/P6 requirement for scale-specific visible answers across all six hubs and both locales.

## Boundary

This report is evidence only. It is not public FAQ copy and must not be imported into CMS or rendered directly.
