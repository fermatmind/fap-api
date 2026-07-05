# Money Intent Owner Page Map

This map is a read-only routing contract for the next SEO/GEO growth work. It assigns one owner page per direct money-intent query family and records the supporting pages that should feed that owner without competing against it.

## Core Assessment Money Owners

| Family | Direct money-intent examples | Current owner page | Supporting pages observed in sitemap | Notes |
| --- | --- | --- | --- | --- |
| MBTI / 16 types | `MBTI免费测试`, `MBTI测试`, `16型人格测试`, `free MBTI test`, `16 personality test` | `/zh/tests/mbti-personality-test-16-personality-types` and locale counterpart `/en/tests/mbti-personality-test-16-personality-types` | `/zh/topics/mbti`, `/zh/articles/mbti-basics`, `/zh/articles/mbti-full-report-career-relationship-communication`, `/zh/articles/mbti-personality-test-science-vs-pseudoscience`, `/zh/articles/big-five-personality-test-vs-mbti`, `/zh/career/guides/from-mbti-to-job-fit` | MBTI is the strongest current owner. Articles should target explanation/comparison/scenario intent, not "take MBTI test" as primary owner. |
| Big Five / OCEAN | `大五人格测试`, `Big Five personality test`, `OCEAN personality test`, `big5 test` | `/zh/tests/big-five-personality-test-ocean-model` and locale counterpart `/en/tests/big-five-personality-test-ocean-model` | `/zh/topics/big-five`, `/zh/articles/big-five-personality-test-vs-mbti`, `/zh/articles/big-five-growth-guide`, `/zh/articles/big-five-tool-guide`, `/zh/career/guides/big5-for-career-decisions` | Keep the test page as owner. Do not strengthen "free complete result" promise until the Big Five commercial/free-result authority mismatch is resolved. |
| Enneagram / nine types | `九型人格测试`, `Enneagram test`, `nine types personality test` | `/zh/tests/enneagram-personality-test-nine-types` and locale counterpart `/en/tests/enneagram-personality-test-nine-types` | `/zh/articles/enneagram-personality-test-explained`, `/zh/articles/enneagram-workplace-friction-core-motivations` | The test page owns direct test intent. The explanation article should own "九型人格是什么/怎么看/解释" intent. |
| RIASEC / Holland | `霍兰德职业兴趣测试`, `RIASEC测试`, `职业兴趣测试`, `Holland Code test`, `career interest test` | `/zh/tests/holland-career-interest-test-riasec` and locale counterpart `/en/tests/holland-career-interest-test-riasec` | `/zh/articles/riasec-holland-career-interest-test-explained`, `/zh/articles/holland-career-interest-test-can-and-cannot-tell-you`, `/zh/articles/career-interest-vs-personality-test-differences`, `/zh/articles/college-major-choice-holland-mbti-career-test`, gaokao/major scenario articles | The test page owns direct test intent. Scenario and gaokao pages should feed RIASEC, not replace it as money owner. |
| IQ | `智商测试`, `IQ测试`, `IQ test`, `intelligence quotient assessment` | `/zh/tests/iq-test-intelligence-quotient-assessment` and locale counterpart `/en/tests/iq-test-intelligence-quotient-assessment` | `/zh/articles/iq-test-score-and-limits-explained`, `/zh/articles/iq-test-growth-guide`, `/zh/articles/iq-test-tool-guide`, `/zh/topics/iq-eq` | The owner can capture "IQ test" only with online-estimate and claim-boundary language. It must not claim clinical/official IQ validity without norm evidence. |
| EQ | `情商测试`, `EQ测试`, `emotional intelligence test` | `/zh/tests/eq-test-emotional-intelligence-assessment` and locale counterpart `/en/tests/eq-test-emotional-intelligence-assessment` | `/zh/articles/eq-test-tool-guide`, `/zh/topics/iq-eq`, `/zh/career/guides/iq-eq-balance-at-work` | Use exact EQ query matching in tooling; substring `eq` creates false positives from "equipment" career URLs. |

## Cross-Family Query Owners

| Query family | Owner recommendation | Reason |
| --- | --- | --- |
| `免费性格测试`, `free personality test` | Tests hub or future personality-tests hub, not a single scale page. | The user has not selected a scale; route to a directory/hub. |
| `MBTI vs 大五`, `Big Five vs MBTI` | `/zh/articles/big-five-personality-test-vs-mbti` | Comparison intent should not be owned by either test page. |
| `MBTI vs 霍兰德`, `MBTI 和 RIASEC 怎么选` | `/zh/articles/mbti-vs-holland-career-choice` or locale counterpart | Comparison intent should route into both owners with claim boundaries. |
| `职业兴趣测试和人格测试区别` | `/zh/articles/career-interest-vs-personality-test-differences` | Explainer/comparison intent; RIASEC test remains CTA target. |
| `霍兰德职业兴趣测试准吗/能告诉你什么` | `/zh/articles/holland-career-interest-test-can-and-cannot-tell-you` | Claim-boundary explainer; direct "take test" still belongs to RIASEC test page. |
| `IQ 测试分数怎么看` | `/zh/articles/iq-test-score-and-limits-explained` | Interpretation and limits, not direct test-taking. |

## Canonical Owner Rule

For future PRs, do not change a page title/meta/H1/FAQ to target a direct money-intent query unless that page is the assigned owner in this map or this map is deliberately updated in a separate read-only/manifest-authorized PR.

Private result pages remain excluded from public SEO/GEO owner mapping.
