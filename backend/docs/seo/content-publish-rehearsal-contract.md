# Content Publish Rehearsal Contract

## Purpose

CONTENT-OPS-02A defines a backend-owned content publish rehearsal contract for
SEO-sensitive content operations.

The rehearsal is dry-run only. It does not publish articles, does not mutate CMS
content, does not write `seo_intel`, does not write the future Observation
Queue, does not enqueue Search Channel Queue, does not submit URLs, does not
change sitemap or `llms.txt`, and does not perform production writes.

CMS/backend remains the content, metadata, publish-state, canonical,
indexability, claim-boundary, and internal-link authority. `fap-web` remains a
deterministic public runtime renderer only.

## Candidate Surfaces

The future rehearsal validator may evaluate these backend-owned or
backend-declared surfaces:

- articles
- research reports
- content pages
- support articles
- interpretation guides
- topics
- personality pages
- career guides
- career jobs
- test landing/detail pages
- homepage/landing surfaces

## Required Rehearsal Checks

Every supported surface must define whether each check is applicable,
unsupported, or blocked by missing schema:

- status / review_state
- is_public / is_indexable
- canonical_path or canonical URL readiness
- seo_title
- seo_description
- robots/noindex state
- locale
- slug
- references
- CTA
- FAQ
- media / cover readiness where relevant
- claim boundary
- internal link readiness
- Search Channel eligibility dry-run
- Observation Queue planned event dry-run

Drafts and non-public records must remain excluded from sitemap, `llms.txt`,
Search Channel Queue, URL submissions, and indexable URL Truth handoff.

## Rehearsal States

The future dry-run validator should return one of:

- safe
- needs_review
- blocked

`safe` means the candidate passes known dry-run checks for the requested surface.
`needs_review` means a human should inspect missing or cautionary fields before
publish. `blocked` means the candidate must not be published or made eligible
for search discovery until resolved.

## Planned Observation Events

CONTENT-OPS-02A does not write Observation Queue rows. It only defines planned
events that a later implementation may report in dry-run output:

- published
- metadata_changed
- canonical_changed
- robots_changed
- locale_link_changed
- claim_boundary_changed
- issue_detected

Planned events are advisory. They are not URL Truth, not Search Channel Queue,
not CMS writes, and not runtime verification.

## Claim Lint Gate

Publish rehearsal must include claim lint state before any content can be
considered safe for manual publish review. Claim lint must not auto-rewrite
content, auto-fix claims, auto-publish content, or create issue rows in this
contract PR.

Public/indexable claim-unsafe content must be treated as blocked in future
runtime work.

## Internal Link Readiness Gate

Publish rehearsal must include internal link readiness. Internal links must be
checked against backend/CMS authority and future entity-key governance. Frontend
fallback, static sitemap, static `llms.txt`, crawler logs, search engine
responses, and local copies must not become internal link authority.

## Search Channel Eligibility Dry-run

Search Channel eligibility may be reported as a dry-run check only. The
rehearsal must not create, approve, retry, enqueue, or submit Search Channel
Queue items.

## Forbidden Behavior

This contract forbids:

- CMS content mutation
- article publish
- production write
- production migration
- sitemap mutation
- `llms.txt` mutation
- Observation Queue write
- Search Channel Queue enqueue
- URL submission
- crawler log read
- scheduler activation
- collector write
- Metabase exposure
- fap-web modification
- frontend fallback as authority
- static sitemap or static `llms.txt` as authority
- crawler logs or search engine responses as URL Truth
- claim linter auto-rewrite
- internal link auto-creation
- pSEO generation

## Surface Readiness Notes

Articles are the most ready surface because the existing controlled article
publish command already has dry-run preflight behavior. Research reports have
strong methodology, disclaimer, claim-boundary, review, public, indexable, and
canonical fields but need a reusable rehearsal implementation. Content pages,
support articles, interpretation guides, topics, personality pages, career
guides, career jobs, test pages, and landing surfaces need surface-specific
field mapping before runtime enforcement.

Missing `translation_group_uuid` remains a sidecar issue. Existing
`translation_group_id` may be used only as a transitional key where it already
exists. Title or slug similarity may help migration planning but must not become
long-term authority.

## Final Decision

`content_publish_rehearsal_contract_ready`

Next task: `CONTENT-OPS-02B`
