# Result Interpretation Owner Route Matrix

Date: 2026-07-06
Scope: six core test families, read-only evidence

## Guardrails

- Owner route means a public, indexable route that can safely answer "结果怎么看 / how to read results" for one test family.
- Support route means an existing article or guide that partially explains a result area, but is not yet confirmed as the canonical owner for result interpretation.
- Private result URLs, attempts, reports, orders, payments, share tokens, and account/history pages are excluded from owner candidacy.

## Matrix

| Test family | Current support route evidence | Explicit owner status | Gap |
| --- | --- | --- | --- |
| MBTI | `/zh/articles/mbti-full-report-career-relationship-communication`; `/zh/articles/mbti-growth-guide`; `/zh/articles/mbti-narrative-portrait`; career support via `/zh/articles/from-mbti-to-job-fit` | Not confirmed | Strong support set exists, but no single route is confirmed as the public "how to read MBTI results" owner. |
| Big Five | `/zh/articles/big-five-growth-guide`; `/zh/articles/big-five-narrative-portrait`; `/zh/articles/big-five-tool-guide`; career support via `/zh/articles/big5-for-career-decisions` | Not confirmed | Support pages cover growth, narrative, and tool framing, but owner-route authority is not closed. |
| Enneagram | `/zh/articles/enneagram-personality-test-explained` | Not confirmed | Thin support surface; result-reading owner needs explicit route and claim-safe boundaries. |
| RIASEC | `/zh/articles/riasec-holland-career-interest-test-explained`; gaokao/major/career scenario support pages | Not confirmed | Scenario cluster exists, but "how to read RIASEC results" remains separate from scenario planning pages. |
| IQ | `/zh/articles/iq-test-score-and-limits-explained`; `/zh/articles/iq-test-growth-guide`; `/zh/articles/iq-test-narrative-portrait`; `/zh/articles/iq-test-tool-guide` | Not confirmed | Strongest limits-bound support set, but still not promoted as a single canonical result-reading owner. |
| EQ | `/zh/articles/eq-test-tool-guide` | Not confirmed | Thinnest support set; needs explicit result-interpretation owner before growth work. |

## Non-owner Boundaries

These surfaces must not be used as public SEO/GEO owner routes:

- `/results/*`
- `/reports/*`
- `/attempts/*`
- `/orders/*`
- `/payments/*`
- authenticated account/history/report recovery pages
- tokenized share, claim, or private report URLs

## Follow-up Classification

Recommended next PRs:

1. `RESULT-INTERPRETATION-PRIVATE-RESULT-BOUNDARY-GUARD-01`: verify private result/report boundaries remain excluded.
2. `RESULT-INTERPRETATION-MODE-C-BRIEF-QUEUE-01`: draft a content brief queue for explicit owner pages without runtime mutation.
3. Future authorized content/runtime PRs: create or promote one public owner route per test family, then run runtime readback.

## Evidence Limits

This report uses repository and existing generated evidence inventory only. It does not use live GSC or GA and does not validate publication state changes outside the recorded evidence scope.
