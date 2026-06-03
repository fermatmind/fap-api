# SEO-CONTENT-P1-09-PUBLISH-PREFLIGHT-01 Controlled Publish Preflight

Date: 2026-06-03

Task type: controlled publish preflight / read-only production validation.

Result: **NO-GO: do not publish.**

No publish action was performed. No CMS data was modified. No new draft was created. No sitemap, Baidu, IndexNow, or search submission action was performed. No GPT-5.5 Pro content package text was authored, rewritten, or edited.

## Scope Boundary

Allowed actions performed:

- Read-only production CMS/DB checks through the current backend release.
- Controlled `articles:publish-controlled` dry-run checks only.
- Read-only public page, public API, sitemap, `llms.txt`, and `llms-full.txt` absence checks.
- Docs-only report and PR train ledger update.

Explicitly not performed:

- No publish.
- No CMS content mutation.
- No new draft creation.
- No importer run.
- No sitemap submission.
- No Baidu push.
- No IndexNow call.
- No production write API call.
- No runtime code change.
- No frontend change.
- No result/order/share/payment ID access.
- No env/cookie/token disclosure.

## Production Context

Backend current release path:

- `/var/www/fap-api/current/backend`
- Resolved release path observed by read-only check: `/var/www/fap-api/releases/backend-main-20260603-76b40c07/backend`

Controlled publish command:

```bash
php artisan articles:publish-controlled --article=37 --article=39 --dry-run --make-indexable --json
php artisan articles:publish-controlled --article=37 --article=39 --dry-run --make-indexable --ack-claim-warning=37 --json
```

Canary drafts:

- zh-CN: article `37`, slug `mbti-vs-holland-career-choice`, working revision `42`
- en: article `39`, slug `mbti-vs-holland-code-career-choice`, working revision `44`
- translation group: `article_mbti_vs_holland_career_choice_v1`

## A. Before/After No-Write Validation

Result: **PASS**

Production counts before dry-run:

| Table / surface | Count |
| --- | ---: |
| `articles` | 36 |
| `article_translation_revisions` | 43 |
| `article_seo_meta` | 32 |
| `article_editorial_package_imports` | 10 |
| publish audit events for target articles | 0 |

Production counts after dry-run:

| Table / surface | Count |
| --- | ---: |
| `articles` | 36 |
| `article_translation_revisions` | 43 |
| `article_seo_meta` | 32 |
| `article_editorial_package_imports` | 10 |
| publish audit events for target articles | 0 |

Target article state after dry-run:

| Locale | Article ID | Slug | Status | Public | Indexable | Published revision | Published at | Working revision |
| --- | ---: | --- | --- | --- | --- | --- | --- | ---: |
| zh-CN | 37 | `mbti-vs-holland-career-choice` | `draft` | false | false | null | null | 42 |
| en | 39 | `mbti-vs-holland-code-career-choice` | `draft` | false | false | null | null | 44 |

Decision:

- `--dry-run` did not write DB rows.
- Both target articles remained draft-only and fail-closed.
- No publish audit event was created.

## B. Controlled Publish Dry-Run

Result: **NO-GO**

### Without claim-warning acknowledgement

Command:

```bash
php artisan articles:publish-controlled --article=37 --article=39 --dry-run --make-indexable --json
```

Summary:

- `ok=false`
- `dry_run=true`
- `published_article_ids=[]`
- expected confirmation emitted by command: `I explicitly approve Codex to publish article ids 37,39 after preflight passes.`

Blocking errors:

| Article ID | Locale | Error code | Meaning |
| ---: | --- | --- | --- |
| 37 | zh-CN | `revision_not_editorially_approved` | Working revision is not in the publishable `approved` state. |
| 37 | zh-CN | `claim_warning_ack_required` | Boundary-context claim warnings require explicit acknowledgement before publish. |
| 39 | en | `revision_not_editorially_approved` | Working revision is not in the publishable `approved` state. |

### With zh-CN claim-warning acknowledgement

Command:

```bash
php artisan articles:publish-controlled --article=37 --article=39 --dry-run --make-indexable --ack-claim-warning=37 --json
```

Summary:

- `ok=false`
- `dry_run=true`
- `published_article_ids=[]`
- zh-CN `claim_warning_acknowledged=true`
- expected confirmation emitted by command: `I explicitly approve Codex to publish article ids 37,39 after preflight passes.`

Remaining blocking errors:

| Article ID | Locale | Error code | Meaning |
| ---: | --- | --- | --- |
| 37 | zh-CN | `revision_not_editorially_approved` | Working revision is not in the publishable `approved` state. |
| 39 | en | `revision_not_editorially_approved` | Working revision is not in the publishable `approved` state. |

Working revision state after dry-run:

| Revision ID | Article ID | Locale | Revision status | Reviewed by present | Reviewed at present | Approved at present | Published at |
| ---: | ---: | --- | --- | --- | --- | --- | --- |
| 42 | 37 | zh-CN | `machine_draft` | false | false | false | null |
| 44 | 39 | en | `machine_draft` | false | false | false | null |

Decision:

- Controlled publish dry-run is functioning and fail-closed.
- The zh-CN claim warning acknowledgement gate is required for article `37`.
- Acknowledging article `37` in dry-run clears only the claim-warning acknowledgement blocker.
- Both articles still fail because their working revisions are `machine_draft`, not `approved`.
- This preflight does not authorize publish.

## C. Public Absence

Result: **PASS**

Read-only public checks:

| Surface | Status | Target slug exposure |
| --- | ---: | --- |
| `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice` | 404 | generic 404 body echoes requested path only |
| `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice` | 404 | generic 404 body echoes requested path only |
| `/api/v0.5/articles/mbti-vs-holland-career-choice` | 404 | absent |
| `/api/v0.5/articles/mbti-vs-holland-career-choice/seo` | 404 | absent |
| `/api/v0.5/articles/mbti-vs-holland-code-career-choice` | 404 | absent |
| `/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo` | 404 | absent |
| `sitemap.xml` | 200 | absent |
| `llms.txt` | 200 | absent |
| `llms-full.txt` | 200 | absent |

Decision:

- Draft article pages remain unavailable publicly.
- Public article APIs remain unavailable.
- Public SEO APIs remain unavailable.
- Sitemap and LLM surfaces do not enumerate either target slug.
- The generic 404 page may echo the requested path, but it does not expose article content or article schema.

## D. GO / NO-GO

**NO-GO: do not publish.**

Blockers:

1. zh-CN working revision `42` is `machine_draft`, not `approved`.
2. en working revision `44` is `machine_draft`, not `approved`.
3. zh-CN article `37` has boundary-context claim warnings that must be explicitly acknowledged during a later controlled publish attempt.

Non-blocking checks:

- Controlled publish dry-run command exists.
- Dry-run did not write DB.
- `--make-indexable` was included in dry-run and removed the noindex/indexability blocker from the dry-run plan.
- Media status is `complete` for both articles.
- Graph status is `complete` for both articles.
- References count is `4` for both articles.
- CTA count is `3` for both articles.
- FAQ count is `5` for both articles.
- Public absence remains PASS.

## E. Publish Confirmation Boundary

No actionable publish confirmation phrase is issued by this preflight because the result is NO-GO.

The controlled command emitted this expected confirmation phrase during dry-run:

```text
I explicitly approve Codex to publish article ids 37,39 after preflight passes.
```

That phrase must not be used yet. A later publish attempt remains forbidden until:

1. Both working revisions are approved through an authorized CMS/operator workflow.
2. Controlled publish preflight is rerun and returns `ok=true`.
3. The later task explicitly includes `--ack-claim-warning=37` or equivalent acknowledgement for the zh-CN boundary-context warnings.
4. The user gives a separate exact publish authorization after preflight passes.

## F. Recommended Next Step

Recommended next step: obtain authorized operator approval for working revisions `42` and `44`, without publishing, then rerun this controlled publish preflight.

Suggested follow-up PR train item, if the operator approval path needs documentation before action:

- proposed id: `SEO-CONTENT-P1-09-REVISION-APPROVAL-GATE-01`
- proposed title: `docs(cms): verify SEO canary revision approval gate`
- proposed scope: document or verify the authorized CMS/operator path for approving working revisions `42` and `44` without publishing
- likely files: `backend/docs/seo/**`, `docs/codex/pr-train.yaml`, `docs/codex/pr-train-state.json`
- required checks: JSON parse, YAML parse, `git diff --check -- backend/docs/seo docs/codex`
- dependency assumption: `SEO-CONTENT-P1-09-PUBLISH-PREFLIGHT-01` merged

Follow-up execution prompt:

```text
明确授权在 fap-api 新增并执行 SEO-CONTENT-P1-09-REVISION-APPROVAL-GATE-01，验证或记录中英文 canary working revisions 42/44 的 authorized operator approval path。不要 publish。
```

## Validation Commands

```bash
php backend/artisan articles:publish-controlled --help
ssh "$API_SSH_ALIAS" 'cd /var/www/fap-api/current/backend && php artisan articles:publish-controlled --article=37 --article=39 --dry-run --make-indexable --json'
ssh "$API_SSH_ALIAS" 'cd /var/www/fap-api/current/backend && php artisan articles:publish-controlled --article=37 --article=39 --dry-run --make-indexable --ack-claim-warning=37 --json'
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/seo docs/codex
git diff --cached --check
```
