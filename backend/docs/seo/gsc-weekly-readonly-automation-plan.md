# GSC Weekly Read-only Automation Plan

Task: `SEO-GSC-WEEKLY-READONLY-AUTOMATION-PLAN-01`

This contract adds a manual weekly read-only runner for the Hong Kong GSC sidecar. It does not enable Laravel scheduler, production cron, queue workers, CMS mutation, Search Channel submission, indexing requests, or `seo_gsc_daily` writes.

## Manual Runner

```bash
WINDOW_DAYS=28 \
ARTIFACT_DIR=/opt/fermatmind/seo-gsc-runner/artifacts \
backend/scripts/seo/gsc_weekly_readonly_runner.sh
```

The runner performs:

1. GSC sidecar preflight.
2. Read-only GSC live read through `gsc_sidecar_runner.sh`.
3. `seo-intel:gsc-readmodel-import-dry-run`.
4. A sanitized opportunity precheck evidence artifact.

Default behavior:

- `WINDOW_DAYS=28`
- `LIMIT=250`
- `DIMENSIONS=query,page`
- `END_DATE=UTC today - 3 days`
- `START_DATE=END_DATE - WINDOW_DAYS + 1`

`WINDOW_DAYS` may be `7` or `28`. `LIMIT` must be within `1..250`. The only allowed dimensions are `query,page`.

## Evidence Contract

The final evidence artifact uses schema `gsc-weekly-readonly-run.v1` and records:

- live-read artifact path, size, and SHA256
- dry-run importer artifact path, size, and SHA256
- `data_origin`
- `data_quality_gate`
- `rows_would_insert`
- opportunity candidate count
- miss reason counts
- negative guarantees

The evidence must not contain raw query, raw URL, credential path, service-account JSON, client email, private key, token, cookie, or session values.

## Boundaries

This runner may call the Google Search Console Search Analytics API in read-only mode. It must not call Google Indexing API, URL Inspection indexing, Search Channel, CMS APIs, queue workers, scheduler, or any write-capable importer.

If candidates are found, the next step is still a separate `seo-intel:gsc-readmodel-import-canary --limit=10 --json` plan and a separate exact human approval before any `--execute` write.

Batch25, scheduler activation, CMS draft generation, Search Channel submission, indexing requests, and automatic TDK/content mutation remain held for later approval.
