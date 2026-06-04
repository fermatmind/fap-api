# SEO URL, Issue Queue, and Brief Program Summary

Date: 2026-06-04

This document summarizes the SEO operations work completed across `fap-web`
and `fap-api` during the recent PR train. It focuses on the URL inventory,
SEO issue queue, dashboard, and future SERP/content brief generator line.

## Executive status

| Track | Status | Current authority | Notes |
| --- | --- | --- | --- |
| `SEO-COMPETITOR-URL-00` competitor URL inventory tracker contract | Complete | `fap-web` docs/contracts | Contract was merged in PR #1013. |
| `SEO-COMPETITOR-URL-01` read-only competitor URL inventory generator | Complete | `fap-web` script + generated artifact | Generator was merged in PR #1014. It remains read-only and does not write CMS or submit search URLs. |
| `SEO-ISSUE-QUEUE-00` SEO issue queue read model | Complete | `fap-web` docs/contracts | Read model contract was merged in PR #1015. |
| `SEO-ISSUE-QUEUE-01` read-only issue queue generator | Complete | `fap-web` script + generated artifact | Generator was merged in PR #1017, with ledger reconciliation in PR #1018. |
| `SEO-ISSUE-QUEUE-02` dashboard shell artifact adapter | Complete | `fap-web` ops shell | Dashboard reads the artifact-backed issue queue mock/adapter. Merged in PR #1020, with ledger reconciliation in PR #1021. |
| `SEO-BRIEF-00` SERP/content brief generator contract | Not started | Proposed contract | No merged PR, manifest entry, state entry, or local git evidence was found. This should be the next item in this line. |
| Backend `seo_intel` schema/API/migration/collector gates | In progress foundation complete | `fap-api` | Docs/contracts, migration, read-only API, production migration, dry-run smoke evidence, and controlled write gate are in place. Real collector writes remain approval-gated. |

## Completed frontend/docs line

### Competitor URL inventory

Completed PRs:

- `SEO-COMPETITOR-URL-00`: [fap-web PR #1013](https://github.com/fermatmind/fap-web/pull/1013), merged at `ea4e5b83283f5488113bbc80422fe291a60391a4`.
- `SEO-COMPETITOR-URL-01`: [fap-web PR #1014](https://github.com/fermatmind/fap-web/pull/1014), merged at `c04305fce2deeb50c4180e26975515ea37c250a5`.

Key outputs:

- `docs/seo/competitor-url-inventory-tracker.md`
- `docs/seo/generated/competitor-url-inventory-tracker.v1.json`
- `docs/seo/generated/competitor-url-inventory-generator.v1.json`
- `scripts/seo/generate-competitor-url-inventory.mjs`
- `tests/contracts/competitor-url-inventory-tracker.contract.test.ts`
- `tests/contracts/competitor-url-inventory-generator.contract.test.ts`

Contract boundaries:

- Pull competitor sitemaps as read-only inputs.
- Normalize URLs before classification and diffing.
- Classify URLs into page families such as `test_detail`, `career_job`,
  `career_guide`, `article`, `topic`, `tool`, `support`, and `unknown`.
- Detect locale, canonical, and hreflang where available.
- Produce monthly diff outputs such as added URLs, removed URLs, directory
  movement, and content-family movement.
- Align competitor inventory with FermatMind URL Truth.
- Produce JSON/CSV artifacts.
- Do not write CMS, generate content automatically, submit search URLs, or
  block publish flows.

### SEO issue queue

Completed PRs:

- `SEO-ISSUE-QUEUE-00`: [fap-web PR #1015](https://github.com/fermatmind/fap-web/pull/1015), merged at `2026-06-03T07:52:24Z`.
- `SEO-ISSUE-QUEUE-00` ledger reconciliation: [fap-web PR #1016](https://github.com/fermatmind/fap-web/pull/1016), merged at `2026-06-03T11:35:32Z`.
- `SEO-ISSUE-QUEUE-01`: [fap-web PR #1017](https://github.com/fermatmind/fap-web/pull/1017), merged at `cae0a98c24c5894478b80b4809f78239068f7705`.
- `SEO-ISSUE-QUEUE-01` ledger reconciliation: [fap-web PR #1018](https://github.com/fermatmind/fap-web/pull/1018), merged at `118f07d3f8c2e31041e929ff6138f4b511a854d4`.
- `SEO-ISSUE-QUEUE-02`: [fap-web PR #1020](https://github.com/fermatmind/fap-web/pull/1020), merged at `2026-06-03T12:57:20Z`.
- `SEO-ISSUE-QUEUE-02` ledger reconciliation: [fap-web PR #1021](https://github.com/fermatmind/fap-web/pull/1021), merged at `2026-06-03T14:57:53Z`.

Key outputs:

- `docs/seo/seo-issue-queue-read-model.md`
- `docs/seo/generated/seo-issue-queue.v1.json`
- `docs/seo/generated/seo-issue-queue.v1.csv`
- `scripts/seo/generate-seo-issue-queue.mjs`
- `tests/contracts/seo-issue-queue.contract.test.ts`
- `tests/contracts/seo-issue-queue-generator.contract.test.ts`
- `components/ops/seo/IssueQueueTable.tsx`
- `components/ops/seo/SeoOperationsDashboard.tsx`
- `components/ops/seo/seoIssueQueueArtifactAdapter.ts`
- `tests/contracts/seo-issue-queue-dashboard-shell.contract.test.ts`

Contract boundaries:

- Read from URL Truth, sitemap inventory, competitor inventory, CMS
  draft/release samples, and search/analytics sample artifacts.
- Produce a sanitized, actionable queue for SEO operations.
- Keep `fap-web` as an ops dashboard shell, not the backend authority system.
- Keep CMS publish, search submission, and production collector writes out of
  scope.

## Completed backend `seo_intel` foundation

The backend line moved beyond docs-only planning into disabled, approval-gated
runtime foundations.

Important completed backend items include:

- `SEO-DASH-00-RECONCILE`: schema, PII/consent, source-of-truth, and read-only
  API contract reconciliation.
- `SEO-DASH-API-01`: read-only SEO Intelligence API route and permission
  contract foundation.
- `SEO-DASH-MIGRATION-01`: production migration readiness and migration
  execution after explicit approval.
- `SEO-DASH-COLLECTOR-01`: post-migration collector dry-run/no-write readiness.
- Production collector smoke: 13 collectors completed with `dry_run=true`,
  `writes_attempted=false`, `writes_committed=false`, and
  `external_calls_attempted=false`.
- `SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE`: smoke evidence docs/contract.
- `SEO-DASH-COLLECTOR-02`: controlled collector write gate contract, merged in
  [fap-api PR #1883](https://github.com/fermatmind/fap-api/pull/1883) at
  `949b623976499b9c36010fd618bdd9cfbd0aa0dc`.

Current backend constraints:

- Scheduler remains disabled by default.
- Production writes are not enabled by default.
- External API calls are not enabled by default.
- CMS mutation and search submission are not enabled by default.
- Any future production write must use an exact approval phrase and a bounded
  canary plan.

## Is the URL, issue queue, and brief line complete?

No. The line is partially complete.

Complete:

- Competitor URL inventory contract.
- Competitor URL inventory read-only generator.
- SEO issue queue read model.
- SEO issue queue read-only generator.
- Dashboard shell connected to issue queue artifact.
- Backend `seo_intel` read-only foundation, production migration, dry-run smoke,
  and controlled write gate.

Not complete:

- `SEO-BRIEF-00` SERP/content brief generator contract.
- A read-only SERP/content brief generator.
- A backend-owned source-of-truth contract for whether brief artifacts are only
  advisory or may later become CMS draft inputs.
- Any live SERP provider integration.
- Any CMS draft creation from briefs.
- Any automatic content generation or publishing.

## Recommended next tasks

### 1. Ledger reconciliation for `SEO-DASH-COLLECTOR-02`

`fap-api` PR #1883 is merged, but the merged PR content records the task as
`pr_open` because the final merge happened after the PR branch was merged. The
next small hygiene PR should reconcile the ledger:

- PR id: `SEO-DASH-COLLECTOR-02-RECONCILE`
- Title: `docs(seo): reconcile collector write gate ledger`
- Scope: `docs/codex/pr-train-state.json` only.
- Record merge commit: `949b623976499b9c36010fd618bdd9cfbd0aa0dc`.
- No runtime, API, migration, scheduler, production write, external API, CMS,
  search submission, deployment, or env changes.

### 2. `SEO-BRIEF-00`: SERP/content brief generator contract

This should be the next substantive task for the URL/issue/brief line.

Suggested PR:

- PR id: `SEO-BRIEF-00`
- Title: `docs(seo): define SERP content brief generator contract`
- Suggested repo: `fap-web` for the first docs/contract artifact, unless the
  team decides the brief contract must be backend-authoritative from day one.
- Scope: docs/generated/focused contract test/pr-train metadata.
- No runtime, no CMS write, no content publication, no external SERP API, no
  search submission, no deployment.

Contract should define:

- Inputs:
  - FermatMind URL Truth.
  - Sitemap inventory.
  - Competitor URL inventory.
  - SEO issue queue.
  - Existing CMS draft/release status samples.
  - Optional manually captured SERP top-10 samples.
- Output:
  - JSON brief artifact.
  - Optional Markdown brief artifact.
  - Keyword intent.
  - URL/page-family target.
  - SERP table stakes.
  - Value-add opportunities.
  - Internal-link suggestions.
  - Schema hints.
  - Risk flags for claims, medical/psychological language, PII, and consent.
- Hard boundaries:
  - Advisory only.
  - Does not generate publishable article copy.
  - Does not create or mutate CMS drafts.
  - Does not publish.
  - Does not submit to search platforms.
  - Does not use live external APIs until a separate approval-gated PR.

### 3. `SEO-BRIEF-01`: read-only brief generator

After `SEO-BRIEF-00`, add a generator that consumes existing artifacts and mock
SERP samples:

- PR id: `SEO-BRIEF-01`
- Title: `docs(seo): add read-only SERP content brief generator`
- Suggested repo: `fap-web` if it remains artifact/tooling only.
- Output: `docs/seo/generated/seo-content-briefs.v1.json` and optional CSV/MD.
- It should not call live SERP APIs, write CMS, or produce final article body
  copy.

### 4. Backend handoff contract for brief-to-CMS boundaries

Before any brief can influence CMS drafts:

- Define whether backend CMS treats brief artifacts as advisory metadata,
  editorial input, or importable draft scaffolding.
- Add approval gates for claim safety, reviewer ownership, locale parity, and
  publication state.
- Keep Codex out of publishable content generation by default.

### 5. Approval-gated controlled collector write canary

This is backend-specific and separate from the brief line. After the ledger
reconciliation, the next backend collector task should be only a preflight for a
bounded `url_truth_inventory` controlled write canary. It must still require an
exact approval phrase before any production write.

## Operating principle

The safe sequence is:

1. Observe public/runtime truth.
2. Generate read-only artifacts.
3. Convert artifacts into issue queues and briefs.
4. Let humans review and approve.
5. Only then allow bounded backend writes with exact approval.

The system should not jump from competitor or SERP signals directly to CMS
publication. SEO Intelligence is an observation and operations layer first; CMS
and backend remain the authority for content, metadata, publication state, and
production writes.
