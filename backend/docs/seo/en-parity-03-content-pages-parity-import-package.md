# EN-PARITY-03 Content Pages EN/ZH Parity Import Package

## Decision

EN-PARITY-03 lands a repository-backed content page parity package for foundational company, help, and policy pages. It does not create substantial new English prose, publish content, mutate production CMS, deploy, run production migrations, submit URLs, or modify fap-web.

The existing `content_baselines/content_pages` authority source already contains bilingual help pages and a smaller set of English company/policy pages. This PR strengthens the local baseline importer so those rows carry content-page translation group metadata when imported into a local or operator-controlled environment.

## Authority Boundary

- Backend `content_pages` remains the content authority.
- `content_baselines/content_pages` is an import/recovery package, not frontend runtime authority.
- fap-web fallback, sitemap, `llms.txt`, and production runtime observation are not accepted as content authority.
- Missing English counterparts stay explicit and must not be exposed in sitemap, llms, hreflang, or public URL Truth.

## Current Import Package

The generated JSON artifact records:

- existing EN/ZH authority-backed pairs that can be imported from current baselines;
- missing English counterpart candidates that require human-reviewed copy before import;
- support/root-route decisions that remain outside content authority until a real `content_pages` row exists;
- claim-boundary controls for about/support/help/company/policy pages.

This PR updates `content-pages:import-local-baseline` to preserve or derive:

- `translation_group_id`
- `source_locale`
- `translation_status`
- `page_type`
- `review_state`
- `seo_description`
- `canonical_path`

The importer remains operator-run. No production import is executed.

## Missing English Counterparts

The following Chinese baseline pages have no English content page counterpart in the current repository baseline and are deferred to human-reviewed draft/import work:

- `brand`
- `charter`

The package intentionally does not invent body copy for these pages. English `foundation`, `careers`, and `policies` now have repo-backed baseline authority and are no longer treated as deferred missing counterparts by this package.

## Validation

Focused validation:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=EnParity03 --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/en-parity-03-content-pages-parity-import-package.v1.json >/dev/null
python3 -c 'import yaml, json; yaml.safe_load(open("docs/codex/pr-train.yaml")); json.load(open("docs/codex/pr-train-state.json")); print("manifest/state parse ok")'
git diff --check
```

## Deferred Items

- Human-reviewed English copy for `brand` and `charter`.
- Production CMS import or publish approval.
- fap-web runtime restoration for hard/soft 404 pages.
- Sitemap, llms, JSON-LD, and FAQ parity gates remain EN-PARITY-07.

## Next Task

EN-PARITY-04 article English counterpart completion, under the no-mass-generation control rule.
