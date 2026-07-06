# RIASEC Gaokao Cluster Content Package Handoff

Date: 2026-07-06
Scope: operator handoff only; no publishable body

## Package Candidate

| Field | Value |
| --- | --- |
| Future package id | `GAOKAO-SCORE-MAJOR-SHORTLIST-RIASEC-CONTENT-PACKAGE-01` |
| Topic id | `gaokao-score-major-shortlist-riasec-checklist` |
| Locale | `zh-CN` |
| Working title direction | `高考分数出来后怎么筛专业：分数区间、课程和兴趣检查清单` |
| Primary user job | Build a realistic major shortlist after score/rank is known. |
| Primary CTA | `/zh/tests/holland-career-interest-test-riasec` |
| Operation type | future content package only after explicit authorization |

## Required Article Shape For Future Package

This handoff does not write the article body. A future package should cover:

1. Direct answer block.
2. Score/rank and school-major-group range screening.
3. Course table and graduation requirement review.
4. Interest/activity preference reflection using RIASEC.
5. Parent/family constraint discussion checklist.
6. Fallback plan: accept, adjust, minor/double degree, transfer rules, graduate school, later career exploration.
7. CTA to the RIASEC test owner.
8. Boundary block: what RIASEC can and cannot decide.

## Required Link Direction For Future Package

Use only public URLs after confirming they exist and are canonical:

- `/zh/tests/holland-career-interest-test-riasec`
- `/zh/articles/riasec-holland-career-interest-test-explained`
- `/zh/articles/holland-career-interest-test-can-and-cannot-tell-you`
- `/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist`
- `/zh/articles/hot-major-fit-riasec-course-career-checklist`

Do not link to private result/report/attempt/share/history/order/payment/account/auth/token/admin/local URLs.

## Claim Boundary

Allowed:

- RIASEC can help organize interests, activity preferences, and exploration questions.
- Course structure, score/rank range, school policy, transfer rules, family constraints, and long-term career direction should be checked separately.
- A checklist can make the major shortlist more concrete.

Forbidden:

- RIASEC predicts admission, transfer success, employment, salary, or career success.
- RIASEC selects one best major automatically.
- A checklist guarantees the right decision.
- FermatMind has official school, ministry, counseling, clinical, or employment endorsement without separate evidence.

## Channel Holds

Held until separate authorization:

- CMS package/draft/import/publish;
- full article body;
- live title/meta/H1 update;
- internal-link implementation;
- sitemap, llms, schema, canonical, noindex, hreflang;
- GSC Request Indexing, IndexNow, Baidu, 360, Sogou, Shenma, or Search Channel writes;
- runtime cache invalidation or deploy.

## Acceptance Criteria For Future Package

A future content-package PR should not merge unless:

- all outbound links are public and canonical;
- no private URL or identifier appears;
- no deterministic outcome claim appears;
- RIASEC is framed as reflection support, not decision authority;
- the package is explicitly held for operator review before CMS import or publish.
