# GLOBAL-EN-ZH-PARITY-CONTENT-ASSET-BATCH-01 Content Help Policy Import Package

## Executive Summary

This PR records the controlled content/help/policy parity batch for the remaining full-site EN/ZH parity train. It does not publish pages, mutate production CMS, deploy, submit URLs, or generate substantial English prose.

The batch confirms that some content/help/policy pages already have repo-backed authority or import-ready pairs, while company and policy gaps that require real English copy stay draft-review-only or deferred.

## Authority Boundary

- Backend `content_pages` remains the authority for company, help, support, and policy pages.
- `content_baselines/content_pages` is a repo-backed import/recovery package, not frontend runtime authority.
- fap-web fallback is not accepted as content authority.
- Draft, missing-authority, fallback-only, hard-404, soft-404, private, or noindex pages must not enter sitemap or llms.

## Batch Classification

Authority-backed or import-ready pairs:

- `about`
- `privacy`
- `terms`
- `help/about`
- `help/contact`
- `help/faq`

Draft-review-only English counterparts:

- `brand`
- `careers`
- `charter`
- `foundation`
- `policies`

Deferred missing authority:

- `support`

## What This PR Does Not Do

- It does not create or publish English body copy.
- It does not mutate production CMS.
- It does not expose any draft/import candidate in sitemap, llms, hreflang, URL Truth, or public runtime.
- It does not change fap-web.
- It does not submit URLs or run Search Channel actions.

## Validation

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=GlobalEnZhContentAssetBatch01 --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/global-en-zh-content-asset-batch-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 - <<'PY'
import yaml, pathlib
yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text())
print('yaml ok')
PY
git diff --check
git diff --cached --check
```

## Next Task

`GLOBAL-EN-ZH-PARITY-ARTICLE-COUNTERPART-BATCH-01`.
