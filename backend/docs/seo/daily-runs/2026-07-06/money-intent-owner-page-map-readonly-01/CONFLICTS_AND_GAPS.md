# Conflicts And Gaps

## Potential Cannibalization Boundaries

| Area | Evidence | Decision |
| --- | --- | --- |
| MBTI has test, topic, articles, and career guide URLs in sitemap. | Sitemap contains `/zh/tests/mbti-personality-test-16-personality-types`, `/zh/topics/mbti`, multiple MBTI articles, and `/zh/career/guides/from-mbti-to-job-fit`. | Keep direct test terms on the test page. Topics/articles/career guides own entity, explanation, comparison, and scenario intent. |
| Big Five has test, topic, article, and career guide URLs. | Sitemap contains `/zh/tests/big-five-personality-test-ocean-model`, `/zh/topics/big-five`, Big Five articles, and `/zh/career/guides/big5-for-career-decisions`. | Keep direct test terms on the test page. Defer stronger free-result copy until authority conflict is reconciled. |
| RIASEC has a broad gaokao/major/career article cluster. | Sitemap contains RIASEC test page plus explanation, can/cannot, comparison, gaokao, major, and unwanted-major scenario articles. | Keep direct `霍兰德职业兴趣测试` / `RIASEC测试` owner on the test page; cluster pages should feed it. |
| IQ and EQ share topic/career support under `iq-eq`. | Sitemap contains IQ/EQ test pages plus `/zh/topics/iq-eq` and career guide `/zh/career/guides/iq-eq-balance-at-work`. | Keep direct IQ/EQ test terms on each test page; shared topic owns combined explanation intent. |
| EQ automated URL discovery is noisy. | Simple substring scan for `eq` matched many career job URLs containing "equipment". | Future SEO tooling must use exact token/slug matching for EQ to avoid false positives. |

## Owner Map Gaps

| Gap | Why it matters | Follow-up |
| --- | --- | --- |
| Big Five free-complete-result authority mismatch. | Money-intent page rewrite could overstate access if backend commercial policy remains contradictory. | Keep as external follow-up from PR1; do not repair here. |
| Non-MBTI scale-specific FAQ gaps. | Generic FAQ weakens direct answer blocks for money-intent pages. | Needs backend/CMS-authoritative FAQ PRs, not frontend fallback. |
| Result interpretation owner pages are not fully inventoried. | Queries like `MBTI结果怎么看` and `IQ测试分数怎么看` should not fall back to private result URLs or test pages by accident. | Covered by later `RESULT-INTERPRETATION-PAGE-INVENTORY-READONLY-01`. |
| GSC/seo_intel quality is not verified in this PR. | Owner map should be joined to reliable query/page rows before writing titles or FAQ. | Covered next by `GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01`. |

## Non-Owners

Do not assign public money-intent ownership to:

- `/result/*`, `/attempts/*`, `/report/*`, `/orders/*`, `/payment/*`, `/checkout/*`, `/lookup/*`, `/share/*`, `/history/*`, tokenized URLs, or account/auth routes;
- private screenshots, proof URLs, payment evidence, or user-generated report content;
- generated docs or internal operations reports.

## Sidecar Note

The current local `main` worktree contains pre-existing staged generated files from an earlier task line. This did not affect this PR's isolated branch, local checks, remote checks, merge policy, or scope validation. It should be resolved separately and should not be mixed into this owner-map PR.
