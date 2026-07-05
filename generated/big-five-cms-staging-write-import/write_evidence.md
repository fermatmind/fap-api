# Big Five CMS Staging Write Evidence

## Summary
- Target environment: `staging`
- Package SHA-256: `15d6b6df08cf3ce7c9cd8a859b566c5bfd5fc4f6c6b279c493d48bc9e447ebc6`
- Row count: `42`
- Dry-run status: `pass`, writes committed: `False`
- Write status: `pass`, writes committed: `True`
- Created assets: `42`
- Updated assets: `0`
- Skipped existing assets: `0`

## Gates
- Publish attempted: `False`
- Index attempted: `False`
- Sitemap / llms release attempted: `False`
- Runtime JSON-LD release attempted: `False`

## Readback Gate
- Rows found by source hash: `42`
- `is_public=true`: `0`
- `index_eligible=true`: `0`
- `sitemap_eligible=true`: `0`
- `llms_eligible=true`: `0`
- `published_at` not null: `0`
- Robots values: `noindex,follow`
- Launch states: `review`
- Review states: `cms_import_draft_pending_review`
- FAQ-like body sections: `0`
- Runtime JSON-LD enabled rows: `0`

## Rollback Handle
- Import batch id: `big5-cms-staging-15d6b6df08cf-20260705140633`
- Source package: `big-five-cms-import-draft-polished.v2`
- Source hash: `15d6b6df08cf3ce7c9cd8a859b566c5bfd5fc4f6c6b279c493d48bc9e447ebc6`
- Restore note: Restore pre_import_snapshot rows or delete rows matching deterministic_delete_criteria in staging/dev only. Do not touch production.

## Boundaries
- No production import was run.
- No publish, indexability, sitemap, llms, runtime JSON-LD, search submission, manual deploy, or production deploy was triggered.
- This evidence records staging/dev draft import only.
