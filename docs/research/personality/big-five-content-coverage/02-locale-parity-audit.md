# Locale Parity Audit

## Result

Status: `PASS`

The Big Five seed has zh-CN/en parity for both V1 render candidates and facet stubs.

## Parity Checks

- `en` total: 47
- `zh-CN` total: 47
- Render candidate code set: equal across `en` and `zh-CN`
- Facet count: 30 per locale
- Render candidate count: 17 per locale

## Notes

The same stable `code` values are used across locales. Locale-specific copy, title, SEO title/description, FAQ, and summaries are localized in the source seed.

## Evidence

- Seed evidence: `backend/content_assets/personality_public/big_five_v1_seed.json`
- Test evidence: `test_big_five_seed_has_expected_counts_parity_and_indexability`

