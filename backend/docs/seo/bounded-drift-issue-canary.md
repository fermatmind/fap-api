# Bounded Drift Issue Canary

## Purpose

`SEO-DASH-PROD-03D-SCAN` blocked the next production write canary because `drift_foundation`, `issue_queue_foundation`, and `attribution_revenue_foundation` were still fixture-only foundations. Fixture dry-runs prove command safety, but they do not validate a production write path from the already-created URL Truth rows.

This plan adds bounded drift issue canary support without enabling production writes in this PR.

## Approved Source And Target

The bounded drift canary may read only sanitized SEO Intelligence URL Truth tables:

- `seo_urls`
- `seo_url_entities`

The only write target is:

- `seo_issue_queue`

The canary must not read Node2 local Laravel, Node2 local DB, frontend fallbacks, static sitemap fallbacks, static `llms.txt` fallbacks, production crawler logs, public HTML crawls, live search APIs, analytics systems, or business raw tables.

## Candidate Issues

Allowed deterministic issue candidates are:

- `url_truth_coverage_low`
- `missing_url_entity_mapping`
- `missing_lastmod_for_indexable_url`
- `missing_or_unknown_indexability_state`
- `forbidden_private_flow_indexable`
- `forbidden_source_authority_detected`
- `unsupported_page_entity_type`
- `url_truth_canary_observation`

These candidates are generated from current `seo_urls` / `seo_url_entities` state only. The canary does not claim sitemap, `llms.txt`, crawler, or external drift unless that source is explicitly checked by an approved future task.

## Bounds

Write mode requires one of:

```bash
php artisan seo-intel:collect --collector=drift_foundation --json --canary
```

```bash
php artisan seo-intel:collect --collector=drift_foundation --json --limit=5
```

`--canary` uses a small default limit. `--limit` is capped by configuration. Unbounded write mode is blocked.

## Dry-run Requirements

Dry-runs must include:

- `--dry-run`
- `--no-write`
- `--json`
- `--canary` or `--limit=<n>` for bounded preview

Dry-run output must report source tables, target table, candidate count, planned issue count, issue type breakdown, and safety booleans for no external calls, no production log reads, no search submissions, no CMS mutation, no auto-publish, and no pSEO.

## Actual Write Requirements

An actual canary write requires explicit human approval and command-session-only env flags. It must:

- target only `seo_issue_queue`
- use deterministic issue fingerprints
- be idempotent through update/insert behavior
- store sanitized evidence only
- avoid raw URLs as evidence
- avoid PII and raw identifiers
- avoid scheduler activation

## Rollback / Disable

If the canary behaves unexpectedly:

- immediately keep `SEO_INTEL_WRITE_ENABLED=false`
- keep scheduler disabled
- do not delete rows without separate approval
- use a separately approved forward-fix or ignore/mark policy for bad issue rows

## Next Task

`SEO-DASH-PROD-03D-PREFLIGHT-R2`
