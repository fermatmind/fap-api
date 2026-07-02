# MBTI Cross-Type Comparison Content Assets Draft Package

This package is a backend-only dry-run package for GPT-generated MBTI cross-type comparison assets.

## Result

- Package: `mbti-cross-type-comparison-content-assets-draft-20260702`
- Artifact: `MBTI64-CROSS-TYPE-COMPARISON-ASSETS-DRY-RUN-01`
- Mode: dry-run only
- CMS write: no
- Publish: no
- Search/sitemap/llms/canonical/hreflang/JSON-LD release: no

## Included comparison assets

This package contains 6 draft cross-type comparison assets: `intj-vs-intp`, `infj-vs-infp`, `enfp-vs-entp`, `estj-vs-entj`, `isfp-vs-infp`, and `entj-vs-intj`.

## Missing requested asset

`istj-vs-isfj` was requested but no matching desktop asset package was found in this scan.

## Validation

```bash
cd backend
php artisan personality:mbti64-cross-type-comparison-assets-dry-run --source-dir=docs/seo/import-packages/mbti-cross-type-comparison-content-assets-draft-20260702 --json
php artisan test --filter=PersonalityMbti64CrossTypeComparisonAssetsDryRunCommandTest --no-ansi
```

Expected dry-run summary:

- `assets_found=6`
- `valid_count=6`
- `rows_would_stage=6`
- `writes_committed=false`
- `cms_write_attempted=false`
- `publish_attempted=false`
- `search_release_attempted=false`
- `sitemap_llms_release_attempted=false`

