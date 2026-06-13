# fap-web Consumer Readiness

## Result

Status: `GO_FOR_NOINDEX_RENDER_CONSUMER`

The backend contract is ready for fap-web to consume Big Five public content assets in a noindex render phase.

## Required fap-web Behavior

- Render only backend/API-provided content.
- Preserve `robots=noindex,follow` for Big Five V1 render candidates.
- Do not enumerate these assets in sitemap or llms.
- Do not render `content_stub` facets as public pages.
- Do not create Big Five 32-type/OCEAN profile SEO pages.
- Do not reuse private result-page modules as public SEO body content.

## API Paths

- List: `/api/v0.5/personality-content-assets`
- Slug lookup: `/api/v0.5/personality-content-assets/{framework}/{slug}`
- Stable code lookup: `/api/v0.5/personality-content-assets/{framework}/{entityType}/{code}`

## Evidence

- Route evidence: `backend/routes/api.php`
- Controller evidence: `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityPublicContentAssetController.php`
- Test evidence: `test_write_import_is_idempotent_and_exposes_only_render_candidates`

