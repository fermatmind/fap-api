# SEO Agent Controlled CMS Draft Writer

Task: `SEO-AGENT-CONTROLLED-CMS-DRAFT-WRITER-01`

This command converts an approved `seo-agent-cms-draft-package-dry-run.v1` package into bounded CMS draft revisions. It defaults to a dry-run write plan. Execute mode requires the package SHA, an exact confirmation phrase, and `--limit=1..10`.

## Command

```bash
php artisan seo-agent:cms-draft-write --package=<path> --limit=1 --json
```

Execute mode:

```bash
php artisan seo-agent:cms-draft-write \
  --package=<path> \
  --limit=1 \
  --confirm-package-sha256=<sha256> \
  --confirm-write="<exact phrase from dry-run output>" \
  --execute \
  --json
```

## Boundaries

- Article proposals create isolated `article_revisions.payload_json` revisions.
- ContentPage proposals create `cms_translation_revisions.payload_json` draft revisions.
- The writer does not publish, mutate published revision pointers, enqueue search, submit indexing, start scheduler jobs, start queue workers, or call external model APIs.
- Idempotency is `subject_ref + package_sha256 + sorted target_fields`.
