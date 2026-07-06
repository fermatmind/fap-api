# Money Intent Cannibalization Link Graph

Inputs:

- `MONEY-INTENT-OWNER-PAGE-MAP-READONLY-01`
- `MONEY-INTENT-OWNER-PAGE-RUNTIME-RECONCILE-01`
- production `sitemap.xml` URL sampling on 2026-07-06

## Owner And Support Split

| Query family | Owner | Supporting surfaces found | Cannibalization risk | Decision |
| --- | --- | --- | --- | --- |
| `MBTI免费测试`, `MBTI测试`, `16型人格测试` | `/zh/tests/mbti-personality-test-16-personality-types` | `/zh/topics/mbti`, MBTI articles, MBTI personality pages, `/zh/career/guides/from-mbti-to-job-fit` | medium | Keep direct test terms on test page; support pages own explanation/entity/career scenarios. |
| `大五人格测试` | `/zh/tests/big-five-personality-test-ocean-model` | `/zh/topics/big-five`, Big Five articles, Big Five trait pages | medium | Keep direct test terms on test page; trait/topic pages own entity and interpretation intent. |
| `九型人格测试` | `/zh/tests/enneagram-personality-test-nine-types` | Enneagram articles | low-to-medium | Keep direct test terms on test page; articles own explanation/workplace-friction intent. |
| `霍兰德职业兴趣测试`, `RIASEC测试`, `职业兴趣测试` | `/zh/tests/holland-career-interest-test-riasec` | RIASEC explanation, gaokao, major, and unwanted-major scenario articles | medium-to-high | Keep direct test terms on test page; scenario cluster should link toward the test owner. |
| `智商测试`, `IQ测试` | `/zh/tests/iq-test-intelligence-quotient-assessment` | IQ tool/growth/narrative/score-limit articles, `/zh/topics/iq-eq`, `/zh/career/guides/iq-eq-balance-at-work` | medium | Keep direct IQ test terms on IQ page; articles own score limits and growth interpretation. |
| `情商测试`, `EQ测试` | `/zh/tests/eq-test-emotional-intelligence-assessment` | EQ tool article, `/zh/topics/iq-eq`, `/zh/career/guides/iq-eq-balance-at-work` | low-to-medium | Keep direct EQ test terms on EQ page; shared IQ/EQ topic owns combined explanation intent. |
| `免费测试` | `/zh/tests` candidate | six test pages plus category pages | medium | Treat `/zh/tests` as broad hub candidate until GSC/read-model proves otherwise. |
| `免费性格测试` | unresolved | `/zh/tests`, `/zh/personality`, MBTI/Big Five/Enneagram pages | high | Do not rewrite TDK until unique owner proof exists. |
| `免费职业测试` | unresolved | `/zh/tests`, `/zh/career/tests`, RIASEC test, RIASEC/gaokao articles | high | Do not rewrite TDK until unique owner proof exists. |

## Link Direction Recommendation

This is a recommendation only, not an implementation:

1. Explanation articles should link to the direct test owner when the next action is taking the test.
2. Topics/personality/entity pages should not duplicate direct "free test" H1/title promises.
3. Career and gaokao scenario articles should route direct career-interest test intent to RIASEC.
4. `/zh/tests` should stay the broad aggregator for generic `免费测试` until a better owner is proven.
5. Private result/report/order/payment paths must never become owner candidates.

## Boundary

This PR is read-only. It does not inspect or mutate private URLs, does not write internal links, and does not use GSC/GA as purchase truth.
