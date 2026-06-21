# SEO Agent Post-Publish Search Submit

Task: `SEO-AGENT-POST-PUBLISH-SEARCH-SUBMIT-01`

This command bridges one successful SEO Agent CMS publish canary into Search Channel queue evidence. It accepts only `seo-agent-cms-publish-canary.v1` success evidence and only enqueues a URL that is already published, public, indexable, and present in URL Truth (`seo_urls`).

## Command

Dry-run plan:

```bash
php artisan seo-agent:post-publish-search-submit \
  --publish-evidence=<seo-agent-cms-publish-canary.v1.json> \
  --channels=indexnow,google_sitemap \
  --limit=1 \
  --json
```

Execute enqueue:

```bash
php artisan seo-agent:post-publish-search-submit \
  --publish-evidence=<seo-agent-cms-publish-canary.v1.json> \
  --channels=indexnow,google_sitemap \
  --limit=1 \
  --confirm-evidence-sha256=<publish-evidence-sha256> \
  --execute \
  --json
```

## Boundaries

- Writes only existing Search Channel queue tables through the existing queue writer.
- Does not mutate CMS.
- Does not publish CMS.
- Does not perform live Search Channel submission.
- Does not call Google Indexing API directly.
- Does not submit sitemap directly.
- Does not start scheduler or queue workers.
- Does not call external model APIs.
- Does not accept draft or uncommitted publish evidence.

## Google Indexing Boundary

The first implementation records the Google side as the existing `google_sitemap` Search Channel queue/readiness path. It does not call a live Google Indexing API from this command. A future live Google Indexing executor must be implemented and approved separately.
