# SEO Content Package Importer Contract

This runbook records the daily bilingual article package boundary before CMS
draft import. It documents current Stage 4/manual normalization and importer
dry-run behavior; it does not authorize CMS writes, publish, search,
discoverability mutation, schema/hreflang rollout, revalidation, or deploy.

## Required Importer-Compatible Projections

For slug `<slug>`, the final derived package contains:

```text
pages/zh-CN-<slug>.md
pages/en-<slug>.md
cms/CMS_IMPORT_DRAFT_zh-CN_<slug>.json
cms/CMS_IMPORT_DRAFT_en_<slug>.json
cms/CMS_FIELDS_zh-CN_<slug>.json
cms/CMS_FIELDS_en_<slug>.json
contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json
contracts/ROUTE_ALIAS_CONTRACT.json
contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json
contracts/PRIVATE_URL_GUARD.json
codex/qa_checklist.md
```

Generic authoring files may coexist, but import uses the verified
locale-and-slug projections. `body_markdown_file` must resolve inside the final
derived package.

## Identity And Field Mapping

- zh-CN and en share one `translation_group_id` of at most 64 characters.
- Both locales use the locked slug and locale-specific public canonical path.
- Map `seo_title` to `meta_title`.
- Map `canonical_url` to `canonical_path`.
- Map `category_suggestion` to `category_name`.
- Project the selected CTA to `primary_cta.href` and `primary_cta.label`.
- Validate backend title and description limits before import; do not silently
  truncate meaning.
- Keep Article, BreadcrumbList, and FAQPage eligibility false in the draft
  package and preserve draft/no-publish/no-index/no-sitemap/no-llms, schema,
  hreflang, search, and revalidation holds.

FAQ, CTA, internal links, and body visual references must agree across page
markdown, CMS fields, and CMS import draft. A required body visual must have a
manifest entry and matching CMS metadata/markdown projection at the declared
body anchor and answer block.

## Deterministic Derived Package Rule

Stage 4 produces exactly one `FINAL_DERIVED_IMPORT_READY_PACKAGE` and records:

- source package absolute path and SHA-256;
- derived package absolute path and SHA-256;
- every deterministic normalization;
- importer dry-run readiness;
- unchanged body, claims, FAQ, CTA destinations, internal-link destinations,
  identity, source images, and release state.

If a repair is ambiguous or would alter those protected surfaces, stop before
CMS import. Do not create serial media, compatibility, and metadata packages in
Stage 5.

## Dry-Run First

Run the existing SEO content package draft importer in dry-run mode against the
final derived package before any draft write. The dry-run must pass identity,
file, field, metadata, route/private-URL, media, and hold-state checks. Use the
command help from the deployed production revision to obtain the exact current
options; do not copy an unverified command shape from an old artifact.

Media Library readiness is separate. Follow
`docs/runbooks/seo-daily-media-release-runner.md` for source/CDN/variant/CMS
image metadata work, then prove preview/public body visual parity in the article
release chain.

## Proposed Capabilities

**Proposed capability - not yet implemented:**

- `seo-agent:compile-mode-c-package`;
- automatic checkpoint persistence and `--resume-from-checkpoint`;
- automatic provider capability routing;
- backend enforcement that blocks GEO closeout when a required body visual is
  absent from the public body.

Until implemented, Codex performs documented deterministic Stage 4
normalization and uses existing importer/Media Library dry-runs. These proposed
names are not executable instructions.
