# SEO CMS Canary Import Dry-Run Verification

Date: 2026-06-02

Task: `SEO-CMS-CANARY-IMPORT-DRYRUN-01`

PR scope: docs-only verification for the first bilingual SEO canary package artifacts.

## Decision

NO-GO: do not create CMS draft yet.

`SEO-CONTENT-P1-08` must remain blocked. Publish remains forbidden.

## Source artifacts

- `backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.package-notes.md`

Codex did not author, rewrite, expand, polish, or localize GPT-5.5 Pro content. The package artifacts were used as-is for validation and dry-run attempts.

## A. Manifest / state

Result: `SEO-CMS-CANARY-IMPORT-DRYRUN-01` was not present in `docs/codex/pr-train.yaml` or `docs/codex/pr-train-state.json` before this task.

Action taken under user authorization:

- Added `SEO-CMS-CANARY-IMPORT-DRYRUN-01` to `docs/codex/pr-train.yaml`.
- Added `SEO-CMS-CANARY-IMPORT-DRYRUN-01` execution state to `docs/codex/pr-train-state.json`.
- Reconciled `SEO-CONTENT-CANARY-PACKAGE-01` as merged because GitHub PR #1857 is merged and `origin/main` contains merge commit `e7d5f14d55be02bd9f74bb2a9fbdd9ccd0e30be4`.

## B. Package artifact validation

Result: PASS.

Checks:

- Both JSON files parse.
- `locale` values are `zh-CN` and `en`.
- Slugs are:
  - `mbti-vs-holland-career-choice`
  - `mbti-vs-holland-code-career-choice`
- `translation_group_id` is consistent:
  - `article_mbti_vs_holland_career_choice_v1`
- `publish_gate.publish_allowed=false` in both artifacts.
- CTA targets are public canonical test routes:
  - `/zh/tests/holland-career-interest-test-riasec`
  - `/zh/tests/mbti-personality-test-16-personality-types`
  - `/zh/tests/big-five-personality-test-ocean-model`
  - `/en/tests/holland-career-interest-test-riasec`
  - `/en/tests/mbti-personality-test-16-personality-types`
  - `/en/tests/big-five-personality-test-ocean-model`
- CTA targets do not contain `result`, `orders`, `share`, `pay`, `payment`, `history`, `take`, tokenized URLs, or external URLs.
- FAQ exists in both artifacts.
- `body_markdown` exists in both artifacts.
- `claim_boundary_checklist` exists in both artifacts.

Validation command:

```bash
python3 - <<'PY'
import json,pathlib,re
paths=[pathlib.Path('backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.json'),pathlib.Path('backend/docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.json')]
expected={'zh-CN':('mbti-vs-holland-career-choice','/zh/articles/mbti-vs-holland-career-choice'),'en':('mbti-vs-holland-code-career-choice','/en/articles/mbti-vs-holland-code-career-choice')}
forbidden=re.compile(r'(result|orders|share|pay|payment|history|take|token|^https?://)', re.I)
for p in paths:
    d=json.loads(p.read_text())
    loc=d['locale']
    assert loc in expected
    assert d['slug']==expected[loc][0]
    assert d['canonical_path']==expected[loc][1]
    assert d['translation_group_id']=='article_mbti_vs_holland_career_choice_v1'
    assert d['publish_gate']['publish_allowed'] is False
    assert d.get('faq')
    assert d.get('body_markdown')
    assert d.get('claim_boundary_checklist')
    for slot in d['cta_slots']:
        href=slot['href']
        assert href.startswith(('/zh/tests/','/en/tests/')), (p,href)
        assert not forbidden.search(href), (p,href)
print('package artifact validation ok')
PY
```

## C. Hidden collision check

Public exposure checks: PASS.

Commands executed:

```bash
curl -sS -o /tmp/fm-canary-api.out -w '%{http_code}' 'https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice?locale=zh-CN'
curl -sS -o /tmp/fm-canary-api.out -w '%{http_code}' 'https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice/seo?locale=zh-CN'
curl -sS -o /tmp/fm-canary-api.out -w '%{http_code}' 'https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice?locale=en'
curl -sS -o /tmp/fm-canary-api.out -w '%{http_code}' 'https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en'
```

Observed:

- zh public API: 404
- zh public SEO API: 404
- en public API: 404
- en public SEO API: 404

Index surface checks:

- `https://fermatmind.com/sitemap.xml`: target slugs not found.
- `https://fermatmind.com/llms.txt`: target slugs not found.
- `https://fermatmind.com/llms-full.txt`: target slugs not found.

CMS/DB readonly collision result: Unknown.

Reason: this task did not receive a verified production CMS/DB readonly path. Codex did not read env/cookies/tokens and did not access CMS private data. Hidden draft/private record collision and translation group collision therefore remain Unknown.

Impact: Unknown hidden collision blocks `SEO-CONTENT-P1-08` unless the user explicitly accepts Unknown and requires an authorized operator to re-check before draft creation.

## D. Controlled importer dry-run

Importer command found:

```bash
php backend/artisan articles:import-editorial-package --file=docs/seo/canary-packages/<package>.json --locale=<locale> --dry-run --json
```

Dry-run write behavior:

- The command exposes `--dry-run`.
- Command code calls `EditorialPackageDraftImporter::planFromFile()` when `--dry-run` is set.
- The DB-writing path is `importFromFile()`, which is only used when `--dry-run` is not set.
- Existing tests confirm dry-run leaves `articles`, `article_translation_revisions`, `article_seo_meta`, and `article_editorial_package_imports` at zero rows in the fixture case.

Dry-run execution result: FAIL.

Commands executed:

```bash
php backend/artisan articles:import-editorial-package --file=docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.json --locale=zh-CN --dry-run --json
php backend/artisan articles:import-editorial-package --file=docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.json --locale=en --dry-run --json
```

Observed for both packages:

```json
{
  "ok": false,
  "action": "will_skip",
  "errors": [
    {
      "field": "command",
      "code": "unexpected_error",
      "message": "Array to string conversion"
    }
  ],
  "warnings": [],
  "claim_matches": []
}
```

Root cause assessment: package schema mismatch.

The current importer expects a normalized `editorial_package.v1` shape. The approved canary artifacts are valid V2 docs artifacts but do not match several importer fields directly:

- Artifact has `seo_description`; importer expects `meta_description`.
- Artifact has `canonical_path`; importer expects `canonical`.
- Artifact has `faq`; importer expects `answer_surface_v1.faq_items` when answer surface is used.
- Artifact has `claim_boundary_checklist`; importer expects `claim_boundary_notes`.
- Artifact has `internal_links` as object entries; importer currently normalizes `internal_links` as a list of strings, causing `Array to string conversion`.
- Artifact does not contain importer-required operational/media/graph metadata such as `content_track`, `audience_intent`, `decision_domains`, `target_tests`, `target_topics`, `cover_image`, `cover_image_alt`, `cover_image_prompt`, `cover_image_style_tag`, `claim_level`, `sensitivity_level`, and `review_required_by`.

Because Codex is forbidden to modify the GPT-5.5 Pro package content or create additional content fields in this task, no schema adapter package was created.

Dry-run capabilities that could not be verified for these artifacts:

- zh/en two records
- same `translation_group_id`
- different slug
- SEO title / description / canonical ingestion
- body ingestion
- FAQ package ingestion
- CTA slot ingestion
- draft fail-closed
- no publish

## E. GO / NO-GO

NO-GO: do not create CMS draft yet.

Reason:

- Package artifact validation passed.
- Public exposure absence passed.
- Controlled importer dry-run failed.
- Hidden CMS/DB collision remains Unknown.
- Draft fail-closed could not be verified for these artifacts because dry-run failed before a valid plan.
- Preview is not the current blocker; importer/package schema compatibility and hidden collision are the blockers.
- Publish remains forbidden.

`SEO-CONTENT-P1-08` is not authorized.

## F. Required next authorization

Before `SEO-CONTENT-P1-08`, the user must authorize one of the following:

1. A docs-only/package-adapter follow-up that creates an importer-consumable mechanical adapter artifact without changing approved GPT-5.5 Pro content prose.
2. A backend importer schema-mapping follow-up that lets `articles:import-editorial-package --dry-run` consume the approved V2 canary artifact shape without runtime publish behavior changes.
3. A verified production CMS/DB readonly hidden collision check by an authorized operator, or explicit acceptance that hidden collision remains Unknown until immediately before draft creation.

`SEO-CMS-CANARY-PREVIEW-01` is not required yet. Preview should only be considered after importer dry-run and hidden collision gates pass, or if draft creation remains blocked solely by lack of rendered preview.

## Forbidden actions not performed

- No CMS draft was created.
- No Article was created.
- No CMS form was saved.
- No publish action was performed.
- No production write API was called.
- No sitemap, Baidu push, IndexNow, or search-channel action was performed.
- No runtime code, routes, migrations, tests, frontend files, or GPT content prose were modified.
