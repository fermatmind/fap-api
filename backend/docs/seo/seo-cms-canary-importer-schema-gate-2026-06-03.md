# SEO CMS Canary Importer Schema Gate - 2026-06-03

Task: `SEO-CMS-CANARY-IMPORTER-SCHEMA-GATE-01`

This change adds a pre-write schema-length guard to the controlled editorial package importer. It does not create CMS drafts, does not publish, does not call production write APIs, does not submit sitemap/Baidu/IndexNow, and does not modify GPT-5.5 Pro content or adapter prose.

## Context

`SEO-CONTENT-P1-08` draft-only execution partially completed in production:

- zh-CN draft creation succeeded and remains draft-only.
- en draft creation failed before completion when `article_seo_meta.seo_title` exceeded the production column limit.
- No English partial Article row was left behind.
- Public article pages and public APIs remained absent, and publish stayed forbidden.

The blocker showed that the importer dry-run did not previously model all fixed DB column length constraints before a real import.

## Gate

The importer now validates fixed-length DB-backed fields before any write plan can proceed:

- `author`
- `title`
- `slug`
- `locale`
- `translation_group_id`
- `cover_image`
- `cover_image_alt`
- `seo_title`
- `meta_description`
- `canonical`
- `target_tests` slugs used for Article test placement

When a field exceeds its DB-backed limit, dry-run returns `schema_length_exceeded`, `action=will_skip`, `would_write=false`, and no Article, revision, SEO meta, or import telemetry row is created.

## Current Canary Impact

The approved English adapter still preserves the GPT-5.5 Pro source content exactly. Because the English `seo_title` and `meta_description` exceed current production SEO meta limits, the adapter should now fail dry-run with explicit validation errors instead of failing during a real import write.

This PR does not shorten, rewrite, or mechanically adapt the English content package. A follow-up authorization is required to decide whether to:

- create a mechanical adapter metadata adjustment that preserves content authority, or
- change backend schema/authority limits through a separate runtime/schema PR.

## Validation

Required checks:

```bash
cd backend && php artisan test tests/Feature/Console/ArticleImportEditorialPackageCommandTest.php
php backend/artisan articles:import-editorial-package --file=docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.importer-adapter.json --locale=en --dry-run --json
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/app/Services/Cms/EditorialPackage backend/tests/Feature/Console/ArticleImportEditorialPackageCommandTest.php backend/docs/seo docs/codex
git diff --cached --check
```

## Deferred

- No CMS draft creation.
- No publish.
- No GPT content edits.
- No sitemap, Baidu, IndexNow, or Search Channel action.
- No frontend changes.
