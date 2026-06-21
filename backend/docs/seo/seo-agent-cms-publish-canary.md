# SEO Agent CMS Publish Canary

Task: `SEO-AGENT-CMS-PUBLISH-CANARY-01`

This command promotes at most one low-risk SEO Agent CMS draft into the live ContentPage read path. It is the first bounded publish step after the draft writer and remains separate from search queue, Google Indexing, scheduler, and queue workers.

## Command

Dry-run plan:

```bash
php artisan seo-agent:cms-publish-canary \
  --package=<seo-agent-cms-draft-package-dry-run.v1.json> \
  --draft-write-evidence=<seo-agent-controlled-cms-draft-write.v1.json> \
  --limit=1 \
  --json
```

Low-risk canary execute:

```bash
php artisan seo-agent:cms-publish-canary \
  --package=<seo-agent-cms-draft-package-dry-run.v1.json> \
  --draft-write-evidence=<seo-agent-controlled-cms-draft-write.v1.json> \
  --limit=1 \
  --confirm-package-sha256=<package-sha256> \
  --auto-approve-low-risk \
  --execute \
  --json
```

## Scope

- Publishes at most one ContentPage SEO Agent draft revision.
- Requires the package SHA to match the draft write evidence.
- Requires the draft write evidence to show a successful controlled draft write.
- Requires low-risk source families only: `cms_tdk_gap` and `cms_faq_gap`.
- Requires allowed target fields only: SEO title, SEO description, canonical path, indexability metadata, FAQ items, and FAQ schema eligibility.
- Creates rollback evidence before mutating the live ContentPage row.
- Marks claim gate as passed only after a deterministic forbidden-claim scan of proposed SEO fields.

## Boundaries

- Article publish canary is not supported in v1 because the current draft writer stores Article proposals in isolated `article_revisions`, while the existing Article publish path uses `article_translation_revisions`.
- Search queue enqueue remains false.
- Search submit remains false.
- Google Indexing request remains false.
- Scheduler activation remains false.
- Queue worker activation remains false.
- No external model API is called.
- No full URL, raw query, raw body, credential path, client email, private key, token, cookie, or service-account JSON may appear in input or output artifacts.
