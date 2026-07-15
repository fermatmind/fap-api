# Big Five → Career bridge auditor v1

`career:audit-big-five-bridge` is a read-only, fail-closed CLI for PR13. It does not add a public route or reader and does not write CMS, database, cache, sitemap, LLMS, search, media, or deployment state.

## Inputs

The command requires three local JSON artifacts:

- `--big-five-projection`: `big_five_published_public_projection` / `big_five.published_public_projection.v1`, containing only backend public-API projection rows that select `published_revision_id` through `selected_revision_source=published_revision`.
- `--career-projection`: the existing `career_runtime_publish_projection` / `career.runtime_publish_projection.v1` artifact. Its deterministic SHA-256 fingerprint must match both candidate Career locks.
- `--candidates`: `big_five_career_bridge_candidates` / `big_five_career_bridge_candidates.v1`, with rows containing the PR12 `input` and `output` documents.

The bridge locale remains `en` or `zh-CN`; the Career runtime projection uses its existing `en` or `zh` authority locale. The executable contract binds `zh-CN` Big Five authority to the exact `zh` Career projection instead of inventing a `zh-CN` Career row.

## Audit behavior

For every candidate, the auditor:

1. selects exactly one Big Five row by canonical Authority V2 asset id and locale;
2. compares only published/public revision provenance and visible-evidence permissions, never a working/draft payload or generated Authority V2 package;
3. selects exactly one Career runtime row by canonical slug and Career locale;
4. validates the full Career projection fingerprint, published state, canonical resolution, detail route, dataset visibility, release gate, and empty blockers;
5. applies `BigFiveCareerBridgeContract` to the complete candidate;
6. returns `published_projection_ready` only when every authority and contract lock passes; otherwise it returns `blocked` and never generates fallback copy.

The deterministic JSON/Markdown report contains candidate, ready, and blocked counts; sorted blocker breakdown; published revision provenance; Career projection provenance; claim/private-data boundaries; and per-candidate status. It deliberately omits candidate content, private score vectors, percentiles, answers, selector traces, attempt/report links, and user/order/payment data.

Artifacts containing draft/review snapshots, generated Authority V2 packages, private score fields, attempt/report identifiers, or user/order/payment fields are rejected globally rather than treated as an alternate source.
An empty candidate set is blocked rather than treated as a vacuous pass, and malformed non-object rows in either authority projection are rejected instead of being silently discarded.

## Command

```bash
cd backend
php artisan career:audit-big-five-bridge \
  --big-five-projection=/path/to/big-five-published-public-projection.json \
  --career-projection=/path/to/career-runtime-publish-projection.json \
  --candidates=/path/to/big-five-career-bridge-candidates.json \
  --format=json
```

Use `--format=markdown` for Markdown. `--output` is optional and may write only the requested local audit artifact; without it, the command writes only to stdout.

## Boundaries

- RIASEC remains the primary career-interest signal.
- Big Five remains supplementary work-style explanation under `claim_mode=explanation_only`.
- Recommendation authority, ranking, hiring use, outcome prediction, diagnosis, pSEO, and discoverability changes remain disabled.
- No public API, frontend consumer, runtime reader, CMS/database/cache/search write, sitemap/LLMS mutation, migration, promotion, publish, or deployment is included.

## Repository rule impact

No content-authority rule changes. Big Five remains backend published/public-projection authority, Career remains backend runtime-publish-projection authority, and this PR adds only a read-only audit path plus the exact locale binding needed to consume the existing Career projection contract.
