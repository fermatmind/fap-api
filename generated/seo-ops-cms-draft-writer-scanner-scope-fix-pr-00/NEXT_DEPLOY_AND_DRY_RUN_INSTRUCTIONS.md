# Next Deploy and Dry-Run Instructions

Task: SEO-OPS-CMS-DRAFT-WRITER-SCANNER-SCOPE-FIX-PR-00

## Current Decision

GO_FOR_PR_REVIEW

## After PR Merge

Do not deploy automatically. First run deploy readiness for the merged SHA and confirm production backend has the scanner-scope fix.

Exact next operator approval should be requested only after:

1. PR is merged.
2. `origin/main` contains the merge commit.
3. Production backend deploy readiness confirms the merged SHA is not deployed yet.

## Production Dry-Run Command

After backend production deploy is explicitly approved and completed, run production dry-run only:

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

Expected scanner result:

- `active_surface_guard_scan.status=passed`
- `contract_integrity_scan.status=passed`
- no `old_big_five_route_found_in_active_surface`
- no `route_alias_contract_invalid`
- no `private_route_found_in_active_surface`

## Hard Holds

- Do not create CMS drafts until dry-run passes in production.
- Do not publish.
- Do not make indexable.
- Do not mark sitemap or llms eligible.
- Do not submit Search Channel, GSC, Baidu, or IndexNow.
- Do not trigger ISR/revalidation as part of dry-run.
