# GSC read-model bounded backfill

`SEO-10K-GSC-BOUNDED-BACKFILL-01` extends the existing 10-row canary with a
separate, bounded and resumable importer for sanitized GSC sidecar artifacts.
It does not replace or loosen the canary.

## Safety boundary

- Default mode is dry-run. It performs no database write and no live GSC call.
- The only executable write target is `seo_gsc_daily`.
- The source artifact must use
  `gsc-hk-sidecar-runner-wrapper.v1`, `mode=live-read`,
  `data_origin=live_gsc_api`, and a passing data-quality gate.
- Raw query, raw URL, credentials and other forbidden artifact fields fail
  closed through the shared dry-run importer.
- Execution requires the exact artifact SHA-256 and exact confirmation phrase.
- Production additionally requires `--confirm-production-write`.
- There is no scheduler, queue worker, CMS write, Search Channel action,
  Request Indexing call, sitemap submission or credential provisioning.

## Cohorts and bounds

The command accepts `page`, `query`, and `query-page` cohorts. Cohort choice
defines deterministic row ordering; the underlying read-model grain remains
the existing query-by-page daily row. Each invocation requires:

- `--batch-size`, between 1 and 1,000;
- `--hard-max-rows`, between 1 and 10,000;
- `--resume-key`, hashed before it enters a receipt;
- an optional opaque `--cursor` from the preceding receipt; or
- `--reset` without a cursor to restart from offset zero.

The cursor is bound to the artifact SHA-256, cohort, hashed resume key and hard
maximum. A mismatch fails closed. Batch size may change between invocations
without invalidating the cursor.

## Dry-run example

```bash
php artisan seo-intel:gsc-readmodel-import-canary \
  --backfill \
  --artifact=/absolute/path/to/sanitized-gsc-artifact.json \
  --cohort=query-page \
  --batch-size=100 \
  --hard-max-rows=10000 \
  --resume-key=operator-selected-run-id \
  --json
```

Use the emitted `required_confirmation_phrase`, artifact SHA-256 and
`next_cursor` for a separately approved execution. Do not place raw queries,
URLs, credentials or private identifiers in `--resume-key`.

## Idempotency, failure and readback

Every row uses the existing `seo_gsc_daily` unique idempotency tuple:
report date, canonical URL hash, query hash, source engine, device, country and
search type. An existing key is skipped, while a concurrent duplicate is
confirmed by readback before it is classified as skipped. Other database
errors remain failures instead of being silently ignored.

Each receipt includes a batch idempotency key, row counts, next cursor and a
read-only readback count. On a database error, processing stops at the failed
row. The partial-failure receipt exposes only its hashed row key and a retry
cursor positioned at that row, so previously inserted rows are safely skipped
on resume.

## Repository rule impact

This is backend product/operations code that consumes an existing sanitized
GSC evidence artifact and writes only the existing backend read model after
explicit confirmation. It does not change content authority, publication,
indexability, sitemap, llms or frontend fallback rules.
