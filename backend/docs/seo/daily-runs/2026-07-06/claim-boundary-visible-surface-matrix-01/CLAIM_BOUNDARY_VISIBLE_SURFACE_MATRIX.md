# Claim Boundary Visible Surface Matrix

Date: 2026-07-06

Source: unauthenticated public HTTP reads from `https://fermatmind.com`.

## Method

For each route, the public page text was stripped from HTML and scanned for visible claim-boundary evidence.

This matrix checks page-level visible text, not only the first screen.

Boundary classes:

- diagnosis / treatment boundary
- professional-advice boundary
- hiring / screening boundary
- admission / major / job guarantee boundary
- career outcome guarantee boundary
- IQ score / norm / estimate caution context

## Route Matrix

| Route | HTTP | Page-level boundary status | Strongest visible boundary evidence | Remaining concern |
| --- | ---: | --- | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | 200 | pass | `不作诊断、治疗、招聘筛选或职业保证` | none for current read-only stage |
| `/en/tests/mbti-personality-test-16-personality-types` | 200 | pass | `not for hiring, diagnosis, or life-outcome guarantees` | keep MBTI-specific English FAQ repair separate |
| `/zh/tests/big-five-personality-test-ocean-model` | 200 | pass | `不是。本测评用于教育性自我理解，不替代专业建议。` | boundary exists but FAQ remains generic |
| `/en/tests/big-five-personality-test-ocean-model` | 200 | pass | `does not replace professional advice` | first-screen boundary was partial in previous card |
| `/zh/tests/enneagram-personality-test-nine-types` | 200 | pass | `不应被当成专业诊断、招聘筛选或职业成功预测` plus professional-advice FAQ boundary | boundary exists but route still uses generic FAQ |
| `/en/tests/enneagram-personality-test-nine-types` | 200 | partial/pass | generic FAQ/professional-advice boundary is visible | Enneagram-specific claim surface is not proven |
| `/zh/tests/holland-career-interest-test-riasec` | 200 | pass | `不承诺专业、录取、岗位匹配或职业结果` | diagnosis boundary appears generic/lower page, not first-screen |
| `/en/tests/holland-career-interest-test-riasec` | 200 | pass | `not ability, admission odds, or job guarantees` | English career/major boundary should stay claim-reviewed |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | pass with caution | `不替代专业建议` and IQ score article context | IQ score/norm/estimate claims still require stricter proof before amplification |
| `/en/tests/iq-test-intelligence-quotient-assessment` | 200 | pass with caution | `does not replace professional advice` and qualified guidance note | IQ score/norm/estimate claims still require stricter proof before amplification |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | pass | `不替代专业建议` and treatment/professional opinion note | route still uses generic FAQ |
| `/en/tests/eq-test-emotional-intelligence-assessment` | 200 | pass | `does not replace professional advice` and treatment/professional guidance note | first-screen boundary was partial in previous card |

## Cross-Route Findings

1. Page-level claim-boundary evidence exists on all 12 routes.
2. First-screen claim-boundary evidence is weaker than page-level evidence on several English routes.
3. FAQ parity is healthy, but 11 routes still use generic FAQ; this limits scale-specific AEO/GEO readiness.
4. RIASEC has the most important non-diagnostic route-specific boundary: admission, major, job, and career-result claims must remain constrained.
5. IQ should remain in the strictest lane: do not strengthen score, norm, intelligence, or estimate language without explicit public proof and claim review.

## Repair Implications

This card does not create a runtime blocker. It creates a prioritization signal:

1. keep Chinese MBTI in observe/readback mode
2. improve English and non-MBTI first-screen boundary placement only through backend/CMS authority
3. repair generic FAQ quality separately from claim-boundary placement
4. run IQ claim review before any money-intent or answer-block amplification

## Boundary

This report is evidence only. It is not public copy and must not be imported into CMS or rendered directly.
