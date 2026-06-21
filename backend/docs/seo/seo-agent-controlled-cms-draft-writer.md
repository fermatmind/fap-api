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

Low-risk auto-approved batch mode:

```bash
php artisan seo-agent:cms-draft-write \
  --package=<path> \
  --limit=10 \
  --auto-approve-low-risk \
  --execute \
  --json
```

## Boundaries

- Article proposals create isolated `article_revisions.payload_json` revisions.
- ContentPage proposals create `cms_translation_revisions.payload_json` draft revisions.
- Low-risk auto approval is limited to CMS TDK/FAQ/canonical/indexability proposals with `source_family=cms_tdk_gap|cms_faq_gap`, `severity=p1|p2`, and allowed target fields.
- The writer does not publish, mutate published revision pointers, enqueue search, submit indexing, start scheduler jobs, start queue workers, or call external model APIs.
- Idempotency is `subject_ref + package_sha256 + sorted target_fields`.
