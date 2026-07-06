# Executive Decision

Task: `SIX-TEST-HUB-BACKEND-AUTHORITY-OWNER-MATRIX-01`

Final verdict: `AUTHORITY_MATRIX_READY`

This generated-only scan maps the backend authority owners for six-test hub FAQ, CTA, claim boundaries, result promises, schema inputs, sitemap-source, and public lookup data. The current authority pattern is backend scale registry first, with frontend expected to consume public API/runtime data rather than inventing CMS-backed landing content.

Key finding:

- `scales_registry.content_i18n_json` is the current owner candidate for test landing localized content, including FAQ-like landing content.
- `scales_registry.seo_schema_json` / `seo_i18n_json` are the backend owner candidates for structured SEO inputs.
- `ScaleRegistrySeeder::catalogContent()` currently supports a zh FAQ override but otherwise falls back to `genericFaq()`.
- Public lookup routes under `/api/v0.3/scales/*` and sitemap-source routes expose scale registry data to consumers.
- Any repair to non-MBTI FAQ, claim boundaries, or free-result promises should be backend-authoritative and should not be implemented as fap-web fallback copy.

No code, data, CMS, runtime, sitemap, llms, schema, Search, deploy, or fap-web mutation was performed.
