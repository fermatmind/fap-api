# SEO URL, Issue Queue, and Brief Program Summary

Date: 2026-06-04
Updated after: fap-web PR #1034

This document summarizes the completed SEO operations artifact line for URL inventory,
competitor gap tracking, SEO issue queue, dashboard shell wiring, and read-only
SERP/content brief generation. It is meant to be the handoff document for future SEO
operations scans and website growth planning.

## Executive Status

| Work item | Status | Current truth |
| --- | --- | --- |
| SEO-COMPETITOR-URL-00 | Complete | Contract for competitor URL inventory tracker. |
| SEO-COMPETITOR-URL-01 | Complete | Read-only generator and generated competitor URL artifacts. |
| SEO-ISSUE-QUEUE-00 | Complete | Contract for SEO issue queue read model. |
| SEO-ISSUE-QUEUE-01 | Complete | Read-only issue queue generator with JSON/CSV artifacts. |
| SEO-ISSUE-QUEUE-02 | Complete | `/ops/seo-operations` dashboard shell reads artifact-backed mock data. |
| SEO-BRIEF-00 | Complete | Contract for SERP/content brief generator. |
| SEO-BRIEF-01 | Complete | Read-only brief generator with JSON/Markdown artifacts. |
| Backend seo_intel foundation | Separate line | Schema/API/migration/collector readiness exists, but live writes remain approval-gated. |

Ledger note: GitHub truth shows SEO-BRIEF-01 merged in fap-web PR #1034. The local
`docs/codex/pr-train-state.json` may still show a non-final state if no ledger-only
follow-up PR was opened, because we intentionally stopped creating endless reconciliation
PRs just to mark already-merged work.

## Current Operating Chain

The current SEO operations chain is:

```text
URL truth
  -> own sitemap and URL inventory
  -> competitor URL inventory
  -> SEO issue queue
  -> ops dashboard shell
  -> read-only SERP/content briefs
  -> human editorial/CMS handoff
```

This chain is intentionally read-only. It produces evidence and operating artifacts; it
does not mutate CMS content, submit URLs to search platforms, enable collectors, or write
production SEO data.

## Completed Frontend/Docs Line

### Competitor URL Inventory

Completed PRs:

- fap-web PR #1013: `SEO-COMPETITOR-URL-00`
- fap-web PR #1014: `SEO-COMPETITOR-URL-01`

What it gives us:

- A competitor URL inventory contract.
- Read-only sitemap ingestion rules.
- URL normalization rules.
- Page-family classification such as `test_detail`, `career_job`, `career_guide`,
  `article`, `topic`, `tool`, `support`, and `unknown`.
- Locale, canonical, and hreflang recognition rules.
- Monthly diff concepts: added URLs, removed URLs, directory movement, and content-family
  movement.
- Output formats for JSON and CSV.

Boundary:

- No CMS writes.
- No automatic content generation.
- No search-platform submission.
- No runtime route changes.

### SEO Issue Queue

Completed PRs:

- fap-web PR #1015: `SEO-ISSUE-QUEUE-00`
- fap-web PR #1017: `SEO-ISSUE-QUEUE-01`
- fap-web PR #1020: `SEO-ISSUE-QUEUE-02`

What it gives us:

- A read-model contract for combining SEO signals into operator tasks.
- Generated JSON/CSV issue queue artifacts.
- Artifact-backed mock adapter for the SEO operations dashboard.
- A practical task surface for URL, sitemap, GSC/Baidu/GA4 sample, CMS draft/release
  sample, and competitor inventory findings.

Boundary:

- The dashboard is an ops shell, not the SEO source of truth.
- It does not replace fap-api, CMS, or seo_intel authority.
- It does not write CMS tasks or production issue records.

### SERP / Content Briefs

Completed PRs:

- fap-web PR #1030: `SEO-BRIEF-00`
- fap-web PR #1034: `SEO-BRIEF-01`

What it gives us:

- A contract for advisory SERP/content brief generation.
- A read-only generator based on existing artifact/mock SERP samples.
- JSON and Markdown brief outputs for operator review.
- Brief structure for:
  - query and intent
  - current URL truth
  - competitor SERP patterns
  - table-stakes coverage
  - value-add opportunities
  - internal link suggestions
  - editorial risks and review notes

Boundary:

- No live SERP provider calls.
- No CMS draft creation.
- No formal article generation.
- No publishing.
- No search submission.

## Generated Artifact Inventory

Key artifact locations in fap-web:

- `docs/seo/generated/competitor-url-inventory.v1.json`
- `docs/seo/generated/competitor-url-inventory.v1.csv`
- `docs/seo/generated/seo-issue-queue.v1.json`
- `docs/seo/generated/seo-issue-queue.v1.csv`
- `docs/seo/generated/seo-content-briefs.v1.json`
- `docs/seo/generated/seo-content-briefs.v1.md`

Key generator and test locations:

- `scripts/seo/generate-competitor-url-inventory.mjs`
- `scripts/seo/generate-seo-issue-queue.mjs`
- `scripts/seo/generate-seo-content-briefs.mjs`
- `tests/contracts/competitor-url-inventory-generator.contract.test.ts`
- `tests/contracts/seo-issue-queue-generator.contract.test.ts`
- `tests/contracts/seo-content-brief-generator-readonly.contract.test.ts`

## What This Enables Now

This line is complete enough to support recurring SEO operations scans:

1. Compare our URL truth with competitor sitemap inventories.
2. Detect page-family gaps across tests, career, articles/topics, tools, and support.
3. Turn URL and measurement artifacts into a prioritized issue queue.
4. Review the issue queue in the ops dashboard shell.
5. Generate advisory briefs for selected opportunities.
6. Hand approved opportunities to CMS/backend work as explicit future tasks.

For future website operations, this gives a repeatable analysis loop instead of one-off
SEO brainstorming.

## Recommended SEO Operations Scan Workflow

For each monthly or campaign-specific scan:

1. Regenerate URL inventory artifacts.
2. Regenerate competitor URL inventory artifacts.
3. Regenerate SEO issue queue artifacts.
4. Regenerate content brief artifacts for selected opportunities.
5. Review the dashboard and Markdown briefs manually.
6. Classify opportunities by page family:
   - tests
   - career
   - articles/topics
   - tools
   - support/help
7. Decide which opportunities become backend/CMS work.
8. Open separate scoped PRs for any API, CMS, collector, or publishing changes.

## Still Intentionally Not Done

These are not gaps in this artifact line; they are separate future authority-layer tasks:

- Live SERP provider integration.
- GSC/Baidu/GA4 production collector writes.
- CMS draft creation from briefs.
- CMS issue summary writeback.
- Search-platform submission.
- Automatic content generation or publishing.
- Scheduler enablement.
- Production data writes without exact approval.

## Suggested Next Tasks

Recommended next tasks should build on this line without weakening the authority boundary:

1. `SEO-OPS-SCAN-01`: monthly SEO operations scan report using existing generated
   artifacts only.
2. `SEO-BRIEF-CMS-HANDOFF-00`: backend-owned contract for how approved briefs become CMS
   editorial tasks or draft requests.
3. `SEO-SERP-LIVE-READINESS-00`: approval-gated live SERP provider readiness, with no
   external calls by default.
4. `SEO-DASH-REAL-DATA-READINESS-01`: plan the transition from artifact-backed dashboard
   mock data to backend seo_intel read APIs.
5. `SEO-COLLECTOR-WRITE-CANARY-01`: separately approved backend collector write canary
   with allowlist, batch limit, audit log, rollback, and hard stop conditions.

## Operating Principle

Keep the SEO operations loop evidence-first:

```text
Observe
  -> generate read-only artifacts
  -> review issue queue and briefs
  -> human approve
  -> route to backend/CMS authority
  -> publish or write only through separate approved workflows
```

This keeps fap-web as an operations shell and artifact viewer, while fap-api, CMS, and
seo_intel remain the authority layers for data, content, and production writes.
