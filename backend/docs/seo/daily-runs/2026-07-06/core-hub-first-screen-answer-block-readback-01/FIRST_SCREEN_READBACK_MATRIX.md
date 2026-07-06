# Core Hub First-Screen Answer Block Readback Matrix

Date: 2026-07-06

Source: unauthenticated public HTTP reads from `https://fermatmind.com`.

## Acceptance Proxy

Each route was checked for four visible first-screen proxy signals in title, description, H1, and the first 1,400 characters of stripped page text:

1. scale definition
2. free result expectation
3. claim boundary
4. primary CTA

`pass` means all four proxy signals were found. `partial` means two or three were found. This matrix does not assert visual viewport layout.

## Route Matrix

| Route | HTTP | H1 | Definition | Free result | Claim boundary | CTA | Verdict |
| --- | ---: | --- | --- | --- | --- | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | 200 | `免费 MBTI测试：16 型人格完整结果` | yes | yes | yes | yes | pass |
| `/en/tests/mbti-personality-test-16-personality-types` | 200 | `Free MBTI Personality Test with Full Report` | yes | yes | yes | yes | pass |
| `/zh/tests/big-five-personality-test-ocean-model` | 200 | `大五人格免费测试` | yes | yes | yes | yes | pass |
| `/en/tests/big-five-personality-test-ocean-model` | 200 | `Big Five Personality Test 【OCEAN Model】` | yes | no | no | yes | partial |
| `/zh/tests/enneagram-personality-test-nine-types` | 200 | `九型人格免费测试` | yes | yes | yes | yes | pass |
| `/en/tests/enneagram-personality-test-nine-types` | 200 | `Enneagram Personality Test 【Nine Types】` | yes | no | no | yes | partial |
| `/zh/tests/holland-career-interest-test-riasec` | 200 | `免费霍兰德职业兴趣测试：RIASEC完整结果` | yes | yes | no | yes | partial |
| `/en/tests/holland-career-interest-test-riasec` | 200 | `Free Holland Career Interest Test with Full Report` | yes | yes | yes | yes | pass |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | `智商【IQ】测试` | yes | yes | yes | yes | pass |
| `/en/tests/iq-test-intelligence-quotient-assessment` | 200 | `IQ Test 【Intelligence Quotient Assessment】` | yes | no | no | yes | partial |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | `情商【EQ】测试` | yes | yes | yes | yes | pass |
| `/en/tests/eq-test-emotional-intelligence-assessment` | 200 | `EQ Test 【Emotional Intelligence Assessment】` | yes | yes | no | yes | partial |

## Captured Evidence Samples

| Route | First-screen proxy sample |
| --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | `免费完成 MBTI 人格测试，查看 16 型人格结果、偏好维度与后续探索建议。 结果用于自我了解，不作诊断、治疗、招聘筛选或职业保证。` |
| `/en/tests/mbti-personality-test-16-personality-types` | `After submission, the current release lets users read the full result instead of stopping at a preview. Use for self-understanding and communication reflection, not for hiring, diagnosis, or life-outcome guarantees.` |
| `/zh/tests/big-five-personality-test-ocean-model` | `用一次测评了解开放性、尽责性、外倾性、宜人性与神经质。 可免费开始，基础结果免费；高级报告如有付费需提前说明。` |
| `/en/tests/big-five-personality-test-ocean-model` | `Measure your Openness, Conscientiousness, Extraversion, Agreeableness, and Neuroticism in one assessment.` |
| `/zh/tests/enneagram-personality-test-nine-types` | `通过结构化测评了解你的九型人格类型排序。 可免费开始，基础结果免费；高级报告如有付费需提前说明。` |
| `/en/tests/enneagram-personality-test-nine-types` | `Explore your Enneagram profile across the nine personality types.` |
| `/zh/tests/holland-career-interest-test-riasec` | `免费完成霍兰德职业兴趣测试，查看 RIASEC 兴趣排序和职业探索线索。 结果用于方向参考，不承诺专业、录取、岗位匹配或职业结果。` |
| `/en/tests/holland-career-interest-test-riasec` | `Interest results reflect preference, not ability, admission odds, or job guarantees.` |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | `评估矩阵推理、模式识别与抽象问题解决能力。` |
| `/en/tests/iq-test-intelligence-quotient-assessment` | `Assess your matrix reasoning, pattern recognition, and abstract problem-solving ability.` |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | `评估情绪觉察、情绪调节、共情与人际沟通倾向。` |
| `/en/tests/eq-test-emotional-intelligence-assessment` | `Measure emotional awareness, regulation, empathy, and interpersonal communication tendencies.` |

## Findings

1. All 12 hub routes returned HTTP 200.
2. Every route had a scale-specific H1 and primary CTA evidence.
3. English Big Five, English Enneagram, English IQ, and English EQ did not expose free-result plus claim-boundary evidence in the bounded first-screen proxy.
4. Chinese RIASEC exposed strong free-result and career-boundary text, but the bounded keyword proxy did not classify it as a pass because its boundary wording is career/admission specific rather than generic diagnosis-specific.
5. The next cards should verify FAQ visible/JSON-LD parity and visible claim-boundary coverage before any repair split.

## Boundary

This matrix is evidence only. It is not public copy and must not be imported into CMS or rendered directly.
