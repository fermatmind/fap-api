# FAQ Visible Parity Matrix Decision

Date: 2026-07-06

PR/task: `FAQ-VISIBLE-PARITY-MATRIX-ALL-HUBS-01`

Final verdict: `FAQ_PARITY_MATRIX_READY`

## Decision

All 12 six-test hub routes currently pass visible FAQ to FAQPage JSON-LD parity by count, question text, and order.

This parity pass does not mean FAQ quality is complete. Eleven routes still expose the generic four-question FAQ pattern. Only the Chinese MBTI hub exposes eight MBTI-specific questions.

## Key Findings

1. `visible_count == jsonld_count` for all 12 routes.
2. `visible_questions == jsonld_questions` for all 12 routes.
3. Chinese MBTI has 8 scale-specific FAQ questions.
4. The other 11 routes have the generic four-question pattern.
5. No hidden schema-only mismatch was found in this readback.

## Decision Boundary

The correct follow-up is not a JSON-LD repair. The next step is visible claim-boundary evidence and then backend/CMS-authoritative FAQ quality repair split where needed.

This PR does not change FAQ content, JSON-LD, CMS, frontend rendering, sitemap, `llms`, metadata, canonical, noindex, production imports, or deployment state.
