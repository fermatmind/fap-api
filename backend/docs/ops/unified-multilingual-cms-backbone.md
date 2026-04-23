# Unified Multilingual CMS Backbone

## What is covered

The Ops CMS multilingual backbone now spans four editorial content types:

- `articles`
- `support_articles`
- `interpretation_guides`
- `content_pages`

The public frontend contracts are unchanged. This PR adds backend workflow, metadata, auditability, and publish follow-up behavior.

## Contract shape

All supported content types now carry the same core translation metadata:

- `translation_group_id`
- `source_locale`
- `translation_status`
- `source_content_id` or `source_article_id`
- `source_version_hash`
- `translated_from_version_hash`

`articles` remain revision-backed and continue to use:

- `working_revision_id`
- `published_revision_id`
- `article_translation_revisions`

`support_articles`, `interpretation_guides`, and `content_pages` are now sibling-row-backed:

- one canonical source row
- one sibling row per target locale
- stale detection from source hash drift
- publish/approve/review state managed on the sibling row itself

## Ownership rule

All multilingual editorial content must live on the public editorial org surface:

- `org_id = 0`

This now applies by design to:

- article translation workflow
- row-backed translation workflow for support articles
- row-backed translation workflow for interpretation guides
- row-backed translation workflow for content pages

Current tenant/workspace context must not change canonical translation ownership.

## Ops console

The existing `/ops/article-translation-ops` page is now a unified console.

It supports:

- content type filter
- slug search
- source locale filter
- target locale filter
- translation status filter
- stale/current filter
- published/unpublished filter
- missing locale filter
- ownership mismatch filter

Each group shows:

- content type
- translation group id
- canonical source row
- locale coverage matrix
- stale alerts
- ownership alerts
- preflight blockers
- source hash vs translated-from hash
- open source / open target actions

## Action support

### Fully supported

`articles`

- create translation draft
- re-sync from source
- promote to human review
- approve translation
- publish translation
- archive stale revision

### Supported on row-backed types

`support_articles`, `interpretation_guides`, `content_pages`

- promote to human review
- approve translation
- publish translation
- archive stale unpublished translation
- open source
- open target

### Conditionally supported

Machine-draft creation and re-sync depend on a configured provider.

When no provider is configured:

- the console shows the action as disabled
- the reason is rendered in the UI
- no fake translation is performed

### Intentionally disabled

Published re-sync for row-backed content types is disabled.

Reason:

- current public reads for these content types still come from the base row
- overwriting a published row with a new machine draft would leak draft content
- these content types need a later revision-backed or draft-shadow cutover before safe draft-over-published re-sync

## Provider integration

Two layers now exist:

- `ArticleMachineTranslationProvider`: existing article-specific provider contract
- `CmsMachineTranslationProviderRegistry`: shared registry for multilingual CMS content types

Default behavior:

- `article` resolves to the article bridge provider
- other content types resolve to the disabled provider unless explicitly configured

This keeps the provider boundary clean and makes future provider plug-in work type-aware.

## Publish invalidation

Publish follow-up remains webhook-driven through `ContentReleaseAudit` and `ContentReleaseFollowUp`.

Now covered content types:

- `article`
- `support_article`
- `interpretation_guide`
- `content_page`

Signals emitted after publish:

- `content_release_publish`
- `content_release_cache_signal`
- `content_release_broadcast` when broadcast webhook is configured

Failure behavior:

- failed invalidation/broadcast attempts are logged to `audit_logs`
- Ops alert webhook is notified when configured

Additional source-side hook:

- saving a published `support_article`, `interpretation_guide`, or `content_page` through the Ops CMS create/edit page now emits the same release follow-up signal

## Stale semantics

Stale is detected by comparing:

- latest source `source_version_hash`
- target `translated_from_version_hash`

For articles:

- stale can be re-synced into a new working revision under the same canonical target article

For row-backed content:

- stale is visible in the console
- publish preflight blocks stale targets
- archive stale unpublished target is supported
- published re-sync remains disabled until revision-backed editing exists

## Validation baseline

Recommended validation commands:

```bash
vendor/bin/pint --test
php artisan test tests/Feature/Ops/ArticleTranslationOpsPageTest.php tests/Feature/Ops/CmsTranslationBackboneTest.php tests/Feature/Ops/PostReleaseObservabilityPageTest.php tests/Feature/Ops/ContentCmsProductLayerTest.php tests/Feature/V0_5/ArticlePublicApiTest.php tests/Feature/Ops/ArticleTranslationRevisionContractTest.php tests/Feature/V0_5/SupportTrustCmsApiTest.php tests/Feature/Architecture/ServiceLayerBoundaryTest.php
php artisan fap:schema:verify
php artisan route:list
```

## Deferred follow-up

Still deferred for a later PR:

- real provider implementations for non-article content types
- row-backed published re-sync via revision-backed editing
- frontend consumption of multilingual support articles / interpretation guides / content pages beyond current public contract
- a dedicated invalidation queue with retry state surfaced directly in the translation console
