# MBTI vs Holland Importer Adapter Notes

Date: 2026-06-03

Task: `SEO-CMS-CANARY-PACKAGE-ADAPTER-01`

## Purpose

These adapter artifacts mechanically map the approved GPT-5.5 Pro V2 canary package artifacts into the current `articles:import-editorial-package` importer shape.

They are for importer dry-run validation only.

## Source artifacts

- `backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.json`

## Adapter artifacts

- `backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.importer-adapter.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.importer-adapter.json`

## Content authority

- Content owner: GPT-5.5 Pro.
- Codex did not author, rewrite, expand, polish, or localize the article content.
- `body_markdown` is copied byte-for-byte from each approved source artifact.
- `title`, `h1`, `seo_title`, `seo_description`, `excerpt`, FAQ items, CTA labels, and CTA hrefs are copied or mechanically aliased from the approved source artifacts.
- CMS/backend remains the final content authority.

## Mechanical mapping

- `seo_description` -> `meta_description`
- `canonical_path` -> `canonical` by prefixing `https://fermatmind.com`
- `faq` -> `answer_surface_v1.faq_items`
- `cta_slots[*].target_test_slug` or CTA href basename -> `target_tests`
- `internal_links[*].href` -> `internal_links`
- `claim_boundary_checklist` list values -> `claim_boundary_notes`
- `reference_notes_for_editor[*].title + url` -> `references`

## Operational adapter metadata

The following values are importer compatibility metadata, not editorial content creation:

- `package_version=editorial_package.v1`
- `content_track=evergreen_knowledge`
- `topic_cluster=riasec`
- `content_series=seo-cms-canary`
- `audience_intent=career_decision`
- `commercial_priority=low`
- `signal_source=RIASEC`
- `signal_type=interest`
- `decision_domains=["career"]`
- `target_topics=["riasec", "mbti"]`
- `cover_image=__CMS_MEDIA_LIBRARY_PLACEHOLDER__`
- `cover_image_prompt=__CMS_MEDIA_LIBRARY_PLACEHOLDER__`
- `cover_image_style_tag=cms-placeholder`
- `review_required_by=["editor", "psychometrics"]`

These values are conservative placeholders for dry-run compatibility. They do not authorize CMS draft creation, media selection, publish, or search submission.

## Publish and draft gates

- `publish_gate.publish_allowed=false` remains required.
- `adapter_publish_gate.publish_allowed=false` is included.
- `intended_status=draft`.
- `indexability=false`.
- No CMS draft is authorized by these adapter artifacts.
- No publish action is authorized by these adapter artifacts.

## Hidden collision status

Production CMS/DB readonly hidden collision remains Unknown in this PR.

This PR does not access production CMS/DB, read env/cookies/tokens, or inspect private CMS records.

Before `SEO-CONTENT-P1-08` or any draft creation, an authorized operator must verify:

- no hidden/private/unpublished article exists for `mbti-vs-holland-career-choice`
- no hidden/private/unpublished article exists for `mbti-vs-holland-code-career-choice`
- no existing record uses `translation_group_id=article_mbti_vs_holland_career_choice_v1`

Unknown hidden collision does not authorize CMS draft creation.

## Current adapter dry-run result

The adapter resolves the prior raw V2 artifact schema mismatch that produced `Array to string conversion` for object-shaped `internal_links`.

However, the current importer still blocks the Chinese adapter without content changes:

- `evergreen_definition_required`
- `evergreen_method_required`
- `claim_boundary_forbidden_phrase` for the boundary-context sentence containing `一定适合`

The English adapter passes with `content_track=evergreen_knowledge` in a local temporary SQLite dry-run environment.

Therefore this PR is still NO-GO for `SEO-CONTENT-P1-08`.

## Forbidden actions

- Do not create a CMS draft.
- Do not create a real Article.
- Do not publish.
- Do not call production write APIs.
- Do not submit sitemap.
- Do not call Baidu API push.
- Do not call IndexNow.
- Do not use the adapter as frontend runtime content.
