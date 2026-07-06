# Next Exact Authorization Prompts

Use only when ready to unblock GSC-driven SEO repair selection.

## Phase 1

Authorize:

`GSC-LIVE-READONLY-SANITIZED-EVIDENCE-CAPTURE-01`

Scope:

- capture bounded sanitized live GSC artifact;
- prove `source_engine=google` and `data_origin=live_gsc_api`;
- no raw query, raw URL, credential, token, cookie, session, service-account JSON, or raw payload;
- generated artifact only.

Forbidden:

- no DB write/import;
- no scheduler/queue;
- no CMS/Search/deploy.

## Phase 2

Authorize after Phase 1:

`GSC-READMODEL-DRYRUN-IMPORT-READBACK-01`

Scope:

- run dry-run importer against exact artifact SHA256;
- prove quality gate pass on preview rows;
- no write.

## Phase 3

Authorize after Phase 2:

`GSC-READMODEL-CONTROLLED-IMPORT-CANARY-01`

Scope:

- exact SHA-pinned batch10 canary only;
- explicit confirmation phrase required;
- no scheduler, opportunity queue, CMS, Search, or deploy.
