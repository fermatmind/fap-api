# SEO Agent GSC Post-publish Feedback

`seo-agent:gsc-post-publish-feedback` is the L5-A read-only feedback step after a bounded CMS canary publish. It accepts successful SEO Agent publish evidence, resolves published ContentPage targets through URL Truth, reads already-imported `seo_intel.seo_gsc_daily` rows, and classifies each target as `improved`, `flat`, `declined`, or `insufficient_data`.

## Command

```bash
php artisan seo-agent:gsc-post-publish-feedback \
  --publish-evidence=<seo-agent-cms-publish-canary-or-auto-canary.json> \
  --window=7 \
  --artifact-dir=/var/www/fap-api/current/backend/storage/app/seo-agent/gsc-post-publish-feedback \
  --json
```

Allowed windows are `7`, `14`, and `28` days.

## Contract

- Accepts `seo-agent-cms-publish-canary.v1` and `seo-agent-cms-publish-auto-canary.v1` success evidence.
- Only evaluates published or already-published `content_page` affected refs.
- Resolves targets through `seo_urls`; it does not trust raw publish paths alone.
- Reads `seo_gsc_daily` rows where `source_engine=google` and row metadata has `data_origin=live_gsc_api`.
- Compares the selected pre/post publish windows using clicks, impressions, CTR ppm, and average position milli.
- Writes a sanitized evidence artifact only.

## Boundaries

This command does not call Google Search Console live APIs, Google Indexing APIs, IndexNow, Baidu, Search Channel enqueue/submit, CMS write/publish paths, scheduler, queue workers, or frontend code paths. It does not write `seo_gsc_daily`; it only consumes rows already imported through the approved GSC sidecar/read-model flow.

## Follow-up

The feedback artifact is advisory input for `SEO-AGENT-AUTO-ROLLBACK-GUARD-01` and later L5 run scoring. It does not pause, rollback, or modify automation state by itself.
