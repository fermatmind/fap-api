# SEO-DASH-COLLECTOR-01 Collector Dry-Run Readiness

## Purpose

SEO-DASH-COLLECTOR-01 defines the first post-migration collector readiness
contract after production `seo_intel` migrations have been deployed and run.

This PR is docs and contract only. It does not enable scheduler jobs, write to
production, connect external APIs, mutate CMS records, submit URLs to search
platforms, deploy, or modify `fap-web`.

Hard boundary: collector readiness means only explicit manual
`--dry-run --no-write --json` smoke commands are allowed.

## Dependency Boundary

Required completed state before any collector smoke:

- Production backend code contains the read-only API route family from
  `SEO-DASH-API-01`.
- Production backend is deployed to the reviewed SHA that contains
  `SEO-DASH-MIGRATION-01`.
- Production `seo_intel` migrations under `database/migrations/seo_intel` have
  been applied with the dedicated path.
- Collector defaults remain disabled:
  - `SEO_INTEL_COLLECTORS_ENABLED=false`
  - `SEO_INTEL_WRITE_ENABLED=false`
  - `SEO_INTEL_DRY_RUN_DEFAULT=true`
  - `SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED=false`

## Allowed Smoke Commands

Only bounded, explicit, no-write collector smoke is allowed:

```bash
php artisan seo-intel:collect --collector=noop --dry-run --no-write --json
php artisan seo-intel:collect --collector=url_truth_inventory --dry-run --no-write --json --canary
php artisan seo-intel:collect --collector=drift_foundation --dry-run --no-write --json --canary
php artisan seo-intel:collect --collector=crawler_log_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=attribution_revenue_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=gsc_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=baidu_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=indexnow_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=so360_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=sogou_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=shenma_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=chinese_crawler_log_foundation --dry-run --no-write --json --limit=5
php artisan seo-intel:collect --collector=issue_queue_foundation --dry-run --no-write --json --canary
```

Every allowed command must report:

- `dry_run=true`
- `writes_attempted=false`
- `writes_committed=false`
- `external_calls_attempted=false`

## Explicitly Forbidden

The following remain out of scope:

- scheduler activation
- queue worker activation for collectors
- persistent production writes
- `SEO_INTEL_COLLECTORS_ENABLED=true`
- `SEO_INTEL_WRITE_ENABLED=true`
- `SEO_INTEL_DRY_RUN_DEFAULT=false`
- crawler log aggregate writes
- GSC, Baidu, GA4, IndexNow, 360, Sogou, Shenma, Apify, ScreamingFrog, or other
  external API calls
- CMS draft, publish, unpublish, rollback, field mutation, or issue-summary
  writeback
- URL submission to Google, Baidu, IndexNow, 360, Sogou, Shenma, or `llms.txt`
  submission flows
- content generation, pSEO generation, or competitor scraping
- `fap-web` dashboard authority changes
- deployment or production env edits

## Readiness Checklist

Before a separate production dry-run smoke, confirm:

- deployed backend SHA matches the reviewed post-migration SHA
- `php artisan migrate:status --database=seo_intel --path=database/migrations/seo_intel`
  shows all isolated `seo_intel` migrations as ran
- `seo_crawler_log_daily_aggregates` exists and row count is expected for this
  stage
- `/api/v0.5/ops/seo-intel/*` route family is present and private routes still
  reject unauthenticated requests
- collector config defaults are disabled/no-write/dry-run
- scheduler does not contain `seo-intel:collect`
- no incident, deploy, CMS publish, or search submission is in progress

## No-Go Conditions

Stop if any condition is true:

- production SHA is not the reviewed post-migration SHA
- any `seo_intel` migration is pending
- collector write, scheduler, queue, external API, or CMS mutation flag would be
  enabled
- command omits `--dry-run`, `--no-write`, or `--json`
- command is unbounded for a non-noop collector
- command would read raw production crawler logs or persist raw PII
- smoke output reports writes or external calls
- search platform submission or CMS publication is bundled into the same
  operation

## Next Task

After this contract PR is merged, a separate approval-gated production
collector smoke may run the allowed commands above. The next PR or operation
must keep no-write/dry-run boundaries unless a later write-enablement PR is
explicitly authorized.

Next operation label: approval-gated production collector dry-run/no-write smoke.
