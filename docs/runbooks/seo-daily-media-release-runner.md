# SEO Daily Media Release Runner

This runbook hardens the Media Library stage that runs before CMS draft import for daily SEO articles.

## Production Runner Requirements

- Run Media Library write stages only from the production runner.
- Required media runtime:
  - `APP_ENV=production`
  - `FAP_MEDIA_ASSET_ORIGIN=https://assets.fermatmind.com`
  - `FAP_MEDIA_OSS_SYNC_ENABLED=true`
  - `FAP_MEDIA_CDN_VERIFY_ENABLED=true`
  - `FAP_MEDIA_OSS_DISK` points to a configured filesystem disk.
  - `FAP_MEDIA_OSS_KEY_PREFIX=storage`
- Keep OSS credentials in the runner environment only. Do not commit secrets, resolved credential values, private URLs, or temporary key files.

## Required Sequence

1. Preflight package, manifest, existing asset state, and production media runtime:

```bash
php artisan media-assets:seo-release-preflight \
  --package=/path/to/stage4-package \
  --translation-group-id=tg_article_example_2026v1 \
  --locales=zh-CN,en \
  --expected-asset-prefix=article.example.topic \
  --json
```

2. Run importer dry-run:

```bash
php artisan media-assets:import-seo-image-bundle \
  --package=/path/to/stage4-package \
  --translation-group-id=tg_article_example_2026v1 \
  --locales=zh-CN,en \
  --expected-asset-prefix=article.example.topic \
  --dry-run \
  --write-resolved-package \
  --json
```

3. Write/resume Media Library assets and create the resolved CMS-ready package:

```bash
php artisan media-assets:import-seo-image-bundle \
  --package=/path/to/stage4-package \
  --translation-group-id=tg_article_example_2026v1 \
  --locales=zh-CN,en \
  --expected-asset-prefix=article.example.topic \
  --allow-update-existing \
  --write-resolved-package \
  --resolved-output-dir=/path/to/resolved-package \
  --json
```

4. Continue only after the resolved package contains canonical `https://assets.fermatmind.com/...` image URLs. Then proceed to CMS draft import, preview QA, controlled publish, URL Truth, Search Channel, GSC Request Indexing, and D1/D7/D14 observation.

## Discoverability Boundary

The Media Library runner is responsible only for source image validation, CDN
availability, variant generation, and CMS image metadata readiness.

`/llms-full.txt` complete/degraded state is a fap-web public runtime
artifact/cache concern, not a Media Library or backend importer concern. After
article publish, `llms-full` parity and stabilization must be handled by the
fap-web public verifier plus the content-release revalidation / llms-full warm
gate when needed.

Do not try to repair `/llms-full.txt` degraded mode through the Media Library
importer, CMS image backfill, or the SEO content package draft importer.

## Package Normalization And Body Visual Boundary

The Media Library stage accepts an importer-compatible Stage 4 package; it does
not repair package file names, synthesize missing CMS field projections, shorten
metadata, or reconcile article/FAQ/CTA/internal-link identity. Stage 4 must
provide one `FINAL_DERIVED_IMPORT_READY_PACKAGE` with source and derived hashes
and a deterministic change ledger before this runner starts.

The runner owns source validation, Media Library registration, CDN availability,
variant generation, asset keys, and CMS image metadata readiness. Those facts
do not prove that a required body visual is rendered in preview or in the public
article body. Preview/public body visual parity remains a downstream article
release gate and must verify the markdown/body projection, body anchor, answer
block, public URL, and closeout URL count.

Do not use Media Library import or image metadata backfill to repair Mode C
file naming, CMS field mapping, article content, or `/llms-full.txt` runtime
state.

## Half-Failed Asset Recovery

When an earlier run created MediaAsset rows but CDN/object truth was not ready, audit first:

```bash
php artisan media-assets:seo-release-cleanup \
  --asset-prefix=article.example.topic \
  --translation-group-id=tg_article_example_2026v1 \
  --dry-run \
  --json
```

If the production media runtime is now ready, resync without deleting assets:

```bash
php artisan media-assets:seo-release-cleanup \
  --asset-prefix=article.example.topic \
  --translation-group-id=tg_article_example_2026v1 \
  --resync \
  --json
```

Deletion is intentionally held. Do not delete half-failed assets without a separate reviewed operator approval.

## Existing Bilingual Article Cover Batch

For an image-only replacement on existing published bilingual articles, use the
batch runner instead of manually invoking the single-group commands. Start from
[`docs/examples/article-cover-replacement.manifest.example.json`](../examples/article-cover-replacement.manifest.example.json)
and validate its shape against
[`docs/examples/article-cover-replacement.manifest.schema.json`](../examples/article-cover-replacement.manifest.schema.json).
Keep real source image paths outside the repository.

Dry-run is the default and performs a complete cohort preflight before any
Media Library or article write:

```bash
php artisan seo-agent:replace-article-covers \
  --manifest=/absolute/path/article-cover-replacement.json \
  --dry-run \
  --receipt=/absolute/path/cover-batch-dry-run-receipt.json
```

The dry-run receipt emits `manifest_sha256` and
`authorization.required_confirmation_phrase`. Execute only with those exact
values and all holds:

```bash
php artisan seo-agent:replace-article-covers \
  --manifest=/absolute/path/article-cover-replacement.json \
  --execute \
  --actor=solo-owner \
  --reason=approved-bilingual-article-cover-refresh \
  --confirm-manifest-sha256=<dry-run-manifest-sha256> \
  --confirm-execution='EXECUTE ARTICLE COVER BATCH <dry-run-manifest-sha256>' \
  --no-publish --no-schema --no-hreflang --no-search \
  --no-sitemap-llms-change --no-revalidation \
  --receipt=/absolute/path/cover-batch-execute-receipt.json
```

If any target lacks `article_seo_meta`, both the group manifest and command must
opt in: set `allow_ensure_seo_meta_baseline=true` on that group and add
`--allow-ensure-seo-meta-baseline`. The manifest canonical and discoverability
state are then locked and re-read after the image update. The command never
publishes, enables schema/hreflang, changes sitemap/llms eligibility, submits a
search channel, or triggers revalidation.

The execute receipt reports `passed`, `failed`, or `partial`. A `partial` result
means at least one durable write committed or public API/HTML cache verification
did not converge within the bounded polling window; use the per-group and
verification results for safe resume rather than treating it as success.
