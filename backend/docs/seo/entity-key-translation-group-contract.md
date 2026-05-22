# Entity Key and Translation Group Contract

## Purpose

SEO-OBS-GOV-04 defines stable entity identity for locale pairing, SEO
observation, internal links, and future locale compare.

This PR is contract-only. It does not mutate CMS content, run backfill, add a
real migration, modify `fap-web`, use frontend fallback authority, deploy, or
edit production environment.

## Required Future Key

The preferred future key is `translation_group_uuid`.

`entity_key` should prefer `translation_group_uuid` for all multi-locale
entities. It should be stable across EN/ZH and any later locale.

## Allowed Transitional Key

`translation_group_id` is allowed only where it already exists today. It is a
transitional key, not the long-term authority.
translation_group_id is allowed only where it already exists today.

If `translation_group_uuid` is absent:

- use existing `translation_group_id` only where already supported
- otherwise mark `legacy_unpaired`
- slug/title similarity may be used only as a migration helper, not authority

## Entity Key Format

Future `entity_key` format:

`translation_group_uuid:<uuid>`

Transitional format:

`translation_group_id:<source_table>:<translation_group_id>`

Legacy unpaired format:

`legacy_unpaired:<surface>:<locale>:<stable_slug_or_id>`

The legacy format is for observation gaps only. It must not become permanent
locale-pair authority.

## Locale Pair Rules

- Locale comparison must group by `entity_key`.
- EN/ZH pairs require the same preferred `translation_group_uuid`.
- If only transitional `translation_group_id` exists, mark the row as
  `transitional_paired`.
- If neither key exists, mark the row as `legacy_unpaired`.
- Missing locale peers are observations, not automatic content tasks.

## Surface Coverage

Required surfaces:

- Research reports
- Articles
- Topics
- Personality pages
- Career guides
- Career jobs
- Test landing/detail pages
- Content/support pages

## Surface Policy

Research reports:

- Future authority should be `translation_group_uuid`.
- Current same-slug pairing may be used only as migration helper.

Articles:

- Existing `translation_group_id` is a transitional key.
- Future authority should be `translation_group_uuid`.

Topics:

- Future authority should be `translation_group_uuid`.
- Slug similarity is not final authority.

Personality pages:

- Future authority should be `translation_group_uuid` or a backend-declared
  canonical type group.
- Frontend route/type fallback must not define locale-pair truth.

Career guides and career jobs:

- Future authority should be `translation_group_uuid` or a backend-declared
  career entity group.
- Crawler-derived pairing is forbidden.

Test landing/detail pages:

- Future authority should be backend scale/catalog identity plus
  `translation_group_uuid` where localized content diverges.

Content/support pages:

- Existing `translation_group_id` is transitional where already present.
- Future authority should be `translation_group_uuid`.

## Backfill Plan

The future backfill must be a separate approved task. It should:

1. Add schema only after human approval.
2. Dry-run mapping by surface.
3. Use existing `translation_group_id` where present.
4. Mark unpaired legacy content without mutation.
5. Use slug/title similarity only as a candidate suggestion.
6. Require human review before any content or key mutation.

## Forbidden Authority

- no automatic content mutation
- no frontend fallback pairing
- no crawler-derived pairing
- no title/slug similarity as final authority
- no search engine response pairing
- no local copy authority
- no static sitemap/llms authority

## Final Decision

`entity_key_translation_group_contract_ready_without_migration`

Next task: `SEO-OBS-GOV-05`
