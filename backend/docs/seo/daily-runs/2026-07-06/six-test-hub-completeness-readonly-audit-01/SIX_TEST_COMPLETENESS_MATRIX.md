# Six Test Hub Completeness Matrix

Evidence collected on 2026-07-06 from production public HTML and repository-visible backend authority. The runtime scan was read-only and did not use credentials, private attempt IDs, CMS writes, search submission, deploy, or cache invalidation.

## Runtime Matrix

| Code | Public URL | HTTP | Robots | Canonical | H1 | FAQPage JSON-LD | Visible/runtime notes | Discoverability |
| --- | --- | ---: | --- | --- | --- | ---: | --- | --- |
| MBTI | `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types` | 200 | `index, follow` | self | `免费 MBTI测试：16 型人格完整结果` | 8 | Primary and secondary take CTAs present; FAQ is scale-specific. | sitemap yes; llms yes; llms-full yes |
| BIG5_OCEAN | `https://fermatmind.com/zh/tests/big-five-personality-test-ocean-model` | 200 | `index, follow` | self | `大五人格免费测试` | 4 | Take CTAs present; FAQ is generic. | sitemap yes; llms yes; llms-full yes |
| ENNEAGRAM | `https://fermatmind.com/zh/tests/enneagram-personality-test-nine-types` | 200 | `index, follow` | self | `九型人格免费测试` | 4 | Two take CTAs present; FAQ is generic. | sitemap yes; llms yes; llms-full yes |
| RIASEC | `https://fermatmind.com/zh/tests/holland-career-interest-test-riasec` | 200 | `index, follow` | self | `免费霍兰德职业兴趣测试：RIASEC完整结果` | 4 | Two take CTAs present; FAQ remains generic in this scan. | sitemap yes; llms yes; llms-full yes |
| IQ_RAVEN | `https://fermatmind.com/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | `index, follow` | self | `智商【IQ】测试` | 4 | Take CTA present; FAQ is generic; IQ result authority remains evidence-sensitive. | sitemap yes; llms yes; llms-full no |
| EQ_60 | `https://fermatmind.com/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | `index, follow` | self | `情商【EQ】测试` | 4 | Take CTA present; FAQ is generic. | sitemap yes; llms yes; llms-full yes |

## Completeness Gates

| Gate | MBTI | Big Five | Enneagram | RIASEC | IQ | EQ |
| --- | --- | --- | --- | --- | --- | --- |
| Backend registry public scale | Pass | Pass | Pass | Pass | Pass | Pass |
| Public landing page 200 | Pass | Pass | Pass | Pass | Pass | Pass |
| Indexable robots/canonical | Pass | Pass | Pass | Pass | Pass | Pass |
| Public test CTA present | Pass | Pass | Pass | Pass | Pass | Pass |
| Sitemap presence | Pass | Pass | Pass | Pass | Pass | Pass |
| `llms.txt` presence | Pass | Pass | Pass | Pass | Pass | Pass |
| `llms-full.txt` presence | Pass | Pass | Pass | Pass | Gap | Pass |
| Scale-specific FAQ | Pass | Gap | Gap | Gap | Gap | Gap |
| FAQPage JSON-LD present | Pass | Pass | Pass | Pass | Pass | Pass |
| Free-complete-result landing promise | Pass | Needs authority reconciliation | Partial | Partial | Needs claim review | Partial |
| Private URL leakage in scanned public HTML | Pass | Pass | Pass | Pass | Pass | Pass |
| Claim-safe boundary | Pass with explicit non-diagnosis/career boundary | Pass but generic | Pass but generic | Pass with no admission/outcome promise in meta | Needs stricter norm/estimate proof | Pass but generic |

## Production FAQ Questions Captured

MBTI:

1. `MBTI 测试免费吗？`
2. `MBTI 完整结果能看到什么？`
3. `MBTI 测试一般多久？`
4. `MBTI 能决定职业吗？`
5. `MBTI 是心理诊断吗？`
6. `16 型人格结果会变吗？`
7. `MBTI 和大五人格有什么区别？`
8. `做完 MBTI 后下一步看什么？`

Generic FAQ currently used by Big Five, Enneagram, RIASEC, IQ, and EQ:

1. `需要多久？`
2. `每道题都要回答吗？`
3. `可以重复测试吗？`
4. `这是诊断吗？`

## Interpretation

The six-test hub exists as a public crawlable skeleton, but only MBTI is fully aligned with the P0 closeout pattern that motivated this audit. The rest of the hubs should not be treated as complete SEO/GEO money-intent closeouts until their backend/CMS-authoritative free-result promise, FAQ specificity, claim boundaries, and result-interpretation support are separately audited and repaired.
