# EN-PARITY-2026-07-30 — Unified Handoff

Master handoff directory for the FermatMind English parity strategic initiative.

## Lane Map

| Lane | Window | Scope |
|------|--------|-------|
| `CONTROL/` | W0 | Global manifest, state machine, asset ID, batch numbers, CMS/release/sitemap/llms control |
| `W1-mbti/` | W1 | MBTI English articles, comparisons, result assets |
| `W2-big-five/` | W2 | Big Five English articles, ContentPages, ResultPageV2 |
| `W3-career-guide/` | W3 | CareerGuide English CMS siblings |
| `W4-riasec/` | W4 | RIASEC English articles, deep result assets |
| `W5-enneagram/` | W5 | Enneagram English result assets |
| `W7-eq/` | W7 | EQ English assets |
| `W8-career-job/` | W8 | CareerJob bilingual mapping |
| `W9-qa/` | W9 | Independent QA / Release Readiness |

## Standard Lane Delivery Format

Each producer lane delivers the following to its directory:

```
<lane>/
  scope_manifest.json
  assets.jsonl
  translation_map.json
  source_ledger.json
  claim_boundary_report.json
  editorial_review.json
  dry_run_readiness.json
  handoff.md
```

CONTROL reads these packages, then updates the single global manifest.

## Permissions

- Only CONTROL may modify global manifest/state, PR train entries, CMS publish status, sitemap, llms, indexability, production cache, and search submissions.
- Producer lanes write only to their own directory.
- W9 is read-only: it produces PASS/REPAIR_REQUIRED/BLOCKED verdicts and release batch proposals, but never modifies master manifest or performs production release.
