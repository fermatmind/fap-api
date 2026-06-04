# SEO-DASH-COLLECTOR-01 Smoke Evidence

## Purpose

SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE records the production collector
dry-run/no-write smoke that was executed after the post-migration readiness
contract was merged and the user gave exact approval.

This PR records evidence only. It does not run collectors, enable scheduler
jobs, write production rows, connect external APIs, mutate CMS records, submit
URLs to search platforms, deploy, edit production env, or modify `fap-web`.

## Approval Boundary

The approved production operation was limited to:

- read-only production verification
- the allowed `SEO-DASH-COLLECTOR-01` collector commands
- `--dry-run --no-write --json` only
- no scheduler enablement
- no production writes
- no external API calls
- no CMS mutation
- no search submission
- no deployment or production env edit

## Pre-Smoke Evidence

- Production backend SHA: `619ce5881cbb63200568796c07467aacd66b52c2`.
- `/api/v0.5/ops/seo-intel/*` route family was present with 5 private routes.
- `seo_intel` migrations under `database/migrations/seo_intel` were all `Ran`.
- Collector defaults remained disabled:
  - `collectors_enabled=false`
  - `write_enabled=false`
  - `dry_run_default=true`
  - `allow_external_api_calls=false`
  - `crawler_log_aggregate_write_enabled=false`
- Scheduler scan found no `seo-intel:collect` activation in scheduler entry
  points.
- `seo_crawler_log_daily_aggregates` existed with `0` rows.
- Private overview without token returned HTTP `401`.
- Public MBTI scale lookup returned HTTP `200`.

## Smoke Results

All 13 approved collectors completed successfully.

| Collector | Status | Items seen | Issues |
| --- | --- | ---: | --- |
| `noop` | `success` | 0 | none |
| `url_truth_inventory` | `success` | 52 | none |
| `drift_foundation` | `success` | 12 | `missing_in_sitemap`, `extra_in_sitemap`, `missing_in_llms`, `private_flow_exposure_warning` |
| `crawler_log_foundation` | `success` | 1 | none |
| `attribution_revenue_foundation` | `success` | 5 | `fixture_only_no_live_data_read`, `writes_blocked_by_default` |
| `gsc_foundation` | `success` | 2 | `fixture_only_no_live_gsc_api_call`, `writes_blocked_by_default` |
| `baidu_foundation` | `success` | 3 | `fixture_only_no_live_baidu_api_call`, `real_url_submission_blocked`, `draft_url_rejected`, `non_indexable_rejected` |
| `indexnow_foundation` | `success` | 3 | `fixture_only_no_live_indexnow_api_call`, `real_url_submission_blocked`, `private_flow_rejected` |
| `so360_foundation` | `success` | 4 | `fixture_only_no_live_so360_api_call`, `real_url_submission_blocked`, `engine_specific_page_generation_blocked`, `draft_url_rejected`, `non_indexable_rejected`, `private_flow_rejected`, `private_flow_entity_type_rejected` |
| `sogou_foundation` | `success` | 4 | `fixture_only_no_live_sogou_api_call`, `real_url_submission_blocked`, `engine_specific_page_generation_blocked`, `draft_url_rejected`, `non_indexable_rejected`, `private_flow_rejected`, `private_flow_entity_type_rejected` |
| `shenma_foundation` | `success` | 4 | `fixture_only_no_live_shenma_api_call`, `real_url_submission_blocked`, `engine_specific_page_generation_blocked`, `draft_url_rejected`, `non_indexable_rejected`, `private_flow_rejected`, `private_flow_entity_type_rejected` |
| `chinese_crawler_log_foundation` | `success` | 5 | `fixture_only_no_production_log_read`, `raw_ip_storage_blocked`, `raw_cookie_storage_blocked`, `raw_user_agent_storage_blocked`, `private_flow_crawler_hit_warning`, `noindex_crawler_hit_warning` |
| `issue_queue_foundation` | `success` | 3 | `fixture_only_no_live_data_read`, `cms_mutation_blocked`, `auto_publish_blocked`, `auto_pseo_blocked`, `raw_pii_blocked` |

Every collector reported:

- `dry_run=true`
- `writes_attempted=false`
- `writes_committed=false`
- `external_calls_attempted=false`

## Post-Smoke Evidence

- Production backend SHA still matched
  `619ce5881cbb63200568796c07467aacd66b52c2`.
- Collector defaults still blocked writes and external calls:
  - `collectors_enabled=false`
  - `write_enabled=false`
  - `dry_run_default=true`
  - `allow_external_api_calls=false`
  - `crawler_log_aggregate_write_enabled=false`
- `seo_crawler_log_daily_aggregates` still existed with `0` rows.

## Interpretation

The smoke proves the collector command family can execute the approved
readiness path without production writes or external calls. It does not approve
scheduled operation, write enablement, live search API integration, CMS
writeback, search submission, or production data ingestion.

## Next Task

Proceed to `SEO-DASH-COLLECTOR-02` only as a separate PR. That PR should define
the next collector runbook or controlled write gate without enabling writes by
default.
