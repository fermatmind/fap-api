# Big Five Authority V2 article intent architecture

`BIG5-AUTHORITY-V2-ARTICLE-IA-21` is the immutable editorial handoff for PR24–33. It audits the nine existing Article surfaces and two topic hubs named by the train, then locks ten batches of five themes. Every theme produces one English and one Simplified Chinese draft candidate: 50 themes and 100 locale assets in total.

## Authority and evidence separation

- CMS/backend remains the publication authority. This package performs no CMS, publication, indexability, or production write.
- Academic evidence is limited to the reviewed PR05 source ledger and each source's stated limitation.
- Competitor evidence is a time-bound public-structure observation. It cannot support FermatMind scientific, product, superiority, rating, pricing, or endorsement claims.
- No auditable Search Console query/page export is present in scope. The package therefore records `GSC_EVIDENCE_PENDING`; it does not infer demand, ranking, clicks, or impressions.
- Repository and public-contract evidence governs product and claim boundaries. Unsupported product reliability, validity, norms, sample sizes, and percentiles remain Unknown.

## Locked consumer contract

PR24–33 must consume the exact `topic_id`, batch, slug, title intent, primary question, audience, user task, search intent, keyword set, source requirements, internal-link targets, and risk boundary in `article-intent-matrix.json`. A later content PR may develop the article body but may not replace or invent a topic inside this train.

The matrix deliberately excludes trait × career and trait × problem combinations. It also separates the new PR28 procrastination intent, PR30 stress/recovery intents, and PR32 MBTI intent from the three existing articles that PR22 owns.

## Artifacts

- `existing-surface-audit.json`: exact 9 Article + 2 topic-hub inventory and PR22 disposition.
- `article-intent-matrix.json`: locked 50-theme / 100-locale architecture.
- `evidence-register.json`: separated academic, competitor, and GSC evidence states.
- `batch-handoff.json`: exact topic IDs allocated to PR24–33.
- `qa_report.json`: machine-readable counts and forbidden-action report.
- `build-package.mjs`: deterministic artifact builder; it creates metadata only, never article bodies.
- `validate-package.mjs`: fail-closed package and source-ledger validation.

## Validation

```bash
node generated/big-five-authority-v2/big5-authority-v2-article-ia-21/validate-package.mjs
cd backend && php artisan test tests/Feature/SEO/BigFiveAuthorityV221Test.php --no-ansi
```

Repository rule impact: none. This planning package follows the existing CMS/backend-authoritative Article and topic-hub model and introduces no runtime authority or fallback.
