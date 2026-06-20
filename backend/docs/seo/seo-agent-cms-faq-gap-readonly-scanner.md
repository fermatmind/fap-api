# SEO Agent CMS FAQ Gap Readonly Scanner

`SEO-AGENT-CMS-FAQ-GAP-READONLY-SCANNER-01` adds a conservative CMS FAQ opportunity source for the FermatMind SEO Agent L4 loop.

Command:

```bash
php artisan seo-agent:cms-faq-gap-scan \
  --surface=all \
  --limit=100 \
  --artifact-dir=/path/to/artifacts \
  --json
```

Supported surfaces:

- `articles`
- `content-pages`
- `all`

The scanner only emits candidates for records with explicit FAQ or FAQ-schema signals. It does not assume every public page needs FAQ content.

Article signals:

- `article_seo_meta.schema_json` contains a `FAQPage` JSON-LD signal.
- `article_seo_meta.schema_json.editorial_package_v1.answer_surface_v1` exists but does not contain valid FAQ items.

ContentPage signals:

- `faq_schema_eligible=true`.
- `schema_enabled=true`.
- Help/support/FAQ identity from `kind`, `page_type`, `slug`, or `path`.

Boundaries:

- No CMS write.
- No DB write.
- No FAQ schema activation.
- No queue enqueue.
- No scheduler activation.
- No Search Channel submit.
- No indexing request.
- No raw body, schema payload, full URL, token, credential, or private key in artifacts.

The output candidate family is `cms_faq_gap`. Candidates are review-only and require Codex review before any later CMS draft dry-run package can be considered.
