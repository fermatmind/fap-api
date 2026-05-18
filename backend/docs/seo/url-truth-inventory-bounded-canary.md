# URL Truth Inventory Bounded Canary

## Purpose

`SEO-DASH-PROD-03B` proved that the production write boundary for `url_truth_inventory` did not mutate unexpected tables, but it wrote zero rows because the backend authority adapter still returned an empty candidate set. A zero-row canary does not validate the actual `seo_urls` / `seo_url_entities` write path.

This plan adds bounded nonzero canary support. It does not run production writes, enable scheduler, deploy Metabase, connect live search APIs, submit URLs, or read production crawler logs.

## Approved Authority

The collector may use backend-owned URL authority only:

- `backend_sitemap_source`
- `backend_public_surface`
- `scale_catalog`
- CMS-backed public authorities already listed in the SEO Intelligence contract

The bounded canary is deterministic and small. It may use public backend authority candidates from the scale catalog when available, and a backend-owned public surface canary contract when the full authority adapter is unavailable. It must not use frontend fallback, Node2 local Laravel, Node2 local DB, static sitemap fallback, static `llms` fallback, analytics data, or synthetic production fixtures as SEO truth.

## Bounded Options

Supported options for `seo-intel:collect --collector=url_truth_inventory`:

- `--canary`: uses a deterministic default subset capped by `url_truth_inventory.canary_default_limit`.
- `--limit=<int>`: bounds the planned candidate set and is capped by `url_truth_inventory.canary_max_limit`.
- `--locale=<locale>`: filters candidate locale.
- `--page-type=<type>`: filters candidate page entity type.

Write mode requires either `--canary` or `--limit`. Dry-run may run without a bound, but write mode refuses unbounded `url_truth_inventory` writes.

## Command Examples

Dry-run bounded canary:

```bash
php artisan seo-intel:collect --collector=url_truth_inventory --dry-run --no-write --json --canary
```

Dry-run explicit limit:

```bash
php artisan seo-intel:collect --collector=url_truth_inventory --dry-run --no-write --json --limit=5
```

Future controlled write canary, after separate approval:

```bash
SEO_INTEL_ENABLED=true \
SEO_INTEL_COLLECTORS_ENABLED=true \
SEO_INTEL_WRITE_ENABLED=true \
SEO_INTEL_DRY_RUN_DEFAULT=false \
php artisan seo-intel:collect --collector=url_truth_inventory --json --canary
```

## Candidate Rules

Each accepted candidate must include canonical URL, locale, page entity type, entity identifier or slug, source authority, indexability state, private-flow status, and non-PII metadata.

Allowed page entity types remain limited to public SEO surfaces such as `home`, `test_hub`, `test_detail`, `article`, `topic`, `personality`, `career_job`, `career_recommendation`, `methodology`, `dataset`, `report_preview`, and `landing_page`.

Forbidden page entity types include `take`, `result`, `order`, `share`, `pay`, `checkout`, and `report_private`.

## Safety Guards

The collector writes only to:

- `seo_urls`
- `seo_url_entities`

It uses idempotent `updateOrInsert` writes and rejects private flows, forbidden source authorities, forbidden page entity types, and metadata or attributes containing raw PII/detail keys such as email, order number, attempt ID, payment ID, provider event ID, cookies, raw IP, raw user agent, payloads, tokens, API keys, or secrets.

No external API calls, production crawler log reads, URL submissions, scheduler activation, Metabase deployment, or production env edits are part of this PR.

## Next Task

`SEO-DASH-PROD-03B-PREFLIGHT-R2` should run a read-only preflight against the verified migration runtime. It should confirm bounded dry-run produces a small nonzero candidate set and that actual production write remains limited to `seo_urls` and `seo_url_entities`.
