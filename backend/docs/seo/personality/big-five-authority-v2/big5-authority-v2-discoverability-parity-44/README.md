# Big Five Authority V2 discoverability parity 44

This package locks the three hreflang and three `llms.txt` failures recorded by the read-only PR38 production runtime closeout.

The backend projector is read-only and fail-closed:

- Hreflang output requires a non-empty shared translation group and two distinct current, published, public, indexable EN/ZH Article revisions.
- Same-slug guessing is not counterpart authority. When no real counterpart exists, `no_hreflang` is the valid explicit policy and no alternate is emitted.
- `llms.txt` membership requires current published-revision authority, public/indexable state, and the explicit backend `llms_eligible` flag.
- LLMS eligibility is intentionally independent from sitemap eligibility; this PR does not modify sitemap behavior.
- Drafts, stale revisions, future publications, archived records, and disabled backend LLMS flags fail closed.

Repository rule impact: none. Article/CMS/backend remains the content and discoverability authority. This PR adds a read-only authority contract, fixture, and tests; it does not mutate CMS/database records, enable production LLMS membership, submit Search URLs, publish drafts, deploy, or alter sitemap/runtime enumeration.
