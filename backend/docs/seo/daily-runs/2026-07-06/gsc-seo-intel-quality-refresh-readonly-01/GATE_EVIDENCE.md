# GSC / seo_intel Gate Evidence

## Contract Evidence

Source: `backend/docs/seo/gsc-data-quality-gate.md`.

Pass requirements:

- source engine must be `google`;
- data origin must be `live_gsc_api`;
- row source must not be `fixture`, `mock`, `static_artifact`, or `unknown`;
- report date must satisfy configured GSC finalization lag;
- report date must satisfy freshness window;
- required fields must include canonical URL hash, query hash, clicks, and impressions.

Current recorded status:

- `data_origin=fixture`;
- `data_quality_gate_status=blocked`;
- `opportunity_queue_eligible=false`.

## Code Evidence

Source: `backend/app/Services/SeoIntel/GscDataQualityGate.php`.

The gate blocks:

- insufficient rows;
- forbidden data origins;
- data origins not in the allowed list;
- non-google source engines;
- missing report dates;
- missing `canonical_url_hash`, `query_hash`, `clicks`, or `impressions`;
- report dates newer than the finalization lag;
- stale report dates older than the configured maximum age.

Default effective values from code/config:

- `gsc_backfill_lag_days=3`;
- `gsc_data_quality.max_report_age_days=10`;
- `allowed_data_origins=["live_gsc_api"]`;
- `forbidden_data_origins=["fixture","mock","static_artifact","unknown"]`.

## Read Model Evidence

Source: `backend/database/migrations/seo_intel/2026_05_17_000900_create_seo_gsc_daily_table.php`.

`seo_gsc_daily` includes:

- `report_date`;
- `canonical_url_hash`;
- nullable `canonical_url`;
- `query_hash`;
- `query_display_masked`;
- `locale`, `device`, `country`, `search_type`;
- `source_engine`;
- `clicks`, `impressions`, `ctr_ppm`, `average_position_milli`;
- `is_brand_query`, `query_type`, `data_state`;
- `metadata_json`.

Source: `backend/database/migrations/seo_intel/2026_06_20_130000_add_idempotency_key_to_seo_gsc_daily_table.php`.

`seo_gsc_daily.idempotency_key` is derived from report date, canonical URL hash, query hash, source engine, device, country, and search type, then protected by a unique index.

## Runtime Default Evidence

Source: `backend/.env.example`.

Defaults keep live reads and external actions off:

- `SEO_INTEL_ENABLED=false`;
- `SEO_INTEL_WRITE_ENABLED=false`;
- `SEO_INTEL_COLLECTORS_ENABLED=false`;
- `SEO_INTEL_DRY_RUN_DEFAULT=true`;
- `SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=false`;
- `SEO_INTEL_GSC_ENABLED=false`;
- `SEO_INTEL_GSC_LIVE_API_ENABLED=false`;
- `SEO_INTEL_GSC_AUTH_MODE=disabled`;
- `SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=false`;
- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=false`;
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=false`.

## Import / Readback Command Evidence

Source: `backend/app/Console/Commands/SeoIntelGscReadModelImportDryRunCommand.php`.

- Requires `--dry-run`.
- Validates a sanitized artifact.
- Emits preview only.
- `would_write=false`.
- Target table is `seo_gsc_daily`.

Source: `backend/app/Console/Commands/SeoIntelGscReadModelCanaryReadbackCommand.php`.

- Requires artifact path and exact SHA256.
- Read-only.
- `would_write=false`.
- Reports idempotency/readback status without printing raw query or URL.

## Current Train Interpretation

This quality refresh does not produce passable GSC metrics. It preserves the existing conservative boundary: keep using repository/runtime inventory and later GSC/seo_intel quality work separately before any CTR repair loop.
