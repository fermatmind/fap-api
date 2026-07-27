# Career search-entry quality batch 01

`CAREER-SEARCH-ENTRY-QUALITY-BATCH-01` is a bounded, backend-authoritative dry-run package for exactly 50 Career review candidates. Its checked-in manifest is the complete input pool. Runtime code must not scan or backfill from the remaining Career inventory.

Selection is deterministic:

1. effective `stable` publish track;
2. effective `candidate` publish track;
3. descending deterministic bilingual quality score;
4. canonical slug.

Every candidate must pass both EN and zh-CN active/LKG detail readiness, exact index membership, canonical path, `index,follow`, visible-content thickness, public source references, explicit claim permissions, exact visible FAQ-to-FAQPage parity, locale-safe internal links, held-slug exclusion, and expected publish-track identity. A failure leaves the batch in `HOLD`; it never substitutes `review_needed`, `hold`, an unknown track, a held slug, or a later inventory member.

The package deliberately distinguishes current and target state:

- `search_entry_tier` remains `ineligible` before controlled reviewer evidence binding.
- `target_search_entry_tier` is the expected `stable` or `approved_candidate` result only after the exact `approved_all` review package is bound.
- `content_quality_tier` is Tier A only while the current bilingual evidence continues to pass.
- each candidate includes six exact review targets: content, SEO, and visible claims for EN and zh-CN.
- `current_content_sha256_by_locale` and `current_seo_sha256_by_locale` are the explicit EN/zh-CN hashes from those current review targets; `review_target_sha256_by_identity` preserves every per-target review SHA.

Build a zero-write package:

```bash
php artisan career:build-search-entry-quality-batch \
  --output=/private/path/career-search-entry-quality-batch-01.json \
  --json
```

Before any controlled apply, rerun against the exact prior artifact:

```bash
php artisan career:build-search-entry-quality-batch \
  --expected-package=/private/path/career-search-entry-quality-batch-01.json \
  --json
```

Verification binds `quality_package_sha256`, reviewer `package_sha256`, `target_set_sha256`, exact slug and URL lists, and counts. Any content, SEO, index-entry, quality, ordering, or membership drift fails closed.

The command and services do not write database, CMS, cache, queue, publication, indexability, sitemap, llms, or Search Channel state; do not submit URLs; do not deploy; and do not release held slugs. A private `--output` file is an operator artifact, not authority mutation.

Repository rule impact: Career content and discoverability remain backend/CMS-authoritative. This batch adds a bounded validation/read projection and exact review package only. It adds no frontend fallback, publication executor, indexability change, route, migration, or Search Channel action.
