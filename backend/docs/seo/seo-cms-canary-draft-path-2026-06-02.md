# FermatMind SEO CMS Canary Draft Path Verification — 2026-06-02

## Scope and guardrails

Task: `SEO-CMS-CANARY-DRAFT-PATH-01`

This verification is docs-only. It does not execute `SEO-CONTENT-P1-08`, does not create a CMS draft, does not publish, does not edit GPT-5.5 Pro V2 content assets, does not generate or modify article title/H1/meta/body/FAQ/CTA copy, and does not change runtime code, routes, migrations, tests, sitemap runtime, analytics runtime, CMS data, or frontend files.

The fap-web dependency ledger PR is merged:

- `SEO-CMS-CANARY-PREFLIGHT-LEDGER-01`: fap-web PR #1004, merged at `2026-06-02T14:01:06Z`, merge commit `52247a648702e97eb7e601e94f75338ba1b12f18`.

The backend canary support dependency is merged:

- `SEO-CMS-CANARY-BE-01`: fap-api PR #1852, merged at `2026-06-02T11:46:03Z`, merge commit `dc96157c0c226ed39c2036a27670c092bc54013f`, contained by `origin/main`.

## A. CMS Operator Permissions Confirmation

Result: **not confirmed for the current operator**.

Code evidence confirms the permission model:

- `ArticleResource::canCreate()` and `ArticleResource::canEdit()` require `ContentAccess::canWrite()`.
- `ContentAccess::canWrite()` accepts `admin.content.write`, `admin.content.publish`, or owner authority.
- Release/publish controls are separate: `ContentAccess::canRelease()` requires release/publish/owner authority.
- `ArticleResource` release action is gated by release permission, unpublished state, and editorial review approval.

This scan did not authenticate to CMS, did not inspect cookies/tokens, did not read secrets, and did not perform a CMS UI save. Therefore the specific human/operator account that would run the canary action still needs explicit confirmation for:

- read access to Article records,
- write access for draft creation/import only,
- no accidental release/publish permission use during this task,
- access to a safe, read-only collision check or approved importer dry-run.

## B. Hidden Slug and Translation Group Collision Check

Result: **not verified against production CMS/DB**.

Code evidence confirms the collision surfaces:

- Article public lookup and sitemap eligibility are not enough to detect hidden rows.
- Hidden collisions can exist as draft/private/noindex rows because public APIs require public readability.
- `EditorialPackageDraftImporter::existingArticle()` checks existing Article rows by public org, locale, and slug.
- `Article` supports `translation_group_id`; the importer accepts an explicit value and persists it when present.
- `ArticleSeoService::buildAlternates()` uses `translation_group_id` only among public indexable articles, so public SEO alternates cannot prove hidden translation group uniqueness.

Required read-only checks before any actual draft create:

- `articles.slug` collision for `zh-CN` slug `mbti-vs-holland-career-choice` in public org.
- `articles.slug` collision for `en` slug `mbti-vs-holland-code-career-choice` in public org.
- `articles.translation_group_id` collision for the intended bilingual canary group.
- Existing published/public article collision, which the importer refuses to mutate.

No authorized production CMS/DB read-only query was available in this run, so hidden collision state remains **unknown**.

## C. Draft Creation Path Decision

Recommended path: **controlled editorial package importer, after explicit dry-run and collision-check authorization**.

Manual ArticleResource UI is not the recommended path for this canary because it cannot directly and completely set the required canary metadata:

- `translation_group_id` is displayed as a placeholder/table field, not a complete editable creation control.
- CTA slots are not full direct ArticleResource form fields; public CTA output reads `editorial_package_v1.cta_slots`.
- FAQ package data is not a direct ArticleResource form field; public FAQ output reads `answer_surface_v1.faq_items`.
- A manual UI path increases the chance of mismatched bilingual metadata and incomplete SEO/answer surface payloads.

Controlled importer path is the recommended path because:

- `articles:import-editorial-package --dry-run` validates and plans without writing.
- `EditorialPackageDraftImporter` accepts `translation_group_id`, `answer_surface_v1`, CTA slots, target tests, references, media metadata, and SEO metadata from one package.
- Actual import creates or updates only draft/non-public/noindex Article state.
- Import refuses mutation of an existing published/public article.
- Tests cover draft status, `is_public=false`, `is_indexable=false`, `published_revision_id=null`, explicit `translation_group_id`, CTA slots, and FAQ package persistence.

Other backend controlled command/API path:

- `ArticleService::createArticle()` can create a draft and resolves slug uniqueness, but it does not provide the complete canary package path for explicit `translation_group_id`, CTA slots, and FAQ package metadata.
- `articles:import-local-baseline` is a baseline/importer path, not the preferred controlled editorial canary package path for this task.

## D. Controlled Importer Dry-Run Feasibility

Result: **feasible in code, not executed against production in this run**.

Dry-run capability:

- Command: `articles:import-editorial-package --file=... --locale=... --dry-run --json`
- The command routes dry-run to `EditorialPackageDraftImporter::planFromFile()`.
- Dry-run output includes `action`, `existing_article_id`, `would_write`, validation errors/warnings, body hash, answer surface hash, references count, and claim matches.
- Dry-run does not call `importFromFile()` and does not create or update Article rows.

Required authorization before running a real canary package dry-run:

- explicit permission to use the controlled importer in dry-run mode,
- explicit path to the already-approved canary editorial package file,
- explicit confirmation that dry-run may read the target environment,
- explicit instruction that no import/write follows the dry-run.

Because the current instruction forbids editing the GPT content package and forbids generating article copy, this run did not create or modify an editorial package payload.

## E. Preview Alternative Decision

Result: **safe preview PR is not yet justified; preview remains unresolved for publish readiness**.

Current public runtime behavior is fail-closed for drafts:

- Article list/detail/SEO public endpoints require `Article::publiclyReadable()`.
- `publiclyReadable()` requires `status=published`, `is_public=true`, `published_revision_id` present, and a published revision.
- Sitemap article URLs require `Article::publiclyIndexable()`, which adds `is_indexable=true`.
- Importer-created drafts set `status=draft`, `is_public=false`, `is_indexable=false`, and `published_revision_id=null`.

No dedicated safe rendered draft preview route was found in this scan. That means:

- Draft creation can be technically safe only after collision and operator/importer gates pass.
- Pre-publish rendered validation still needs either an accepted CMS-only review/importer artifact path or a future preview gate.
- `SEO-CMS-CANARY-PREVIEW-01` should execute only if `SEO-CMS-CANARY-DRAFT-PATH-01` follow-up proves no safe preview alternative is accepted.

## F. Draft Public Exposure Validation Checklist

Before any actual draft create:

- Confirm operator can create/import draft without invoking release/publish.
- Confirm hidden slug and translation group collision checks are clean.
- Run controlled importer dry-run and confirm `ok=true`, no blocking validation errors, and expected `would_write=1` or expected update target.
- Confirm package intended status is `draft` or `review_pending`, not published.
- Confirm no claim-warning bypass unless explicitly approved.

Immediately after any authorized draft create:

- Article row remains `status=draft`.
- Article row remains `is_public=false`.
- Article row remains `is_indexable=false`.
- Article row has `published_revision_id=null`.
- SEO meta remains `robots=noindex,nofollow` and `is_indexable=false`.
- Public article detail endpoint returns 404 for both target slugs.
- Public article SEO endpoint returns 404 for both target slugs.
- Sitemap excludes both target article URLs.
- `llms.txt` and `llms-full.txt` exclude both target article URLs.
- No sitemap submission, Baidu push, IndexNow, or other live search-channel action occurs.

Before any publish consideration:

- Published revision exists and matches approved content.
- Rendered page or accepted preview alternative is validated.
- Canonical, hreflang alternates, CTA bundle, FAQ structured data, robots, sitemap, and public API surfaces are checked.
- User gives a separate exact publish authorization through the controlled publish SOP.

## G. GO / NO-GO

Overall decision: **NO-GO to create a CMS draft now**.

Reason:

- Current operator permissions are not verified.
- Hidden CMS/DB slug collision state is not verified.
- Hidden `translation_group_id` collision state is not verified.
- Controlled importer dry-run is feasible but not yet authorized/executed against the target canary package.
- Safe rendered preview path is not confirmed; it is not necessarily a draft blocker, but it remains a publish-readiness blocker unless an accepted noindex/CMS-only alternative is approved.

Conditional path decision:

- **GO** to request the next authorization for controlled importer dry-run plus read-only collision checks.
- **NO-GO** for actual CMS draft creation until those checks pass.
- **NO-GO** for publish. Publish remains forbidden.

## H. Follow-Up Task Split

Recommended next authorization:

1. Authorize a read-only CMS/DB collision check for both target slugs and the intended `translation_group_id`.
2. Authorize `articles:import-editorial-package --dry-run --json` against the approved package file only.
3. If both pass, separately authorize actual draft import without publish.

Follow-up PR train items:

- `SEO-CMS-CANARY-DRAFT-PATH-01` follow-up execution: perform only the authorized read-only collision checks and controlled importer dry-run, then update this report/state.
- `SEO-CMS-CANARY-PREVIEW-01`: execute only if the dry-run/collision follow-up proves no safe preview/noindex alternative is accepted for pre-publish rendering validation.
- `SEO-CONTENT-P1-08`: remains blocked. It must not execute until the canary draft path and publish-readiness gates are explicitly cleared.

## I. Checks

Executed local checks for this docs-only PR:

```bash
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- docs/codex backend/docs/seo
git diff --cached --check
```

No CMS write, production data mutation, runtime code edit, test edit, frontend edit, GPT content edit, sitemap submission, Baidu push, IndexNow action, or publish action is part of this PR.
