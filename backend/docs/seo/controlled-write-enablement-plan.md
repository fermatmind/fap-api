# SEO Intelligence Controlled Write Enablement Plan

## A. Purpose

This plan governs the first production writes into `seo_intel`.

This PR does not enable writes. It does not enable scheduler execution. It does not deploy Metabase. It does not connect live external APIs, submit URLs to search engines, read production crawler logs, edit production environment files, or change runtime behavior.

## B. Current Readiness State

- Production `seo_intel` schema exists.
- SEO-DASH-PROD-01B ran the isolated production migration successfully.
- SEO-DASH-PROD-02 production collector dry-run smoke passed.
- Production writes remain disabled.
- Scheduler remains disabled.
- Live external APIs remain disabled.
- Metabase remains undeployed.

## C. Write Enablement Principles

- Write enablement is manual, explicit, and reversible.
- No scheduler may be enabled until repeated manual write smokes pass.
- No external API collector write may run before credentials and live API readiness are explicitly approved.
- No production crawler log collector write may run before production log access is explicitly approved.
- No Node2 local Laravel or Node2 local DB may be used as a data source.
- No business DB raw tables may be used as a Metabase source.
- No PII or raw detail values may be stored in `seo_intel` detail fields.
- The migration operator identity is not a runtime collector identity.

## D. Collector Write Tiers

### Tier 0: Dry-run Only / Always Safe

- `noop`
- Any collector invoked with both `--dry-run` and `--no-write`

### Tier 1: First Controlled Write Candidates

- `url_truth_inventory`
- `issue_queue_foundation` only if it writes sanitized fixture or governance-safe issue rows; otherwise it remains dry-run only.
- `attribution_revenue_foundation` only if it reads existing safe backend events/orders through an approved aggregate builder and does not expose raw identifiers.

### Tier 2: Later Controlled Write After Tier 1 Success

- `drift_foundation`
- `crawler_log_foundation` with fixture-only or non-production sample input only
- `chinese_crawler_log_foundation` with fixture-only input only

### Tier 3: Blocked Until External / Live Credentials Approved

- `gsc_foundation`
- `baidu_foundation`
- `indexnow_foundation`
- `so360_foundation`
- `sogou_foundation`
- `shenma_foundation`

### Tier 4: Blocked Until Production Log-read Approval

- Any production crawler log read
- Any CDN, OpenResty, or Nginx production log ingestion

## E. First Canary Recommendation

The recommended first actual write canary is `url_truth_inventory`.

The canary must be:

1. One manual run only.
2. No scheduler.
3. No external API.
4. No production crawler logs.
5. Verified only against `seo_urls` and `seo_url_entities`.
6. Checked for no forbidden PII columns or values.
7. Checked for canonical URL, locale, and page entity type shape.
8. Checked so no private flow URLs are accepted as indexable.

If `url_truth_inventory` cannot produce a bounded, reviewable, nonzero canary write from approved backend authority inputs, actual write execution is blocked until bounded canary write support is added.

Current implementation note: the backend authority URL truth source is still fixture-driven/skeleton in current source, so SEO-DASH-PROD-03B must confirm bounded canary support before any production write.

## F. Required Env Posture for Actual Write Canary

Use a temporary migration/runtime shell or explicit one-time command env only:

```bash
SEO_INTEL_ENABLED=true
SEO_INTEL_COLLECTORS_ENABLED=true
SEO_INTEL_WRITE_ENABLED=true
SEO_INTEL_DRY_RUN_DEFAULT=false
SEO_INTEL_GSC_ENABLED=false
SEO_INTEL_BAIDU_ENABLED=false
SEO_INTEL_INDEXNOW_ENABLED=false
SEO_INTEL_SO360_ENABLED=false
SEO_INTEL_SOGOU_ENABLED=false
SEO_INTEL_SHENMA_ENABLED=false
SEO_INTEL_CHINESE_CRAWLER_LOGS_ENABLED=false
```

Do not edit production service env permanently. Do not start queue workers or scheduler. Do not enable live external API flags. Do not enable production log-read flags.

## G. Actual Write Command Template

Use placeholders only and run from the verified migration/runtime host:

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
php artisan seo-intel:collect --collector=url_truth_inventory --json
```

The current collector command has explicit safety flags for dry-run prevention of writes: `--dry-run` and `--no-write`. The actual write canary must omit those dry-run/no-write flags only after human approval and only with the temporary env posture above.

Forbidden commands:

- Collector command without an explicit collector name.
- Any all-collectors wildcard or loop.
- Scheduler-triggered collector execution.
- Live GSC, Baidu, IndexNow, 360, Sogou, or Shenma collectors.
- Production crawler log collectors.

## H. Verification After Canary

After the canary, verify:

- Row counts before and after.
- Only `seo_urls` and `seo_url_entities` changed for the first canary.
- No business DB tables changed.
- No forbidden PII values appear in any `seo_intel` table.
- No raw email, order number, attempt id, payment id, provider event id, cookie, raw IP, raw user agent, token, secret, raw payload, payment payload, or provider payload is present.
- No external API calls occurred.
- No URL submissions occurred.
- No production crawler logs were read.
- No scheduler state changed.
- No Metabase deployment or connection was created.

## I. Rollback / Disable Procedure

- Immediately set `SEO_INTEL_WRITE_ENABLED=false`.
- Keep scheduler disabled.
- Do not rollback schema.
- If canary rows are wrong, mark or ignore them, or perform a forward-cleanup with separate human approval.
- Do not run destructive delete, truncate, rollback, or refresh commands without separate approval.
- A restore/forward-fix owner is required before write canary approval.

## J. Human Approval Gates

Explicit human approval is required before:

- First write canary.
- Enabling any additional collector.
- Enabling scheduler.
- Enabling live external APIs.
- Enabling production crawler log read.
- Deploying Metabase.

## K. Next Task

Next task: `SEO-DASH-PROD-03B`.

If bounded `url_truth_inventory` canary support is confirmed, the task is `SEO-DASH-PROD-03B｜url_truth_inventory controlled write canary`.

If bounded canary support is missing or the current source still emits no approved candidates, the task is `SEO-DASH-PROD-03B｜add bounded canary write support`.
