# SEO-DASH-COLLECTOR-02 Controlled Collector Write Gate

## Purpose

SEO-DASH-COLLECTOR-02 reconciles the completed collector dry-run/no-write smoke
with the older controlled write enablement plan from `SEO-DASH-PROD-03A`.

This PR is docs and contract only. It does not enable writes, scheduler
execution, queue workers, live external APIs, production crawler log reads,
Metabase, CMS mutation, search submission, deployment, production env edits, or
`fap-web` changes.

## Source Evidence

- `SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE`: 13 collectors completed the approved
  production smoke with `--dry-run --no-write --json`.
- PR #1882 merge commit:
  `1b88323eb05d579e847d37b0e89ed24464b1924c`.
- `SEO-DASH-PROD-03A`: controlled write enablement plan already defines the
  original write tiers, rollback posture, and human approval gates.
- `url-truth-inventory-bounded-canary.v1.json`: confirms bounded
  `url_truth_inventory` canary support in source contracts.

## Collector Write Eligibility

### Tier 0: Dry-run Only

- `noop`
- Any collector with both `--dry-run` and `--no-write`

### Tier 1: First Controlled Write Candidate

Only `url_truth_inventory` is eligible for the first controlled write canary.

Required command shape:

```bash
SEO_INTEL_ENABLED=true \
SEO_INTEL_COLLECTORS_ENABLED=true \
SEO_INTEL_WRITE_ENABLED=true \
SEO_INTEL_DRY_RUN_DEFAULT=false \
SEO_INTEL_GSC_ENABLED=false \
SEO_INTEL_BAIDU_ENABLED=false \
SEO_INTEL_INDEXNOW_ENABLED=false \
SEO_INTEL_SO360_ENABLED=false \
SEO_INTEL_SOGOU_ENABLED=false \
SEO_INTEL_SHENMA_ENABLED=false \
SEO_INTEL_CHINESE_CRAWLER_LOGS_ENABLED=false \
php artisan seo-intel:collect --collector=url_truth_inventory --json --canary
```

This command is not approved by this PR. It is only the future command template.

The future production write approval phrase must include the exact backend SHA,
the collector name, the `--canary` bound, and the statement that no scheduler,
external API, CMS mutation, search submission, deployment, or env file edit is
approved.

## Batch Limits

- First write canary: `--canary` only.
- Default canary limit: 10.
- Hard maximum limit: 50.
- Unbounded write mode is forbidden.
- A command without an explicit `--collector` is forbidden.
- Any all-collector loop, wildcard, scheduler trigger, or queue-worker trigger is
  forbidden.

## Deferred Collectors

The following collectors remain blocked for controlled writes until a separate
bounded write/read-model gate is approved:

- `issue_queue_foundation`
- `drift_foundation`
- `crawler_log_foundation`
- `attribution_revenue_foundation`
- `chinese_crawler_log_foundation`

The following collectors remain blocked until live credentials and external API
readiness are separately approved:

- `gsc_foundation`
- `baidu_foundation`
- `indexnow_foundation`
- `so360_foundation`
- `sogou_foundation`
- `shenma_foundation`

Production crawler log ingestion remains blocked until production log-read
approval is separately granted.

## Verification Requirements

Before the future write canary:

- Confirm deployed backend SHA.
- Confirm `seo_intel` migrations are all ran.
- Confirm scheduler has no `seo-intel:collect` activation.
- Capture row counts for `seo_urls` and `seo_url_entities`.
- Confirm `SEO_INTEL_WRITE_ENABLED=false` in the normal service posture.
- Confirm no external API flags are enabled.

After the future write canary:

- Verify only `seo_urls` and `seo_url_entities` changed.
- Verify row count delta is bounded by the canary limit.
- Verify no business DB table changed.
- Verify no raw email, order number, attempt id, payment id, provider event id,
  cookie, raw IP, raw user agent, token, secret, raw payload, payment payload, or
  provider payload was stored.
- Verify no external API calls occurred.
- Verify no URL submissions occurred.
- Verify no CMS mutation occurred.
- Verify scheduler remains disabled.

## Rollback And Stop Conditions

First rollback action: set `SEO_INTEL_WRITE_ENABLED=false`.

Do not rollback schema. Do not truncate, delete, or refresh rows without a
separate approval. Wrong canary rows require either a forward cleanup or ignore
policy with a named owner and separate approval.

Stop immediately if:

- production SHA is not the approved SHA
- scheduler is enabled
- external API flags are enabled
- command is unbounded
- command targets any collector other than `url_truth_inventory`
- output reports unexpected target tables
- output reports external calls, CMS mutation, search submission, or PII storage

## Next Task

Next task: approval-gated `url_truth_inventory` controlled write canary
preflight. No production write is approved by this PR.
