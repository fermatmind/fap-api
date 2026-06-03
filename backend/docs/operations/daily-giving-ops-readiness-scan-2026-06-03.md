# Daily Giving Operations Readiness Scan

Date: 2026-06-03

Repo: fap-api

PR train item: DAILY-GIVING-OPS-READINESS-SCAN-01

## 1. GO / NO-GO

NO-GO for Daily Giving public amplification, paid-page trust badges, search submission, and social distribution.

GO only for private operator-run ledger planning and docs-only readiness work. This scan did not create DailyGiving records, upload proof files, change CMS data, publish, submit search, deploy, or read secrets.

Primary blocker: production public Daily Giving records and months are currently empty, while proof storage privacy is not fully proven at the storage-disk level. The backend has field-level public/private separation, but no dedicated proof disk, storage service, or enforced upload path was found in this scan.

## 2. DailyGiving Code Inventory

Backend-owned assets found:

| Area | File | Current finding |
| --- | --- | --- |
| Model | `backend/app/Models/DailyGivingRecord.php` | Defines donation/proof statuses, `publishedPublic()` scope, `isPublishable()`, public array serialization, social link serialization, and private fields. |
| Ops resource | `backend/app/Filament/Ops/Resources/DailyGivingRecordResource.php` | Provides back-office create/edit fields, default private publication toggles, admin-only proof/private fields, and manual social sync boundary text. |
| Public API controller | `backend/app/Http/Controllers/API/V0_5/Foundation/DailyGivingRecordController.php` | Exposes collection, detail, months, and month-record endpoints through `publishedPublic()`. |
| Public API resource | `backend/app/Http/Resources/Foundation/DailyGivingRecordResource.php` | Delegates output to `DailyGivingRecord::toPublicArray()`. |
| Migration | `backend/database/migrations/2026_05_30_000100_create_daily_giving_records_table.php` | Creates public/private proof fields, redaction/private receipt fields, `is_public`, `is_indexable`, and `published_at`. |
| Tests | `backend/tests/Feature/Foundation/*DailyGiving*` | Cover public API filtering, publication gates, private-field exclusion, months endpoints, and manual social sync boundaries. |
| Technical entrypoint | `backend/docs/seo/foundation-daily-giving-technical-entrypoint.md` | Records backend authority, public API route order, discoverability gate, claim boundary, and manual social sync boundary. |

Frontend-owned read-only references:

| Area | File | Current finding |
| --- | --- | --- |
| Ledger page | `fap-web/app/(localized)/[locale]/foundation/daily-giving/page.tsx` | Uses backend public API, forces dynamic rendering, and sets `noindex` when `hasDailyGivingPublicRecords()` is false. |
| Discoverability | `fap-web/lib/foundation/dailyGivingSeo.ts` | Returns no sitemap/llms entries when public months are empty. |
| API adapter | `fap-web/lib/foundation/dailyGiving.ts` | Reads same-origin Foundation Daily Giving API paths without frontend content fallback. |

## 3. Model / Resource / API / Storage Matrix

| Check | Status | Evidence |
| --- | --- | --- |
| `is_public` gate exists | PASS | Model scope requires `is_public=true`; migration default is false; Ops resource default is false. |
| `is_indexable` field exists | PASS | Migration and model include `is_indexable`; Ops default is false. |
| Public API requires published record | PASS | `publishedPublic()` requires `is_public=true`, non-null `published_at`, `published_at <= now`, and completed/verified donation status. |
| `isPublishable()` checks proof status | PARTIAL PASS | It checks proof status, recipient, official URL, date, status, and publish timing. The public API scope does not itself filter proof status. |
| Public API excludes private proof path | PASS | `toPublicArray()` omits `proof_private_path`, `proof_redaction_notes`, `receipt_reference_private`, internal notes, and admin IDs. |
| Proof public/private field separation exists | PASS | Migration/model split `proof_public_url` from `proof_private_path`. |
| Dedicated private proof disk verified | UNKNOWN | No DailyGiving-specific disk column, upload service, or storage policy was found. `backend/config/filesystems.php` is not tied to DailyGiving proof handling in the scanned code. |
| Redaction notes admin-only | PASS | Ops helper text marks redaction/private fields as admin-only; public serialization omits them. |
| Manual social sync only | PASS | Ops resource documents manual-only posting; tests cover that automatic posting state and diagnostics are not public API output. |
| Runtime mutation in this scan | PASS | None. This PR is docs/metadata only. |

## 4. Proof File Storage And Redaction Risk

The code has a safe data-shape boundary, but not a fully proven storage boundary.

Safe evidence:

- `proof_public_url` and `proof_private_path` are separate fields.
- `proof_redaction_notes` and `receipt_reference_private` exist for admin-only information.
- Public API output includes `proof_public_url` and redacted receipt reference only.
- Public API output excludes raw proof path, redaction notes, private receipt reference, internal notes, and admin IDs.

Remaining risk:

- The scan did not find a dedicated DailyGiving proof upload service, disk binding, storage policy, or private bucket assertion.
- `proof_public_url` is a raw URL string field. A future operator could paste an unsafe URL unless the ops process or later validation gates restrict it.
- `proof_status=withheld` is publishable by current `isPublishable()` logic. That may be acceptable for privacy, but public amplification needs a clear policy for when withheld proof is allowed.
- The public API scope does not include `proof_status` filtering. It relies on operator workflow and tests around `isPublishable()`, not on the API query itself.

Conclusion: proof storage is field-separated, but the disk/privacy enforcement path is Unknown. Public amplification should remain blocked until a readiness gate proves proof originals are private and redacted public evidence is intentional.

## 5. Public Page Indexability

Production read-only checks:

| URL | Status | Robots | Canonical | ItemList |
| --- | --- | --- | --- | --- |
| `https://fermatmind.com/zh/foundation/daily-giving` | 200 | `noindex, nofollow, noarchive, nocache` | self canonical | absent when records are empty |
| `https://fermatmind.com/en/foundation/daily-giving` | 200 | `noindex, nofollow, noarchive, nocache` | self canonical | absent when records are empty |

Root Foundation pages are publicly indexable surfaces. The Daily Giving ledger page itself remains noindex because public records are empty.

Sitemap and llms production checks:

| Surface | Daily Giving URL present? |
| --- | --- |
| `https://fermatmind.com/sitemap.xml` | No |
| `https://fermatmind.com/llms.txt` | No |
| `https://fermatmind.com/llms-full.txt` | No |

This is the expected behavior while the public months endpoint is empty.

## 6. Public API Records / Months State

Production read-only API checks:

| API | Result |
| --- | --- |
| `GET /api/v0.5/foundation/giving-records` | 200, `ok=true`, `items=[]`, `pagination.total=0` |
| `GET /api/v0.5/foundation/giving-records/months` | 200, `ok=true`, `months=[]` |

Cause of zero public state:

- The public API only returns records that satisfy `publishedPublic()`.
- Current production-visible records collection is empty.
- This scan did not perform a production DB read, create a record, or mutate a record, so it cannot distinguish between "no records exist" and "records exist but are non-public/unpublished/planned/future-dated/filtered out".
- From public truth, there are zero public records and zero public months.

## 7. Public Benefit Claim Boundary

No exact DailyGiving-core code hit was found for unsupported phrases such as "UN official partner", "endorsed", "certified", or "guaranteed impact" in the DailyGiving implementation files scanned.

Known boundary:

- Test factory data uses `United Nations Foundation` as a fixture recipient and marks public notes as an independent giving record.
- Backend technical docs state that external authorization, partnership, legal status, or third-party endorsement claims require explicit documentation.
- Public amplification must not imply official UN affiliation, partner status, certification, endorsement, or guaranteed impact.

Current risk:

- Foundation surfaces are already public, while Daily Giving public records are empty.
- Any future public messaging, trust badge, or social distribution must stay factual and record-backed.
- This scan did not approve or create public-benefit promotional copy.

## 8. Trust Badge Readiness

NO-GO for paid-page trust badges.

Reason:

- No dedicated safe read-only trust badge endpoint was identified in this scan.
- The public Daily Giving API currently returns zero public records and months.
- A paid-page badge that implies active daily giving would outrun the public evidence surface.
- No runtime or frontend badge changes were made.

Permitted later direction:

- A future badge can read a backend-authoritative, redacted, public-safe summary only after public records exist and a readiness gate verifies proof/redaction/indexability boundaries.

## 9. Release Prerequisites Before Public Amplification

Before Daily Giving can be publicly amplified, all of the following should pass:

1. At least one production DailyGiving record is intentionally operator-created, completed or verified, review-approved, and safe for public display.
2. The record is either backed by a redacted public proof URL or has an approved withheld-proof reason.
3. Raw proof originals are proven private at the storage/path level.
4. `proof_private_path`, redaction notes, private receipt references, internal notes, admin IDs, and private sync diagnostics remain absent from public API output.
5. Public API records and months return the intended records.
6. Daily Giving pages render expected records and only then become indexable under the approved discoverability rule.
7. Sitemap, llms, and llms-full enumerate Daily Giving only when public records/months are present.
8. Claim-boundary scan stays clean for official partner, endorsement, certification, and guaranteed-impact language.
9. Any trust badge reads only a backend-authoritative public-safe endpoint.
10. Search submission and social distribution receive separate explicit authorization after the above checks pass.

Paid ads remain forbidden until the commercial event taxonomy, freemium locale policy, privacy/private URL review, Daily Giving readiness gate, and trust-badge evidence are all green.

## 10. Follow-up PR Scope: DAILY-GIVING-OPS-READINESS-01

Recommended PR train item:

- PR id: `DAILY-GIVING-OPS-READINESS-01`
- Repo: `fap-api`
- Branch: `codex/daily-giving-ops-readiness-01`
- PR title: `fix(benefit): add daily giving public readiness gate`

Goal:

Add a read-only Daily Giving readiness gate that operators can run before public amplification, search submission, social distribution, or paid-page trust badge exposure.

Likely scope:

- `backend/app/Services/Foundation/**`
- `backend/app/Console/Commands/**`
- `backend/tests/Feature/Foundation/**`
- `backend/docs/operations/**`
- `docs/codex/pr-train.yaml`
- `docs/codex/pr-train-state.json`

Gate should report, without writes:

- total records and public records
- months count
- records blocked by `is_public=false`, missing `published_at`, future `published_at`, planned/voided status, missing recipient URL, and unsafe proof status
- proof status distribution
- `proof_public_url` present count
- `proof_private_path` present count
- redaction/private fields public API absence check
- indexable records count
- social link presence and manual-only boundary
- claim-boundary scan status
- sitemap/llms expected exposure state

Required checks for the follow-up:

- focused Foundation/DailyGiving PHPUnit tests
- route-list smoke for Foundation routes
- JSON/YAML parse for train metadata
- `git diff --check`
- no production writes
- no CMS mutation
- no publish
- no search submission
- no deploy

## Validation Performed

- `git status --short --branch`
- `git log -n 5 --oneline`
- `php backend/artisan route:list | grep -i "giving\|foundation" || true`
- read-only source scan of DailyGiving model, Ops resource, controller, resource, migration, tests, and technical docs
- read-only cross-repo scan of fap-web Daily Giving page, API adapter, and discoverability helper
- production read-only checks for Daily Giving pages, records API, months API, sitemap, llms, and llms-full

No backend runtime code, routes, database migrations, tests, CMS data, public API behavior, frontend code, proof files, DailyGiving records, publish state, search submission, or deployment was changed.
