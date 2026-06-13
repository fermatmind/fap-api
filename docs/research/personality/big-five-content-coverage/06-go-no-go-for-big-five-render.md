# GO / NO-GO for Big Five Render

## Decision

Decision: `GO_FOR_FAP_WEB_NOINDEX_RENDER`

## Why GO

- Bilingual Big Five V1 render candidates are present with parity.
- Public API exposes only `content_ready` render candidates.
- Facet stubs are present but hidden from public API.
- All assets remain noindex and excluded from sitemap/llms.
- OpenAPI snapshot includes typed response schema.
- Stable code lookup route exists.
- Import is idempotent.
- sqlite migration/import smoke passes.

## Conditions

fap-web must keep this as a noindex/render consumer phase. It must not create sitemap, llms, or publicly indexable SEO pages until a later backend approval explicitly sets assets to `published`, `index_eligible=true`, and `robots=index,follow`.

## Blockers

None for noindex render consumer.

## Explicit Non-Goals Preserved

- No MBTI changes.
- No Big Five result-page behavior changes.
- No Enneagram result-page behavior changes.
- No test scoring changes.
- No 32 OCEAN SEO.
- No Enneagram 54 wing x instinct.
- No Tritype.

