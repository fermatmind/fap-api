# Big Five Authority V2 visible-date contract (PR42)

This package closes the contract-design gap identified for 82 Big Five public-page candidates. It does not mutate CMS rows or claim that those candidates now have verified dates in production.

## Authority rules

- `published_at` is visible only from a published, public authority record or its matching current published revision. Article revisions must match the record's `published_revision_id`, tenant, locale, article identity, published state, and effective publication time.
- `reviewed_at` requires a completed manual-review state and a dedicated review timestamp or explicit review provenance.
- `updated_at` requires explicit `editorial_update` provenance, a non-empty authority reference, and an exact match to the authority record's canonical update timestamp.
- `revision_created_at`, `imported_at`, `built_at`, and `deployed_at` remain audit-only. They can never backfill a visible publication date.
- A missing or mismatched authority date stays `null`; the corresponding eligibility flag is false and includes a machine-readable blocked reason.
- Every visible field requires a currently published, public authority record; private, draft, archived, or soft-deleted records remain field-ineligible even when their timestamps or provenance metadata are populated.
- A stale or working draft revision cannot supply visible date evidence or eligibility. Its identity-matched revision-created/import/build/deploy lineage may remain audit-only and can never be promoted to a visible field. Personality and Topic visible revision evidence must match the record's `published_revision_id`; Article follows the stricter public-read contract above.
- When a Personality revision is supplied, parent visible dates project only when it is the asset's current `published_revision_id`; a working or stale revision leaves every visible field `null` while retaining identity-matched audit-only lineage.

The projector covers `Article`, `PersonalityPublicContentAsset`, `TopicProfile`, and `LandingSurface`. It is deliberately not wired into public controllers in this PR: later gated promotion work must supply real CMS/revision authority before a visible date can be released.

## Fixture provenance

`visible-date-findings.json` contains the exact 82 `assessment.visible_date == FAIL` rows from the PR38 runtime closeout artifact, locked to source SHA256 `60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65`. The fixture and validator lock page-family, authority-surface, locale, route, and asset identity distributions.

## Repository rule impact

No content ownership or publishing workflow changes. CMS/backend remains the authority; this is a backend projection and fail-closed eligibility contract only. No reviewer, source, media, schema, public runtime, indexability, publish, deploy, or production-write behavior changes.
