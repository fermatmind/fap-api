# Career SEO/GEO Readiness Sources

REPAIR-SEO-GEO-1 normalizes planner-derived SEO/GEO readiness for the canonical eligibility audit.

The audit remains read-only. It does not deploy sitemap or LLMS files, crawl live HTML, mutate DB rows, publish occupations, or claim 2786 readiness.

## Source Mapping

`career:audit-canonical-eligibility` builds the SEO/GEO audit input from public-resolution planner rows. The mapper reads both top-level planner fields and nested workbook/export fields under `raw` and `seo`.

Accepted planner source evidence includes:

- sitemap policy: `ready_for_sitemap` or `Ready_For_Sitemap`
- LLMS policy: `ready_for_llms`, `Ready_For_LLMS`, `ready_for_llms_full`, or `Ready_For_LLMS_Full`
- citation metadata: EN/CN SEO title and description fields, including nested `seo.en_title`, `seo.en_description`, `seo.zh_title`, and `seo.zh_description`
- LLMS metadata source: EN/CN target queries plus `Search_Intent_Type` or `seo.search_intent_type`
- structured data source: explicit occupation schema JSON fields, or canonical slug, locale title, source code, and SEO metadata sufficient for the static structured-data source layer

## Missing vs Expected Not Ready

The auditor now separates absent evidence from explicit source policy:

- `sitemap_missing`: no sitemap readiness source was supplied
- `sitemap_expected_not_ready`: the source explicitly says sitemap is not ready
- `llms_missing`: no LLMS readiness source or metadata source was supplied
- `llms_expected_not_ready`: LLMS metadata exists but the source policy is not ready
- `llms_full_missing`: no LLMS-full readiness source or metadata source was supplied
- `llms_full_expected_not_ready`: LLMS-full metadata exists but the source policy is not ready

Expected-not-ready reasons still block eligibility. They are not publication defects and do not authorize rollout, DB mutation, sitemap deployment, LLMS deployment, or live crawling.

## Deferred Baseline Display Fields

`required_display_field_missing` is emitted by the baseline layer. SCAN-3 grouped it with broad SEO/GEO symptoms because the same workbook-derived display source is involved, but the code remediation for baseline display mapping belongs to `REPAIR-BASELINE-1`.
