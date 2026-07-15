# Big Five Authority V2 visible-date contract (PR42)

This package closes the contract-design gap identified for 82 Big Five public-page candidates. It does not mutate CMS rows or claim that those candidates now have verified dates in production.

## Authority rules

- `published_at` is visible only from a published, public authority record or its matching published revision.
- `reviewed_at` requires a completed manual-review state and a dedicated review timestamp or explicit review provenance.
- `updated_at` requires explicit `editorial_update` provenance, a non-empty authority reference, and an exact match to the authority record's canonical update timestamp.
- `revision_created_at`, `imported_at`, `built_at`, and `deployed_at` remain audit-only. They can never backfill a visible publication date.
- A missing or mismatched authority date stays `null`; the corresponding eligibility flag is false and includes a machine-readable blocked reason.

The projector covers `Article`, `PersonalityPublicContentAsset`, `TopicProfile`, and `LandingSurface`. It is deliberately not wired into public controllers in this PR: later gated promotion work must supply real CMS/revision authority before a visible date can be released.

## Fixture provenance

`visible-date-findings.json` contains the exact 82 `assessment.visible_date == FAIL` rows from the PR38 runtime closeout artifact, locked to source SHA256 `60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65`. The fixture and validator lock page-family, authority-surface, locale, route, and asset identity distributions.

## Repository rule impact

No content ownership or publishing workflow changes. CMS/backend remains the authority; this is a backend projection and fail-closed eligibility contract only. No reviewer, source, media, schema, public runtime, indexability, publish, deploy, or production-write behavior changes.
