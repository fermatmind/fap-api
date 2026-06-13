# Indexability Audit

## Result

Status: `PASS`

No Big Five content asset in this PR is indexable.

## Rules Enforced

- `index_eligible=true` requires `launch_state=published`
- `index_eligible=true` requires `robots=index,follow`
- `robots=index,follow` requires published indexable assets
- `sitemap_eligible` and `llms_eligible` require published indexable assets
- Model save logic forces sitemap/llms false unless the asset is published, indexable, and `robots=index,follow`

## Public API Visibility

`content_ready` assets are visible to the public API for fap-web noindex render consumption. They are not indexable and do not enter sitemap/llms.

`content_stub` facet assets are hidden from the public API and remain taxonomy/future-draft records.

## Evidence

- Code evidence: `backend/app/Models/PersonalityPublicContentAsset.php`
- Contract evidence: `backend/app/Services/Cms/PersonalityPublicContentAssetContract.php`
- Test evidence: `test_write_import_is_idempotent_and_exposes_only_render_candidates`

