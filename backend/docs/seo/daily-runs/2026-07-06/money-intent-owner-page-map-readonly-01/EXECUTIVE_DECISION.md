# MONEY-INTENT-OWNER-PAGE-MAP-READONLY-01

Date: 2026-07-06
Repo: fap-api
Scope: generated-only / read-only owner-page map
Runtime impact: none
CMS impact: none
Search submission impact: none
Deploy impact: none

## Decision

Decision output: `money_intent_owner_page_map_completed_ready_for_data_quality_scan`

The owner-page rule for the six core assessment money-intent families is:

> Direct "take the test / free test / complete result" query families should resolve to exactly one public test landing page per scale. Explanation, comparison, scenario, topic, and result-interpretation queries should not compete with those money pages.

This audit assigns the current owner page for each core money-intent family and records conflict boundaries. It does not change titles, metadata, H1, FAQ, internal links, sitemap, `llms`, Search Console, CMS rows, runtime code, or fap-web.

## Immediate Owner Map

| Query family | Owner page | Status |
| --- | --- | --- |
| `MBTI免费测试`, `MBTI测试`, `16型人格测试`, `免费16型人格测试` | `/zh/tests/mbti-personality-test-16-personality-types` | Keep owner. Strongest current money page. |
| `大五人格测试`, `Big Five personality test`, `OCEAN人格测试` | `/zh/tests/big-five-personality-test-ocean-model` | Keep owner, but do not strengthen "free complete result" copy until authority conflict is reconciled. |
| `九型人格测试`, `Enneagram test`, `九型人格免费测试` | `/zh/tests/enneagram-personality-test-nine-types` | Keep owner; FAQ/content specificity gap remains. |
| `霍兰德职业兴趣测试`, `RIASEC测试`, `职业兴趣测试`, `Holland career interest test` | `/zh/tests/holland-career-interest-test-riasec` | Keep owner; RIASEC article cluster should feed this page, not replace it. |
| `智商测试`, `IQ测试`, `IQ test`, `intelligence quotient assessment` | `/zh/tests/iq-test-intelligence-quotient-assessment` | Keep owner with stricter claim boundary; no official/clinical IQ promise. |
| `情商测试`, `EQ测试`, `emotional intelligence test` | `/zh/tests/eq-test-emotional-intelligence-assessment` | Keep owner; current sitemap regex scan has career "equipment" noise, so exact EQ matching should be used in future automation. |

## Owner Boundary

These page families are not money-intent owners:

- private result, attempt, report, order, payment, lookup, share, token, account, auth, and history URLs;
- articles that answer "what is", "vs", "can/cannot tell you", "how to use", or scenario questions;
- topics pages that aggregate an entity cluster;
- career guide pages that route from personality/career concepts into jobs or majors.

They can support the money owner through internal links, but they should not carry the primary title/H1/meta promise for the direct test-taking query unless a future PR explicitly changes the owner map.

## Next Train Item

Proceed to:

`GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01`

Reason: before writing any title/meta/H1/FAQ repair PR, the query/page evidence layer needs quality gates. This avoids using stale or noisy GSC / seo_intel rows to rewrite money pages.

## Deferred Items

This PR intentionally does not:

- edit owner pages or CMS rows;
- add or rewrite FAQ;
- alter sitemap, `llms.txt`, `llms-full.txt`, canonical, noindex, schema, or runtime;
- query private result URLs;
- use GSC/GA as purchase truth;
- execute held RIASEC readback;
- wait for or trigger deploy.
