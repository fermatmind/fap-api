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

- source: `source_type`, `source_id`, `source_locale`, `source_canonical`
- relation: allowlisted `relation_type`
- target: `target_type`, `target_id`, `target_locale`, explicit `cross_locale_approved`
- visible projection: `visible_label`, optional `context`, `position`
- governance: `active`, `proposed_active_state`, `publication_allowed`, `blocker`, `review_state`, `evidence_refs`, `version`
- time boundary: `valid_from`, `valid_until`
- private audit identity: `created_by_admin_user_id`, `updated_by_admin_user_id`, timestamps
- release evidence: `target_publication_eligible`, `target_canonical`

The database unique key binds organization, complete source identity, relation, and complete target identity. Runtime projection also deduplicates by the same stable identity and orders by `position → relation_type → target_type → target_id`, with target locale and edge id used only as deterministic tie-breakers.

The `public_topic_edge` review surface is registered under the repository's solo-owner CMS policy. Actor ids and the database row id remain private CMS audit fields and are never included in the public API; the public item exposes only its SHA-256 stable edge identity, review state, path-free evidence references, and version.

Supported v1 public entity types are `article`, `content_page`, `personality_profile`, and `topic`. The governed G03 relation allowlist is exactly `breadcrumb`, `learn_more`, and `take_assessment`. Unknown types, relations, or locales are rejected before storage. Locale mismatch fails closed unless the CMS record carries an explicit cross-locale approval; the G03 fixture is evidence only and cannot set that approval on a record.

## Eligibility and readback

An item is returned only when all of the following are true at request time:

1. The source exists in backend authority for the exact id and locale, is currently public and indexable, and its stored source canonical exactly matches the live backend canonical.
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
source_type=topic&source_id=123&locale=en
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

No eligible edge, an unavailable source, and a Career-gated source all return `items: []` with a specific authority reason. A source with no eligible edge returns `200`; an authority storage/read failure returns `503` with `AUTHORITY_UNAVAILABLE`. Both remain fail closed and are not authorization to fabricate copy or links.

## G03 governed review handoff

The governed handoff is bound to fap-web PR #2040 merge commit `5466522801be6f71d01f0af96a495a84bddd059f`, not the earlier audit-closeout state. Its compact test fixture records 422 owner rows and 4,805 candidate edges: 3,268 non-Career candidates approved for backend modeling, 1,255 non-Career candidates held, and all 282 Career candidates kept `BLOCKED_WAITING_ON_C06` with activation/publication false. The approved relations are exactly 755 `breadcrumb`, 1,956 `learn_more`, and 557 `take_assessment`; 102 neutral-home cross-locale candidates still require explicit CMS approval and exact backend identities.

The fixture binds the merged owner registry SHA-256 `452f802993b7c3468876de94209e4486f3c441c1a8b1a0f12220806f4c35b3a0`, edge registry SHA-256 `2c99d0ba87db7d854b35275f5ed07d2468a49decc273b6e3aafc3654d7103655`, and governed summary SHA-256 `79b65bc75bf6d27077f2a160a2cd09c1d0be95d8264e0db72a59d2d72b3814bf`.

This acceptance does not publish or import an edge. Every materialized record still requires exact CMS identities, an approved review state, evidence references, current publication/indexability truth, exact source/target canonical readback, and all runtime gates above. Held or blocked fixture rows are never converted into public targets.

## Deferred items

- Migration up, rollback command, and re-up are exercised only against a disposable local SQLite database. The repository-mandated `down()` is intentionally non-destructive so edge audit history cannot be dropped automatically; no production migration apply or CMS import/publication is part of this PR.
- No frontend renderer or tracking runtime is part of this PR.
- No sitemap, llms, schema, canonical, hreflang, robots, or Search Channel behavior changes.
- No Career record can project until controlled C02/C03 recovery and a current C06 PASS. Adding support for a Career resolver is a later, separately reviewed backend scope.
- Other CMS source types remain unsupported until their own publication and canonical authorities can be resolved without inference.

Repository rule impact: this contract establishes fap-api/CMS as the single Topic Graph edge authority and makes empty/failure behavior and the pre-C06 Career closure explicit. It does not change content ownership or authorize any production write.
