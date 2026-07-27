# Career search-entry tier contract

`career.search_entry_authority.v1` is a backend-authoritative, read-only projection. It does not change publication, robots, sitemap, llms, review evidence, CMS state, database state, or Search Channel state.

The projection keeps these dimensions independent:

- `public_visibility`
- `robots_indexable`
- `search_entry_eligible`
- `review_state`
- `publish_track`
- `content_quality_tier`

`stable` requires a public endpoint projection, exact `index,follow` eligibility, current exact bilingual reviewer evidence from `CareerPilotReviewEvidenceBridge`, and the existing `stable` publish track. `approved_candidate` has the same gates and the existing `candidate` publish track. Every other state is `ineligible`.

Reviewer approval is current only when the existing bridge can rebuild the exact bilingual target package and match its content, SEO, visible-claim, and locale-index-entry hashes to an `approved_all` attestation. Any content, SEO, public evidence, bilingual target, or index-entry drift makes the bridge return `unknown`, which makes the search-entry tier fail closed.

The permanent Career directory exclusion set remains authoritative for held slugs. A held slug is ineligible even if all supplied visibility, robots, review, and publish-track inputs otherwise appear eligible.

Repository rule impact: Career public content and discoverability authority remain backend-owned. This contract adds a read-only public API projection only; it adds no frontend fallback and no publishing or submission executor.
