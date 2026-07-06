# Blockers And Held Lanes

## Blockers

| Blocker | Affected lanes | Evidence | Required resolution |
| --- | --- | --- | --- |
| GSC/seo_intel fixture/data-quality block | P1, P5, P6, P3 observation | #2760 and `gsc-data-quality-gate.md` | Prove live/non-fixture `live_gsc_api` read-model rows pass `GscDataQualityGate`. |
| Missing 12-route acceptance proof | P0, P1, P6 | #2758 covers zh hub matrix; en/full route criteria not fully proven. | Run a strict read-only 12-route runtime evidence gap scan. |
| Non-MBTI generic FAQ/free-result gaps | P0, P6 | #2758 records generic FAQ for Big Five, Enneagram, RIASEC, IQ, EQ. | Separate backend/CMS authority audits before any FAQ/runtime mutation. |
| RIASEC FAQ parity unverified | P3, P6 | 2026-07-05 RIASEC P0 diagnostic. | Run `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01` only if reauthorized; it was explicitly excluded from the prior train. |
| Result interpretation owner gaps | P2, P6 | #2761 inventory. | Generate owner map and later content-package plans, then publish only after exact authorization. |

## Held Lanes

| Held lane | Reason |
| --- | --- |
| CMS write/import/publish | Not authorized by this scan. |
| Search Channel enqueue and provider submission | Requires separate exact authorization and passing gates. |
| sitemap, `llms`, `llms-full`, canonical, noindex, schema, hreflang mutation | Explicitly outside scope. |
| Competitor alternatives | Source/legal/claim/indexability gates held. |
| MBTI D7/D28 observations | Date-gated until 2026-07-12 and 2026-08-02 respectively. |
| Production deploy | Requires separate exact SHA authorization. |

## Operator Policy Holds

P7 is held by design: the source ledger allows future planning but blocks public competitor alternative pages until claim/legal review is approved.
