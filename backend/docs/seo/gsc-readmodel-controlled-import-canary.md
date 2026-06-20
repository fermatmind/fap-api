# GSC Read Model Controlled Import Canary

Task: `SEO-GSC-READMODEL-CONTROLLED-IMPORT-CANARY-01`

## Purpose

This PR adds the first write-capable GSC read-model importer, limited to a single controlled canary row in `seo_gsc_daily`.

The command reads a sanitized Hong Kong GSC sidecar live-read artifact, reuses the dry-run importer validation gates, and writes at most one row only when the operator provides `--execute`, an exact artifact SHA256 confirmation, and the exact generated write confirmation phrase.

The follow-up idempotency PR adds a dedicated `idempotency_key` column and unique index so future small-batch imports can skip existing rows deterministically. It does not change the write limit: the command remains limited to one row until a separate batch10 PR is approved.

This PR family does not add a scheduler, enqueue opportunities, mutate CMS content, enqueue or submit Search Channel records, request GSC indexing, submit a sitemap, call Google APIs, or change production environment variables.

## Command

Dry-run canary plan:

```bash
php artisan seo-intel:gsc-readmodel-import-canary \
  --artifact=/opt/fermatmind/seo-gsc-runner/artifacts/gsc-live-read-wrapper-YYYYMMDDTHHMMSSZ-success.json \
  --limit=1 \
  --json
```

Bounded canary execution:

```bash
php artisan seo-intel:gsc-readmodel-import-canary \
  --artifact=/opt/fermatmind/seo-gsc-runner/artifacts/gsc-live-read-wrapper-20260620T093059Z-success.json \
  --limit=1 \
  --confirm-artifact-sha256=00238a3132d5399fa1d3aaf992451b87da9e4062d82077c75dc317cf33045d0d \
  --confirm-write="I explicitly approve SEO-GSC-READMODEL-CONTROLLED-IMPORT-CANARY-01 to write at most 1 row to seo_gsc_daily from artifact sha256 00238a3132d5399fa1d3aaf992451b87da9e4062d82077c75dc317cf33045d0d; no scheduler, no queue, no CMS, no search, no indexing." \
  --execute \
  --json
```

The production execution command remains held until a separate explicit operator approval.

## Required Gates

- `--limit=1`; any other value fails closed.
- Artifact SHA256 must match `--confirm-artifact-sha256`.
- `--confirm-write` must exactly match the command-generated confirmation phrase.
- Dry-run importer validation must pass:
  - `payload.status=success`
  - `payload.metadata.data_origin=live_gsc_api`
  - `payload.metadata.data_quality_gate.status=pass`
  - no forbidden raw or credential fields
  - no upstream write, CMS, Search Channel, or indexing flags
  - at least one sanitized preview row

## Write Boundary

Allowed write target:

- connection: `seo_intel`
- table: `seo_gsc_daily`
- maximum rows per execution: `1`

The inserted row comes from the dry-run `preview_rows` shape. `canonical_url` remains `null` because raw URLs are not allowed in sidecar artifacts. The row metadata records `data_origin=live_gsc_api`, `row_source=live_gsc_api`, the source artifact SHA256, and the canary task id.

Before insert, the importer computes `idempotency_key=sha256(report_date|canonical_url_hash|query_hash|source_engine|device|country|search_type)`. Existing rows with the same idempotency key are skipped and reported as `rows_skipped_existing=1`.

Schema boundary:

- column: `seo_gsc_daily.idempotency_key`
- unique index: `seo_gsc_daily_idempotency_key_unique`
- existing rows are backfilled by the forward migration before the unique index is created

## Negative Guarantees

- no database write outside `seo_gsc_daily`
- no schema change outside `seo_gsc_daily.idempotency_key`
- no scheduler activation
- no queue worker activation
- no opportunity queue enqueue
- no CMS draft or CMS write
- no Search Channel enqueue, approval, retry, or submission
- no GSC URL Inspection request indexing
- no sitemap submission
- no live GSC API call
- no credential read, print, storage, or mutation
- no production environment change

## Next Allowed Work

After merge, the next operational step is deploying `main` to the HK sidecar runner and running the dry-run canary plan. A real `--execute` canary write still requires separate explicit approval.

Batch import, scheduler activation, opportunity queue execution, CMS drafting, and search/indexing actions remain separate holds.
