# SCIENCE-CONTENTPAGE-CMS-FIELD-MAPPING-01

Date: 2026-06-05
Updated: 2026-06-08
Mode: backend/CMS field mapping planning, docs-only
Branch: `codex/science-contentpage-field-mapping-01`

This report plans how six science/trust/methodology draft pages should map into the current `ContentPage` model and records the later controlled non-public draft import closeout. The original field-mapping PR did not implement code, migrations, CMS writes, imports, publication, route creation, sitemap/llms/schema/footer/header changes, or deployment. Later PRs and operator-approved runtime execution imported five non-public draft rows only.

## Decision

**CONDITIONAL, non-public draft import complete.** The Science ContentPage line has completed the backend/importer safety path, frontend exposure gates, production no-write dry-run, and the operator-approved controlled CMS draft import for five new non-public rows. The line is **not** complete as a publication or distribution task: no public publish, sitemap/llms inclusion, footer exposure, search submission, or social amplification has been authorized or performed.

Current practical meaning:
- Non-public package validation is supported through backend dry-run commands.
- Operator review readiness is supported through first-class ContentPage review and publish-safety fields.
- Controlled non-public draft import has run once in production after exact operator approval.
- Frontend route wrappers and discoverability gates exist, but draft routes stay non-public/non-indexable and are not eligible for footer, sitemap, or llms exposure until later gates pass.

## 0.1 2026-06-08 production import closeout

The later production closeout changed only the draft-storage status, not publication status.

| Check | Result |
|---|---|
| Production parser blocker | Fixed by fap-api #1983, which removed the Science package parser's Symfony YAML runtime dependency. |
| Production no-write dry-run before execute | `ok=1`, `dry_run=1`, `writes_committed=0`, `pages_seen=6`, `planned_create_count=5`, `authority_revision_only_count=1`, `blocked_count=0`. |
| Controlled execute | Ran `content-pages:science-import-drafts --execute` with the exact approval phrase `SCIENCE_CONTENTPAGE_NON_PUBLIC_DRAFT_IMPORT_APPROVED`. |
| Controlled execute output | `ok=1`, `dry_run=0`, `writes_committed=1`, `pages_seen=6`, `created_count=5`, `publish_allowed=0`, `discoverability_allowed=0`. |
| Idempotency dry-run after execute | `ok=1`, `dry_run=1`, `writes_committed=0`, `planned_create_count=0`, `skipped_existing_count=5`, `authority_revision_only_count=1`. |
| Created draft slugs | `/science`, `/item-design-notes`, `/reliability-validity`, `/data-privacy`, `/common-misconceptions`. |
| Existing authority skipped | `/method-boundaries` remained existing-authority revision-only and was not created or overwritten. |
| Post-import exposure QA | Five created rows were `status=draft`, `is_public=false`, `is_indexable=false`, `publish_allowed=false`, `schema_enabled=false`, `faq_schema_eligible=false`, `operator_approval_required=true`, `claim_gate_status=not_reviewed`. |
| Public discoverability QA | Public sitemap and `llms.txt` checks found no created draft URLs. |

Current remaining blockers:
- CMS operator review is still required for publication.
- Claim gate remains `not_reviewed`.
- Science/legal review remains required where flagged.
- FAQ schema remains disabled until visible FAQ review and schema eligibility approval.
- Sitemap, llms, footer, search submission, and public distribution remain **NO-GO**.

## 0. 2026-06-08 line completion review

This update reconciles the earlier planning report against the merged cross-repo PR sequence.

| Area | PRs reviewed | Status | Result |
|---|---|---|---|
| Backend field mapping | fap-api #1922 | Complete | Established the initial ContentPage mapping and draft-only constraints. |
| Backend importer dry-run | fap-api #1944 | Complete | Added Science ContentPage package dry-run validation without writes. |
| Backend operator review | fap-api #1948 | Complete | Added operator review readiness gate for non-public draft review. |
| Backend pre-import QA | fap-api #1955 | Complete | Added pre-real-import QA gate for claim, route, FAQ, private URL, and exposure checks. |
| Backend authority reconciliation | fap-api #1958 | Complete | Treated `/method-boundaries` as existing authority/revision-only, not a new record. |
| Backend publish safety fields | fap-api #1959 | Complete | Promoted `publish_allowed`, operator approval, claim gate, forbidden claims, and FAQ schema eligibility into first-class ContentPage fields. |
| Backend real-import lock | fap-api #1960 | Complete | Locked real import as dry-run-only and explicitly required separate operator approval plus a separate import-command PR. |
| Backend package split | fap-api #1973 | Complete | Packaged the GPT-5.5 Pro draft into review audit, six page candidates, and operator review artifacts. |
| Backend route governance | fap-api #1976 | Complete | Confirmed `/reliability-validity` as the selected public canonical draft route candidate. |
| Backend production no-write dry-run | fap-api #1978 | Complete | Recorded production no-write dry-run gate and kept writes blocked. |
| Backend real import command | fap-api #1981 | Complete | Added `content-pages:science-import-drafts` with exact approval phrase and draft-only write guard. |
| Backend production parser blocker | fap-api #1983 | Complete | Removed missing production Symfony YAML runtime dependency from Science package parsing. |
| Frontend method-boundary reconciliation | fap-web #1060 | Complete | Reconciled method-boundaries route authority from the frontend planning side. |
| Frontend guarded route wrappers | fap-web #1062 | Complete | Added guarded root route wrappers for the Science ContentPage slugs; empty CMS remains safe/noindex. |
| Frontend claim gate | fap-web #1064 | Complete | Added claim-boundary checks for Science ContentPage content. |
| Frontend FAQ schema gate | fap-web #1065 | Complete | Enforced visible-FAQ-only schema eligibility. |
| Frontend discoverability gates | fap-web #1066 and #1068 | Complete | Kept draft Science ContentPages out of footer/sitemap/llms and preserved only existing authority routes. |
| Frontend sitemap source convergence | fap-web #1069 | Complete | Converged static sitemap generation to backend source; Science drafts remain excluded by gate state. |

Line status summary:
- **Completed:** schema mapping, dry-run validation, operator review readiness, pre-import QA, authority reconciliation, publish-safety fields, real-import lock, guarded routes, claim/FAQ/discoverability tests, production no-write dry-run, controlled non-public draft import, and post-import exposure QA.
- **Not completed by design:** publication, public indexability, footer expansion, sitemap/llms inclusion, search submission, or content distribution.
- **Next allowed phase:** CMS operator review and claim/science/legal review for the imported drafts. Any publish or discoverability PR must remain blocked until those approvals are recorded.

## 1. Current ContentPage field map

Evidence scanned:
- `backend/app/Models/ContentPage.php`
- `backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php`
- `backend/app/Filament/Ops/Resources/ContentPageResource.php`
- `backend/database/migrations/2026_04_19_000300_create_content_pages_table.php`
- `backend/database/migrations/2026_04_22_120000_create_support_trust_content_tables.php`
- `backend/database/migrations/2026_04_24_100000_create_cms_translation_revisions.php`
- `backend/routes/api.php`

| Field | Exists? | Type | Required? | Public API exposed? | Admin editable? | Notes |
|---|---:|---|---:|---:|---:|---|
| `slug` | Yes | string 128 | Yes | Yes | Yes | Unique with `org_id` and `locale`; public route uses `/content-pages/{slug}`. |
| `path` | Yes | string 160 | Yes | Yes | Yes | Internal update derives root path from slug except `help-*` helper paths. |
| `locale` | Yes | string | Yes | Yes | Yes | Read accepts `en`, `zh-CN`, `zh`; normalized to `en` or `zh-CN`. |
| `title` | Yes | string 255 | Yes | Yes | Yes | Required by internal update and admin. |
| `page_type` | Yes | enum-like string | Nullable in API, required in admin | Yes | Yes | Supported values include `science`, `methodology`, `boundary`, `privacy`, `policy`, `trust`, etc. |
| `kind` | Yes | enum-like string | Yes | Yes | Yes | Current API/admin allow only `company`, `policy`, `help`. Package custom values are incompatible. |
| `review_state` | Yes | enum-like string | Nullable in API, required in admin | Yes | Yes | Supported states include `draft`, `owner_review`, `legal_review`, `science_review`, `approved`. |
| `science_review_required` | Yes | boolean | No | Yes | Yes | Suitable for science/methodology/boundary drafts. |
| `legal_review_required` | Yes | boolean | No | Yes | Yes | Suitable for boundary/privacy/legal-risk drafts. |
| `is_public` | Yes | boolean | Yes in API | Yes | Yes | Public `show` requires a published status plus a true public flag. |
| `is_indexable` | Yes | boolean | Yes in API | Yes | Yes | Exposed to frontend; does not by itself decide public API visibility. |
| `content_md` | Yes | long text | One of md/html required | Yes | Yes | Headings are extracted from markdown. |
| `content_html` | Yes | long text | One of md/html required | Yes | Yes | Optional alternative body. |
| `seo_title` | Yes | string 255 | No | Yes | Yes | Public payload field. |
| `meta_description` | Yes | text | No | Yes | Yes | Public payload field; fallback for `seo_description`. |
| `seo_description` | Yes | text | No | Yes | Yes | Public payload falls back to `meta_description` when empty. |
| `canonical_path` | Yes | string 255 | No | Yes | Yes | Defaults to derived public path on internal update. |
| `faq` / `faq_items` | No | N/A | N/A | No | No | No first-class ContentPage FAQ field found. Visible FAQ can live only inside body unless future schema support is added. |
| `sitemap_eligible` | No | N/A | N/A | No | No | No first-class ContentPage field. Discoverability is controlled outside this backend model. |
| `llms_eligible` | No | N/A | N/A | No | No | No first-class ContentPage field. |
| `footer_eligible` | No | N/A | N/A | No | No | No first-class ContentPage field. |
| `status` | Yes | enum-like string | Nullable in API, required in admin | Yes | Yes | Draft import must keep `status=draft`. |
| `template` | Yes | enum-like string | Yes | Yes | Yes | API allows `company`, `charter`, `foundation`, `careers`, `brand`, `policy`, `help`. |
| `animation_profile` | Yes | enum-like string | Yes | Yes | Yes | API allows `mission`, `principles`, `editorial`, `brand`, `policy`, `none`. |
| `source_doc` | Yes | string 255 | No | Yes | Yes | Good place for package/source trace, not for large review metadata. |
| `headings_json` | Yes | JSON | Derived | Yes as `headings` | Indirect | Extracted from markdown by controller. |
| revision pointers | Yes | integer ids | No | No | Indirect | `working_revision_id` and `published_revision_id` exist; draft workflow uses revision workspace. |

## 2. Draft package field compatibility

Package defaults:
- `is_public=false`
- `is_indexable=false`
- `sitemap_eligible=false`
- `llms_eligible=false`
- `footer_eligible=false`

| Draft field | Current ContentPage target field | Compatible? | Transformation needed | Risk |
|---|---|---:|---|---|
| `page_key` | `source_doc` or importer metadata | Partial | Preserve as source trace or external import manifest key. | Low if not exposed publicly. |
| `zh_title` | `title` for `locale=zh-CN` | Yes | Locale split required. | Low. |
| `en_title` | `title` for `locale=en` | Yes | Locale split required. | Low. |
| `proposed_slug` | `slug` / `path` / `canonical_path` | Partial | Strip leading slash for `slug`; keep root-level path only. | Medium because unsupported routes remain 404 until fap-web route wrappers exist. |
| `fallback_slug_if_nested_route_not_supported` | slug decision input | Partial | Choose one canonical slug before import. | Medium if multiple slugs create duplicate records. |
| `page_type` | `page_type` | Yes | Use supported enum values as provided. | Low. |
| `kind` | `kind` | No as-is | Map to existing allowed kind or extend backend enum in later PR. | High as direct API/admin import fails. |
| `review_state` | `review_state` | Yes | Preserve package value. | Low. |
| `science_review_required` | `science_review_required` | Yes | Preserve package value. | Low. |
| `legal_review_required` | `legal_review_required` | Yes | Preserve package value. | Low. |
| `is_public` | `is_public` | Yes | Preserve `false`. | Low; required draft safety gate. |
| `is_indexable` | `is_indexable` | Yes | Preserve `false`. | Low; required draft safety gate. |
| `sitemap_eligible` | No field | No | Keep in import manifest/review docs only; do not map to backend field. | Medium if mistaken as backend-controlled. |
| `llms_eligible` | No field | No | Keep in import manifest/review docs only; do not map to backend field. | Medium if mistaken as backend-controlled. |
| `footer_eligible` | No field | No | Keep in import manifest/review docs only; do not map to backend field. | Medium if mistaken as backend-controlled. |
| `meta_title_draft` | `seo_title` | Yes | Locale split required. | Medium because publish-level SEO copy still needs review. |
| `meta_description_draft` | `meta_description` / `seo_description` | Yes | Locale split required; keep draft-only. | Medium because legal/science review required. |
| `h1` | body heading / `title` | Partial | Do not add a separate H1 field; body renderer/route decides display. | Low. |
| `content_md` | `content_md` | Yes | Locale split required; do not expose until review. | Medium because body includes claims needing lint/review. |
| `visible_faq_items` | `content_md` only | Partial | Keep visible in markdown; no FAQ schema mapping now. | Medium if treated as schema-ready. |
| `internal_links_allowed` | content review notes / body links | Partial | Validate public canonical routes before publication. | Medium because route existence differs by slug. |
| `forbidden_routes` | import QA metadata | Partial | Preserve as lint/review input, not public payload. | High if private patterns leak into live content. |
| `claim_boundary_notes` | review metadata / content review checklist | Partial | Preserve outside public body or as reviewer note. | High if unsupported claims are published. |
| `unknown_fields` | review metadata | Partial | Preserve as `Unknown`; do not coerce to `false` or `0`. | Medium. |
| `reviewer_checklist` | review metadata | Partial | Preserve in package/import report, not public body. | Low. |
| `publish_blockers` | release gate metadata | Partial | Preserve as import gate; not a ContentPage field. | High if ignored. |
| `en_parity_notes` | translation workflow notes | Partial | Use as reviewer note; not first-class field. | Medium. |

## 3. `kind` strategy options

### Option A: Map all science/trust/methodology drafts to existing `policy`

| Area | Impact |
|---|---|
| Changes required | Importer/package normalization only. No backend enum, migration, controller, or admin change. |
| Backward compatibility | Strong. Uses current `ContentPage::KIND_POLICY`, current API validation, and current admin options. |
| Admin clarity | Moderate. `page_type` carries the real distinction; `kind=policy` is broad but available. |
| API impact | Minimal. Public/internal payload shape unchanged. |
| Risk | Lowest implementation risk; possible taxonomy ambiguity in admin lists. |
| Test requirements | Importer dry-run/schema validation should assert package custom kinds normalize to `policy`; no runtime ContentPage enum tests need to change. |
| Recommendation | Recommended first for non-public draft import. |

### Option B: Extend backend `kind` enum

Candidate values:
- `science`
- `methodology`
- `boundary`
- `data_notes`
- `misconception`
- or broad `trust_methodology`

| Area | Impact |
|---|---|
| Changes required | Update model constants, controller validation, Filament options, likely tests, and any downstream grouping assumptions. Migration likely not required because DB field is string. |
| Backward compatibility | Generally compatible at DB level, but API/admin consumers may assume only `company/policy/help`. |
| Admin clarity | Better taxonomy if implemented carefully. |
| API impact | New public payload values for `kind`; frontend and SEO consumers must tolerate them. |
| Risk | Medium. Adds a taxonomy before route/discoverability policy is finalized. |
| Test requirements | Controller validation tests, Filament/admin field tests if present, public payload contract checks, sitemap/llms/footer non-exposure checks. |
| Recommendation | Defer until after draft import and route/discoverability gates prove the taxonomy is needed. |

Final recommendation: **Option A now, Option B later only with explicit product taxonomy approval.**

## 4. `page_type` strategy

| Page key | Recommended page_type | Supported now? | Notes |
|---|---|---:|---|
| `SCIENCE-HUB-CONTENT-01` | `science` | Yes | Best fit for the top-level methods/science hub. |
| `METHOD-BOUNDARY-CONTENT-01` | `boundary` | Yes | Must reconcile with existing public `/method-boundaries` record. |
| `ITEM-DESIGN-CONTENT-01` | `methodology` | Yes | Good fit; must avoid item bank leakage. |
| `RELIABILITY-VALIDITY-CONTENT-01` | `methodology` | Yes | Good fit; all numeric evidence remains Unknown/not public unless supplied. |
| `DATA-NOTES-CONTENT-01` | `privacy` | Yes | Good fit if aligned to privacy/data-result boundaries. |
| `MISCONCEPTIONS-CONTENT-01` | `boundary` | Yes | Good fit if framed as safe usage boundaries, not competitor attack. |

## 5. Root-level slug strategy

Nested `/science/*` routes should not be recommended until route architecture supports nested ContentPage routing. Current backend public route is slug-based and current fap-web rendering uses dedicated root route wrappers.

| Page key | Final candidate slug | Fallback slug | Current route status | Import allowed as draft? | Publish allowed now? | Notes |
|---|---|---|---|---:|---:|---|
| `SCIENCE-HUB-CONTENT-01` | `science` | `science` | 404 in route scan | Conditional after `kind` mapping | No | Needs fap-web route wrapper before public rendering. |
| `METHOD-BOUNDARY-CONTENT-01` | `method-boundaries` | `method-boundaries` | Existing public route | No new record; revision only | No package publish | Existing ContentPage authority must remain single source. |
| `ITEM-DESIGN-CONTENT-01` | `item-design-notes` | `item-design-notes` | 404 in route scan | Conditional after `kind` mapping | No | Root route wrapper required later. |
| `RELIABILITY-VALIDITY-CONTENT-01` | `reliability-validity` | `evidence-measurement-error` | Both 404 in route scan | Conditional after `kind` mapping and slug choice | No | `evidence-measurement-error` is lower claim risk if review rejects validity phrasing. |
| `DATA-NOTES-CONTENT-01` | `data-privacy` | `data-results-notes` | Both 404 in route scan | Conditional after `kind` mapping and slug choice | No | Prefer `data-privacy` if this is primarily a data/privacy trust page. |
| `MISCONCEPTIONS-CONTENT-01` | `common-misconceptions` | `common-misconceptions` | 404 in route scan | Conditional after `kind` mapping | No | Must keep competitor and anxiety-marketing claims blocked. |

## 6. Existing `/method-boundaries` strategy

`METHOD-BOUNDARY-CONTENT-01` should be treated as a **revision proposal / merge notes**, not a new `ContentPage`.

Safe workflow:
1. Keep existing `/method-boundaries` record as authority.
2. Do not create `/science/method-boundaries`.
3. Do not create a second root `/method-boundaries` record.
4. Compare existing zh/en live content and CMS payload against the package draft in a dedicated reconciliation PR.
5. If accepted, create a working CMS revision only after science/legal review.
6. Keep current public/indexable/discoverability state unchanged until explicit publish approval.
7. Run claim lint and route/private URL lint before any revision publication.

## 7. Draft import readiness

| Page key | Classification | Draft import note | Publish note |
|---|---|---|---|
| `SCIENCE-HUB-CONTENT-01` | `draft_import_blocked_kind`, then `draft_import_ready_after_mapping` | Normalize `kind` to `policy`; keep draft/non-public/noindex. | `publish_blocked` by missing route and reviews. |
| `METHOD-BOUNDARY-CONTENT-01` | `draft_import_blocked_existing_authority_conflict` | Do not import as a new page. Use reconciliation/revision workflow. | `publish_blocked` as package content. Existing page remains authority. |
| `ITEM-DESIGN-CONTENT-01` | `draft_import_blocked_kind`, then `draft_import_ready_after_mapping` | Normalize `kind` to `policy`; keep draft/non-public/noindex. | `publish_blocked` by missing route and item-bank review. |
| `RELIABILITY-VALIDITY-CONTENT-01` | `draft_import_blocked_kind`, then `draft_import_ready_after_mapping` | Normalize `kind` to `policy`; choose final slug before import. | `publish_blocked` by missing route and evidence review. |
| `DATA-NOTES-CONTENT-01` | `draft_import_blocked_kind`, then `draft_import_ready_after_mapping` | Normalize `kind` to `policy`; prefer `data-privacy` if legal/product review agrees. | `publish_blocked` by missing route and legal/product review. |
| `MISCONCEPTIONS-CONTENT-01` | `draft_import_blocked_kind`, then `draft_import_ready_after_mapping` | Normalize `kind` to `policy`; keep draft/non-public/noindex. | `publish_blocked` by missing route and claim review. |

No page should be classified as public-ready. Route absence does not block non-public draft storage by itself, but it blocks publication and discoverability.

## 8. Required guardrails before any future import

- Preserve `is_public=false`.
- Preserve `is_indexable=false`.
- Preserve `status=draft`.
- Preserve review states and review-required flags.
- Preserve `Unknown` as Unknown; do not convert missing evidence to `0`, `false`, or implied approval.
- Do not map `sitemap_eligible`, `llms_eligible`, or `footer_eligible` into nonexistent backend fields.
- Do not create FAQ schema from `visible_faq_items`.
- Do not include private URL patterns in public body or link fields.
- Do not publish or mutate existing `/method-boundaries` during import normalization.

## 9. Completed implementation PRs and remaining gates

The originally recommended importer normalization path has now been completed as a guarded dry-run/import-readiness line. The current repository state supports validating the six-page Science draft package as a non-public candidate, but it intentionally does not provide a real import or publish path.

Completed implementation scope:
- Dry-run package mapping and normalization.
- `/method-boundaries` existing-authority reconciliation.
- Operator review readiness.
- Pre-real-import QA.
- First-class publish/operator/claim/schema safety fields.
- Real-import contract lock requiring separate approval and a later import-command PR.
- Frontend guarded route wrappers and claim/FAQ/discoverability gates.

Remaining gates before any real CMS import:
- Operator confirms the exact package revision and content source.
- Science/legal/product review resolves `science_review_required`, `legal_review_required`, `review_state`, `claim_gate_status`, `forbidden_claims`, and FAQ/schema eligibility.
- A separate PR introduces or enables the real import command path; this document does not authorize it.
- Production dry-run evidence is collected without database writes before any import execution.

Remaining gates before any publication or discoverability exposure:
- Imported CMS records exist and pass review.
- `publish_allowed=true` is set only after operator approval.
- `review_state=approved`, claim gate passed, forbidden claims empty, and schema eligibility reviewed when schema is enabled.
- Frontend route smoke checks pass against real CMS content.
- Sitemap, llms, footer, search submission, and distribution are each handled in separately scoped approval-gated tasks.

## 10. Validation

Local validation required for this docs-only PR:

```bash
git diff --check -- backend/docs/seo/science-contentpage-cms-field-mapping-01.md
rg -n "clinical|diagnos|guarantee|best job|最适合|精准职业匹配|Review|AggregateRating|Product|Offer|sitemap_eligible: true|llms_eligible: true|footer_eligible: true|is_public: true|is_indexable: true" backend/docs/seo/science-contentpage-cms-field-mapping-01.md
git diff --name-only origin/main..HEAD
```

Runtime commands such as `php artisan route:list`, `php artisan migrate`, curl mutation checks, and `bash backend/scripts/ci_verify_mbti.sh` are not applicable to this docs-only planning PR because no runtime, route, migration, controller, service, CMS, or content package files are changed.

## 11. Final decision table

| Gate | Decision | Reason |
|---|---|---|
| Current ContentPage model coverage | GO | Required draft and publish-safety fields now exist. |
| Package dry-run compatibility | GO | Backend dry-run validates the package without writes and preserves safety defaults. |
| `page_type` compatibility | GO | Required values are supported. |
| Root slug import strategy | CONDITIONAL | Use root slugs only; route absence no longer blocks draft validation, but still blocks publication until content exists and route smoke passes. |
| `/method-boundaries` handling | GO for revision-only, NO-GO for new record | Existing authority must remain single-source and be updated only through a reviewed revision path. |
| Non-public draft readiness | CONDITIONAL | Dry-run and QA can pass for non-public drafts, but real import still needs separate operator approval and import-command PR. |
| Real CMS import | NO-GO in this line | The real-import contract is intentionally locked as dry-run-only. |
| Publication | NO-GO | Operator approval, approved review state, claim gate, schema eligibility, live route smoke, and separate publish authorization remain required. |
| Discoverability | NO-GO | Sitemap, llms, footer, search submission, and distribution remain separate approval-gated tasks. |
