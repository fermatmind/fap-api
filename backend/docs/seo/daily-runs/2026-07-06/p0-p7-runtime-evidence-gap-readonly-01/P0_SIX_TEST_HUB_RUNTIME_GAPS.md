# P0 Six-Test Hub Runtime Gaps

Status carried from acceptance scan: `PARTIAL`

## 12-Route Runtime Gap Table

Legend:

- `verified`: evidence exists in this scan or prior generated readback.
- `partial`: some evidence exists, but not enough for acceptance.
- `missing`: no reliable proof in current evidence set.
- `unstable`: bounded readback did not complete reliably in this environment.

| Route | HTTP | Canonical | Robots | sitemap | llms | llms-full | title/meta/H1 | Free test | Free full/complete result | FAQ visible/schema | CTA | Claim boundary | Private URL guard | Backend owner | Missing evidence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | verified by prior readback | verified | verified | verified | verified | verified by #2758 | verified | verified | verified | visible 8 / JSON-LD 8 verified | verified | verified | verified | scale registry/API | D7/D28 observation only; fresh bounded fetch was unstable. |
| `/en/tests/mbti-personality-test-16-personality-types` | 200 | self canonical | `index, follow` | present | present | missing complete proof | verified | verified | verified | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` proof, visible FAQ parity, private URL guard with non-overbroad classifier. |
| `/zh/tests/big-five-personality-test-ocean-model` | prior #2758 says 200; bounded fetch unstable | prior self canonical | prior `index, follow` | present | present | prior #2758 says present | prior verified | present | partial; commercial/free-result authority conflict | generic FAQ 4 in #2758 | present | generic | prior pass | scale registry/API | fresh route readback, authority reconciliation, scale-specific FAQ, full result policy proof. |
| `/en/tests/big-five-personality-test-ocean-model` | 200 | self canonical | `index, follow` | present | present | missing complete proof | verified | present | partial | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` proof, free-result authority, visible FAQ parity. |
| `/zh/tests/enneagram-personality-test-nine-types` | prior #2758 says 200; bounded fetch incomplete | prior self canonical | prior `index, follow` | present | present | prior #2758 says present | prior verified | present | partial | generic FAQ 4 in #2758 | present | generic | prior pass | scale registry/API | fresh route readback, scale-specific FAQ, result interpretation support. |
| `/en/tests/enneagram-personality-test-nine-types` | 200 | self canonical | `index, follow` | present | present | missing complete proof | verified | present | partial | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` proof, visible FAQ parity, Enneagram-specific answer blocks. |
| `/zh/tests/holland-career-interest-test-riasec` | prior diagnostic says 200; bounded fetch unstable | prior self canonical | prior `index, follow` | present | present | prior diagnostic says present | prior verified | present | partial | JSON-LD 4; visible parity unverified | present | present | prior pass | scale registry/API | DOM FAQ parity readback, direct free FAQ answer, scale-specific answer blocks. |
| `/en/tests/holland-career-interest-test-riasec` | 200 | self canonical | `index, follow` | present | present | missing complete proof | verified | verified | verified | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` proof, FAQ parity, English claim/major boundary check. |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | 200 | self canonical | `index, follow` | present | present | gap in #2758; sampled proof missing | verified | present | needs claim review | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` status, IQ estimate/norm claim boundary proof, FAQ parity. |
| `/en/tests/iq-test-intelligence-quotient-assessment` | 200 | self canonical | `index, follow` | present | present | missing complete proof | verified | present | needs claim review | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` proof, online-estimate boundary, FAQ parity. |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | 200 | self canonical | `index, follow` | present | present | prior #2758 says present; sampled proof missing | verified | present | partial | JSON-LD 4; visible count needs DOM proof | present | generic | needs stricter href classification | scale registry/API | scale-specific FAQ, visible answer block, result interpretation support. |
| `/en/tests/eq-test-emotional-intelligence-assessment` | 200 | self canonical | `index, follow` | present | present | missing complete proof | verified | present | partial | JSON-LD 4; visible count needs DOM proof | present | present | needs stricter href classification | scale registry/API | `llms-full` proof, EQ-specific answer blocks and FAQ parity. |

## Route-Level Evidence Gaps

P0 cannot become complete until these are closed:

1. Complete reliable 12-route readback with HTTP, canonical, robots, title/meta/H1, FAQ, CTA, and private URL guard.
2. Complete `llms-full` readback without timeout or use an approved bounded/indexed artifact.
3. Replace or intentionally hold generic FAQ for non-MBTI hubs with backend/CMS authority.
4. Reconcile Big Five free-only versus paid commercial authority before strengthening free-complete-result claims.
5. Add IQ online-estimate/norm boundary proof before stronger IQ money-page claims.
6. Prove fap-web is not acting as editorial fallback for each route.

## Gap Count

P0 runtime evidence gap count: `12` route-level acceptance bundles still require at least one missing or partial proof.
