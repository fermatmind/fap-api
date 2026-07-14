# Big Five Authority V2 existing article and topic-hub refresh

`BIG5-AUTHORITY-V2-ARTICLE-REFRESH-22` prepares reviewable replacements for the exact nine existing Article locale records and two topic hubs locked by PR21. It does not add any PR24–33 article, mutate CMS, publish content, or change indexability.

## Coverage

- English Articles: three exact slugs.
- Simplified Chinese Articles: six exact slugs.
- Topic hubs: `/en/topics/big-five` and `/zh/topics/big-five`.
- Total existing surfaces: eleven.

Each Article candidate has a single unbranded editorial title and distinct intent, then a rewritten direct opening, reasoning sequence, concrete scenario, counterexample, three-step action framework, claim boundary, visible sources with limitations, and internal links. The procrastination, stress/recovery, and MBTI candidates are intentionally separated from the later PR21 themes.

## Review and authority

- Every candidate is `pending_manual_review`; no human review is claimed.
- Author, reviewer, published date, and updated date are null in the package. An importer must preserve these values from the matching CMS Article or topic record and must never synthesize them.
- Scientific references are copied from the reviewed PR05 source ledger. Practical frameworks are explicitly editorial observation tools, not validated interventions.
- Topic hubs contain no hardcoded Article entries. They may enumerate only records returned by the backend public API as both published and eligible.

## Artifacts

- `article-refresh-candidates.json`: nine complete existing-Article replacement candidates.
- `topic-hub-candidates.json`: two backend-enumerated topic-hub candidates.
- `skeptical-review.json`: risk, repair, and unresolved manual-review record for every Article.
- `qa_report.json`: exact counts and zero-mutation evidence.
- `build-package.mjs`: deterministic candidate builder.
- `validate-package.mjs`: fail-closed identity, content, source, authority, and boundary validation.

## Validation

```bash
node generated/big-five-authority-v2/big5-authority-v2-article-refresh-22/validate-package.mjs
cd backend && php artisan test tests/Feature/SEO/BigFiveAuthorityV222Test.php --no-ansi
```

Repository rule impact: none. The package keeps Articles and topic hubs CMS/backend-authoritative, adds no frontend or runtime fallback, and performs no production write.
