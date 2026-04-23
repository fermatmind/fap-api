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

`support_articles`, `interpretation_guides`, and `content_pages` now use sibling rows with a shared shadow revision layer:

- one canonical source row
- one sibling row per target locale
- one `cms_translation_revisions` history per row-backed locale record
- `working_revision_id` and `published_revision_id` pointers on the base row
- stale detection from source hash drift
- publish/approve/review state managed on the working revision and surfaced on the row

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

- create or hydrate initial shadow revision
- re-sync from source into a new working revision
- promote to human review
- approve translation
- publish translation
- archive stale working revision
- open source
- open target

### Conditionally supported

Machine-draft creation and re-sync depend on a configured provider.

When no provider is configured:

- the console shows the action as disabled
- the reason is rendered in the UI
- no fake translation is performed

### Current shadow behavior

For published row-backed translations:

- the base row remains the public owner
- machine re-sync creates a new working revision instead of overwriting the base row
- the base row keeps serving the previously published payload until publish is executed
- publishing copies the working revision payload back onto the base row and advances `published_revision_id`

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
- re-sync forks a new working revision under the same locale row
- internal/editor reads can hydrate from the working revision
- public reads stay on the base published row until publish advances the pointer

## Validation baseline

Recommended validation commands:

```bash
vendor/bin/pint --test
php artisan test tests/Feature/Ops/ArticleTranslationOpsPageTest.php tests/Feature/Ops/CmsTranslationBackboneTest.php tests/Feature/Ops/PostReleaseObservabilityPageTest.php tests/Feature/Ops/ContentCmsProductLayerTest.php tests/Feature/V0_5/ArticlePublicApiTest.php tests/Feature/Ops/ArticleTranslationRevisionContractTest.php tests/Feature/V0_5/SupportTrustCmsApiTest.php tests/Feature/Architecture/ServiceLayerBoundaryTest.php
php artisan test tests/Feature/Ops/CmsTranslationShadowRevisionTest.php
php artisan fap:schema:verify
php artisan route:list
```

## Deferred follow-up

Still deferred for a later PR:

- real provider implementations for non-article content types
- frontend consumption of multilingual support articles / interpretation guides / content pages beyond current public contract
- a dedicated invalidation queue with retry state surfaced directly in the translation console
