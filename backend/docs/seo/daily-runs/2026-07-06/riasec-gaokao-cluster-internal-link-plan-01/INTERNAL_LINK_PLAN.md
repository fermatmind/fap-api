# RIASEC Gaokao Cluster Internal Link Plan

Date: 2026-07-06
Scope: planning only; no link mutation

## Core Direction

The cluster should preserve a clear hierarchy:

1. `/zh/tests/holland-career-interest-test-riasec` owns direct test and career-interest-test intent.
2. `/zh/articles/riasec-holland-career-interest-test-explained` explains the model.
3. `/zh/articles/holland-career-interest-test-can-and-cannot-tell-you` handles boundary and accuracy questions.
4. Gaokao/major scenario pages handle specific decision situations.
5. Career guides handle longer career-direction exploration.

## Recommended Link Edges

| Source type | Target | Link role | Notes |
| --- | --- | --- | --- |
| RIASEC test owner | Selected high-intent scenario pages | Optional support links after authorization | Do not overload the owner page with every scenario. |
| Scenario pages | `/zh/tests/holland-career-interest-test-riasec` | Primary CTA | CTA should frame RIASEC as interest/activity reflection. |
| Scenario pages | Model explainer | Education support | Use when the scenario references RIASEC terms. |
| Scenario pages | Boundary explainer | Claim safety | Use near "准不准 / 能不能决定专业" claims. |
| Score shortlist scenario | Adjustment checklist | Downstream decision support | Link only after score/rank shortlist context is covered. |
| Adjustment checklist | Transfer/repeat/stay pages | Downstream fallback | Hold transfer/repeat links until those pages are authorized or confirmed. |
| Hot major scenario | Course/career fit support | Adjacent support | Avoid implying hot major equals outcome. |
| Math/computer/AI scenario | Course task checklist and boundary explainer | Adjacent support | Avoid ability prediction. |
| Career guides | RIASEC test owner | Optional exploration CTA | Use when career-interest exploration is contextually relevant. |

## Candidate Cluster URLs

Owner and explainers:

- `/zh/tests/holland-career-interest-test-riasec`
- `/zh/articles/riasec-holland-career-interest-test-explained`
- `/zh/articles/holland-career-interest-test-can-and-cannot-tell-you`
- `/zh/articles/career-interest-vs-personality-test-differences`

Gaokao/major scenarios:

- `/zh/articles/gaokao-score-major-shortlist-riasec-checklist`
- `/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist`
- `/zh/articles/hot-major-fit-riasec-course-career-checklist`
- `/zh/articles/math-not-good-computer-ai-major-course-riasec-checklist`
- `/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist`
- `/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist`
- `/zh/articles/unwanted-major-adjustment-riasec-transfer-plan`

Career support:

- `/zh/articles/career-confusion-test-map`
- `/zh/articles/major-career-mismatch-job-search-skills-plan`
- `/zh/career/guides/how-to-choose-college-major`
- `/zh/career/guides/how-to-find-right-career-direction`

## Anti-cannibalization Rules

- Direct `霍兰德职业兴趣测试 / RIASEC测试 / 职业兴趣测试` intent should point to the test owner.
- `RIASEC是什么 / 霍兰德职业兴趣是什么` should point to the explainer.
- `准不准 / 能不能决定专业 / 能告诉你什么` should point to the boundary explainer.
- `高考分数出来后怎么筛专业` should point to score-shortlist content.
- `被调剂到不想去的专业怎么办` should point to adjustment content.
- `热门专业适不适合自己` should point to hot-major fit content.

## Prohibited Edges

Do not add public links to:

- private result/report/attempt/share/history URLs;
- order/payment/account/auth URLs;
- tokenized preview, admin, or local URLs;
- uncreated routes;
- pages that imply deterministic major, admission, job, salary, or career-success prediction.

## Validation Needed Before Any Future Link Mutation

Before a future link-writing PR:

- confirm the target URL exists and is public;
- confirm the target URL is indexable only if intended;
- confirm canonical and language are correct;
- confirm page copy passes claim boundary;
- confirm no private identifiers or private URLs appear in anchor text or destination.
