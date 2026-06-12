# Next Deploy And Draft Import Rerun Instructions

## After PR Merge

1. Confirm the merge commit is on `origin/main`.
2. Deploy the exact backend SHA only after operator approval.
3. Verify production command registration:

```bash
php artisan list articles | rg "import-seo-content-package-draft"
```

## Production Dry-Run Rerun

Run the already-approved package dry-run first:

```bash
php artisan articles:import-seo-content-package-draft \
  --package=/path/to/source-package \
  --translation-group-id=tg_article_career_interest_vs_personality_test_2026v1 \
  --locales=zh-CN,en \
  --dry-run \
  --json \
  --draft-only \
  --no-publish \
  --no-index \
  --no-sitemap \
  --no-llms \
  --schema-hold \
  --hreflang-hold \
  --expected-zh-slug=career-interest-vs-personality-test-differences \
  --expected-en-slug=career-interest-test-vs-personality-test
```

Proceed to real draft-only import only if dry-run returns `ok=true`, `errors=[]`, and the JSON serialization preflight passes.

## Hard Holds

Do not publish, make indexable, add sitemap eligibility, add llms eligibility, enable schema, enable hreflang, submit search channels, or trigger revalidation as part of this fix rollout.
