# Current Runtime Readback

Target: `https://fermatmind.com/zh/tests/holland-career-interest-test-riasec`

Readback time: `2026-07-05T14:44:55Z`

Method: public HTML fetch and static extraction. This is a read-only runtime readback; it did not use screenshots as formal metrics and did not mutate runtime state.

## HTTP and Indexability

| Field | Value |
| --- | --- |
| HTTP status | `200` |
| Final URL | `https://fermatmind.com/zh/tests/holland-career-interest-test-riasec` |
| Cache-Control | `public, s-maxage=60, stale-while-revalidate=300` |
| X-Proxy-Cache | `MISS` |
| Title | `免费霍兰德职业兴趣测试：RIASEC 完整结果 | FermatMind` |
| Meta description | `免费完成霍兰德职业兴趣测试，查看 RIASEC 兴趣排序和职业探索线索。结果用于方向参考，不承诺专业或录取结果。` |
| Robots | `index, follow` |
| Canonical | `https://fermatmind.com/zh/tests/holland-career-interest-test-riasec` |
| Alternate links | `3` |

## Discoverability Surfaces

| Surface | HTTP status | Contains target path |
| --- | ---: | --- |
| `sitemap.xml` | 200 | yes |
| `llms.txt` | 200 | yes |
| `llms-full.txt` | 200 | yes |
| `robots.txt` | 200 | no direct path entry observed |

`robots.txt` not containing the path is not treated as a blocker because the page-level robots tag is `index, follow` and the path is present in sitemap/llms surfaces.

## Heading Readback

Primary headings observed:

- H1: `免费霍兰德职业兴趣测试：RIASEC完整结果`
- H2: `选择霍兰德职业兴趣版本`
- H3: `60题标准版`
- H3: `140题增强版`
- H2: `何时使用这份测评`
- H3: `你将获得什么`
- H3: `相关文章`
- H2: `FAQ`
- H3: `免责声明`
- H3: `准备开始？`

The page also renders related article headings tied to major choice, course cost, job activity, career interest, MBTI-vs-Holland comparison, and RIASEC explanation intents.

## Structured Data Readback

JSON-LD types observed:

- `WebPage`
- `BreadcrumbList`
- `SoftwareApplication`
- `FAQPage`

FAQPage `mainEntity` count: `4`

FAQPage questions:

1. `需要多久？`
2. `每道题都要回答吗？`
3. `可以重复测试吗？`
4. `这是诊断吗？`

## Visible FAQ Parity Note

The simple heading parser found these headings after the `FAQ` H2:

- `免责声明`
- `准备开始？`

This does not prove the visible FAQ questions are absent because the runtime may render questions using non-heading markup, hydrated UI, or hidden/accordion controls. It does prove that parity is not established by this diagnostic extraction.

Recorded issue: `faq_visible_jsonld_parity_unverified`

Required follow-up: a separate read-only DOM/Playwright readback that extracts rendered visible FAQ labels and compares them to FAQPage JSON-LD questions.

## Text Presence Checks

| Text | Present in HTML |
| --- | --- |
| `霍兰德职业兴趣测试免费吗` | no |
| `免费` | yes |
| `60题` | yes |
| `140题` | yes |
| `探索` | yes |
| `精准` | no |
| `推荐` | no |
| `专业` | yes |
| `课程` | yes |
| `工作活动` | yes |

Interpretation: free intent is present through title/H1/meta/CTA copy, but the exact FAQ-style query "霍兰德职业兴趣测试免费吗" is not directly answered in the observed HTML. This is a future answer-surface opportunity, not a repair authorization in this PR.

## CTA and Link Readback

Primary CTA links observed:

- `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_60` with text `开始免费霍兰德测试`
- `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_140` with text `开始霍兰德职业兴趣增强版免费测试`
- repeated public `/take?form=riasec_60` and `/take?form=riasec_140` links near later CTA blocks

Related public links observed include zh article routes about unwanted major adjustment, major-career mismatch, hot major fit, high school major choice, MBTI vs Holland, RIASEC explanation, and test hub links for MBTI, Big Five, Enneagram, IQ, and EQ.

Private URL hits: none.
