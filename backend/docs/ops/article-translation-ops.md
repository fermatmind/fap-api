# Article Translation Ops Console

This runbook documents the backend-only editorial article translation workflow exposed in Ops CMS.

## Scope

- Applies only to editorial `articles` and `article_translation_revisions`.
- Does not change public article route resolution, locale fallback rules, support articles, interpretation guides, or content pages.
- Article body copy, slugs, citations, references, and DOI content remain editorial data and are not rewritten by the console itself.

## Ownership Rule

Editorial article translations are owned by the public editorial surface by design:

- `articles.org_id = 0`
- `article_translation_revisions.org_id = 0`
- `article_seo_meta.org_id = 0`

The current Ops workspace organization must not decide ownership for article-owned translation rows or sidecars. Create, re-sync, approve, and publish paths enforce this public editorial ownership before exposing a translation.

## Actions

The console exposes these actions from a single translation group surface:

- Open source article: enabled when a canonical source row exists.
- Open target article: enabled for every locale article row.
- Create translation draft: enabled only when a machine translation provider binding is configured, the target locale is missing, and the operator has content write permission.
- Re-sync from source: enabled only for stale target translations when a provider is configured.
- Promote to human review: enabled for current `machine_draft` working revisions.
- Approve translation: enabled for current `human_review` working revisions that pass preflight.
- Publish current working revision: enabled only for approved working revisions that pass preflight.
- Archive stale revision: enabled for stale non-published working revisions.

When no provider is configured, machine draft and re-sync actions remain disabled with an explicit reason. The service contract is still present so a provider can be bound without changing the console action surface.

## Preflight

Publish and approval use the translation workflow preflight guard. Blocking conditions include:

- target article is source
- target locale missing
- working revision missing, stale, archived, or ownership-mismatched
- source canonical invalid
- source linkage, `source_locale`, or `translation_group_id` mismatch
- `article_seo_meta` ownership mismatch
- references/citations presence check failed

The references/citations check is intentionally a presence guard, not a semantic citation validator. If the source contains reference markers, the target working revision must retain a reference marker before approval or publish.

## Stale Re-sync

When a source changes after target translation:

- The target canonical article is reused.
- A new `machine_draft` revision is created on the same target article.
- `supersedes_revision_id` points at the prior working revision.
- Existing published revision history remains intact.
- `working_revision_id` moves to the new machine draft.

No sibling target article row should be created during stale re-sync.

## Coverage and Compare Surface

The group detail panel shows:

- configured target locales from `ARTICLE_TRANSLATION_TARGET_LOCALES` (default: `en`)
- existing locales
- published locales
- machine draft locales
- human review locales
- stale locales
- missing target locales
- source hash and translated-from hash comparison
- working revision vs published revision relationship
- preflight status and blockers

This is the operational source of truth for determining whether an article translation is ready for the next workflow action.
