# Topic Selection

## Recommended Topic

| Field | Value |
| --- | --- |
| Topic id | `gaokao-major-adjustment-unacceptable-major-checklist` |
| Locale | `zh-CN` |
| Primary silo | RIASEC / 高考 / 专业选择 / 职业探索 |
| Operation | `new_article_package_prompt_only` |
| Decision | `GO_FOR_MODE_C_PACKAGE_PROMPT` |
| Primary CTA | `/zh/tests/holland-career-interest-test-riasec` |
| Claim posture | Use RIASEC as interest/course-fit reflection only. Do not imply admission, transfer, job, salary, or career-success prediction. |

## Search Intent

The target reader has received, fears receiving, or is evaluating a major adjustment result that does not match their preference. Their job is not to be reassured with a fixed answer. Their job is to decide what to check next:

- whether the major is truly unacceptable or only unfamiliar;
- whether the course structure conflicts with their interests and strengths;
- whether transfer, minor, second degree, exam, or career exploration paths exist;
- whether to retake, accept, negotiate with family, or build a fallback plan.

## Target Queries

Primary query family:

- `被调剂到不喜欢的专业怎么办`
- `高考调剂到不想去的专业`
- `不想读被调剂的专业怎么办`

Secondary query family:

- `专业不喜欢可以转专业吗`
- `怎么判断专业适不适合自己`
- `霍兰德职业兴趣测试 专业选择`
- `职业兴趣测试 高考志愿`

## Title And Meta Direction

Title direction:

`被调剂到不想去的专业怎么办：兴趣、课程和转专业检查清单`

Meta direction:

`先别用“喜欢/不喜欢”直接下结论。用课程结构、兴趣类型、转专业条件和未来职业方向做一次复盘，再决定接受、调整还是准备备选方案。`

H1 direction:

`被调剂到不想去的专业怎么办`

These are directions for a later content package only. This PR does not write CMS title, meta, H1, or article body.

## Answer Block Shape

The article should answer directly:

> 被调剂到不想去的专业时，先把问题拆成课程是否能接受、兴趣是否严重冲突、学校转专业条件、家庭和成本约束、未来职业方向五项。RIASEC 可以帮助你整理兴趣和活动偏好，但不能替你预测录取、转专业成功率或职业结果。

## Internal Link Targets

Recommended internal links for a later package:

- RIASEC money owner: `/zh/tests/holland-career-interest-test-riasec`
- RIASEC explainer: `/zh/articles/riasec-holland-career-interest-test-explained`
- Gaokao shortlist support: `/zh/articles/gaokao-score-major-shortlist-riasec-checklist`
- Hot major fit support: `/zh/articles/hot-major-fit-riasec-course-career-checklist`

Do not link to private result, report, attempt, order, payment, share, account, or tokenized URLs.

## Claim Risks

Forbidden claims:

- RIASEC can decide the best major.
- RIASEC can predict admission, transfer, employment, salary, or career success.
- A test result can overrule school policy, family constraints, medical/legal/financial advice, or professional counseling.
- The article knows the reader's final answer without their school policy, score, family situation, and course preference.

Allowed claims:

- RIASEC can organize interest and activity-preference signals.
- Course structure, major curriculum, transfer rules, and career direction should be checked separately.
- The checklist can support a calmer conversation with family or advisors.

## Observation Plan

Future observation should remain read-only and separate from this PR:

- GSC page/query impressions, clicks, CTR, and average position after publication;
- GA organic sessions if available;
- article-to-test click events if already tracked;
- RIASEC test start events if already tracked;
- no purchase truth inferred from GSC/GA alone.

Because `GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01` blocked the current data gate, this topic selection uses inventory and strategy evidence, not current GSC query metrics.
