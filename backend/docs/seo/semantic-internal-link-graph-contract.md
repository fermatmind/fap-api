# Semantic Internal Link Graph Contract

## Purpose

INTERNAL-LINK-01A defines the backend-owned semantic internal link graph
contract for SEO-sensitive content operations.

The graph is a contract only in this PR. It does not mutate CMS content, does not create internal links, does not modify fap-web, does not add migrations, does not write `seo_intel`, and does not use crawler logs, sitemap, `llms.txt`, GSC, GA4, referral data, or frontend fallback links as graph authority.

## Authority Model

Backend/CMS entity graph owns internal link truth. `fap-web` renders links from
backend/public API contracts and may expose deterministic runtime observations,
but static frontend links are not final authority. Sitemap-derived links,
crawler logs, search engine responses, analytics, and referral signals may only
suggest opportunities for human review; they cannot auto-create links.

## Entity Key Rule

`entity_key` should prefer `translation_group_uuid` when available. Existing
`translation_group_id` may be transitional where already supported. Surfaces
without a stable key must be marked `legacy_unpaired`. Title or slug similarity
may be used only as a migration helper, not as authority.

## Required Link Families

- article -> test
- article -> topic
- article -> research
- article -> related_article
- topic -> test
- topic -> article
- topic -> personality/entity
- research -> topic/test/article
- test -> article/topic/research
- career_guide -> test/topic/article
- career_job -> career_guide/test/topic
- personality_page -> test/topic/article

## Future Graph Fields

- source_entity_type
- source_entity_key
- target_entity_type
- target_entity_key
- link_role
- locale
- authority_source
- visibility_state
- safety_state
- created_by_system
- review_state

## Safety States

The future dry-run/read model should classify graph edges and opportunities as:

- safe
- needs_review
- blocked

`blocked` applies to links derived from forbidden authority sources, missing
stable source or target identity, private/noindex/claim-unsafe surfaces, or
links that would bypass CMS/backend review.

## Forbidden Behavior

This contract forbids:

- runtime link writes
- CMS mutation
- fap-web modification
- migration creation
- crawler-derived link authority
- sitemap-derived graph truth
- `llms.txt`-derived graph truth
- frontend fallback as graph authority
- GSC/GA4/referral auto-link creation
- title/slug similarity as permanent key
- Search Channel enqueue or submission
- Observation Queue write
- pSEO generation

## Final Decision

`semantic_internal_link_graph_contract_ready`

Next task: `INTERNAL-LINK-01B`
