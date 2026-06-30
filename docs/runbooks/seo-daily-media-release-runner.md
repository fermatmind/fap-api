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
