# Command Capability Matrix

Command:

```bash
php artisan articles:import-seo-content-package-draft
```

## Required Parameters

| Requirement | Status |
|---|---|
| `--package=/path/to/content-package` | supported |
| `--translation-group-id=` | supported |
| `--locales=zh-CN,en` | supported |
| `--dry-run` | supported |
| `--json` | supported |
| `--draft-only` | supported and required |
| `--no-publish` | supported and required |
| `--no-index` | supported and required |
| `--no-sitemap` | supported and required |
| `--no-llms` | supported and required |
| `--schema-hold` | supported and required |
| `--hreflang-hold` | supported and required |
| `--expected-zh-slug=` | supported |
| `--expected-en-slug=` | supported |

## Output

JSON output includes:

- `ok`
- `dry_run`
- `action`
- `would_write`
- `translation_group_id`
- `articles[].article_id`
- `articles[].working_revision_id`
- `articles[].status`
- `articles[].locale`
- `articles[].slug`
- `articles[].preview_url_candidate`
- `errors`
- `warnings`

Dry-run reports candidate actions without writing DB rows.
