# GEO / AEO Answer Block Gap Matrix

Date: 2026-07-06

This matrix converts the previous route, `llms-full`, FAQ, CTA, and claim evidence into a P6 answer-block queue. It is a read-only planning artifact.

## Status Legend

- `pass`: visible evidence appears sufficient for the current read-only stage.
- `partial`: visible evidence exists but is generic, incomplete, or not scale-specific enough.
- `gap`: public claim-safe answer-block evidence is missing or unsafe to infer.

## Route Matrix

| Route | Visible answer-block status | Main gap | Repair lane |
| --- | --- | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | pass/observe | Strongest current hub: 8 MBTI-specific FAQ questions and visible boundary evidence are present. First-screen position still needs a dedicated readback. | observe, then first-screen readback |
| `/en/tests/mbti-personality-test-16-personality-types` | partial | FAQPage still uses generic 4-question pattern; MBTI-specific English answer and claim surface are not proven. | English MBTI FAQ/answer-block authority design |
| `/zh/tests/big-five-personality-test-ocean-model` | partial | Generic FAQ; free-result and commercial authority need reconciliation before money-intent wording. | Big Five free-result and FAQ authority design |
| `/en/tests/big-five-personality-test-ocean-model` | partial | Generic FAQ; free-result and commercial authority need reconciliation before money-intent wording. | Big Five free-result and FAQ authority design |
| `/zh/tests/enneagram-personality-test-nine-types` | partial | Generic FAQ; Enneagram-specific definition, result boundary, and next-step answer block are not proven. | Enneagram FAQ/answer-block authority design |
| `/en/tests/enneagram-personality-test-nine-types` | partial | Generic FAQ; Enneagram-specific definition, result boundary, and next-step answer block are not proven. | Enneagram FAQ/answer-block authority design |
| `/zh/tests/holland-career-interest-test-riasec` | partial | Generic FAQ; RIASEC-specific career exploration boundary and major/admission outcome boundary need explicit visible answer evidence. | RIASEC FAQ/answer-block authority design |
| `/en/tests/holland-career-interest-test-riasec` | partial | Generic FAQ; English career/major boundary and next-step answer evidence need review. | RIASEC English FAQ/claim authority design |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | gap | Online estimate, norm, and intelligence wording require stricter public proof before stronger answer-block claims. | IQ claim-boundary review before answer-block repair |
| `/en/tests/iq-test-intelligence-quotient-assessment` | gap | Online estimate, norm, and intelligence wording require stricter public proof before stronger answer-block claims. | IQ claim-boundary review before answer-block repair |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | partial | Generic FAQ; EQ-specific definition, result boundary, and action guidance are not proven. | EQ FAQ/answer-block authority design |
| `/en/tests/eq-test-emotional-intelligence-assessment` | partial | Generic FAQ; EQ-specific definition, result boundary, and action guidance are not proven. | EQ FAQ/answer-block authority design |

## Cross-Route Findings

1. Discoverability is not the primary blocker. Prior readback found all 12 routes present in public sitemap, `llms.txt`, and `llms-full.txt`.
2. CTA presence is broadly passing, but CTA presence does not prove AEO/GEO answer readiness.
3. Generic FAQ cannot close scale-specific answer-block requirements for MBTI English, Big Five, Enneagram, RIASEC, IQ, or EQ.
4. IQ requires the strictest claim review because "intelligence", estimate, norm, and score language can overstate authority without public proof.
5. RIASEC requires explicit boundaries around career exploration versus admission, hiring, income, or major-placement guarantees.
6. No repair should be hidden-schema-only. The answer must be visible on the public page first, with structured data only reflecting visible evidence.

## Recommended Repair Split

These are proposed follow-up scopes only and are not authorized by this read-only PR:

| Candidate repair | Required authority before implementation |
| --- | --- |
| `MBTI-EN-FAQ-ANSWER-BLOCK-AUTHORITY-REPAIR-01` | English MBTI FAQ and claim-safe answer copy from backend/CMS authority |
| `BIG-FIVE-FREE-RESULT-FAQ-AUTHORITY-REPAIR-01` | Free-result promise, commercial boundary, and Big Five-specific FAQ authority |
| `ENNEAGRAM-FAQ-ANSWER-BLOCK-AUTHORITY-REPAIR-01` | Enneagram-specific result boundary and next-step answer authority |
| `RIASEC-FAQ-CAREER-BOUNDARY-AUTHORITY-REPAIR-01` | RIASEC career/major/admission boundary authority |
| `IQ-CLAIM-BOUNDARY-ANSWER-BLOCK-REVIEW-01` | IQ score/estimate/norm claim proof before copy repair |
| `EQ-FAQ-ANSWER-BLOCK-AUTHORITY-REPAIR-01` | EQ-specific result boundary and action-guidance authority |

## Boundary

This file is not a source of public copy. It must not be imported into CMS or rendered directly on any public page.
