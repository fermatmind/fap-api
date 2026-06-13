# OpenAPI Schema Readiness

## Result

Status: `PASS`

The OpenAPI route snapshot now includes structured response schemas for personality content assets.

## Covered Schemas

- List response
- Item response
- Asset object
- SEO object
- Section object
- FAQ object
- Media object
- Method boundary object
- Evidence note object
- Internal link object
- Indexability fields: `robots`, `launch_state`, `index_eligible`, `sitemap_eligible`, `llms_eligible`

## Consumer Fields

The API payload exposes:

- `framework`
- `entity_type`
- `code`
- `entity_key`
- `locale`
- `slug`
- `seo`
- `robots`
- `canonical_path`
- `canonical`
- `hreflang`
- `sections`
- `faq`
- `media`
- `schema`
- `method_boundary`
- `evidence_notes`
- `internal_links`

## Evidence

- OpenAPI evidence: `backend/docs/contracts/openapi.snapshot.json`
- Exporter evidence: `backend/scripts/export_openapi.sh`
- Check evidence: `bash scripts/export_openapi.sh --check`

