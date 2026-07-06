# GEO / AEO Answer Block Gap Matrix Decision

Date: 2026-07-06

PR/task: `GEO-AEO-ANSWER-BLOCK-GAP-MATRIX-01`

Final verdict: `ANSWER_BLOCK_GAP_MATRIX_READY`

## Decision

The P6 GEO / AEO answer-block line should not move directly into copy, schema, sitemap, `llms`, or frontend repair work.

The correct next step is scale-by-scale backend/CMS authority design and then tightly scoped repair PRs for visible answer blocks. The current evidence shows that all 12 hub routes are discoverable and indexable, but only the Chinese MBTI page has a strong scale-specific FAQ/claim surface. The other 11 routes still depend on generic FAQ and partial claim-boundary evidence.

## Evidence Used

- `SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01`
- `SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01`
- `SIX-TEST-HUB-FAQ-CTA-CLAIM-GAP-MATRIX-01`
- Repository claim-boundary and SEO/GEO authority rules

## Answer-Block Acceptance Definition

A hub answer block is considered ready only when the public visible page, not hidden schema alone, can answer:

1. what this test measures
2. what the free result includes
3. what the result cannot decide
4. whether it is diagnostic, admission, hiring, financial, legal, or clinical advice
5. what the user should do next
6. whether private result/order/attempt URLs are excluded from public surfaces

## Queue Decision

The immediate queue should be:

1. Run first-screen answer-block readback for the current public hub pages.
2. Run visible FAQ and FAQPage JSON-LD parity across all hubs.
3. Run claim-boundary visible surface matrix.
4. Split repair PRs only after backend/CMS authority, copy source, and claim guard are explicit.

## Explicit Non-Actions

This PR does not modify:

- public page body copy
- FAQ content
- title, meta, H1, canonical, robots, or hreflang
- JSON-LD
- sitemap, `llms.txt`, or `llms-full.txt`
- CMS data
- runtime rendering
- frontend code
- production data or deployment
