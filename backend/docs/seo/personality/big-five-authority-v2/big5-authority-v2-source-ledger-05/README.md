# Big Five Authority V2 source and claim ledger

Train item: `BIG5-AUTHORITY-V2-SOURCE-LEDGER-05`

This package establishes the backend-owned bilingual terminology ledger and shared source-to-claim ledger for later Big Five Authority V2 content PRs. It is evidence preparation only: it does not write CMS data, publish pages, change indexability, activate schema, submit search URLs, or deploy runtime code.

## Canonical artifacts

- `generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json` is the machine-readable source, claim, page-family, limitation, and eligibility authority.
- `generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/terminology-ledger.json` is the bilingual terminology authority for `en` and `zh-CN`.
- `generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/qa_report.json` records validation outcome and non-mutation boundaries.
- `BLOCKED_OR_UNVERIFIED_ASSUMPTIONS.md` records decisions that this PR deliberately does not make.

The generated JSON is a content-production input, not runtime or publication authority. Future packages must still pass their own schema, originality, source coverage, reviewer, CMS import, public-readback, indexability, and release gates.

## Evidence classes and allowed use

1. `academic_evidence` — peer-reviewed original research and formal research synthesis. Eligible for a narrowly worded scientific claim only when the source explicitly supports that claim and the source limitation remains attached.
2. `official_product_evidence` — official research resources or FermatMind backend contracts. An official research resource may document a taxonomy; a FermatMind contract may establish product fields and product boundaries. Neither automatically validates FermatMind scores.
3. `competitor_evidence` — read-only public structural observations. It may inform page-family comparison, never FermatMind science, superiority, accuracy, pricing, rating, endorsement, or copied wording.
4. `internal_repository_evidence` — repository policy and recorded implementation facts. It governs FermatMind behavior but is not independent scientific evidence.
5. `inference` — an editorial synthesis that is never standalone evidence and always requires human review before public use.

Secondary media, search snippets, competitor copy, and unsourced summaries are not admitted as the sole evidence for a core scientific claim.

## Claim use contract

- Public copy may use only a claim with `allowed_as_public_claim: true`.
- The page family must occur in both the claim and every cited source used on the page.
- Core scientific claims must resolve at least one `primary_source_id` to `academic_evidence` with `core_scientific_evidence_eligible: true`.
- A source title, author/organization, year, DOI or URL, access date, supported claim, limitation, and applicable page families must remain traceable.
- An inference remains blocked until an accountable reviewer approves the exact page wording.
- Source visibility does not imply `schema_eligible`, sitemap/llms inclusion, indexability, publication, search ranking, AI citation, or scientific validation.

## Bilingual terminology contract

English and Simplified Chinese must preserve the same concept and claim boundary, but they are independently edited rather than forced into word-for-word translation. Framework-specific labels stay named. In particular:

- `facet` / `侧面` is not silently merged with `aspect` / `方面`.
- `Neuroticism` / `神经质` is a technical trait label in this context, not a medical diagnosis.
- A `trait` / `特质` is written as a continuous descriptive tendency, not a fixed identity.
- A `score range` / `分数区间` is not called a percentile, norm rank, diagnosis, or permanent label without separately approved evidence.

## Repository rule impact

This PR adds a backend-authoritative evidence-preparation surface. It does not change runtime content ownership: Big Five public content, visible sources, editorial state, media, SEO metadata, sitemap, llms, and publication remain CMS/backend-authoritative. No temporary frontend fallback is introduced.
