# Big Five Authority V2 Topic authority package (PR46)

This PR replaces the unsafe Big Five Topic draft authority with two backend-owned working-revision candidates, one for `en` and one for `zh-CN`. It does not write those revisions to CMS or change the current public Topic runtime.

## Authority contract

- The start-test entry is a `scale` entry in the `tests` group with `target_key=BIG5_OCEAN`. The backend resolver must derive `big-five-personality-test-ocean-model` from the public, active `scales_registry.primary_slug`; no MBTI or custom URL fallback is stored in the package.
- Visible copy explains the model and its limits. It contains no internal SEO-operations language and treats work/career use as supplementary context only, never as occupational fit or outcome authority.
- Source rows are locked to the PR05 source ledger. Author and reviewer remain `null`; no human review is claimed.
- Published, reviewed, and updated dates remain `null`. A later promotion gate must preserve current published-revision authority or block; revision/import/build/deploy timestamps are forbidden fallbacks.
- PR41 has no approved Topic media. Hero, inline, and OG slots therefore remain `missing_pending`, with no URL, alt, rights, provenance, or operator approval invented.
- Every public/promotion/indexability/discoverability gate remains false. A public reader must never select this working revision before a separately authorized promotion.

## Validation

- `node generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/validate-package.mjs`
- `cd backend && php artisan test tests/Feature/SEO/BigFiveAuthorityV246Test.php --no-ansi`
- `cd backend && php artisan personality:big-five-authority-v2-topic-draft-preflight --package=../generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/topic-draft-revision-package.json --package-only --json`

Omit `--package-only` only in a migrated, non-production validation database containing the canonical public `BIG5_OCEAN` registry row. The command has no write, promotion, publish, or indexability mode.

## Repository rule impact

Topic content remains CMS/backend-authoritative. This package is an explicit backend baseline/recovery and draft-revision artifact, not runtime page authority. No frontend fallback, CMS/database mutation, production publication, indexability change, sitemap/LLMS/schema change, cache operation, Search submission, or deployment is included.
