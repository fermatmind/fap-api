# 12-Route Runtime Readback Matrix

Collected on 2026-07-06 with unauthenticated public HTTP reads only.

## Route Results

| Route | HTTP | Canonical | Robots | Title/H1 | FAQPage JSON-LD | CTA evidence | Private URL guard |
| --- | ---: | --- | --- | --- | ---: | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | 200 | self | `index, follow` | pass | 8 | pass | pass; `/zh/articles/...report...` is a public article slug false positive |
| `/en/tests/mbti-personality-test-16-personality-types` | 200 | self | `index, follow` | pass | 4 | pass; `Start Free MBTI Test` link present | pass; `/en/articles/...report...` is a public article slug false positive |
| `/zh/tests/big-five-personality-test-ocean-model` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/en/tests/big-five-personality-test-ocean-model` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/zh/tests/enneagram-personality-test-nine-types` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/en/tests/enneagram-personality-test-nine-types` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/zh/tests/holland-career-interest-test-riasec` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/en/tests/holland-career-interest-test-riasec` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/en/tests/iq-test-intelligence-quotient-assessment` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | self | `index, follow` | pass | 4 | pass | pass |
| `/en/tests/eq-test-emotional-intelligence-assessment` | 200 | self | `index, follow` | pass | 4 | pass | pass |

## Discoverability Spot Check

| Surface | HTTP | Size | 12-route presence |
| --- | ---: | ---: | --- |
| `https://fermatmind.com/sitemap.xml` | 200 | 362934 bytes | all 12 present |
| `https://fermatmind.com/llms.txt` | 200 | 163379 bytes | all 12 present |

## FAQ Questions Captured

`/zh/tests/mbti-personality-test-16-personality-types` exposes eight MBTI-specific FAQPage questions:

1. `MBTI 测试免费吗？`
2. `MBTI 完整结果能看到什么？`
3. `MBTI 测试一般多久？`
4. `MBTI 能决定职业吗？`
5. `MBTI 是心理诊断吗？`
6. `16 型人格结果会变吗？`
7. `MBTI 和大五人格有什么区别？`
8. `做完 MBTI 后下一步看什么？`

The other 11 sampled routes expose the generic four-question FAQPage pattern:

1. zh: `需要多久？` / en: `How long does it take?`
2. zh: `每道题都要回答吗？` / en: `Do I need to answer every question?`
3. zh: `可以重复测试吗？` / en: `Can I retake it?`
4. zh: `这是诊断吗？` / en: `Is this a diagnosis?`

## Notes

- This card proves route availability and basic public runtime/readback health.
- It does not claim P0 hub closeout is complete.
- Scale-specific FAQ, claim-safe copy, answer-block completeness, and free-result authority remain downstream queue items.
- `llms-full.txt` was not evaluated here because it has a dedicated next card.
