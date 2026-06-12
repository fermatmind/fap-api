# Next Prod Draft Import Instructions

Status: WAIT_FOR_PR_REVIEW_AND_MERGE

Do not run production import from this branch before review, merge, deploy, and explicit operator approval.

## After PR Merge And Deploy

1. Confirm production backend revision contains this PR.
2. Stage the operator-approved Mode C package on production through an approved read-only/write-file path.
3. Run dry-run only:

```bash
cd /var/www/fap-api/current/backend
php artisan articles:import-seo-content-package-draft \
  --package=/path/to/content-package \
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

4. Proceed to non-dry-run only after explicit operator approval and dry-run `ok=true`.

## Still Forbidden Until Separate Approval

- production non-dry-run import
- publish
- make indexable
- sitemap eligibility
- llms eligibility
- schema or hreflang enablement
- Search Channel, GSC, Baidu, or IndexNow submission
- ISR/cache revalidation
