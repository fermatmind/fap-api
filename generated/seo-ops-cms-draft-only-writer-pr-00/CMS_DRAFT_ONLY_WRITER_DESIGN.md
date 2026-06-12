# CMS Draft-Only Writer Design

Task: SEO-OPS-CMS-DRAFT-ONLY-WRITER-PR-00

Branch: `codex/cms-draft-only-writer-00`

## Summary

Added a narrow Laravel console command for GPT-5.5 Pro Mode C bilingual SEO article packages:

```bash
php artisan articles:import-seo-content-package-draft
```

The writer validates a Mode C content package and can create or update CMS Article draft working revisions only. It does not publish and does not enable discoverability surfaces.

## Files Added

- `backend/app/Console/Commands/ArticleImportSeoContentPackageDraft.php`
- `backend/app/Services/Cms/SeoContentPackage/SeoContentPackageDraftImporter.php`
- `backend/tests/Feature/Console/ArticleImportSeoContentPackageDraftCommandTest.php`

## File Modified

- `backend/app/Console/Kernel.php`

## Supported Input Tree

The importer requires:

- `manifest.json`
- `pages/*.md`
- `cms/CMS_FIELDS_*.json`
- `cms/CMS_IMPORT_DRAFT_*.json`
- `contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json`
- `contracts/ROUTE_ALIAS_CONTRACT.json`
- `contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json`
- `contracts/DYNAMIC_CTA_CONTRACT.json`
- `contracts/INTERNAL_LINK_PLAN.json`
- `contracts/PRIVATE_URL_GUARD.json`
- `review/claim_gate.md`
- `review/operator_review.md`
- `codex/qa_checklist.md`

## Draft Write Behavior

Non-dry-run writes:

- create or update matching Article draft by `translation_group_id + locale + slug`
- save a human-review working revision
- keep `status=draft`
- keep `is_public=false`
- keep `is_indexable=false`
- keep `sitemap_eligible=false`
- keep `llms_eligible=false`
- keep `published_at=null`
- keep `published_revision_id=null`
- create/update `ArticleSeoMeta` with `robots=noindex,nofollow`
- store internal package gate metadata under `editorial_package_v1`

The command blocks published/public existing articles and never calls `ArticlePublishService`, `ContentReleaseAudit`, cache invalidation, ISR, or search submission paths.
