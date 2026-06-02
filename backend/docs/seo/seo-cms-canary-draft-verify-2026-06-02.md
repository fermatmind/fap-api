# SEO CMS Canary Draft Verify — 2026-06-02

Task: `SEO-CMS-CANARY-DRAFT-VERIFY-01`

This report verifies whether the first bilingual SEO canary can safely enter a controlled importer draft-only flow. It does not create a CMS draft, does not publish, does not save CMS forms, does not call production write APIs, does not edit GPT-5.5 Pro content packages, does not generate or modify title/H1/meta/body/FAQ/CTA copy, and does not change runtime code, routes, migrations, tests, sitemap runtime, analytics runtime, or frontend files.

Dependencies verified:

- `SEO-CMS-CANARY-BE-01`: fap-api PR #1852 is merged.
- `SEO-CMS-CANARY-DRAFT-PATH-01`: fap-api PR #1855 is merged.

Target canary:

- zh slug: `mbti-vs-holland-career-choice`
- en slug: `mbti-vs-holland-code-career-choice`
- translation group: `article_mbti_vs_holland_career_choice_v1`

## A. Operator Permissions

Result: **partially confirmed by code; current operator remains Unknown**.

Confirmed by code:

1. A draft-capable Article operator role exists in the RBAC model. `ROLE_CONTENT` receives `admin.content.read`, `admin.content.write`, and `admin.content.probe`.
2. A create/edit-but-not-release role exists. `ContentAccess::canWrite()` accepts `admin.content.write`, while `ContentAccess::canRelease()` requires `admin.content.release`, `admin.content.publish`, or owner authority.
3. `ArticleResource::canCreate()` and `ArticleResource::canEdit()` use `canWrite()`.
4. Publish/release requires higher permission and editorial approval. The ArticleResource release action is visible only when `canRelease()` is true, the article is not already published, and latest editorial review state is approved. `releaseRecord()` repeats those checks and requires a publishable revision.

Unknown:

- The current human/operator account was not authenticated through CMS in this run.
- No cookies, tokens, env secrets, or live CMS session data were read.
- Therefore current operator draft create/edit permission is **Unknown**.

## B. Hidden Collision Check

Result: **public absence passed; hidden CMS/DB collision remains Unknown**.

Read-only public checks:

- `GET https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice?locale=zh-CN` returned `404`.
- `GET https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice/seo?locale=zh-CN` returned `404`.
- `GET https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice?locale=en` returned `404`.
- `GET https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en` returned `404`.
- `sitemap.xml`, `llms.txt`, and `llms-full.txt` did not contain either target slug.

Code confirms why public absence is insufficient:

- Public article detail and SEO endpoints use `Article::publiclyReadable()`.
- `publiclyReadable()` requires `status=published`, `is_public=true`, `published_revision_id` present, and a published revision.
- Sitemap article URLs use `Article::publiclyIndexable()`, which adds `is_indexable=true`.
- Hidden draft/private/noindex/unpublished records can therefore be absent from public API, sitemap, and LLM files.

Not verified:

- CMS/DB hidden draft row for `zh-CN` slug `mbti-vs-holland-career-choice`.
- CMS/DB hidden draft row for `en` slug `mbti-vs-holland-code-career-choice`.
- CMS/DB row with `translation_group_id=article_mbti_vs_holland_career_choice_v1`.
- Any noindex/private/unpublished record collision.

Because no authorized production CMS/DB read-only collision path was available in this run, hidden collision status is **Unknown** and must not be treated as passed.

## C. Controlled Importer Dry-Run

Result: **importer dry-run capability confirmed; target canary dry-run not passed**.

Confirmed by code:

1. `articles:import-editorial-package` has `--dry-run`.
2. With `--dry-run`, the command calls `EditorialPackageDraftImporter::planFromFile()` rather than `importFromFile()`.
3. `plan()` validates the normalized package, resolves existing Article by public org + locale + slug, emits `action`, `existing_article_id`, `would_write`, body hash, answer surface hash, references count, claim matches, warnings, and errors.
4. Importer normalization supports `translation_group_id`, locale, slug, `answer_surface_v1`, and `cta_slots`.
5. Actual import, if separately authorized later, sets `status=draft`, `is_public=false`, `is_indexable=false`, and `published_revision_id=null`, and writes SEO meta as `robots=noindex,nofollow`.
6. Existing published/public articles are refused by importer action planning and runtime import guard.
7. Test coverage includes the exact translation group string `article_mbti_vs_holland_career_choice_v1`, draft-only state, CTA slots, and FAQ package persistence.

Not passed for the target canary:

- No approved GPT-5.5 Pro V2 canary editorial package artifact was present or provided for the exact bilingual target.
- This run did not create, edit, infer, or generate a content package.
- Therefore no target `articles:import-editorial-package --dry-run --json` was executed for the first bilingual canary.
- The importer dry-run has not validated the actual zh/en package pair, shared `translation_group_id`, different slugs, CTA slots, FAQ package, or validation report for this target.

User-provided artifact needed:

- Yes. A pre-approved CMS-ready GPT-5.5 Pro V2 editorial package artifact is required for both locales before the controlled dry-run can be meaningful.

## D. Draft-Only Preview Alternative

Result: **preview does not appear to block draft creation, but it still blocks publish readiness unless an accepted alternative is approved**.

Based on code and prior DRAFT-PATH report:

- Drafts are fail-closed from public Article API and sitemap surfaces when they remain `draft`, non-public, noindex, and without `published_revision_id`.
- CMS-only inspection plus controlled dry-run plus public API/sitemap/LLM absence checks can be a reasonable draft creation gate only after operator permissions and hidden collisions are confirmed.
- No dedicated rendered draft preview route was found.
- Preview absence should be treated as a publish-readiness blocker, not a draft blocker, unless product/SEO owners explicitly require rendered preview before draft creation.

Current state:

- Because hidden collision and target dry-run are not passed, draft creation is blocked regardless of preview.
- `SEO-CMS-CANARY-PREVIEW-01` is not required before this docs-only PR can merge.
- `SEO-CMS-CANARY-PREVIEW-01` should be executed only if the next authorized verification proves that no CMS-only/noindex alternative is acceptable before publish.

## E. SEO-CONTENT-P1-08 Decision

Decision: **NO-GO: do not create CMS draft yet**.

`SEO-CONTENT-P1-08` must remain blocked because:

- Current operator permissions are not confirmed.
- Hidden CMS/DB slug collisions are not confirmed.
- Hidden `translation_group_id` collision is not confirmed.
- The actual target canary importer dry-run did not run and did not pass.
- Required GPT-5.5 Pro V2 content package artifact was not provided.

The recommended draft creation path remains:

1. Use the controlled editorial package importer.
2. First run authorized read-only CMS/DB collision checks.
3. Then run authorized `articles:import-editorial-package --dry-run --json` against the approved zh/en canary package artifacts.
4. Only after those pass, request separate authorization for draft-only import.

Publish remains forbidden.

## F. Checks

Executed validation for this docs-only PR:

```bash
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- docs/codex backend/docs/seo
git diff --cached --check
```

No CMS write, draft create, publish, production DB mutation, runtime edit, test edit, GPT content edit, sitemap submission, Baidu push, IndexNow action, or frontend change was performed.
