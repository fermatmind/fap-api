# Backend Authority Owner Matrix

This matrix is based on repository-visible backend code and prior generated runtime readbacks. It is a planning artifact only.

## Source Evidence

| Evidence | Meaning |
| --- | --- |
| `backend/app/Models/ScaleRegistry.php` includes `seo_schema_json`, `seo_i18n_json`, `content_i18n_json`, and `report_summary_i18n_json` casts | Scale registry is a backend-authoritative content/SEO data container for test surfaces. |
| `backend/database/migrations/2026_02_15_160000_add_i18n_seo_content_fields_to_scales_registry.php` adds `seo_i18n_json`, `content_i18n_json`, and `report_summary_i18n_json` | The backend schema intentionally owns i18n SEO/content fields for scales. |
| `backend/database/seeders/ScaleRegistrySeeder.php` writes `seo_schema_json` and `content_i18n_json` for scales | Seed/import authority exists in backend. |
| `ScaleRegistrySeeder::catalogContent()` passes `faq: $zhFaq` only for zh override, otherwise uses generic locale content | Current generic FAQ behavior is backend-originated, not a frontend-only fact. |
| `ScaleRegistrySeeder::catalogLocaleContent()` returns `faq => $faq ?? $this->genericFaq(...)` | Non-overridden routes inherit generic FAQ from backend seed logic. |
| `/api/v0.3/scales`, `/api/v0.3/scales/catalog`, `/api/v0.3/scales/lookup`, `/api/v0.3/scales/sitemap-source` | Public scale data read paths are backend-owned. |
| `/api/v0.5/seo/sitemap-source` | Backend SEO sitemap-source authority exists for discoverability feeds. |
| `backend/app/Services/Scale/ScaleRegistryWriter.php` writes `seo_schema_json`, `seo_i18n_json`, `content_i18n_json`, and `report_summary_i18n_json` | Backend has a controlled writer path for scale registry fields. |

## Owner Matrix

| Surface | Current backend owner candidate | Current status | Repair implication |
| --- | --- | --- | --- |
| Landing title/meta/H1 inputs | `scales_registry.seo_i18n_json` and scale registry payload | backend-owned candidate | Do not repair in fap-web copy. |
| Landing body/FAQ-like content | `scales_registry.content_i18n_json` | backend-owned; generic fallback in seeder for most routes | Add scale-specific FAQ through backend authority path only. |
| FAQPage JSON-LD source inputs | scale registry localized FAQ data consumed by frontend/runtime schema renderer | backend-owned input, runtime renderer separate | Repair source data/adapter contract before schema-only changes. |
| CTA text/target | scale registry catalog/landing content plus frontend product flow wiring | mixed: backend content intent + frontend interaction target | CTA copy changes need backend authority; target-flow changes need frontend PR. |
| Free full/complete result promise | scale registry result/free-section metadata and product policy | backend/product authority needed | Big Five and IQ require policy/claim review before stronger copy. |
| Claim boundary | backend content fields plus claim-boundary docs/policy | backend/policy owner | IQ, career/admission, diagnostic, and guarantee claims require explicit review. |
| Public test route discoverability | `/api/v0.3/scales/sitemap-source`, `/api/v0.5/seo/sitemap-source`, sitemap/llms consumers | backend source, frontend/public surfaces consume | Do not edit sitemap/llms directly in repair PRs. |
| Result/report private URL boundary | protected attempt/result routes under API plus frontend public/private routing | backend auth and frontend route guard | Public SEO PRs must avoid private attempt/report/order/payment URLs. |

## Repair Sequencing Recommendation

1. Keep `SIX-TEST-HUB-FAQ-CTA-CLAIM-GAP-MATRIX-01` as the gap source.
2. Use this owner matrix to split repairs by backend authority layer:
   - scale registry content/FAQ source
   - claim-boundary/product-policy review
   - frontend adapter/schema parity only if backend source is already correct
3. Do not start title/meta/H1 or schema-only PRs until source authority is confirmed.
4. Do not mutate sitemap, `llms.txt`, or `llms-full.txt` as a proxy for content repair.

## Boundary

This PR does not modify `ScaleRegistrySeeder`, migrations, writer services, controllers, sitemap-source, public APIs, CMS rows, frontend rendering, or any runtime data.
