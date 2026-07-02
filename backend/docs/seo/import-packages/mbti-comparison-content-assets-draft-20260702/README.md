# MBTI A/T Comparison Content Assets Draft Package

This package is a backend-only dry-run package for GPT-generated MBTI Assertive/Turbulent comparison assets.

## Result

- Package: `mbti-comparison-content-assets-draft-20260702`
- Artifact: `MBTI64-COMPARISON-ASSETS-DRY-RUN-01`
- Authority target: `personality_profile_sections.mbti64_comparison_a_vs_t`
- Mode: dry-run only
- CMS write: no
- Publish: no
- Search/sitemap/llms/canonical/hreflang/JSON-LD release: no

## Included comparison assets

Batch 02 contains 14 draft comparison assets: ENFJ, ENTJ, ENTP, ESFJ, ESFP, ESTJ, ESTP, INFJ, INFP, INTP, ISFJ, ISFP, ISTJ, and ISTP.

Each comparison is normalized into `comparisons/FermatMind_{TYPE}-A_vs_{TYPE}-T_CMS_READY.json` with the existing CMS-ready dry-run contract.

## Source context

`source_context/` contains the matching `{TYPE}-A` and `{TYPE}-T` single-profile content assets extracted from the operator-provided `INTJ.zip` source package. The full `INTJ.zip` package is not imported.

## Explicitly excluded

- `Assertive Architect (INTJ-A) vs. Turbulent Architect (INTJ-T) _ 16Personalities.pdf` is reference-only and is not stored in this package.
- `INTJ-A/T` and `ENFP-A/T` comparison pages are not included because their comparison zip packages were not supplied in this batch.

## Validation

Run:

```bash
cd backend
php artisan personality:mbti64-comparison-assets-dry-run --source-dir=docs/seo/import-packages/mbti-comparison-content-assets-draft-20260702 --json
php artisan test --filter=PersonalityMbti64ComparisonAssetsDryRunCommandTest --no-ansi
```

Expected dry-run summary:

- `assets_found=14`
- `valid_count=14`
- `rows_would_stage=14`
- `writes_committed=false`
- `cms_write_attempted=false`
- `publish_attempted=false`
- `search_release_attempted=false`
- `sitemap_llms_release_attempted=false`
