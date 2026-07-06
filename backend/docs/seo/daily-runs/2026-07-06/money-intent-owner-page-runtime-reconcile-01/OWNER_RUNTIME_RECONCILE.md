# Money Intent Owner Runtime Reconcile

Collected on 2026-07-06 with unauthenticated public reads only.

## Core Owner Runtime Matrix

| Query family | Owner route | HTTP | Canonical | Robots | Title/H1 runtime evidence | Runtime status |
| --- | --- | ---: | --- | --- | --- | --- |
| `MBTI免费测试`, `MBTI测试`, `16型人格测试` | `/zh/tests/mbti-personality-test-16-personality-types` | 200 | self | `index, follow` | title `免费 MBTI 测试...`; H1 `免费 MBTI测试...` | reconciled |
| `大五人格测试`, `OCEAN人格测试` | `/zh/tests/big-five-personality-test-ocean-model` | 200 | self | `index, follow` | title `大五人格测试...`; H1 `大五人格免费测试` | reconciled with free-result authority caution |
| `九型人格测试`, `九型人格免费测试` | `/zh/tests/enneagram-personality-test-nine-types` | 200 | self | `index, follow` | title `九型人格测试`; H1 `九型人格免费测试` | reconciled with FAQ specificity caution |
| `霍兰德职业兴趣测试`, `RIASEC测试`, `职业兴趣测试` | `/zh/tests/holland-career-interest-test-riasec` | 200 | self | `index, follow` | title `免费霍兰德职业兴趣测试...`; H1 `免费霍兰德职业兴趣测试...` | reconciled |
| `智商测试`, `IQ测试` | `/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | self | `index, follow` | title `智商（IQ）测试`; H1 `智商【IQ】测试` | reconciled with IQ claim-boundary caution |
| `情商测试`, `EQ测试` | `/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | self | `index, follow` | title `情商（EQ）测试`; H1 `情商【EQ】测试` | reconciled with FAQ specificity caution |

## Generic Owner Runtime Matrix

| Query family | Candidate route | Runtime evidence | Status |
| --- | --- | --- | --- |
| `免费测试` | `/zh/tests` | HTTP 200, self canonical, `index, follow`, title `测评入口中心`, H1 `免费测试` | plausible hub owner |
| `免费性格测试` | `/zh/tests` or `/zh/personality` | `/zh/tests` is a broad test hub; `/zh/personality` is MBTI/16-type entity hub, title `MBTI人格与16型人格` | partial; unique owner not proven |
| `免费职业测试` | `/zh/tests`, `/zh/tests/holland-career-interest-test-riasec`, or `/zh/career` | `/zh/tests` is broad test hub; RIASEC owner is direct career-interest test; `/zh/career` is career library/exploration, not direct test owner | partial; unique owner not proven |

## Reconcile Decision

Six direct test-taking owner pages are runtime-reconciled. The generic money-intent families remain partial because they need:

1. runtime internal-link graph proof,
2. exact-token owner matching for generic intent,
3. reliable GSC/seo_intel read-model rows before any CTR/title/meta repair,
4. no private result/report/order/payment URLs as owner candidates.

## Boundary

This PR does not implement owner changes. It does not change page titles, metadata, H1, body copy, FAQ, internal links, schema, sitemap, llms, CMS rows, Search Channel queues, or frontend rendering.
