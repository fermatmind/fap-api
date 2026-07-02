# MBTI Cross-Type Comparison Content Assets Draft Package

This package is a backend-only dry-run package for GPT-generated MBTI cross-type comparison assets.

## Result

- Package: `mbti-cross-type-comparison-content-assets-draft-20260702`
- Artifact: `MBTI64-CROSS-TYPE-COMPARISON-ASSETS-DRY-RUN-01`
- Authority contract: `mbti.cross_type_comparison.authority.v1`
- Readmodel contract: `mbti.cross_type_comparison.readmodel.v1`
- Storage authority: `backend_authority.mbti64_cross_type_comparison`
- Mode: dry-run only
- CMS write: no
- Publish: no
- Search/sitemap/llms/canonical/hreflang/JSON-LD release: no

## Included comparison assets

This package contains 6 draft cross-type comparison assets: `intj-vs-intp`, `infj-vs-infp`, `enfp-vs-entp`, `estj-vs-entj`, `isfp-vs-infp`, and `entj-vs-intj`.

The dry-run planner exposes a formal internal readmodel projection for each asset:
`slug`, `comparison_type`, `locale`, `left_type`, `right_type`, `title`, `seo_title`,
`seo_description`, `summary`, section/FAQ/internal-link counts, governance status,
source SHA, and public/indexability gates. The projection is internal-only in this
package and keeps `public_api_enabled=false`.

## Missing requested asset

`istj-vs-isfj` was requested but no matching desktop asset package was found in this scan.
It is recorded as `pending_asset` and must not be fabricated from adjacent MBTI content.

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
