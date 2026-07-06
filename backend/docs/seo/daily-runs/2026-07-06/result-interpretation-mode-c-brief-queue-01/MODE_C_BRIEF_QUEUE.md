# Result Interpretation Mode C Brief Queue

Date: 2026-07-06
Scope: generated docs-only queue for future public owner-route content packages

## Queue

| Priority | Brief id | Working public angle | Primary intent | Required boundary |
| --- | --- | --- | --- | --- |
| P0 | `mbti-result-interpretation-owner-brief` | `MBTI 测试结果怎么看：从类型、偏好到行动复盘` | Help users understand type letters, likely strengths, blind spots, communication, career reflection, and next actions without treating type as identity destiny. | Do not claim clinical, hiring, admission, relationship, salary, or life-outcome prediction. |
| P0 | `riasec-result-interpretation-owner-brief` | `霍兰德职业兴趣测试结果怎么看：六型、前三代码和专业职业方向` | Explain RIASEC six types, top-three code, score closeness, uncertainty, major/career exploration, and safe next steps. | Do not promise admission, transfer, job, salary, career success, or exact occupational fit. |
| P1 | `eq-result-interpretation-owner-brief` | `EQ 测试结果怎么看：情绪觉察、调节、同理和沟通复盘` | Turn a thin support set into a clear public explanation of EQ dimensions and practice-oriented next steps. | Do not diagnose mental health, therapy needs, personality defects, or relationship outcomes. |
| P1 | `big-five-result-interpretation-owner-brief` | `大五人格测试结果怎么看：五个维度、组合画像和成长建议` | Explain OCEAN dimensions, high/low scores, trait combinations, and growth framing. | Do not imply fixed identity, employment suitability, or unsupported norm/percentile precision. |
| P2 | `enneagram-result-interpretation-owner-brief` | `九型人格测试结果怎么看：核心动机、压力反应和成长方向` | Explain core type, nearby types, wings if supported, motivation, stress/growth reflection, and cautious use. | Do not overstate type certainty or use Enneagram as clinical/diagnostic authority. |
| P2 | `iq-score-result-interpretation-owner-brief` | `IQ 测试分数怎么看：在线估计、能力边界和训练方向` | Explain online cognitive reasoning score limits, confidence, practice, and non-diagnostic interpretation. | Do not claim official IQ, clinical assessment, admission/employment prediction, or percentile/norm precision unless backend authority exists. |

## Shared Brief Requirements

Every future brief should include:

- one direct answer block near the top;
- a dimension/type/code explanation table where relevant;
- "what this result can help you do next";
- "what this result cannot decide";
- CTA back to the relevant test owner page;
- private URL exclusion note for internal reviewers;
- no hidden-schema-only evidence;
- no Search submission or index request in the content PR.

## Owner Route Candidate Shape

Future owner pages should be public article or guide routes, not private result/report pages. Candidate slugs should be chosen in a separate authorized PR. Working slug directions:

- `mbti-test-result-meaning-guide`
- `riasec-test-result-meaning-guide`
- `eq-test-result-meaning-guide`
- `big-five-test-result-meaning-guide`
- `enneagram-test-result-meaning-guide`
- `iq-test-score-meaning-guide`

These are directions only, not committed route changes.

## Internal Link Direction

When future owner pages exist, each should link to:

- its direct test owner landing page;
- one support article already found in the inventory when relevant;
- related high-intent cluster pages only when the reader intent matches.

Do not link from sitemap/llms or public hubs to uncreated routes.

## Hold Conditions

Hold a brief if any of these are true:

- no CMS/backend authority path exists for the route;
- the brief requires private result payload examples;
- the brief would need claims about accuracy, norms, clinical validity, admissions, hiring, salary, or guaranteed outcomes;
- GSC/GA data is being used as purchase truth;
- the same PR would also mutate title/meta/H1/sitemap/llms/schema/runtime.
