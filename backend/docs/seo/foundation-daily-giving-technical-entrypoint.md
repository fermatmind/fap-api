# Foundation / Daily Giving Technical Entrypoint

This document is the technical entrypoint for the FermatMind Foundation / Daily Giving public-benefit trust chain. It connects the backend CMS, Daily Giving ledger, public API, frontend rendering, SEO/schema/llms surfaces, social link workflow, and post-deploy audit reports into one operating map.

## Executive Summary

The Foundation / Daily Giving work moves FermatMind's public-benefit story from page copy into an auditable system: bounded Foundation language, backend-authoritative content, real Daily Giving records, stable public APIs, frontend pages, SEO and schema exposure, llms visibility, manual social link reconciliation, and post-deploy smoke reports.

This is not a Search Channel submission track. Indexing, URL submission, and external search API actions remain separate tasks that require explicit approval.

## 2026-06-08 Line Review Update

Decision: backend technical readiness is `CONDITIONAL COMPLETE`; production public activation is `NOT COMPLETE`.

The recent PR line closed the backend contract needed for DailyGiving proof handling:

- operator-approved original charity donation proof images are allowed as public proof media;
- model-level proof storage gates reject obvious private/public proof boundary violations;
- public API smoke fixtures prove records and months can return public data without private fields;
- first-record private-ledger documentation records the real CNY 75 UNICEF proof review state without committing raw proof;
- CMS Media Library Filament uploads now persist through the media generator instead of saving temporary `/tmp/...` paths;
- repository rules now keep DailyGiving backend-authoritative, noindex, and blocked from trust badges or endorsement claims.

The line is not fully operationally complete because the repository does not prove that a production DailyGiving record has been activated with a real public media URL. The remaining non-code gate is operator/CMS production activation.

Production activation completion checklist:

1. CMS / Media Library uploads or selects the operator-approved original public proof media URL.
2. The production record binds `proof_public_url` and sets `proof_status=operator_approved_available`.
3. Proof review and claim review pass before `is_public=true`.
4. The record keeps `is_indexable=false`.
5. Public records and months API smoke passes with production data and confirms no private fields leak.
6. DailyGiving remains blocked from sitemap, `llms.txt`, `llms-full.txt`, trust badges, social distribution, paid-page trust claims, and search submission.

Recent PR review: the broader DailyGiving / Foundation line spans dozens of backend PRs plus the cross-repo frontend rendering and indexability PRs. The current dialogue's direct DailyGiving closeout PRs include backend proof/storage/private-ledger/API/media PRs and frontend proof/noindex adapter PRs. Adjacent Help, Science content-page, and RIASEC media PRs do not change the DailyGiving completion state, except that fap-api #1963 fixes the shared Media Library upload path needed for proof media.

Recent backend DailyGiving / public-benefit PR ledger:

| PR | Status contribution |
| --- | --- |
| fap-api #1879 | DailyGiving operations readiness scan; established no-go for amplification until records/proof gates pass. |
| fap-api #1893 | Public-benefit content asset packages archived for operator/GPT handoff. |
| fap-api #1898 | Foundation trust page asset inventory. |
| fap-api #1901 | Public-benefit claim boundary contract. |
| fap-api #1904 | Foundation content request card. |
| fap-api #1905 | Foundation CMS field map. |
| fap-api #1907 | Foundation FAQ schema gate. |
| fap-api #1910 | DailyGiving proof public approval SOP. |
| fap-api #1912 | DailyGiving proof storage gate. |
| fap-api #1914 | DailyGiving public release prerequisites. |
| fap-api #1917 | DailyGiving public API smoke fixture. |
| fap-api #1920 | First record review template. |
| fap-api #1926 | First record private-ledger gate. |
| fap-api #1929 | Private-ledger closeout. |
| fap-api #1932 | Operator-approved public proof gate; inherited redaction file name but current rule allows approved original proof. |
| fap-api #1941 | Repository rule alignment allowing original DailyGiving proof media. |
| fap-api #1950 | Model/API gate for operator-approved public proof. |
| fap-api #1963 | CMS Media Library upload path fix so Filament uploads do not persist `/tmp/...` paths. |

Recent frontend DailyGiving proof/indexability PR ledger:

| PR | Status contribution |
| --- | --- |
| fap-web #1059 | Keeps DailyGiving noindex and out of llms while gated. |
| fap-web #1063 | Adapts `proof_public_url` to the frontend evidence link. |
| fap-web #1067 | Aligns frontend DailyGiving proof rendering rules with original proof media policy. |

## System Boundary

Backend source of truth:

- CMS `content_pages` own Foundation, policy, brand, charter, careers, and related static-content copy.
- `daily_giving_records` owns Daily Giving ledger data, publication state, month grouping, and social link metadata.
- Filament Ops resources own manual back-office entry and review.
- Public API resources own the frontend and SEO data contract.
- Report docs under `backend/docs/seo` preserve audit history and claim-boundary decisions.

Frontend responsibilities:

- Route rendering for Foundation and Daily Giving pages.
- Same-origin API rewrites for public Foundation API paths.
- Canonical, robots, JSON-LD, sitemap, `llms.txt`, and `llms-full.txt` behavior.
- Display of backend-authoritative manual social links.
- No frontend fallback editorial content for CMS-backed surfaces.

Guardrails:

- Do not use staging as URL truth.
- Do not create local frontend editorial copies for Foundation content.
- Do not enqueue Search Channel items or submit URLs from this chain unless a separate approved task says so.
- Do not add automatic social posting or external social API calls without a separate credential and operations design.

## Completion Map

| Area | Task / PR | Current status | Evidence |
| --- | --- | --- | --- |
| Foundation guarded phrase repair | PR-FDN-01 Foundation wording repair | Functionally complete | fap-api PR #1757, PR #1778 |
| API public TLS / same-origin unblocker | OPS-API-PUBLIC-TLS-PATH-FIX-01A | Complete | fap-web PR #931 |
| API public TLS / edge path | OPS-API-PUBLIC-TLS-PATH-FIX-01B | Operationally repaired | Verified by follow-up internal resolve proof |
| Internal API resolve proof | OPS-API-INTERNAL-RESOLVE-PROOF | Complete | fap-api PR #1840 |
| Daily Giving backend MVP | PR-FDN-02A | Complete | fap-api PR #1758 |
| Daily Giving backend post-deploy validation | PR-FDN-02A-POST-DEPLOY-RUNTIME-VALIDATION | Complete | fap-api PR #1833 |
| Daily Giving frontend pages | PR-FDN-02B | Complete | fap-web PR #933 |
| Daily Giving frontend post-deploy smoke | PR-FDN-02B-POST-DEPLOY-RUNTIME-SMOKE | Complete | fap-web PR #965 |
| Foundation SEO readiness | PR-FDN-SEO-01-READINESS | Complete | fap-web PR #968 |
| Foundation SEO implementation | PR-FDN-SEO-01-IMPLEMENTATION | Complete | fap-web PR #969 |
| Foundation SEO post-deploy smoke | PR-FDN-SEO-01-POST-DEPLOY-SMOKE | Complete | fap-web PR #973 |
| Result private flow isolation | PR-08-RESULT-PRIVATE-FLOW-ISOLATION | Reconciled / covered | fap-web PR #979 |
| Next i18n routing canonical guard | PR-01-NEXT-I18N-ROUTING-CANONICAL-GUARD | Reconciled / covered | fap-web PR #979 |
| Geo / schema / FAQ evidence | PR-07-GEO-SCHEMA-FAQ-EVIDENCE | Reconciled / deferred by page family | fap-web PR #979 |
| Policies ledger reconcile and smoke | PR-POL-01-LEDGER-RECONCILE-AND-SMOKE | Complete | fap-api PR #1838 |
| Hiring post-publish smoke | PR-HIRING-01-POST-PUBLISH-SMOKE | Complete | fap-api PR #1839 and reconciliation PR #1841 |
| Social sync readiness | PR-FDN-SOCIAL-SYNC-READINESS | Complete | fap-api PR #1819 |
| Manual social sync MVP | PR-FDN-SOCIAL-SYNC-MVP-01 | Complete | fap-api PR #1827 |
| Social link frontend display | PR-FDN-SOCIAL-LINK-DISPLAY-IMPLEMENTATION-01 | Complete | fap-web PR #954 |
| DailyGiving original proof media rule | DAILY-GIVING-ORIGINAL-PROOF-RULES | Complete | fap-api PR #1941 |
| Operator-approved proof model gate and tests | DAILY-GIVING-REDACTED-PUBLIC-PROOF-01 / proof gate follow-up | Complete | fap-api PR #1950 |
| First real record private-ledger documentation | DAILY-GIVING-FIRST-RECORD-PRIVATE-LEDGER-01 | Private-ledger complete; public activation still blocked | `backend/docs/operations/daily-giving-first-record-private-ledger.md` |
| Public API smoke fixture for records/months | DAILY-GIVING-PUBLIC-API-SMOKE-01 | Complete for test fixture; production count still requires runtime smoke | `backend/docs/operations/daily-giving-public-api-smoke.md` |
| CMS Media Library upload path repair | CMS Media Library Filament upload fix | Complete | fap-api PR #1963 |
| DailyGiving frontend noindex / llms gate | DAILY-GIVING-INDEXABILITY-GATE-01 | Complete | fap-web PR #1059 |
| DailyGiving frontend proof URL adapter | DAILY-GIVING-PROOF-PUBLIC-URL-FRONTEND-ADAPTER-01 | Complete | fap-web PR #1063 |
| DailyGiving frontend original proof rendering rules | DAILY-GIVING-ORIGINAL-PROOF-WEB-RULES | Complete | fap-web PR #1067 |

For `PR-07-GEO-SCHEMA-FAQ-EVIDENCE`, evidence means visible page content, backend-authoritative data, or an approved audit record that grounds schema, FAQ, llms, and public claims. It does not mean adding unsupported proof language to a page.

## Backend Assets

Public API routes are declared in `backend/routes/api.php`:

- `GET /api/v0.5/foundation/giving-records/months`
- `GET /api/v0.5/foundation/giving-records/months/{yearMonth}`
- `GET /api/v0.5/foundation/giving-records`
- `GET /api/v0.5/foundation/giving-records/{recordCode}`

Route order invariant:

1. Static collection routes must be declared before the dynamic detail route.
2. The required backend order is `months`, `months/{yearMonth}`, collection index, then `{recordCode}` detail.
3. If a future route changes this ordering, the detail route must be constrained so reserved static segments cannot be consumed as record codes.
4. Same-origin frontend rewrites must either preserve the exact path and backend ordering semantics or declare static month routes before dynamic record routes.

Public API response contract:

- Only published Daily Giving records may be returned.
- Record detail lookup must not expose unpublished records.
- Month archive endpoints must list only months that contain public records.
- Locale-aware fields must be selected from backend data, not inferred by the frontend.
- Sorting must be deterministic; listing surfaces should prefer newest-first unless a task explicitly changes that contract.
- Pagination, limit, and month filtering behavior must remain explicit in tests.
- Social URLs may be returned only when stored by backend authority and allowed for public display.
- Admin notes, private sync diagnostics, credentials, internal-only IDs, and unpublished metadata must not be exposed.
- `proof_public_url` is the only public proof media field.
- `proof_private_path`, `proof_redaction_notes`, `receipt_reference_private`, `internal_notes`, and admin user ids must never be returned by public resources.
- `proof_public_url` may point to an operator-approved original charity donation proof image; a separate redacted derivative is not required when the operator approves the original image for public use.
- Public proof media must be an HTTPS public media URL and must not contain private/admin/token/secret/backend-only indicators.
- Public records may remain `is_indexable=false`; indexability is a separate SEO gate and is not implied by `is_public=true`.

Core backend implementation:

- `backend/app/Models/DailyGivingRecord.php`
- `backend/app/Http/Controllers/API/V0_5/Foundation/DailyGivingRecordController.php`
- `backend/app/Http/Resources/Foundation/DailyGivingRecordResource.php`
- `backend/app/Filament/Ops/Resources/DailyGivingRecordResource.php`
- `backend/app/Filament/Ops/Resources/MediaAssetResource.php`
- `backend/database/migrations/2026_05_30_000100_create_daily_giving_records_table.php`

Focused backend tests:

- `backend/tests/Feature/Foundation/DailyGivingRecordPublicApiTest.php`
- `backend/tests/Feature/Foundation/DailyGivingRecordPublicationGateTest.php`
- `backend/tests/Feature/Foundation/DailyGivingRecordManualSocialSyncTest.php`
- `backend/tests/Feature/Foundation/DailyGivingPublicReleasePrereqTest.php`
- `backend/tests/Feature/Foundation/DailyGivingProofStorageGateTest.php`
- `backend/tests/Feature/Foundation/DailyGivingRedactedPublicProofTest.php`
- `backend/tests/Feature/Foundation/DailyGivingFirstRecordPrivateLedgerTest.php`
- `backend/tests/Feature/Foundation/DailyGivingPublicApiSmokeTest.php`
- `backend/tests/Feature/MediaLibrary/MediaLibraryPublicApiTest.php`
- `backend/tests/Feature/Foundation/PrFdn02aPostDeployRuntimeValidationTest.php`
- `backend/tests/Feature/Foundation/PrFdnSocialSyncReadinessTest.php`
- `backend/tests/Feature/ContentPages/FoundationGuardedPhraseRepairTest.php`

Backend audit reports:

- `backend/docs/operations/daily-giving-public-release-prereq.md`
- `backend/docs/operations/daily-giving-proof-storage-gate.md`
- `backend/docs/operations/daily-giving-redacted-public-proof.md`
- `backend/docs/operations/daily-giving-first-record-private-ledger.md`
- `backend/docs/operations/daily-giving-public-api-smoke.md`
- `backend/docs/operations/daily-giving-first-record-review-template.md`
- `backend/docs/seo/pr-fdn-02a-post-deploy-runtime-validation.md`
- `backend/docs/seo/pr-fdn-social-sync-readiness.md`
- `backend/docs/seo/pr-fdn-social-sync-mvp-01.md`
- `backend/docs/seo/ops-api-internal-resolve-proof.md`
- `backend/docs/seo/pr-pol-01-ledger-reconcile-and-smoke.md`
- `backend/docs/seo/pr-hiring-01-post-publish-smoke.md`

## Frontend Assets

Frontend paths are cross-repo references to the `fap-web` repository. This document lives in `fap-api` as the backend-owned technical entrypoint, but frontend files are not expected to exist under the fap-api working tree.

Same-origin Foundation API rewrites are declared in `fap-web/next.config.mjs` for:

- `/api/v0.5/foundation/giving-records`
- `/api/v0.5/foundation/giving-records/:recordCode`
- `/api/v0.5/foundation/giving-records/months`
- `/api/v0.5/foundation/giving-records/months/:yearMonth`

Core frontend implementation:

- `fap-web/app/(localized)/[locale]/foundation/page.tsx`
- `fap-web/app/(localized)/[locale]/foundation/daily-giving/page.tsx`
- `fap-web/app/(localized)/[locale]/foundation/daily-giving/[yearMonth]/page.tsx`
- `fap-web/components/foundation/DailyGivingLedgerPage.tsx`
- `fap-web/lib/foundation/dailyGiving.ts`
- `fap-web/lib/foundation/dailyGivingSeo.ts`

Focused frontend tests and reports:

- `fap-web/tests/contracts/pr-fdn-02b-daily-giving-frontend-pages.contract.test.tsx`
- `fap-web/tests/contracts/pr-fdn-02b-post-deploy-runtime-smoke.contract.test.ts`
- `fap-web/tests/contracts/pr-fdn-seo-01-readiness.contract.test.ts`
- `fap-web/tests/contracts/pr-fdn-seo-01-implementation.contract.test.ts`
- `fap-web/tests/contracts/pr-fdn-seo-01-post-deploy-smoke.contract.test.ts`
- `fap-web/tests/contracts/pr-fdn-social-link-display-implementation-01.contract.test.tsx`
- `fap-web/docs/seo/pr-fdn-02b-post-deploy-runtime-smoke.md`
- `fap-web/docs/seo/pr-fdn-seo-01-readiness.md`
- `fap-web/docs/seo/pr-fdn-seo-01-implementation.md`
- `fap-web/docs/seo/pr-fdn-seo-01-post-deploy-smoke.md`
- `fap-web/docs/seo/pr-fdn-social-link-display-implementation-01.md`
- `fap-web/docs/seo/legacy-seo-reconciliation-scan.md`

## Runtime Contract

Public content page URLs:

- `https://fermatmind.com/en/foundation`
- `https://fermatmind.com/zh/foundation`

Daily Giving URLs:

- `https://fermatmind.com/en/foundation/daily-giving`
- `https://fermatmind.com/zh/foundation/daily-giving`
- `https://fermatmind.com/en/foundation/daily-giving/{YYYY-MM}`
- `https://fermatmind.com/zh/foundation/daily-giving/{YYYY-MM}`

Expected runtime behavior after deployment:

- Public pages return HTTP 200 when the backend authority is published and public.
- Canonical URLs point to the production apex host.
- Staging canonical leakage is blocked.
- Robots behavior must match the approved public/discoverability gate for each surface.
- Daily Giving structured data is generated from backend API data only.
- `sitemap.xml`, `llms.txt`, and `llms-full.txt` exposure is gated by the approved discoverability rules and public data state.
- Footer/nav exposure remains explicit and must not be inferred from page existence alone.

Daily Giving discoverability gate:

- `/foundation` may be indexable only when approved Foundation CMS authority is public.
- `/foundation/daily-giving` may be indexable only when at least one public Daily Giving record exists or an approved empty-state page exists.
- `/foundation/daily-giving/{YYYY-MM}` may return 200 and indexable only when that month exists in the public months endpoint and contains public records.
- Empty or unpublished month archives must not be exposed through sitemap, llms, llms-full, footer, or JSON-LD.
- Unpublished records must never appear in public API responses, frontend HTML, JSON-LD, sitemap, llms, or social link display.

## Claim And Trust Boundary

Allowed Foundation direction:

- Public-benefit mission and governance path.
- Planned public-benefit shareholding direction when supported by approved company materials.
- Youth interests, clear data boundaries, responsible assessment use, and long-term operating principles.
- Transparent Daily Giving records funded and managed by FermatMind's own operational framework.

Guarded claim categories:

- Formal foundation or separate legal-entity status claims.
- Non-commercial entity registration claims.
- Public money-collection or third-party giving-program claims.
- Formal governance-body or legal-duty framing.
- Cap-table precision or completed ownership-transfer claims.
- Acting-for, authorized-by, or partnership-with external organizations unless explicitly documented.
- Clinical, diagnostic, compensation, hiring-fit, or career-outcome guarantee claims.

Public copy should prefer positive factual boundary language over negative sentences that repeat guarded terms. The goal is to preserve legal and trust boundaries without increasing keyword or LLM extraction risk.

## Social Sync Boundary

Social sync is manual and backend-authoritative:

- Backend records may store existing social post URLs and sync metadata.
- Admin workflow may reconcile and record manual posting status.
- Frontend may display backend-provided social links.
- Social sync must be idempotent; the same Daily Giving record must not create duplicate displayed social links.
- Frontend must only display backend-approved social links and must not infer social URLs from record text, slug, or platform metadata.
- The current chain does not perform automatic posting, credential handling, social platform API calls, or scheduled distribution.

## Operational Runbook

After Foundation CMS copy changes:

1. Confirm guarded phrase scans are clean.
2. Update only the backend CMS authority layer through the approved import/update flow.
3. Trigger or wait for content-page revalidation when approved.
4. Recheck `/en/foundation`, `/zh/foundation`, `/llms.txt`, and `/llms-full.txt`.
5. Confirm no Search Channel queue item was created unless explicitly approved.

After Daily Giving data changes:

1. Create or edit records through Filament Ops.
2. Confirm publication gate and locale state.
3. Verify public API responses.
4. Verify frontend ledger and month archive pages.
5. Verify canonical, robots, JSON-LD, sitemap, llms, and footer/nav behavior.
6. Record post-deploy smoke results when the change crosses a release boundary.

## Sidecars And Watch Items

- The API public TLS / SNI edge repair was operational rather than a normal repo PR; `OPS-API-INTERNAL-RESOLVE-PROOF` is the repo-tracked evidence that the path recovered.
- The exact `PR-FDN-01-FOUNDATION-EN-GUARDED-PHRASE-REPAIR-01` task id is not a standalone final artifact in this repo, but the functional Foundation wording repair was completed through the PR-FDN-01 repair chain and follow-up smoke/recheck work.
- Daily Giving discoverability should remain data-gated where pages require public ledger evidence.
- `PR-07-GEO-SCHEMA-FAQ-EVIDENCE` was reconciled as deferred by page family, not implemented globally.

## Future Change Acceptance Checks

Backend checks for Daily Giving or Foundation authority changes:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=DailyGiving --no-ansi
php artisan test --filter=PrFdn02aPostDeployRuntimeValidation --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer audit --locked --no-interaction --ignore-unreachable
```

Frontend checks for Foundation / Daily Giving rendering, SEO, and llms changes:

```bash
cd /Users/rainie/Desktop/GitHub/fap-web
pnpm test:contract -- pr-fdn
pnpm typecheck
NEXT_PUBLIC_API_URL=https://api.fermatmind.com NEXT_PUBLIC_SITE_URL=https://fermatmind.com pnpm build
git diff --check
```

Production read-only smoke examples:

```bash
curl -I https://fermatmind.com/en/foundation
curl -I https://fermatmind.com/en/foundation/daily-giving
curl -sS https://fermatmind.com/api/v0.5/foundation/giving-records?locale=en
curl -sS https://fermatmind.com/llms-full.txt | grep -i "foundation"
```
