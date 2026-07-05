# Big Five CMS Preview / Readback QA

## Summary
- Target environment: `staging`
- Source hash: `15d6b6df08cf3ce7c9cd8a859b566c5bfd5fc4f6c6b279c493d48bc9e447ebc6`
- Row count: `42`
- Preview payload count: `42`
- Public API visible draft rows: `0`
- FAQ duplicate render risk count: `0`
- Runtime JSON-LD enabled count: `0`

## Gates
- Public API draft blocked: `True`
- Sitemap blocked: `True`
- llms blocked: `True`
- JSON-LD runtime blocked: `True`
- is_public=true: `0`
- index_eligible=true: `0`
- sitemap_eligible=true: `0`
- llms_eligible=true: `0`
- published_at not null: `0`
- robots values: `noindex,follow`
- launch states: `review`
- review states: `cms_import_draft_pending_review`

## Boundaries
- Read-only staging QA; no CMS write was performed by this QA step.
- No production import, publish, indexability release, sitemap/llms release, runtime JSON-LD release, search submission, manual deploy, or production deploy was triggered.
