# SEO Agent Post Publish IndexNow Auto

Task: `SEO-AGENT-POST-PUBLISH-INDEXNOW-AUTO-01`

This command is the L5-A post-publish submission step. It accepts successful SEO Agent CMS publish evidence, verifies the published ContentPage through URL Truth, writes an `indexnow` Search Channel queue item when needed, approves the bounded queue item, and submits it through the existing bounded live executor.

## Command

Dry-run plan:

```bash
php artisan seo-agent:post-publish-indexnow-auto \
  --publish-evidence=<seo-agent-cms-publish-canary-or-auto-canary.json> \
  --limit=3 \
  --artifact-dir=/path/to/artifacts \
  --json
```

Execute:

```bash
php artisan seo-agent:post-publish-indexnow-auto \
  --publish-evidence=<seo-agent-cms-publish-canary-or-auto-canary.json> \
  --limit=3 \
  --artifact-dir=/path/to/artifacts \
  --execute \
  --json
```

## Scope

- Accepts `seo-agent-cms-publish-canary.v1` and `seo-agent-cms-publish-auto-canary.v1`.
- Supports only published `content_page` refs.
- Uses URL Truth (`seo_urls`) as the canonical eligibility source.
- Writes/approves/submits only `indexnow` queue items.
- Submits at most three published URLs per run.
- Records queue item ids, provider status, HTTP status, URL hashes, and sanitized evidence.

## Boundaries

- No CMS write or publish.
- No Google Sitemap live submit.
- No Google Indexing live API call.
- No Baidu live submit.
- No scheduler activation.
- No queue worker activation.
- No frontend mutation.
- No external model API call.
- No full URL, raw query, raw body, credential path, client email, private key, token, cookie, or service-account JSON may appear in command output or evidence.

