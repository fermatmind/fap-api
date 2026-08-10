# Public Topic Edge Authority Contract

Status: implementation contract v1

Authority owner: fap-api / CMS

Public endpoint: `GET /api/v0.5/public-topic-edges`

Career link publication gate: `CLOSED`

## Decision

Public topic edges are explicit backend records. The frontend may consume only the eligible API projection and must not infer an edge from a slug, file, rendered page, crawler observation, schema, sitemap, llms surface, or local editorial fallback.

The existing `article_test_edges` relation remains the product-specific Article-to-assessment contract. It cannot represent arbitrary CMS source/target pairs, locale identity, review/audit provenance, validity windows, current target canonical readback, or the Career C06 gate without changing its established runtime meaning. `public_topic_edges` therefore provides the generic public graph authority rather than overloading that narrow product relation. A future controlled migration may materialize approved Article-to-assessment edges from the existing relation, but this implementation performs no import and treats neither table as evidence for the other.

## Record contract

Each record contains:

- source: `source_type`, `source_id`, `source_locale`
- relation: allowlisted `relation_type`
- target: `target_type`, `target_id`, `target_locale`
- visible projection: `visible_label`, optional `context`, `position`
- governance: `active`, `proposed_active_state`, `publication_allowed`, `blocker`, `review_state`, `evidence_refs`, `version`
- time boundary: `valid_from`, `valid_until`
- private audit identity: `created_by_admin_user_id`, `updated_by_admin_user_id`, timestamps
- release evidence: `target_publication_eligible`, `target_canonical`

The database unique key binds organization, complete source identity, relation, and complete target identity. Runtime projection also deduplicates by the same stable identity and orders by `position`, relation, target type/id/locale, then edge id.

The `public_topic_edge` review surface is registered under the repository's solo-owner CMS policy. Actor ids and the database row id remain private CMS audit fields and are never included in the public API; the public item exposes only its SHA-256 stable edge identity, review state, path-free evidence references, and version.

Supported v1 public entity types are `article`, `content_page`, `personality_profile`, and `topic`. Supported relations are `alternate_locale`, `parent`, `related`, `supporting_evidence`, `take_assessment`, and `next_step`. Unknown types or relations fail closed. Non-`alternate_locale` relations require equal source and target locale; `alternate_locale` requires the same entity type and a different locale.

## Eligibility and readback

An item is returned only when all of the following are true at request time:

1. The source exists in backend authority for the exact id and locale and is currently public and indexable.
2. The edge is active, publication-allowed, approved, marked target-publication-eligible, versioned, inside its validity window, and has a non-empty label.
3. The relation and entity types are allowlisted and locale rules pass.
4. The target exists for the exact id and locale, is currently public and indexable, and does not carry a `noindex` SEO directive.
5. The stored target canonical exactly equals the current backend canonical after owned-host normalization.
6. The canonical has no query/fragment, is owned by FermatMind, and is outside private take/result/report/order/share/payment/checkout paths.
7. Neither source nor target is a Career entity while the C06 gate is closed.

Candidate records are cached for at most 300 seconds and any edge save/delete invalidates the relevant source cache. Publication and canonical truth for source and target is resolved live on every request, so cached candidates cannot keep an unpublished, unindexable, private, or canonical-stale target visible.

## Public API

Required query parameters:

```text
source_type=topic&source_id=123&source_locale=en
```

Invalid query values return Laravel's standard `422` validation response. A valid query always returns the authority envelope:

```json
{
  "schema_version": "public-topic-edges.v1",
  "authority": {
    "owner": "fap-api/cms",
    "authority_version": "cms-public-topic-edge-authority.v1",
    "source_type": "topic",
    "source_id": 123,
    "source_locale": "en",
    "source_publication_eligible": true,
    "source_canonical": "https://fermatmind.com/en/topics/example",
    "eligible_item_count": 0,
    "frontend_fallback_allowed": false,
    "candidate_cache_ttl_seconds": 300,
    "target_truth_readback": "live",
    "career_link_publication_gate": "CLOSED",
    "reason": "OK"
  },
  "items": []
}
```

No eligible edge, an unavailable source, and a Career-gated source all return `items: []` with a specific authority reason. An empty response is not authorization to fabricate copy or links.

## G03 governed review handoff

The Window 6 one-query-one-owner registry was accepted as governed planning evidence after fap-web PR #2024 merged at commit `8aaf49c16dd514b64f3f58d55f594c1c0a085950`: its 422 queries had zero owner conflicts and zero blank next-step fields after private flows were explicitly marked not applicable and unresolved public next steps were marked unknown. A01, P03-P05, and R04 evidence is versioned with that handoff.

This acceptance does not publish an edge. Every materialized record still requires exact CMS identities, an approved review state, evidence references, current publication/indexability truth, exact canonical readback, and all runtime gates above. `UNKNOWN` and `NOT_APPLICABLE_PRIVATE_FLOW` values are never converted into public targets.

## Deferred items

- No production migration apply or CMS import/publication is part of this PR.
- No frontend renderer or tracking runtime is part of this PR.
- No sitemap, llms, schema, canonical, hreflang, robots, or Search Channel behavior changes.
- No Career record can project until controlled C02/C03 recovery and a current C06 PASS. Adding support for a Career resolver is a later, separately reviewed backend scope.
- Other CMS source types remain unsupported until their own publication and canonical authorities can be resolved without inference.

Repository rule impact: this contract establishes fap-api/CMS as the single Topic Graph edge authority and makes empty/failure behavior and the pre-C06 Career closure explicit. It does not change content ownership or authorize any production write.
