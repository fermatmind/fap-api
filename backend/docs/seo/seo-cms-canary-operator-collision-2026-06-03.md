# SEO CMS Canary Operator Collision Check Path - 2026-06-03

Task: `SEO-CMS-CANARY-OPERATOR-COLLISION-01`

This PR is docs-only. It did not create a CMS draft, did not publish, did not call a production write API, did not run a migration, did not read or expose env/cookie/token values, did not access private result/order/share/payment IDs, did not edit GPT-5.5 Pro content artifacts, and did not modify runtime code or tests.

Checked at:

- Local time: `2026-06-03 00:56:10 CST`
- UTC: `2026-06-02T16:56:10Z`

## Target

The collision check is for the first bilingual SEO CMS canary package:

| Field | Value |
| --- | --- |
| zh slug | `mbti-vs-holland-career-choice` |
| en slug | `mbti-vs-holland-code-career-choice` |
| translation_group_id | `article_mbti_vs_holland_career_choice_v1` |
| public org scope | `org_id=0` for the current controlled editorial package importer path |

## Dependency Status

`SEO-CMS-CANARY-IMPORTER-GATE-01` is merged as fap-api PR #1860.

- PR URL: `https://github.com/fermatmind/fap-api/pull/1860`
- Merge commit: `26f4fec3b7112acff6d3276613b1cd57744350f9`
- Merged at: `2026-06-02T16:51:37Z`
- Current `origin/main` contains the merge commit.

This means the zh-CN and en adapter artifacts can pass the controlled importer dry-run gate, but it does not prove hidden production CMS/DB collision status.

## Public Absence Checks

These checks are public and read-only. They can detect public exposure but cannot detect private, draft, noindex, unpublished, or soft-deleted rows.

| Check | URL | Result |
| --- | --- | --- |
| zh public detail API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice?locale=zh-CN` | `404` |
| zh public SEO API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice/seo?locale=zh-CN` | `404` |
| en public detail API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice?locale=en` | `404` |
| en public SEO API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en` | `404` |
| sitemap | `https://fermatmind.com/sitemap.xml` | both slugs absent |
| llms.txt | `https://fermatmind.com/llms.txt` | both slugs absent |
| llms-full.txt | `https://fermatmind.com/llms-full.txt` | both slugs absent |

Public absence status: **PASS for public surfaces only**.

## Private Hidden Collision Status

Private production CMS/DB hidden collision status: **Unknown**.

Reason:

- This PR did not have explicit authorization to access production CMS/DB private records.
- This PR did not read env/cookie/token values.
- Public APIs, sitemap, `llms.txt`, and `llms-full.txt` cannot prove absence of hidden Article rows.
- Article rows can be non-public, noindex, draft/review-pending, unpublished, published-but-not-public, lifecycle soft-deleted, or soft-deleted while remaining invisible to public surfaces.

`SEO-CMS-CANARY-HIDDEN-COLLISION-SIDECAR-01` is therefore created as a sidecar blocker. This blocker must be resolved before `SEO-CONTENT-P1-08` can create any CMS draft.

## Operator Readonly Check Path

The authorized operator should run exactly one readonly production CMS/DB check before any draft creation. The check must include soft-deleted rows and must not save CMS forms, call write APIs, run import without dry-run, publish, or submit URLs.

Code evidence for the collision surface:

- `Article` uses `SoftDeletes`.
- `Article` fields include `org_id`, `locale`, `slug`, `translation_group_id`, `status`, `is_public`, `is_indexable`, `published_revision_id`, lifecycle fields, and `deleted_at`.
- `EditorialPackageDraftImporter::existingArticle()` checks `org_id=0`, `locale`, and `slug` through `Article::query()->withoutGlobalScopes()`.
- Actual importer creation writes draft-only rows with `status=draft`, `is_public=false`, `is_indexable=false`, and `published_revision_id=null`.

Required readonly SQL shape:

```sql
SELECT
  id,
  org_id,
  locale,
  slug,
  translation_group_id,
  status,
  is_public,
  is_indexable,
  published_revision_id,
  lifecycle_state,
  deleted_at,
  created_at,
  updated_at
FROM articles
WHERE org_id = 0
  AND (
    (locale = 'zh-CN' AND slug = 'mbti-vs-holland-career-choice')
    OR (locale = 'en' AND slug = 'mbti-vs-holland-code-career-choice')
    OR translation_group_id = 'article_mbti_vs_holland_career_choice_v1'
  )
ORDER BY
  deleted_at IS NULL DESC,
  updated_at DESC,
  id DESC;
```

Required CMS/Tinker-equivalent shape if SQL is not used:

```php
App\Models\Article::query()
    ->withoutGlobalScopes()
    ->withTrashed()
    ->where('org_id', 0)
    ->where(function ($query) {
        $query
            ->where(function ($query) {
                $query
                    ->where('locale', 'zh-CN')
                    ->where('slug', 'mbti-vs-holland-career-choice');
            })
            ->orWhere(function ($query) {
                $query
                    ->where('locale', 'en')
                    ->where('slug', 'mbti-vs-holland-code-career-choice');
            })
            ->orWhere('translation_group_id', 'article_mbti_vs_holland_career_choice_v1');
    })
    ->get([
        'id',
        'org_id',
        'locale',
        'slug',
        'translation_group_id',
        'status',
        'is_public',
        'is_indexable',
        'published_revision_id',
        'lifecycle_state',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
```

Required PASS criteria:

- Zero rows returned for both target slug checks.
- Zero rows returned for `translation_group_id=article_mbti_vs_holland_career_choice_v1`.
- The readonly operator confirms the query included soft-deleted rows.
- The readonly operator confirms the query ran against the production CMS Article authority, public org scope `org_id=0`.

Required FAIL criteria:

- Any row exists with either target slug.
- Any row exists with the target `translation_group_id`.
- Any row exists in `status=draft`, `status=review_pending`, `status=published`, non-public, noindex, unpublished, lifecycle soft-deleted, or soft-deleted state.
- The operator cannot confirm soft-deleted rows were included.
- The operator cannot confirm production CMS Article authority and public org scope.

## Decision

Hidden collision status: **Unknown**.

`SEO-CONTENT-P1-08`: **blocked**.

Publish: **forbidden**.

The next safe action is operator readonly hidden collision verification using the path above. If the operator reports PASS and records evidence without exposing secrets or private content, the train may request authorization for `SEO-CONTENT-P1-08` draft-only execution. If the operator reports FAIL or cannot verify the path, do not create a CMS draft.

## Checks

Local checks required for this PR:

```bash
git diff --check -- backend/docs/seo backend/docs/operations docs/codex
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
git diff --cached --check
```

No CMS draft, publish, production write, sitemap submission, Baidu push, IndexNow action, runtime code edit, test edit, frontend edit, or GPT content edit is part of this PR.
