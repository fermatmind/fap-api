# FAQ / CTA / Claim Gap Matrix

Inputs:

- `SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01`
- `SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01`
- `six-test-hub-completeness-readonly-audit-01`
- `p0-p7-runtime-evidence-gap-readonly-01`
- FermatMind public claim-boundary rules in repo docs

## Route Matrix

| Route | FAQ status | CTA status | Claim-boundary status | Repair lane |
| --- | --- | --- | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | pass: 8 MBTI-specific FAQPage questions | pass | pass: non-diagnosis and no career guarantee boundary visible in readback evidence | observe only |
| `/en/tests/mbti-personality-test-16-personality-types` | gap: generic 4-question FAQPage | pass | partial: safe generic boundary, but not MBTI-specific enough for closeout | MBTI English FAQ/claim repair candidate |
| `/zh/tests/big-five-personality-test-ocean-model` | gap: generic 4-question FAQPage | pass | partial: generic non-diagnosis boundary; free-result/commercial authority needs reconciliation | Big Five authority + FAQ repair candidate |
| `/en/tests/big-five-personality-test-ocean-model` | gap: generic 4-question FAQPage | pass | partial: generic non-diagnosis boundary; free-result/commercial authority needs reconciliation | Big Five authority + FAQ repair candidate |
| `/zh/tests/enneagram-personality-test-nine-types` | gap: generic 4-question FAQPage | pass | partial: generic boundary; lacks Enneagram-specific answer/claim surface | Enneagram FAQ/answer-block repair candidate |
| `/en/tests/enneagram-personality-test-nine-types` | gap: generic 4-question FAQPage | pass | partial: generic boundary; lacks Enneagram-specific answer/claim surface | Enneagram FAQ/answer-block repair candidate |
| `/zh/tests/holland-career-interest-test-riasec` | gap: generic 4-question FAQPage | pass | partial/pass: has no admission/outcome promise in prior evidence, but needs RIASEC-specific FAQ | RIASEC FAQ/answer-block repair candidate |
| `/en/tests/holland-career-interest-test-riasec` | gap: generic 4-question FAQPage | pass | partial: safe career-exploration framing, but English claim/major boundary needs review | RIASEC English FAQ/claim repair candidate |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | gap: generic 4-question FAQPage | pass | gap: online estimate/norm wording requires stricter proof before stronger claims | IQ claim-boundary + FAQ repair candidate |
| `/en/tests/iq-test-intelligence-quotient-assessment` | gap: generic 4-question FAQPage | pass | gap: online estimate/norm wording requires stricter proof before stronger claims | IQ claim-boundary + FAQ repair candidate |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | gap: generic 4-question FAQPage | pass | partial: generic boundary; lacks EQ-specific answer/claim surface | EQ FAQ/answer-block repair candidate |
| `/en/tests/eq-test-emotional-intelligence-assessment` | gap: generic 4-question FAQPage | pass | partial: generic boundary; lacks EQ-specific answer/claim surface | EQ FAQ/answer-block repair candidate |

## Queue-Level Decisions

1. Do not treat P0 six-test hub closeout as complete.
2. Do not create frontend FAQ fallback content.
3. Do not edit JSON-LD directly as a hidden-only fix.
4. Do not strengthen IQ, Big Five, career, admission, diagnostic, or outcome claims without backend/CMS authority and public evidence.
5. Prefer separate backend-authoritative repair PRs by scale or by shared landing-surface authority layer after the owner matrix is complete.

## Proposed Follow-Up Split

These are not authorized for implementation by this PR:

| Candidate | Scope |
| --- | --- |
| `SIX-TEST-HUB-BACKEND-AUTHORITY-OWNER-MATRIX-01` | Identify backend authority owners for FAQ, CTA, claim, result promise, and answer blocks. |
| `SIX-TEST-HUB-NON-MBTI-FAQ-AUTHORITY-DESIGN-01` | Design scale-specific FAQ repair split without writing public copy. |
| `SIX-TEST-HUB-IQ-CLAIM-BOUNDARY-REVIEW-01` | Review IQ estimate/norm boundaries before any money-intent copy changes. |
| `SIX-TEST-HUB-BIG-FIVE-FREE-RESULT-AUTHORITY-RECONCILE-01` | Reconcile free-result promise and commercial authority before title/meta/H1 work. |

## Boundary

This matrix is an evidence and planning artifact. It does not change FAQ, CTA, copy, metadata, JSON-LD, sitemap, `llms.txt`, `llms-full.txt`, API behavior, CMS data, or frontend rendering.
