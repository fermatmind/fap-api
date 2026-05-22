# CRAWLER-LOG-03 Aggregate Dry-run / No-write

## Purpose

CRAWLER-LOG-03 adds an aggregate dry-run path for crawler log observability using only the synthetic fixture from CRAWLER-LOG-02. It proves sanitized parser rows can be grouped into V1 aggregate rows before any production crawler log read, database write, scheduler, collector write, or production canary.

Crawler logs remain aggregate observability only. Crawler logs are not URL Truth, not Search Channel Queue, not CMS authority, not canonical truth, not indexability truth, and not an issue auto-fix system.

## What Changed

- Added `CrawlerLogAggregateDryRun` under `App\Services\SeoIntel\CrawlerLog`.
- Added `php artisan seo-intel:crawler-log-observe` with fixture-only dry-run/no-write behavior.
- Added focused tests for aggregation, command output, no raw persistence, blocked non-fixture mode, and command option boundaries.
- Added generated contract artifact for the aggregate dry-run/no-write MVP.

## Command Contract

Supported command:

```bash
php artisan seo-intel:crawler-log-observe --fixture --dry-run --no-write --json --limit=20
```

Allowed options:

- `--fixture`
- `--dry-run`
- `--no-write`
- `--json`
- `--limit`

Forbidden modes remain unavailable in V1:

- production log read
- tail mode
- schedule mode
- write mode
- submit mode

Running the command without `--fixture` is blocked and still reports:

- `dry_run=true`
- `no_write=true`
- `writes_attempted=false`
- `writes_committed=false`
- `production_log_read_attempted=false`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `raw_persistence=false`

## Aggregate Shape

The dry-run service groups sanitized fixture rows by the V1 aggregate dimensions:

- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `bot_verification_state`
- `route_family`
- `page_entity_type`
- `canonical_path`
- `path_hash`
- `http_status`
- `method_bucket`
- `query_present`
- `query_risk_state`
- `private_path_blocked`
- `source_log_family`
- `privacy_transform_version`

Aggregate output adds:

- `hit_count`
- `first_seen_at`
- `last_seen_at`

The intended future target table is `seo_crawler_logs_daily`, but this PR does not write it and does not alter its schema.

## Privacy Boundary

The command and service use sanitized fixture rows only. They do not output or persist:

- raw IP / remote address
- raw user-agent
- raw request URI
- raw query string
- cookie
- headers
- authorization
- session IDs
- tokens / API keys
- emails
- order IDs
- attempt IDs
- payment IDs
- provider event IDs
- raw payloads
- raw log lines
- `event_payload`
- `metadata_json`
- `attributes_json`

Private paths, API paths, Ops paths, static assets, and unknown public paths remain hash-only and cannot become SEO candidates.

## Safety Flags

- no production crawler log read
- no database writes
- no collector writes
- no scheduler
- no migration
- no deployment
- no env edit
- no external search API call
- no search submission
- no Metabase exposure
- no business DB / Tencent RDS / Node2 access
- no URL Truth creation
- no Search Channel Queue creation
- no issue queue auto-write

Privacy transform version: `crawler_log_privacy_transform_v1`.

## Validation

Required local checks:

```bash
cd backend && php artisan test --filter=SeoIntelCrawlerLogAggregateDryRun
cd backend && php artisan test --filter=SeoIntel
cd backend && php artisan seo-intel:crawler-log-observe --fixture --dry-run --no-write --json --limit=20
cd backend && php artisan route:list --no-ansi
cd backend && vendor/bin/pint --test
python3 -m json.tool backend/docs/seo/generated/crawler-log-aggregate-dry-run.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text())"
git diff --check
git diff --cached --check
```

## Next Task

`CRAWLER-LOG-04｜Production canary preflight`
