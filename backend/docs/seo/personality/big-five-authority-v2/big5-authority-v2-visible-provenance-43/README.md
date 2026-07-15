# Big Five Authority V2 visible-provenance contract (PR43)

This package locks the 11 Big Five public-page candidates with a visible author, reviewer, or source failure in the PR38 runtime closeout. It defines a read-only, fail-closed projection contract; it does not claim that production CMS records now contain verified human-review provenance.

## Authority rules

- Author, reviewer, and source provenance are separate fields. None is inferred from another.
- A visible author requires a real positive `admin_user:*` identity, public label, allowed role, and `revision-author:*` or `author-ledger:*` authority reference. Generated or self-attested identities fail closed. Article metadata must agree with the non-null matching published revision actor and any existing Article label.
- A visible reviewer additionally requires a real review timestamp and completed review state. Article reviewer metadata must agree with the matching published revision reviewer, timestamp, state, and any existing Article label.
- Missing or fabricated reviewer evidence blocks promotion and visible-reviewer eligibility. The projector is read-only and never removes or overwrites existing published content.
- Article provenance is eligible only from the live `published_revision_id`, matching tenant and locale, an effective publication timestamp, and a public non-archived Article. Stale, future, foreign, archived, and soft-deleted revisions expose no provenance. Topic provenance is read from the canonical `snapshot_json.profile` payload of its current published revision.
- Personality assets mirror the backend public-read gate: `content_ready` or `published`, public, and not scheduled for the future. Published Topics likewise require a null-or-effective `published_at`.
- Sources are explicitly classified as `academic_evidence`, `internal_policy`, or `product_authority`. Existing source-ledger aliases are normalized into those public categories.
- Labels implying institutional certification, expert endorsement, clinical review, medical authority, or an official partnership fail closed.

The contract covers `Article`, `PersonalityPublicContentAsset`, `TopicProfile`, and `LandingSurface`. It is not wired into public controllers in this PR; later promotion work must provide real CMS/revision authority before release.

## Fixture provenance

`visible-provenance-findings.json` is the exact union of `visible_author == FAIL`, `visible_reviewer == FAIL`, and `visible_source == FAIL` rows from the PR38 runtime closeout artifact at SHA256 `60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65`. The package locks 11 unique assets: 3 author failures, 7 reviewer failures, and 3 source failures.

## Repository rule impact

No content ownership or publishing workflow changes. CMS/backend remains authoritative. This PR adds only a backend projection and eligibility contract; it performs no migration, CMS/database write, publication, indexability, schema, sitemap, llms, deployment, or public-runtime change.
