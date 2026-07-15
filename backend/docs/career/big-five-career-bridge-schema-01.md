# Big Five → Career bridge schema v1

This PR defines a backend-owned, fail-closed contract. It does not add a public API, reader, route, ranking, recommender, CMS write, migration, or discoverability surface.

## Authority and selection

- Big Five input must come from `backend_public_api_published_projection`, identify a published and public `personality_public_content_asset` by its canonical Authority V2 asset id (for example `model_hub:en:/en/personality/big-five`), and select the exact `published_revision_id` through `selected_revision_source=published_revision`. The top-level identity, revision, locale, four evidence permissions, and `big_five_public_projection_hash` must match that nested projection.
- A working revision, draft snapshot, generated Authority V2 package, record-existence check, or HTTP 200 is not an eligible source.
- Career input must bind the exact occupation id, canonical slug, locale, `career.runtime_publish_projection.v1`, and `career_runtime_projection_hash`. The canonical job, detail route, dataset, release gate, publish eligibility, and public projection must all be published/visible/ready with no blockers.
- Claim, source, reviewer, and visible-date permissions must all pass. Both input and output carry explicit privacy boundaries whose values must remain false.

## Output boundary

The output content is limited to reflection signals, environment questions, feedback/structure preferences, possible friction cues, exploration examples, and boundary copy. It locks both source identities and projection hashes and fixes:

- `claim_mode=explanation_only`
- `primary_career_interest_signal=riasec`
- `big_five_role=supplementary_work_style_explanation`
- occupation ranking, hiring/screening, outcome prediction, diagnosis, and discoverability changes to `false`

Only `published_projection_ready` with zero blockers and non-empty reader-safe content can set `public_reader_allowed=true`. `generated_candidate` and `pending_manual_review` remain non-public; every non-ready state resolves to `blocked` in the executable assessment.

## Private-data boundary

Private Big Five score vectors, percentiles, item answers, selector traces, attempt/report links, user identifiers, orders, and payments are forbidden recursively. RIASEC remains the primary career-interest signal; this bridge provides supplementary work-style explanation only.

## Executable boundary

- Input schema: `backend/docs/career/contracts/big-five-career-bridge-input.v1.schema.json`
- Output schema: `backend/docs/career/contracts/big-five-career-bridge-output.v1.schema.json`
- Runtime-independent gate: `backend/app/Domain/Career/Bridge/BigFiveCareerBridgeContract.php`

The schemas reject unknown fields and describe the portable wire shape. The PHP gate adds cross-document checks that JSON Schema cannot express directly: selected revision equality, locale binding, source-lock equality, canonical asset-id traversal rejection, forbidden private/ranking keys, and deterministic or diagnostic wording.

## Repository rule impact

Career and Big Five public content remain backend/CMS/public-API authoritative. This contract adds no runtime consumer and changes no publication, indexability, sitemap, LLMS, schema markup, media, cache, search, deployment, or production data state.
