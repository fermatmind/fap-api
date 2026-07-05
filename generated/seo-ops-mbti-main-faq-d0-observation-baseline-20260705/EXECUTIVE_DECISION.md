# MBTI-MAIN-FAQ-D0-OBSERVATION-BASELINE-01

Captured at: `2026-07-05T11:04:12Z`

Decision: `D0_BASELINE_CAPTURED_READ_ONLY`

This generated-only baseline records D0 observation data after `MBTI-MAIN-FAQ-PRODUCTION-RUNTIME-READBACK-02` confirmed production FAQ parity.

Runtime guardrail:

- API FAQ count: `8`
- Public visible FAQ expected-question hits: `8`
- FAQPage JSON-LD mainEntity count: `8`
- Canonical public page cache state during readback: `x-proxy-cache: HIT`
- Private URL / claim-boundary checks: inherited pass from Readback-02; this PR did not re-open private result/order surfaces.

Observation sources:

- Google Search Console, property `sc-domain:fermatmind.com`, search type `Web`, window `last 24 hours`, last updated `2.5 hours ago`.
- Google Analytics 4, property `费马心理`, traffic acquisition report, date `2026-07-05`.
- Public API lookup and canonical public page HTML readback.

Decision rules:

- Do not use this D0 snapshot to trigger TDK, first-screen, FAQ, schema, sitemap, llms, Search submission, CMS, or runtime changes.
- Do not treat GSC or GA as purchase truth.
- Wait for D7 or later before deciding whether another content/runtime optimization PR is justified.

Next eligible observation:

- `MBTI-MAIN-FAQ-D1-D7-D28-OBSERVATION-*`, with D7 as the first decision-quality checkpoint.
