# CTA / Internal-Link Audit

Target: `/zh/tests/holland-career-interest-test-riasec`

## CTA Verdict

Status: `PASS_READ_ONLY`

Observed CTA links are public, non-private, and route to the expected RIASEC take surface with explicit form selection.

## Primary CTAs

| URL | Anchor text | Assessment |
| --- | --- | --- |
| `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_60` | `开始免费霍兰德测试` | Public route; no private token or order identifier. |
| `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_140` | `开始霍兰德职业兴趣增强版免费测试` | Public route; no private token or order identifier. |
| `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_140` | `开始霍兰德职业兴趣免费测试` | Public route; no private token or order identifier. |

Repeated CTAs were observed later on the page for both forms. This is acceptable from a URL safety perspective.

## Internal Links

Public related links observed include:

- `/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist`
- `/zh/articles/major-career-mismatch-job-search-skills-plan`
- `/zh/articles/unwanted-major-adjustment-riasec-transfer-plan`
- `/zh/articles/hot-major-fit-riasec-course-career-checklist`
- `/zh/articles/math-not-good-computer-ai-major-course-riasec-checklist`
- `/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist`
- `/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist`
- `/zh/articles/college-major-choice-holland-mbti-career-test`
- `/zh/articles/career-confusion-test-map`
- `/zh/articles/career-interest-vs-personality-test-differences`
- `/zh/articles/mbti-vs-holland-career-choice`
- `/zh/articles/riasec-holland-career-interest-test-explained`
- `/zh/articles/gaokao-score-major-shortlist-riasec-checklist`
- `/zh/articles/big-five-personality-test-vs-mbti`

Public test links observed include:

- `/zh/tests/mbti-personality-test-16-personality-types`
- `/zh/tests/big-five-personality-test-ocean-model`
- `/zh/tests/enneagram-personality-test-nine-types`
- `/zh/tests/holland-career-interest-test-riasec`
- `/zh/tests/iq-test-intelligence-quotient-assessment`
- `/zh/tests/eq-test-emotional-intelligence-assessment`

## Private URL Guard

No URL containing token, attempt, order, payment, result, report, claim, private, or share-token patterns was observed in the extracted links.

## Analytics Safety

From the URL surface inspected here, CTA links are analytics-safe because they use public canonical/take routes and explicit public form query parameters only:

- `form=riasec_60`
- `form=riasec_140`

No user identifier, payment identifier, order number, attempt id, result id, or private report URL was observed.

## Recommendation

No immediate CTA repair is required. If the next SEO/GEO dry-run proceeds, evaluate whether CTA copy should better distinguish "60题标准版" and "140题增强版" in the same backend authority scope as FAQ answer-surface planning.
