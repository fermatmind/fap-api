# FERMATMIND_P0_P7_SMALL_PR_DECOMPOSITION_SCAN_20260706_V2

Generated at: `2026-07-06T12:25:00+08:00`

Repo: `fap-api`

Final verdict: `SMALL_PR_QUEUE_READY_FOR_OPENCODE_EXECUTION`

## Decision

The current P0-P7 roadmap should be decomposed into a strictly bounded 33-card PR queue. The queue starts from the already merged runtime gap scan, `P0-P7-RUNTIME-EVIDENCE-GAP-READONLY-01`, and does not rerun it.

OpenCode + DeepSeek V4 Pro Max may execute only the cards marked:

- `OPENCODE_ELIGIBLE_DOCS_ONLY`
- `OPENCODE_ELIGIBLE_READONLY_RUNTIME`
- `OPENCODE_ELIGIBLE_TEST_ONLY`

Everything involving claim strategy, GSC repair design, competitor alternatives, public copy, CMS/publication, or discoverability mutation remains Codex-required, operator-held, or blocked.

## First Three Executable PRs

1. `SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01`
2. `SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01`
3. `SIX-TEST-HUB-FAQ-CTA-CLAIM-GAP-MATRIX-01`

These are validation-only tasks. They must not write CMS, runtime, sitemap, `llms`, schema, Search, fap-web, database, or deploy state.

## Source State Preserved

- Acceptance scan verdict: `P0_P7_PARTIAL_WITH_BLOCKERS`
- Runtime gap scan verdict: `RUNTIME_EVIDENCE_GAP_MAP_READY`
- P0 gap count: `12`
- P1 gap count: `10`
- P2 gap count: `6`
- P3 gap count: `6`
- P4 gap count: `6`
- P5 blocker: `gsc_data_quality_gate_blocked_fixture_or_missing_live_readmodel`
- P6 gap count: `9`
- P7 held reason: source ledger exists but claim/legal review and page dry-run are missing.
- `llms-full` prior readback was partial/timeout and must not be treated as pass.

## Non-Actions

This scan does not execute the PR cards and performs no CMS write, publish, article import, Search Channel enqueue, search-provider submission, URL Truth write, sitemap mutation, `llms` mutation, schema/FAQPage activation, hreflang activation, fap-web edit, runtime/API mutation, database write, title/meta/FAQ/body copy write, competitor alternative page generation, GSC metric invention, raw GSC payload exposure, or deploy.
