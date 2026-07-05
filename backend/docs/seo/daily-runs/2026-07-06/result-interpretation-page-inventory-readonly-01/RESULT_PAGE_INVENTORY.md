# Result Interpretation Page Inventory

This inventory covers public, crawlable support pages that can answer "what does this test result mean" or "how should I use this result" intent. It excludes private result, report, attempt, order, share, lookup, payment, account, tokenized, and history URLs.

## Sitemap-Derived Public Inventory

| Scale | Public URLs found | Interpretation coverage assessment |
| --- | --- | --- |
| MBTI | `/zh/articles/mbti-full-report-career-relationship-communication`; `/en/articles/mbti-full-report-career-relationship-communication`; `/zh/articles/mbti-growth-guide`; `/zh/articles/mbti-narrative-portrait`; `/zh/career/guides/from-mbti-to-job-fit` | Strong support, but still split across report/career/growth/narrative angles. A single "MBTI 结果怎么看" owner is not explicitly established in this scan. |
| Big Five | `/zh/articles/big-five-growth-guide`; `/zh/articles/big-five-narrative-portrait`; `/zh/articles/big-five-tool-guide`; `/zh/career/guides/big5-for-career-decisions` | Useful zh support. English public counterpart coverage was not found in the sitemap-derived resultish scan. |
| Enneagram | `/zh/articles/enneagram-personality-test-explained` | Has explanation coverage, but not enough result-reading coverage for type ranking, wings, motivation, and safe use boundaries. |
| RIASEC | `/zh/articles/riasec-holland-career-interest-test-explained`; `/zh/articles/gaokao-score-major-shortlist-riasec-checklist` | Strong RIASEC explanation/scenario start. Still needs a result-reading page that explains six dimensions, top-three code, uncertainty, and next-step use. |
| IQ | `/zh/articles/iq-test-score-and-limits-explained`; `/zh/articles/iq-test-growth-guide`; `/zh/articles/iq-test-narrative-portrait`; `/zh/articles/iq-test-tool-guide`; `/zh/career/guides/iq-eq-balance-at-work` | Best existing limits page. Keep IQ result interpretation bounded as online estimate / cognitive reasoning reflection. |
| EQ | `/zh/articles/eq-test-tool-guide` | Minimal. Needs a future result-reading guide for emotional awareness, regulation, empathy, relationship management, and non-diagnostic boundaries. |

## Backend Result/Report Asset Risk

Source: `backend/docs/seo/result-en-parity-00-assessment-result-content-inventory.md`.

Relevant result/report architecture risks:

- MBTI depends on external content package exports and has frontend clone-content authority risk.
- Big Five v2 has many zh assets but no repo-visible English v2 counterparts.
- Enneagram registry content is zh-CN-only for several result/report groups.
- RIASEC lifecycle/result assets are zh-CN-only in scanned inventory.
- IQ report labels and pro payloads need locale-safe authority and strict online-estimate boundaries.
- EQ has balanced compiled packs in current scan, but future sensitive result surfaces need fail-closed no-zh-fallback gates.

## Owner-Page Relationship

Direct money-intent owners remain the six test landing pages from `MONEY-INTENT-OWNER-PAGE-MAP-READONLY-01`.

Result interpretation pages should own query families such as:

- `MBTI结果怎么看`
- `16型人格结果怎么看`
- `大五人格结果怎么看`
- `九型人格结果怎么看`
- `霍兰德职业兴趣结果怎么看`
- `RIASEC结果怎么看`
- `IQ测试分数怎么看`
- `EQ测试结果怎么看`

They should link back to the direct test owner, but they should not become direct "take test" money owners.

## Private URL Boundary

Public result interpretation pages must not expose or index:

- private attempt IDs;
- private report URLs;
- email lookup state;
- share tokens;
- order/payment identifiers;
- user screenshots or raw result payloads;
- personalized report content without explicit public-safe approval.

Private result pages can be free and useful for the user, but they are not public SEO result-interpretation pages by default.
