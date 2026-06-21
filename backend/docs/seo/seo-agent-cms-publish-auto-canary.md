# SEO Agent CMS Publish Auto Canary

Task: `SEO-AGENT-CMS-PUBLISH-AUTO-CANARY3-01`

This command upgrades the one-item publish canary into a low-risk ContentPage auto-canary wrapper. It delegates each selected item to `seo-agent:cms-publish-canary`, uses the original package SHA and draft-write evidence, and publishes at most three ContentPage drafts per execution.

## Command

Dry-run plan:

```bash
php artisan seo-agent:cms-publish-auto-canary \
  --package=<seo-agent-cms-draft-package-dry-run.v1.json> \
  --draft-write-evidence=<seo-agent-controlled-cms-draft-write.v1.json> \
  --limit=3 \
  --artifact-dir=/path/to/artifacts \
  --json
```

Low-risk canary execute:

```bash
php artisan seo-agent:cms-publish-auto-canary \
  --package=<seo-agent-cms-draft-package-dry-run.v1.json> \
  --draft-write-evidence=<seo-agent-controlled-cms-draft-write.v1.json> \
  --limit=3 \
  --artifact-dir=/path/to/artifacts \
  --auto-approve-low-risk \
  --execute \
  --json
```

## Scope

- Selects only candidates auto-approved by `seo-agent-auto-approval-policy.v1`.
- Supports only `target_model=content_page` in this phase.
- Allows only low-risk `cms_tdk_gap` and `cms_faq_gap` candidates.
- Publishes at most three ContentPage draft revisions.
- Requires the package SHA to match the draft-write evidence.
- Writes a standalone `seo-agent-cms-publish-auto-canary.v1` evidence artifact.

## Boundaries

- Article publish remains blocked.
- Bulk publish remains blocked.
- Search queue enqueue remains false.
- Search submit remains false.
- Google Indexing request remains false.
- Scheduler activation remains false.
- Queue worker activation remains false.
- No external model API is called.
- No full URL, raw query, raw body, credential path, client email, private key, token, cookie, or service-account JSON may appear in input or output artifacts.

