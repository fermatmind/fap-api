# GSC Read Model Importer Dry-Run

Task: `SEO-GSC-READMODEL-IMPORTER-DRYRUN-01`

## Purpose

This PR adds a dry-run-only preflight for sanitized Hong Kong GSC sidecar artifacts.

The command reads a sanitized sidecar artifact, validates the read-model boundary, and previews the future `seo_gsc_daily` rows that a later approved importer could write.

This PR does not write `seo_gsc_daily`, add a scheduler, enqueue opportunities, mutate CMS content, enqueue or submit Search Channel records, request GSC indexing, submit a sitemap, call Google APIs, or change production environment variables.

## Command

```bash
php artisan seo-intel:gsc-readmodel-import-dry-run \
  --artifact=/opt/fermatmind/seo-gsc-runner/artifacts/gsc-live-read-wrapper-YYYYMMDDTHHMMSSZ-success.json \
  --dry-run \
  --json
```

`--dry-run` is required. Without it, the command fails closed.

## Required Artifact Gates

The input artifact must satisfy all of these conditions:

- `payload.status=success`
- `payload.metadata.data_origin=live_gsc_api`
- `payload.metadata.data_quality_gate.status=pass`
- `payload.metadata.safe_row_preview` exists and contains sanitized rows
- `payload.writes_attempted=false`
- `payload.writes_committed=false`
- `payload.metadata.cms_write_allowed=false`
- `payload.metadata.search_channel_enqueue_allowed=false`
- `payload.metadata.indexing_request_allowed=false`

## Forbidden Fields

The importer recursively rejects artifacts containing these exact field names:

- `raw_query`
- `raw_url`
- `query`
- `page`
- `canonical_url`
- `url`
- `credential_path`
- `token`
- `access_token`
- `api_key`
- `client_email`
- `service_account_json`
- `private_key`
- `cookie`
- `session`
- `raw_payload`

Allowed row preview fields remain sanitized: hashed canonical URL, hashed query, masked query display, report date, source engine, device, country, search type, clicks, impressions, CTR ppm, average-position milli, query type, brand flag, and data state.

## Preview Output

The output includes:

- `target_table=seo_gsc_daily`
- `dry_run=true`
- `would_write=false`
- `rows_previewed`
- `rows_would_insert`
- sanitized `preview_rows`
- negative guarantees for DB, scheduler, queue, CMS, Search Channel, indexing, and live GSC calls

The preview rows deliberately set `canonical_url=null` because raw URLs are not allowed in sidecar artifacts. Future write-mode import remains blocked until a separate approved PR defines whether `seo_gsc_daily.canonical_url` stays null or is safely joined from backend URL truth by hash.

## Negative Guarantees

- no database write
- no `seo_gsc_daily` write
- no migration
- no scheduler activation
- no queue worker activation
- no opportunity queue enqueue
- no CMS draft or CMS write
- no Search Channel enqueue, approval, retry, or submission
- no GSC URL Inspection request indexing
- no sitemap submission
- no live GSC API call
- no credential read, print, storage, or mutation
- no raw query, raw URL, token, client email, credential path, cookie, session, service-account JSON, or raw payload accepted

## Next Allowed Work

After this PR, the next safe step is operator-reviewed deployment of the dry-run command to the sidecar runtime and a dry-run preflight against the latest sanitized live-read artifact.

Write-mode read-model import, scheduler activation, opportunity queue consumption, CMS drafting, and search/indexing actions remain separate holds.
