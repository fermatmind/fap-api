# SEO-DASH-02A URL Truth Inventory Collector

## Purpose

This PR adds the first real Search Intelligence collector foundation: `url_truth_inventory`.
It remains disabled by default, dry-run safe, and scoped to backend authority URL/entity planning.

## Scope

The collector plans `seo_urls` and `seo_url_entities` records from backend authority source rules.
It does not fetch public HTML, does not perform drift detection, does not call external search APIs, and does not deploy or schedule any job.

## Authority Inputs

Allowed source authorities are:

- `backend_sitemap_source`
- `cms_article`
- `cms_topic`
- `cms_personality`
- `cms_career_job`
- `cms_career_recommendation`
- `scale_catalog`
- `backend_public_surface`

The current default source is an adapter skeleton. It is fixture-driven for this PR so the collector behavior, filtering, write boundary, and JSON output can be validated without reading live production data.

## Exclusions

The collector must not use:

- Node2 local Laravel
- fap-web frontend fallback
- static `llms` fallback as graph truth
- static sitemap fallback as graph truth
- GA4 or Baidu Tongji as URL truth
- public HTML fetching
- GSC, Baidu, IndexNow, or other external search APIs

## Entity Boundary

Allowed page entity types:

- `home`
- `test_hub`
- `test_detail`
- `article`
- `topic`
- `personality`
- `career_job`
- `career_recommendation`
- `methodology`
- `dataset`
- `report_preview`
- `landing_page`

Forbidden private-flow entity types:

- `take`
- `result`
- `order`
- `share`
- `pay`
- `checkout`
- `report_private`

Forbidden entities are skipped and reported as collector issues. They are not written.

## PII Boundary

The collector must not write or emit detail fields for email, raw cookies, raw order numbers, attempt IDs, payment IDs, provider event IDs, or raw payloads. Dry-run JSON output reports counts and URL hashes only, not raw URL lists.

## Write Boundary

Default config remains:

- `enabled=false`
- `write_enabled=false`
- `collectors_enabled=false`
- `dry_run_default=true`
- `allow_external_api_calls=false`

Dry-run is allowed with default config. Writes are only possible when a future local/test run explicitly enables collectors and writes. No production DB creation or production migration is performed by this PR.

## Deferred

Deferred to later PRs:

- Wiring a live backend sitemap/CMS adapter.
- Public HTML snapshot collection.
- Canonical/meta/robots/json-ld drift detection.
- Crawler log foundation.
- GSC, Baidu, IndexNow, 360, Sogou, Shenma, or AI search adapters.
- Metabase deployment.
- CMS issue queue.

## Next Task

Next task: `SEO-DASH-02B`.
