# GSC Readmodel Repair Design

Date: 2026-07-06
Scope: generated-only repair design after GSC quality proof blocked

## Current Problem

The current proof state is:

- current proven data origin: `fixture`;
- required data origin: `live_gsc_api`;
- opportunity queue eligibility: `false`;
- no safe live `seo_intel` read-model row set is available for CTR/TDK repair selection.

The repair should not bypass the gate. The repair should create enough sanitized evidence for the existing gate to pass.

## Existing Building Blocks

| Building block | Current role | Safe next use |
| --- | --- | --- |
| `GscReadonlyLiveAdapter` | Read-only live Search Analytics boundary | Capture sanitized artifact only when explicitly authorized |
| `gsc-live-readonly-adapter` docs | Defines credential and live-read guardrails | Keep as operator runbook boundary |
| `seo-intel:gsc-readmodel-import-dry-run` | Validates sanitized artifact and previews `seo_gsc_daily` rows | Run only against a SHA-pinned sanitized artifact |
| `seo-intel:gsc-readmodel-import-canary` | Controlled batch10 write path | Execute only after exact approval and SHA confirmation |
| `GscDataQualityGate` | Blocks non-live, stale, or structurally incomplete rows | Remains the only opportunity-queue gate |

## Repair Sequence

### Phase 1: Sanitized Live Evidence

Create a read-only evidence PR or operator run artifact that proves:

- `source_engine=google`;
- `data_origin=live_gsc_api`;
- row count is bounded;
- all rows omit raw query, raw URL, credential, token, cookie, session, and raw payload fields;
- artifact records no upstream writes, no CMS write, no Search Channel enqueue, and no indexing request.

Output may be generated-only. It must not write the database.

### Phase 2: Dry-Run Import Readback

Use the existing dry-run importer against the exact artifact SHA256:

- validate forbidden-field exclusion;
- preview future `seo_gsc_daily` rows;
- verify `would_write=false`;
- verify quality gate pass on preview rows;
- keep `canonical_url` null unless separately joined from backend URL Truth by hash.

### Phase 3: Controlled Canary Import

Only after Phase 2 passes, request exact operator approval for a batch10 canary:

- write at most 10 rows to `seo_gsc_daily`;
- require exact artifact SHA256 and confirmation phrase;
- no scheduler;
- no queue;
- no CMS;
- no Search or indexing.

### Phase 4: Quality Gate Readback

After the canary import, perform read-only proof:

- imported rows have `metadata_json.data_origin=live_gsc_api`;
- imported rows have `source_engine=google`;
- required metric fields are present;
- report dates satisfy lag and freshness;
- `GscDataQualityGate.status=pass`;
- `opportunity_queue_eligible=true`.

### Phase 5: CTR/TDK Queue Selection

Only after Phase 4 passes:

- select CTR/TDK candidates from read-model rows;
- keep candidate PR dry-run only unless separately authorized;
- do not infer purchase truth from GSC or GA.

## Failure Handling

If any phase fails:

- record the failure as generated evidence;
- do not continue to the next phase;
- do not run Search/CMS/deploy work;
- keep downstream CTR/TDK cards blocked.

## Non-Goals

This design does not authorize:

- production import;
- scheduler activation;
- opportunity queue execution;
- title/meta/H1/FAQ edits;
- CMS drafting;
- Search submission;
- sitemap submission;
- URL inspection or request indexing;
- fap-web mutation;
- staging or production deploy.
