# Next Exact Authorization Prompts

Use only if the operator wants to unblock GSC-driven repair selection.

## Live Read-Only Evidence Authorization

Authorize a separate read-only live evidence PR:

`GSC-LIVE-READONLY-SANITIZED-EVIDENCE-CAPTURE-01`

Scope:

- capture sanitized GSC evidence only;
- no raw query, raw URL, credential path, token, cookie, session, or raw payload;
- prove `source_engine=google` and `data_origin=live_gsc_api`;
- generated artifact only.

Forbidden:

- no DB import/backfill;
- no CMS write;
- no Search Channel enqueue;
- no URL inspection or sitemap submission;
- no deploy.

## Dry-Run Import Readback Authorization

Authorize only after live sanitized evidence exists:

`GSC-READMODEL-DRYRUN-IMPORT-READBACK-01`

Scope:

- dry-run importer/readback only;
- verify quality gate pass before any opportunity queue selection.

Forbidden:

- no production write/import;
- no CTR/TDK repair queue execution.
