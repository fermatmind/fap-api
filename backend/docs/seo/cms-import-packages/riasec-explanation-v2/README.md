# RIASEC Explanation CMS Import Package

Task: `SEO-ARTICLE-RIASEC-V2-CMS-IMPORT-PACKAGE-01`

Decision: **GO for CMS draft preflight; NO-GO for CMS draft creation without exact draft-only authorization.**

## Scope

This package mechanically maps the validated GPT-5.5 Pro RIASEC V2 content package into CMS import-package fields. Codex did not rewrite article copy, create CMS records, publish, submit search URLs, deploy, or access private URLs.

## Targets

- zh: `/zh/articles/riasec-holland-career-interest-test-explained`
- en: `/en/articles/what-is-riasec-holland-code-career-interest-test`

## Draft Defaults

- status=draft
- is_public=false
- is_indexable=false
- robots=noindex,nofollow
- published_at=null
- published_revision_id=null
- publish_allowed=false
- search_submit_allowed=false
- schema_enabled=false until visible FAQ preview is verified
- requires_operator_review=true

## Retained Blockers

- References require operator/editor source acceptance before publish.
- Psychometrics review is required before publish.
- Career hub links remain conditional.
- Cover image remains `__CMS_MEDIA_PLACEHOLDER_REQUIRED__`.

## Result

GO for `SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-PREFLIGHT-01`.
