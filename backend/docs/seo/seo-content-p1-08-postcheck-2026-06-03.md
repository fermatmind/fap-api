# SEO-CONTENT-P1-08-POSTCHECK-01 Draft-Only Post-Create Validation

Date: 2026-06-03

Task type: draft-only post-create validation / read-only validation.

Result: **GO: draft-only postcheck passed, ready for editorial/operator review.**

No publish action was performed. No CMS data was modified. No new draft was created. No sitemap, Baidu, IndexNow, or search submission action was performed. No GPT-5.5 Pro content package text was authored, rewritten, or edited.

## Scope Boundary

Allowed actions performed:

- Read-only production CMS/DB checks through the current backend release.
- Read-only public page, public API, sitemap, `llms.txt`, and `llms-full.txt` checks.
- Read-only frontend deployed-SHA tracking readiness inspection.
- Docs-only report creation.

Explicitly not performed:

- No publish.
- No CMS content mutation.
- No new draft creation.
- No rollback.
- No sitemap submission.
- No Baidu push.
- No IndexNow call.
- No production write API call.
- No runtime code change.
- No frontend change.
- No result/order/share/payment ID access.
- No env/cookie/token disclosure.

## Production Context

Backend current release:

- `/var/www/fap-api/current`
- Backend revision observed earlier in the P1-08 flow: `76b40c07b5eb679e7b593aca2007a77e1e56d4d7`
- Release name observed earlier in the P1-08 flow: `backend-main-20260603-76b40c07`

Frontend deployed SHA used for tracking readiness inspection:

- `b98cf45749e77bf7cb6c5c438ef4686dda264d0d`

Canary translation group:

- `article_mbti_vs_holland_career_choice_v1`

Canary drafts:

- zh-CN: article `37`, slug `mbti-vs-holland-career-choice`
- en: article `39`, slug `mbti-vs-holland-code-career-choice`

Required archive marker summary: both canary drafts remain `status=draft`, `is_public=false`, `is_indexable=false`, and `published_revision_id=null`.

## A. CMS/DB Draft State Validation

Result: **PASS**

Observed production DB state:

| Locale | Article ID | Slug | Status | is_public | is_indexable | published_revision_id | published_at | deleted_at |
| --- | ---: | --- | --- | --- | --- | --- | --- | --- |
| zh-CN | 37 | `mbti-vs-holland-career-choice` | `draft` | false | false | null | null | null |
| en | 39 | `mbti-vs-holland-code-career-choice` | `draft` | false | false | null | null | null |

Translation group validation:

- `translation_group_id`: `article_mbti_vs_holland_career_choice_v1`
- Same translation group count: `2`
- Third same-translation-group record count: `0`
- Same-slug duplicate count:
  - zh-CN slug count: `1`
  - en slug count: `1`
- Soft-deleted same-slug count: `0`
- Public/indexable/published count for both target slugs: `0`

Draft state:

- zh draft state: **PASS**
- en draft state: **PASS**
- translation group: **PASS**
- duplicate/collision: **PASS**

## B. Package Equivalence Validation

Result: **PASS**

Artifacts compared:

- `backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-career-choice.zh-CN.importer-adapter.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.json`
- `backend/docs/seo/canary-packages/mbti-vs-holland-code-career-choice.en.importer-adapter.json`

Equivalence method:

- Compared production `articles`, working `article_translation_revisions`, `article_seo_meta`, `article_test_edges`, latest `article_editorial_package_imports`, and adapter artifacts.
- Used importer-equivalent normalized body hashing for `body_markdown`.
- Used importer-equivalent answer surface hashing for `answer_surface_v1`.
- Did not print full body, FAQ answer text, or private data in this report.

Observed hashes:

| Locale | Body hash | Answer surface hash | FAQ count |
| --- | --- | --- | ---: |
| zh-CN | `2b5ebe2d67c688bbb18239d602630107e83622a51d9201eda904fb4a71ffc0ef` | `06d3adb192b52464be4837b1082f004de6d073a29e92e5a6077e1578044fbf18` | 5 |
| en | `be91e8c640a4b14f374804f9967e78b389fa31c9921c667fce90959df1e1e35b` | `8e4a865c276b000bb6e16a5724acff6af1d713b89ee68e4633643457aea7758a` | 5 |

Field equivalence:

- title: **PASS**
- slug: **PASS**
- H1 / heading sequence from importer exactness: **PASS**
- SEO title: **PASS**
- SEO description: **PASS**
- canonical URL: **PASS**
- excerpt: **PASS**
- body markdown normalized hash: **PASS**
- FAQ package hash: **PASS**
- CTA slots and hrefs: **PASS**
- related test slug / target tests: **PASS**
- category / tags: **PASS**
- claim boundary metadata presence via import package record: **PASS**
- publish fail-closed equivalent:
  - Article `status=draft`
  - SEO meta `robots=noindex,nofollow`
  - `is_indexable=false`
  - `published_revision_id=null`

Content authorship boundary:

- Codex did not rewrite body content during postcheck.
- Codex did not rewrite FAQ during postcheck.
- Codex did not rewrite CTA labels or hrefs during postcheck.
- Codex did not rewrite H1 during postcheck.
- English metadata is the previously approved short SEO title / SEO description.
- Chinese content remains unchanged from the imported adapter.

Publish readiness impact:

- Package equivalence does not block editorial/operator review.
- Publish remains forbidden until separate publish preflight and explicit publish authorization.

## C. Public Exposure Validation

Result: **PASS**

Public page checks:

| Surface | Status | Article schema | FAQPage schema | Breadcrumb schema | Target canonical | Target hreflang |
| --- | ---: | --- | --- | --- | --- | --- |
| `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice` | 404 | absent | absent | absent | absent | absent |
| `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice` | 404 | absent | absent | absent | absent | absent |

Notes:

- The generic 404 page body includes the requested path text and the normal site shell, so string matching the response body alone can show the slug. This is not article content exposure.
- No Article, FAQPage, or Breadcrumb structured data was detected on either draft URL.
- No canonical or hreflang link pointing to either draft URL was detected.
- A global analytics shell marker is present on the generic 404 shell. No article-specific public content, article schema, canonical, hreflang, or public API payload was exposed.

Public API checks:

| Surface | Status | Target slug in body |
| --- | ---: | --- |
| `/api/v0.5/articles/mbti-vs-holland-career-choice` | 404 | no |
| `/api/v0.5/articles/mbti-vs-holland-career-choice/seo` | 404 | no |
| `/api/v0.5/articles/mbti-vs-holland-code-career-choice` | 404 | no |
| `/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo` | 404 | no |

Exposure decision:

- public page exposure: **PASS**
- public API exposure: **PASS**
- public SEO API exposure: **PASS**
- schema exposure: **PASS**

## D. Sitemap / LLMs / Search Submission Validation

Result: **PASS**

Public enumeration checks:

| Surface | Status | Contains target slug |
| --- | ---: | --- |
| `https://fermatmind.com/sitemap.xml` | 200 | no |
| `https://fermatmind.com/llms.txt` | 200 | no |
| `https://fermatmind.com/llms-full.txt` | 200 | no |

Production DB search-submission table checks:

| Surface | Result |
| --- | --- |
| `search_channel_queue` | table missing |
| `search_channel_submissions` | table missing |
| `search_submissions` | table missing |
| `gsc_submission_queue` | table missing |
| `baidu_submission_queue` | table missing |
| `indexnow_queue` | table missing |
| `seo_search_submission_events` | table missing |
| `search_submission_events` | table missing |

Audit/event checks:

- Publish/release audit match count for target slugs: `0`
- Checked available audit log text columns: `action`, `reason`

Decision:

- sitemap absence: **PASS**
- llms absence: **PASS**
- search submission absence: **PASS**
- publish/release event absence: **PASS**

## E. CTA / Tracking Readiness Validation

Result: **PASS**

CMS draft CTA targets:

| Locale | Priority | Target |
| --- | --- | --- |
| zh-CN | primary | `/zh/tests/holland-career-interest-test-riasec` |
| zh-CN | secondary | `/zh/tests/mbti-personality-test-16-personality-types` |
| zh-CN | tertiary | `/zh/tests/big-five-personality-test-ocean-model` |
| en | primary | `/en/tests/holland-career-interest-test-riasec` |
| en | secondary | `/en/tests/mbti-personality-test-16-personality-types` |
| en | tertiary | `/en/tests/big-five-personality-test-ocean-model` |

CTA safety:

- All CTA hrefs are public canonical test routes.
- No CTA href contains `result`, `orders`, `share`, `pay`, `payment`, `history`, `take`, `token`, external URL, or unsafe scheme.
- `article_test_edges` exist for the three target tests in both locales.
- Primary target is `holland-career-interest-test-riasec`.

Tracking readiness, checked against deployed frontend SHA `b98cf45749e77bf7cb6c5c438ef4686dda264d0d`:

- `article_to_test_click` exists in `components/cta/SeoTrackedCtaLink.tsx`.
- `article_to_test_click` exists in `lib/tracking/client.ts`.
- `ARTICLE_TO_TEST_CLICK` exists in `lib/tracking/events.ts`.
- Field whitelist includes:
  - `locale`
  - `article_slug`
  - `translation_group_id`
  - `cta_id`
  - `cta_priority`
  - `target_test_slug`
  - `source_path`
  - `destination_path`
- Article-detail CTA emits `article_to_test_click`, while non-article SEO CTA falls back to `start_attempt`.
- `article_to_test_click` is distinct from `start_test`.
- No checkout, payment, or order trigger was found in the article CTA tracking path.

Decision:

- CTA safety: **PASS**
- tracking readiness: **PASS**
- This does not block editorial/operator review.
- GA4 Key Event configuration remains intentionally deferred.

## F. Schema Readiness Validation

Result: **PASS for data readiness; public schema exposure remains absent while draft-only.**

Draft data available for future schema generation:

- Article title: present.
- SEO description: present.
- Canonical URL: present in `article_seo_meta`.
- Author/safe fallback: present as importer package author / article author fields.
- Category and tags: present.
- FAQ items: present and locale-specific in the adapter answer surface.
- CTA/internal hrefs: public canonical routes only.
- Robots/indexability: currently `noindex,nofollow` and `is_indexable=false`.

Current DB `article_seo_meta.schema_json`:

- Present for both records.
- Contains importer editorial package metadata under `editorial_package_v1`.
- Does not represent exposed public JSON-LD while the articles remain drafts.

Decision:

- Article schema readiness: **PASS**
- Breadcrumb schema readiness: **PASS**
- FAQ schema readiness: **PASS**
- Hidden FAQ should not generate FAQPage; visible FAQ can be used after publish gates.
- No private, tokenized, result/order/share/pay/payment/history URL appears in CTA targets.

## G. Preview Readiness / Preview Gap

Result: **Preview gap remains.**

Observed state:

- No dedicated safe rendered Article draft preview route was confirmed.
- Existing preview-related code found in the repo is for other surfaces, such as career transition preview and Big Five editorial preview.
- The canary source/adapter artifacts still record `preview_url_available=false`.

Decision:

- Preview blocks draft: **No**. Drafts already exist and remain fail-closed.
- Preview blocks editorial/operator review: **No**, if CMS-only inspection is accepted for review.
- Preview blocks publish: **Yes / Conditional**. Before publish, either:
  - the team accepts CMS-only inspection plus public absence checks as the rendered-review substitute, or
  - `SEO-CMS-CANARY-PREVIEW-01` should add a safe auth/token-gated noindex preview.

Recommendation:

- Do not publish from this state.
- Use editorial/operator review next.
- Execute `SEO-CMS-CANARY-PREVIEW-01` before publish if rendered preview is required and no accepted CMS-only substitute is approved.

## H. Decision

**GO: draft-only postcheck passed, ready for editorial/operator review.**

Pass summary:

- Two draft states: **PASS**
- Translation group: **PASS**
- Duplicate/collision: **PASS**
- Package equivalence: **PASS**
- Public exposure: **PASS**
- Sitemap/llms/search submission absence: **PASS**
- CTA safety: **PASS**
- Tracking readiness: **PASS**
- Schema readiness: **PASS**
- Publish event absence: **PASS**

Still forbidden:

- Publish remains forbidden.
- Search submission remains forbidden.
- Sitemap submission remains forbidden.
- Baidu push remains forbidden.
- IndexNow remains forbidden.

## I. Validation Commands

Representative commands used:

```bash
ssh "$API_SSH_ALIAS" 'cd /var/www/fap-api/current/backend && php artisan tinker --execute="..."'
python3 - <<'PY'
# public page/API/sitemap/llms absence checks
PY
git -C /Users/rainie/Desktop/GitHub/fap-web show b98cf45749e77bf7cb6c5c438ef4686dda264d0d:lib/tracking/events.ts
git -C /Users/rainie/Desktop/GitHub/fap-web show b98cf45749e77bf7cb6c5c438ef4686dda264d0d:components/cta/SeoTrackedCtaLink.tsx
```

Report-local checks to run after this docs-only file is created:

```bash
git diff --check -- backend/docs/seo
```

## J. Next Step

Recommended next task:

```text
Proceed to editorial/operator review for the two CMS drafts. Do not publish. If rendered preview is required before publish, execute SEO-CMS-CANARY-PREVIEW-01 or explicitly approve CMS-only inspection as the publish-readiness substitute.
```
