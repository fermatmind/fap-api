# MBTI comparison content assets draft dry-run package

This package stages GPT-generated MBTI A/T comparison content assets for backend
dry-run validation only.

## Source files

- `comparisons/FermatMind_INTP-A_vs_INTP-T_CMS_READY.json`
- `comparisons/FermatMind_INTP-A_vs_INTP-T_VALIDATION.json`
- `source_context/FermatMind_INTP-A_ZH_V8_4_Content_Asset.json`
- `source_context/FermatMind_INTP-T_ZH_V8_4_Content_Asset.json`
- `source_manifest.json`

`INTJ.zip` was a broader MBTI64 zh template/source-context package. Only the
matching INTP-A and INTP-T source-context assets are included here.

## Authority target

The dry-run target is the backend CMS MBTI comparison overlay:

- table: `personality_profile_sections`
- section key: `mbti64_comparison_a_vs_t`
- public API surface: `/api/v0.5/personality/comparisons/{comparison}`

This package is not runtime page authority by itself.

## Safety boundary

- draft only
- no database writes
- no CMS publish
- no queue
- no search submission
- no sitemap, llms, canonical, hreflang, or JSON-LD release
- no production deploy

## Dry-run command

```bash
php artisan personality:mbti64-comparison-assets-dry-run \
  --source-dir=docs/seo/import-packages/mbti-comparison-content-assets-draft-20260702 \
  --json
```
