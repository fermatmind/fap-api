# CRAWLER-LOG-05 Production Canary Report

## Executive Summary

CRAWLER-LOG-05 records the first human-approved crawler log production canary result as a read-only observation artifact.

The canary runtime was deployed to the production backend release at commit `84d48b2cfc15ac48a7999e2b0af56c1b4a3626bd`. The approved source, host, and execution shell are redacted from repository docs and recorded in approved ops inventory.

This report does not expand crawler log collection. It does not add a scheduler, collector write, database write, issue queue write, URL Truth mutation, Search Channel Queue enqueue, or search submission.

## Deployment / Runtime Confirmation

- Production runtime host: redacted from repository docs
- Runtime shell: redacted from repository docs
- Runtime command: `seo-intel:crawler-log-observe`
- Deployed release SHA: `84d48b2cfc15ac48a7999e2b0af56c1b4a3626bd`
- Canary mode: `single_source_production_canary_dry_run`
- Source: redacted from repository docs
- Limit: `1000`
- Required approval phrase verified: yes

## Canary Execution Result

The first execution as `ubuntu` failed closed because the source file was not readable by that user. That blocked attempt read zero production log lines and committed no writes.

The second execution used the same approved command under `sudo -n` to read the same single source. It completed successfully as dry-run/no-write only.

Sanitized successful summary:

- Status: `success`
- Parsed line count: `1000`
- Sanitized row count: `1000`
- Aggregate row count: `154`
- Blocked private path count: `956`
- Unknown bot count: `1`
- Safe public canonical path count: `0`
- Target table: `seo_crawler_logs_daily`
- Target table write attempted: `false`
- Target table write committed: `false`

## Aggregate Breakdowns

Bot family:

- `non_bot`: `999`
- `unknown_bot`: `1`

Route family:

- `blocked_private_path`: `956`
- `static_asset`: `6`
- `unknown_public_path`: `38`

Surface family:

- `blocked_private`: `956`
- `static_asset`: `6`
- `unknown`: `38`

HTTP status:

- `200`: `786`
- `202`: `5`
- `204`: `2`
- `400`: `7`
- `401`: `127`
- `404`: `67`
- `405`: `1`
- `422`: `1`
- `428`: `4`

Method bucket:

- `GET`: `889`
- `HEAD`: `3`
- `OTHER`: `108`

Query risk state:

- `none`: `176`
- `unknown_query_present`: `824`

## Privacy Boundary

CRAWLER-LOG-05 records only aggregate summary values. It does not persist or print raw crawler log lines.

Forbidden persistent and report fields remain:

- raw IP / remote address
- raw user agent
- raw request URI
- raw query string
- cookies
- headers
- authorization values
- session IDs
- tokens / API keys
- emails
- order IDs
- payment IDs
- attempt IDs
- provider event IDs
- raw payloads
- raw log lines
- event payload blobs
- metadata JSON blobs
- attributes JSON blobs

The successful canary output confirmed:

- `writes_attempted=false`
- `writes_committed=false`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `scheduler_enabled=false`
- `collector_write_attempted=false`
- `raw_persistence=false`

## URL Truth Boundary

Crawler logs remain aggregate observability only.

This report does not allow crawler logs to:

- create `seo_urls`
- decide canonical truth
- decide indexability truth
- override CMS/backend URL Truth
- create Search Channel Queue items
- create issue queue rows automatically
- submit URLs to search engines

The observed `safe_public_canonical_path_count=0` means this canary did not identify any safe public canonical URL candidate in the sampled 1000 lines. It is not evidence that public crawler traffic is absent globally; it is only the result of this bounded single-source sample.

## Operational Interpretation

The sampled log slice was dominated by private-flow or blocked-private classification. That is useful as a privacy-boundary signal, but it is not a URL eligibility signal.

The right next step is not to widen production log access. The next step is to keep the collection model aggregate-only and define an observation loop that can summarize future bounded canaries without raw persistence.

## Follow-up Observation Contract

Any follow-up crawler log observation task must remain:

- single approved source unless a new task explicitly approves another source
- bounded by a declared maximum line count
- dry-run/no-write until a separate aggregate storage approval exists
- aggregate-only
- no raw persistence
- no issue queue auto-write
- no URL Truth mutation
- no Search Channel Queue enqueue
- no scheduler
- no search submission
- no Metabase exposure

## What Was Not Done

- No crawler log scheduler was added.
- No collector write was run.
- No database write was committed.
- No issue queue row was created.
- No URL Truth row was created or modified.
- No Search Channel Queue row was created.
- No GSC/Baidu/IndexNow or other search API was called.
- No URL was submitted to a search engine.
- No raw log line was persisted.
- No raw IP, raw UA, raw URI, query string, cookie, token, email, order ID, payment ID, or attempt ID was stored.

## Final Decision

`crawler_log_production_canary_report_recorded_ready_for_aggregate_observation_design`

## Next Task

`CRAWLER-LOG-06｜Aggregate observation design`
