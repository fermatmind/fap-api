# SEO Agent GSC Opportunity Auto Draft

`SEO-AGENT-GSC-OPPORTUNITY-AUTO-DRAFT-01` bridges gated live GSC read-model rows into the L4 SEO Agent dry-run flow.

## Command

```bash
php artisan seo-agent:gsc-opportunity-auto-draft \
  --limit=10 \
  --artifact-dir=/path/to/artifacts \
  --json
```

## Contract

The command reads `seo_intel.seo_gsc_daily` and resolves CMS targets through `seo_intel.seo_urls`. It only creates candidates when the GSC data quality gate passes and the row matches the current opportunity contract:

- `data_origin=live_gsc_api`
- `impressions >= 50`
- `ctr_ppm <= 10000`
- `average_position_milli` between `8000` and `20000`
- `query_type=non_brand`
- `is_brand_query=false`
- URL truth resolves to an `article` or `content_page` CMS target

For eligible rows, it writes sanitized artifacts for:

- GSC opportunity source
- opportunity aggregate
- Codex review handoff
- Codex review verdict
- CMS draft package dry-run
- final run evidence

## Boundaries

This command does not call Google Search Console, Google Indexing, external model APIs, CMS write paths, CMS publish paths, Search Channel enqueue/submit paths, schedulers, or queue workers. It does not write `seo_gsc_daily`; it only consumes rows already imported through the approved GSC sidecar/read-model flow.

Artifacts must not contain full URLs, raw queries, credentials, tokens, cookies, service-account JSON, raw HTML, or CMS body content.
