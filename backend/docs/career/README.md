# Career Backend Technical Handbook

Last reviewed: 2026-06-01

This is the backend entry point for FermatMind Career architecture, authority,
runtime publication, discoverability, and operations. It consolidates the
previous Career Markdown reports and runbooks into one maintained document.

Generated JSON artifacts under `backend/docs/seo/generated/` and
`backend/docs/career/generated/` remain as historical evidence. Markdown reports
that duplicated this handbook have been removed.

## Current Production Shape

Current public Career detail authority is backend-owned:

- Public Career detail cohort: `1046` canonical slugs.
- Localized public detail URLs: `2092` (`1046` EN + `1046` ZH).
- Career job detail pages are public only when backend runtime projection,
  release gate, SEO/indexability policy, and display/content authority agree.
- Sitemap and LLM discoverability are consumers of backend public authority,
  not source-of-truth surfaces.

The following slugs are intentionally excluded from public runtime,
sitemap, `llms.txt`, and `llms-full.txt` until a separate authority decision
changes them:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## Recent Career PR Timeline

This timeline records the recent Career PRs that changed or documented the
1046 rollout, frontend discoverability, 10k directory architecture, and ops
readiness. It includes fap-api authority PRs and fap-web consumer PRs because
the public Career system spans both repositories.

### Runtime Diagnosis and 1048 Planning

| Date | Repo | PR | Merge commit | Purpose |
| --- | --- | --- | --- | --- |
| 2026-05-27 | fap-api | [#1722](https://github.com/fermatmind/fap-api/pull/1722) | `cba8df6b2af0` | Repair Career runtime cohort and detail APIs from the earlier timeout/404 state. |
| 2026-05-27 | fap-api | [#1724](https://github.com/fermatmind/fap-api/pull/1724) | `6e576fcf892a` | Audit detail-ready 1048 candidates. |
| 2026-05-27 | fap-api | [#1725](https://github.com/fermatmind/fap-api/pull/1725) | `b55c3668fc44` | Define detail-ready 1048 authority. |
| 2026-05-27 | fap-api | [#1726](https://github.com/fermatmind/fap-api/pull/1726) | `cbbbbb850434` | Plan 1018 candidate-prep path. |
| 2026-05-27 | fap-api | [#1728](https://github.com/fermatmind/fap-api/pull/1728) | `147fab4ebc11` | Refresh detail-ready 1048 artifacts. |
| 2026-05-27 | fap-api | [#1729](https://github.com/fermatmind/fap-api/pull/1729) | `69216f556cec` | Gate detail-ready 1048 rollout. |
| 2026-05-27 | fap-api | [#1730](https://github.com/fermatmind/fap-api/pull/1730) | `b8fc4683ef94` | Accept detail-ready 1048 closeout. |

### 1048 Replacement Investigation and Clean 1046 Decision

| Date | Repo | PR | Merge commit | Purpose |
| --- | --- | --- | --- | --- |
| 2026-05-28 | fap-api | [#1737](https://github.com/fermatmind/fap-api/pull/1737) | `a543dfc60577` | Diagnose Career runtime cohort/detail state. |
| 2026-05-28 | fap-api | [#1738](https://github.com/fermatmind/fap-api/pull/1738) | `e59b2e1eec48` | Align Career publish authority with runtime detail gate. |
| 2026-05-28 | fap-api | [#1739](https://github.com/fermatmind/fap-api/pull/1739) | `683299199947` | Detail-ready 1048 rollout dry-run and blocker report. |
| 2026-05-28 | fap-api | [#1740](https://github.com/fermatmind/fap-api/pull/1740) | `0777aaf35938` | Prepare replacement authority import package. |
| 2026-05-28 | fap-api | [#1741](https://github.com/fermatmind/fap-api/pull/1741) | `e7074d1009bc` | Add controlled replacement authority import path. |
| 2026-05-28 | fap-api | [#1742](https://github.com/fermatmind/fap-api/pull/1742) | `7bb5335a4601` | Reselect replacement candidate and confirm no clean replacement. |
| 2026-05-28 | fap-api | [#1743](https://github.com/fermatmind/fap-api/pull/1743) | `54b47ccc27aa` | Create replacement authority source-repair package. |
| 2026-05-28 | fap-api | [#1744](https://github.com/fermatmind/fap-api/pull/1744) | `16c01f81b60b` | Add controlled import path for replacement source. |
| 2026-05-28 | fap-api | [#1745](https://github.com/fermatmind/fap-api/pull/1745) | `05f7dda0b135` | Record `digital-forensics-analysts` index-state/runtime-projection conflict. |
| 2026-05-28 | fap-api | [#1746](https://github.com/fermatmind/fap-api/pull/1746) | `e5cbc6816d70` | Generate clean 1047 delta manifest, then discover only 1016 clean delta remains. |
| 2026-05-28 | fap-api | [#1747](https://github.com/fermatmind/fap-api/pull/1747) | `6716bf50c3cd` | Generate clean 1046 delta authority manifest. |
| 2026-05-28 | fap-api | [#1748](https://github.com/fermatmind/fap-api/pull/1748) | `1e04fd01e737` | Production apply preflight for clean 1046 rollout. |
| 2026-05-28 | fap-api | [#1749](https://github.com/fermatmind/fap-api/pull/1749) | `f0dc07d19c4d` | Add no-write rollout dry-run mode. |

### Runtime Stabilization, Discoverability, and Frontend Metadata

| Date | Repo | PR | Merge commit | Purpose |
| --- | --- | --- | --- | --- |
| 2026-05-29 | fap-web | [#924](https://github.com/fermatmind/fap-web/pull/924) | `dcc211d6f9f4` | Revalidate frontend Career detail robots metadata from backend authority. |
| 2026-05-29 | fap-web | [#925](https://github.com/fermatmind/fap-web/pull/925) | `f993fde8ed20` | Expose 1046 Career details in sitemap and LLM surfaces. |
| 2026-05-29 | fap-web | [#926](https://github.com/fermatmind/fap-web/pull/926) | `9f3f4d2c2836` | Repair Career detail metadata drift and `llms-full` stability. |
| 2026-05-29 | fap-api | [#1753](https://github.com/fermatmind/fap-api/pull/1753) | `f4514d82c423` | Stabilize Career jobs runtime and 1046 cohort authority. |
| 2026-05-29 | fap-api | [#1754](https://github.com/fermatmind/fap-api/pull/1754) | `e30f96f4e068` | Reconcile public 1046 runtime with CMS/Ops 378 scope. |
| 2026-05-29 | fap-api | [#1755](https://github.com/fermatmind/fap-api/pull/1755) | `76bacd303599` | Restore sitemap source authority endpoint. |
| 2026-05-30 | fap-web | [#929](https://github.com/fermatmind/fap-web/pull/929) | `db1954690935` | Repair `llms-full` artifact consistency. |
| 2026-05-30 | fap-api | [#1759](https://github.com/fermatmind/fap-api/pull/1759) | `db64c1a9704b` | Cache sitemap source authority payload. |
| 2026-05-31 | fap-web | [#930](https://github.com/fermatmind/fap-web/pull/930) | `b2473638f8ac` | Add frontend Career surface technical notes entry point. |
| 2026-06-01 | fap-web | [#951](https://github.com/fermatmind/fap-web/pull/951) | `4329ba4b65ac` | Fix CodeQL issue in LLM Career URL parsing. |

### Hiring, Ops Read Model, Discovery UX, and 10k Directory Architecture

| Date | Repo | PR | Merge commit | Purpose |
| --- | --- | --- | --- | --- |
| 2026-05-30 | fap-api | [#1760](https://github.com/fermatmind/fap-api/pull/1760) | `a2b24d6c0bb9` | Align EN careers content authority. |
| 2026-05-30 | fap-api | [#1762](https://github.com/fermatmind/fap-api/pull/1762) | `afb66d2a670f` | Add open roles to careers pages. |
| 2026-05-31 | fap-api | [#1773](https://github.com/fermatmind/fap-api/pull/1773) | `1b9efcb86d28` | Keep deploy unblocked by Career warm cache. |
| 2026-05-31 | fap-api | [#1776](https://github.com/fermatmind/fap-api/pull/1776) | `85ab91f1eac8` | Reduce Career warm cache authority rebuilds. |
| 2026-05-31 | fap-api | [#1781](https://github.com/fermatmind/fap-api/pull/1781) | `4d850c0a444f` | Bound Career warm cache step in deploy hardening. |
| 2026-05-31 | fap-api | [#1784](https://github.com/fermatmind/fap-api/pull/1784) | `530f412f8996` | Repair Career runtime read model. |
| 2026-05-31 | fap-api | [#1794](https://github.com/fermatmind/fap-api/pull/1794) | `1f6694b12db6` | Add Career runtime observability SLO. |
| 2026-05-31 | fap-api | [#1798](https://github.com/fermatmind/fap-api/pull/1798) | `38d0c8a7646d` | Add Career internal linking authority. |
| 2026-05-31 | fap-web | [#939](https://github.com/fermatmind/fap-web/pull/939) | `7626b901d77a` | Add 1046 Career frontend discovery UX. |
| 2026-05-31 | fap-api | [#1814](https://github.com/fermatmind/fap-api/pull/1814) | `40b60ff32d52` | Add L3 dynamic slot architecture guardrails. |
| 2026-06-01 | fap-api | [#1830](https://github.com/fermatmind/fap-api/pull/1830) | `68f2b2dfc0b3` | Add Career directory authority artifact/API. |
| 2026-06-01 | fap-api | [#1832](https://github.com/fermatmind/fap-api/pull/1832) | `dd68b36ef2e6` | Align sitemap Career exposure to directory authority. |
| 2026-06-01 | fap-web | [#967](https://github.com/fermatmind/fap-web/pull/967) | `a218ace5b76f` | Convert Career jobs index to paginated directory shell. |
| 2026-06-01 | fap-web | [#970](https://github.com/fermatmind/fap-web/pull/970) | `2ea27cae88af` | Align Career LLM surfaces to directory/sitemap authority. |
| 2026-06-01 | fap-api | [#1835](https://github.com/fermatmind/fap-api/pull/1835) | `9257a57f6dd9` | Add 10k directory warm/validate ops gate. |
| 2026-06-01 | fap-web | [#982](https://github.com/fermatmind/fap-web/pull/982) | `3907e7aaa09a` | Add Career detail P95/P99 latency scan artifact. |
| 2026-06-01 | fap-web | [#983](https://github.com/fermatmind/fap-web/pull/983) | `14b068e68dc9` | Follow-up reconciliation for Career detail P95/P99 latency scan. |
| 2026-06-01 | fap-web | [#984](https://github.com/fermatmind/fap-web/pull/984) | `bf6bc6d42664` | Repair Career detail cache/render budget. |
| 2026-06-01 | fap-web | [#985](https://github.com/fermatmind/fap-web/pull/985) | `be9d63dde181` | Follow-up reconciliation for Career detail cache/render budget. |
| 2026-06-01 | fap-api | [#1845](https://github.com/fermatmind/fap-api/pull/1845) | `1716749dc17d` | Audit legacy full jobs index consumers. |
| 2026-06-01 | fap-api | [#1846](https://github.com/fermatmind/fap-api/pull/1846) | `aa5b54b395ff` | Add directory/detail/sitemap/LLM drift gate. |
| 2026-06-01 | fap-web | [#986](https://github.com/fermatmind/fap-web/pull/986) | `a237fa67e1cf` | Add `llms-full` 10k budget gate. |
| 2026-06-01 | fap-web | [#987](https://github.com/fermatmind/fap-web/pull/987) | `7f1c0de41f2f` | Improve Career directory UX facet parity. |
| 2026-06-01 | fap-api | [#1847](https://github.com/fermatmind/fap-api/pull/1847) | `5ef46feea558` | Add read-only Search Channel readiness gate with HOLD decision. |
| 2026-06-01 | fap-api | [#1848](https://github.com/fermatmind/fap-api/pull/1848) | `0cd32b66091d` | Add Career 10k rollout architecture spec and close this train. |

### Career 10k Scale PR Train Closeout

This train converts the post-1046 Career surface from a successfully launched
large cohort into a 10k-ready architecture. The train did not publish new
occupations, mutate CMS/DB state, deploy production, enqueue Search Channel, or
submit URLs. It established performance scans, cache budgets, authority drift
gates, LLM artifact budgets, UX parity, Search Channel readiness, and final
10k rollout architecture.

| Train item | Repo | PRs | Merge commit(s) | Result |
| --- | --- | --- | --- | --- |
| `CAREER-DETAIL-P95-LATENCY-SCAN-01` | fap-web | [#982](https://github.com/fermatmind/fap-web/pull/982), [#983](https://github.com/fermatmind/fap-web/pull/983) | `3907e7aaa09a`, `14b068e68dc9` | Added read-only latency scan artifacts for sampled Career detail pages and reconciled the scoped train ledger. |
| `CAREER-DETAIL-CACHE-BUDGET-REPAIR-01` | fap-web | [#984](https://github.com/fermatmind/fap-web/pull/984), [#985](https://github.com/fermatmind/fap-web/pull/985) | `bf6bc6d42664`, `be9d63dde181` | Repaired detail fetch/cache/render budget without changing backend authority or exposing held slugs. |
| `CAREER-LEGACY-FULL-JOBS-INDEX-CONSUMER-AUDIT-01` | fap-api | [#1845](https://github.com/fermatmind/fap-api/pull/1845) | `1716749dc17d` | Identified remaining legacy `/api/v0.5/career/jobs` full-index consumers and migration risks. |
| `CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01` | fap-api | [#1846](https://github.com/fermatmind/fap-api/pull/1846) | `aa5b54b395ff` | Added directory/detail/sitemap/LLM count and held-slug drift gate artifacts. |
| `CAREER-LLMS-FULL-10K-BUDGET-GATE-01` | fap-web | [#986](https://github.com/fermatmind/fap-web/pull/986) | `a237fa67e1cf` | Added synthetic 10k/20k URL budget tests proving `llms-full` stays artifact-first and avoids detail fanout. |
| `CAREER-DIRECTORY-UX-FACETS-PARITY-01` | fap-web | [#987](https://github.com/fermatmind/fap-web/pull/987) | `7f1c0de41f2f` | Improved EN/ZH directory facet parity, pagination copy, mobile states, empty/error states, and query canonical/noindex behavior. |
| `CAREER-SEARCH-CHANNEL-READINESS-GATE-01` | fap-api | [#1847](https://github.com/fermatmind/fap-api/pull/1847) | `5ef46feea558` | Produced Search Channel readiness gate with explicit HOLD decision and future staged canary plan; no queue or submission action. |
| `CAREER-10K-ROLLOUT-ARCHITECTURE-SPEC-01` | fap-api | [#1848](https://github.com/fermatmind/fap-api/pull/1848) | `0cd32b66091d` | Established the backend-owned 10k rollout architecture spec, rollback posture, SLO surface, and future PR boundaries. |

Train sidecar:

- fap-api full Pint still reports existing EQ style issues in
  `backend/tests/Unit/Eq/EqSjtValidationTelemetryContractTest.php` and
  `backend/tests/Unit/Report/EqIntegratedReportComposerTest.php`. Scoped
  Career PR checks passed; the EQ files are outside this train's changed scope.

### Related fap-web Technical Notes

The fap-web counterpart entry point is:

- `fap-web/docs/career/README.md`

That document covers frontend rendering, metadata, `llms-full` cache behavior,
and contract/smoke expectations. This backend handbook remains the authority
for backend publication, cohort, directory, and ops rules.

## Ownership Boundary

Backend authority lives in fap-api. fap-web must not create, repair, or fake
Career content authority.

Backend-owned authority surfaces:

- runtime publish projection;
- release ledger / rollout manifest;
- public dataset hub and job index caches;
- Career directory authority service;
- Career job detail bundle and SEO contract;
- sitemap/LLM source eligibility;
- Career import, candidate-prep, rollout, warm, and validation commands.

Frontend-owned surfaces:

- rendering;
- metadata/head output from backend contracts;
- page shell, pagination, filters, and client interaction;
- `llms.txt` / `llms-full.txt` route serving from backend/sitemap authority;
- visual and contract tests.

Do not use frontend fallback, local JSON, static marketing copy, or sitemap
presence as Career content truth.

## Cohort History and Meanings

Older scans used several overlapping numbers. They mean different things:

| Count | Meaning |
| --- | --- |
| `30` | Earlier runtime-published public cohort. It was the active production cohort before the 1046 rollout. |
| `342` | Legacy B71X/DOCX `career_all_342` baseline. It is a governed baseline, not automatic public runtime exposure. |
| `2289` | Earlier excluded candidate/raw authority count reported by dataset authority before the 1046 rollout. |
| `1016` | Clean delta promoted into runtime publication during the 1046 rollout after excluding unsafe replacement/hold slugs. |
| `1046` | Current public detail-indexable Career slug total. |
| `2092` | Current bilingual public Career detail URL total. |
| `10000` | Future scale target; it must use directory pagination and artifact-first discoverability, not full-list SSR. |

The current public truth is `1046` slugs and `2092` localized detail URLs.
Legacy counts remain useful for provenance, not for public runtime enumeration.

Earlier audit families such as `80`, `51 delta`, `2786`, progressive rollout,
CN proxy, runtime candidate prep, and eligibility/context artifacts are now
historical implementation evidence. Their detailed Markdown notes are
consolidated here at policy level; generated JSON artifacts are retained for
machine-readable evidence where present.

## Backend Runtime Authority Model

Career public runtime depends on several layers:

1. Occupation/domain authority records.
2. Crosswalk and source lineage.
3. Display/content asset readiness.
4. Runtime publish projection.
5. Release gate pass.
6. SEO/indexability policy.
7. Public cache / public API response.
8. Sitemap and LLM discoverability consumers.

For directory-draft occupations, public list authority is evaluated in this
order:

1. Display-asset-backed detail authority.
2. Runtime-published detail shell authority.
3. Public directory-stub fallback only when explicitly allowed.

Runtime-published detail authority requires:

- explicit runtime projection item;
- runtime state equivalent to `published`;
- `detail_route_enabled=true`;
- robots/indexability enabled;
- release gate pass;
- no manual hold or conflict slug;
- no draft, fallback-only, private, or noindex state.

Default booleans or fixture-only behavior are not enough to promote a slug.

## Implementation File Map

Use this map to find the active implementation after the recent consolidation.

### Public API and Controllers

- `backend/routes/api.php`
- `backend/app/Http/Controllers/API/V0_5/Career/CareerDirectoryController.php`
- `backend/app/Http/Controllers/API/V0_5/Career/CareerJobListController.php`
- `backend/app/Http/Controllers/API/V0_5/Career/CareerJobDetailController.php`
- `backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php`

### Authority Services and Builders

- `backend/app/Services/Career/CareerDirectoryAuthorityService.php`
- `backend/app/Services/Career/PublicCareerAuthorityResponseCache.php`
- `backend/app/Services/Career/Bundles/CareerJobListBundleBuilder.php`
- `backend/app/Services/Career/Bundles/CareerJobDetailBundleBuilder.php`
- `backend/app/Domain/Career/Publish/CareerRuntimePublishProjectionLookup.php`
- `backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php`

### Rollout, Import, and Ops Commands

- `backend/app/Console/Commands/CareerAuditDetailReady1048Candidates.php`
- `backend/app/Console/Commands/CareerPrepareCanonicalRuntimeCandidates.php`
- `backend/app/Console/Commands/CareerPlanCanonicalRuntimeArtifactRefresh.php`
- `backend/app/Console/Commands/CareerExecuteCanonicalRolloutBatch.php`
- `backend/app/Console/Commands/CareerWarmPublicAuthorityCache.php`
- `backend/app/Console/Commands/CareerValidateDirectory10kScaleReadiness.php`
- `backend/app/Console/Commands/CareerImportOccupationDirectoryDryRun.php`
- `backend/app/Console/Commands/CareerImportOccupationDirectoryDrafts.php`

### Focused Tests

- `backend/tests/Feature/Career/CareerDirectoryAuthorityApiTest.php`
- `backend/tests/Feature/Console/CareerDirectory10kOpsWarmValidateCommandTest.php`
- `backend/tests/Feature/Console/CareerWarmPublicAuthorityCacheCommandTest.php`
- `backend/tests/Feature/V0_5/CareerJobPublicApiTest.php`
- `backend/tests/Feature/Career/CareerJobDetailApiTest.php`
- `backend/tests/Unit/Services/Career/CareerRuntimePublishProjectionLookupTest.php`
- `backend/tests/Unit/Services/Career/CareerJobListBundleBuilderTest.php`

### Retained Machine-Readable Artifacts

- `backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json`
- `backend/docs/seo/generated/career-directory-authority-artifact-api-01.v1.json`
- `backend/docs/seo/generated/career-sitemap-exposure-directory-authority-01.v1.json`
- `backend/docs/seo/generated/career-directory-10k-ops-warm-validate-01.v1.json`
- `backend/docs/career/generated/*.json`

## Public API Contracts

### Directory Endpoint

Endpoint:

```text
GET /api/v0.5/career/directory
  ?locale=en
  &page=1
  &per_page=50
  &family=healthcare
  &q=analyst
```

Purpose:

- Serve lightweight Career directory shells.
- Support 10k-scale pagination, filters, and search.
- Avoid returning full detail bundles on index pages.

Directory items should contain only card/list fields:

- `slug`
- localized title
- EN/ZH titles when needed for parity
- family slug/title
- canonical path
- indexability state
- robots policy
- `indexable`
- `detail_ready`
- `updated_at`

Do not add:

- full detail sections;
- recommendation snapshots;
- long Markdown;
- FAQ bodies;
- structured-data blobs;
- private scoring/provenance internals.

### Existing Job Index

Endpoint:

```text
GET /api/v0.5/career/jobs?locale=en
GET /api/v0.5/career/jobs?locale=zh-CN
```

The legacy job index contract is preserved, but future high-scale frontend
directory pages should prefer `/career/directory`.

### Detail Endpoint

Endpoint:

```text
GET /api/v0.5/career/jobs/{slug}?locale=en
GET /api/v0.5/career/jobs/{slug}?locale=zh-CN
```

Behavior:

- Return `200` only for backend-authority public detail pages.
- Return fast `404` or noindex-safe behavior for excluded, manual-hold,
  conflict, draft, private, or non-public slugs.
- Do not rebuild expensive runtime projection synchronously during public
  request handling.

### SEO Authority Endpoint

SEO authority must align with the same runtime detail gate used by the public
detail endpoint. A slug that cannot build a runtime detail bundle must not
return an indexable SEO contract.

## Directory and 10k Scale Rules

The `/career/jobs` frontend page must not become a full Career database
rendering page. The correct 10k pattern is:

- backend exposes lightweight directory authority;
- frontend SSR renders only the first page plus facets and count;
- search/filter pages use paginated/cursor API calls;
- query/filter pages remain canonicalized to the main directory where needed;
- all public detail SEO coverage comes from detail pages plus sitemap;
- full discoverability artifacts read authority artifacts, not full runtime
fanout.

The 10k ops readiness gate is:

```bash
php artisan career:validate-directory-10k-scale-readiness \
  --expected-public-count=1046 \
  --expected-sitemap-career-urls=2092 \
  --synthetic-count=10000 \
  --json
```

This gate is read-only and validates:

- EN/ZH directory count parity;
- current public count;
- sitemap career URL count;
- first-page item cap;
- first-page payload byte budget;
- absence of full detail-only fields in directory payloads;
- excluded slugs absent;
- synthetic 10k target budget.

Cache warm command:

```bash
php artisan career:warm-public-authority-cache --json
```

It should warm public Career authority caches for both EN and ZH job indexes.

## Sitemap and LLM Discoverability

Sitemap and LLM surfaces must consume backend public authority. They are not
authority.

Required rules:

- sitemap Career detail exposure enumerates only public/indexable directory
  authority;
- EN/ZH URL count should equal `public_detail_count * 2`;
- excluded slugs must be absent;
- draft, noindex, private, fallback-only, display-only, or projection-blocked
  slugs must be absent;
- no Search Channel queueing or URL submission happens as part of sitemap/LLM
  generation.

Current expected Career discoverability counts:

- sitemap Career detail URLs: `2092`;
- `llms.txt` Career detail URLs: `2092`;
- `llms-full.txt` Career detail URLs: `2092` when complete artifact is warm.

`llms-full.txt` must be artifact/cache-first. Request-time full fanout across
thousands of Career details is not acceptable at 10k scale.

### 10k Target Architecture Contract

The 10k Career architecture is a scale posture, not a permission to publish
10,000 new occupations. Runtime expansion still requires separate authority
manifests, dry-runs, controlled apply approval, and post-deploy smoke.

Target data flow:

```text
Occupation authority / content assets
  -> runtime projection and release gates
  -> Career directory authority service
  -> public directory API, detail API, sitemap source, LLM source
  -> fap-web paginated directory shell and detail rendering
```

Hard invariants:

- one backend directory authority source feeds public directory, detail
  eligibility, sitemap Career URLs, and LLM Career URLs;
- `/api/v0.5/career/jobs` may remain as legacy compatibility, but new
  directory surfaces must consume `/api/v0.5/career/directory`;
- `/career/jobs` renders a bounded first page and facets, never the complete
  occupation database;
- query and filter URLs remain noindex/canonicalized unless a future SEO
  authority decision explicitly promotes a facet page;
- sitemap owns full URL discovery for public detail pages;
- `llms.txt` may enumerate public URL/title/type records, but must not fetch
  every detail bundle at request time;
- `llms-full.txt` must be artifact-first, cache-first, and bounded on request;
- held, conflict, draft, private, noindex, fallback-only, and not-runtime-ready
  slugs stay absent from public APIs and discoverability surfaces.

Scale budgets:

| Surface | 1046 state | 10k posture |
| --- | --- | --- |
| Directory API first page | 50 items | 50 to 100 bounded items |
| Directory API payload | card/list fields only | no detail bundle, FAQ, snapshots, or markdown |
| Frontend SSR | first page + facets | first page + facets only |
| Sitemap | 2092 Career detail URLs | full public detail URL coverage from authority artifact |
| `llms.txt` | 2092 Career detail URLs | URL/title/type records from sitemap/directory authority |
| `llms-full.txt` | 2092 when complete artifact warm | precomputed artifact or last-known-good, degraded 200 if needed |
| Search Channel | HOLD | staged only after explicit approval and readiness gate |

Rollback posture:

- every rollout apply needs a batch id, rollback group, and exact slug manifest;
- rollback reverses only the approved runtime promotion batch;
- rollback must not alter held slugs, conflict slugs, content imports, or Search
  Channel state;
- after rollback, rerun directory count, sitemap/LLM count, sample detail,
  claim-boundary, and staging containment checks.

## 1046 Rollout Policy

The 1046 rollout used a clean manifest:

- current public detail count before rollout: `30`;
- clean delta: `1016`;
- target public total: `1046`;
- excluded manual hold: `software-developers`;
- excluded conflict slug: `digital-forensics-analysts`;
- excluded already-indexable replacement candidate:
  `computer-occupations-all-other`.

Rollout apply rules:

- use only official career rollout commands;
- dry-run before apply;
- no ad hoc SQL;
- no tinker writes;
- no production migration for rollout membership;
- no sitemap/llms/footer exposure before runtime promotion and validation;
- no Search Channel or URL submission in rollout tasks;
- preserve rollback group and batch id.

Candidate-prep may create only official candidate records when explicitly
approved. Runtime promotion may only promote approved clean-delta slugs from
candidate/published-candidate state to public/published state.

## 1048 Planning History and Why It Was Not Used

The earlier `detail_ready_1048` target meant `1048` product-visible Career
detail pages, not 2786 partition accounting. Its planned acceptance shape was:

- dataset member count: `1048`;
- Career jobs API item count: `1048`;
- detail-ready count: `1048`;
- public detail indexable count: `1048`;
- localized URL rows: `2096`;
- release gate pass rows: `2096`;
- no 404/noindex/redirect-source URLs in sitemap or LLM surfaces.

That path was blocked because the ready-not-public delta included
`software-developers`, which remains manual hold. Several replacement attempts
were rejected:

- `computer-occupations-all-other` was not a clean replacement because it was
  already indexable in authority state.
- `digital-forensics-analysts` had source/display authority and index-state
  rows, but no runtime publish projection item. That is an
  `index_state_runtime_projection_conflict`, not a clean replacement.
- CN proxy/directory rows and legacy `career_jobs` rows are not acceptable
  replacements for product-visible canonical Career detail authority.

The conservative final decision was to prefer the clean `1046` target over an
unsafe `1047` or `1048` target. Do not revive 1048 without a fresh authority
scan, explicit replacement decision, and separate approval.

## Import and Authoring Rules

### Occupation Directory Import

The occupation directory import package starts as dry-run only. Expected files:

- `career_create_import.jsonl`
- `career_alias_review.csv`
- `career_child_role_review.csv`
- `import_manifest.json`

Safety fields must include:

- `import_action=create`
- `dry_run_only=true`
- `governance.publish_state=draft`
- `governance.requires_backend_truth_compute=true`
- `governance.requires_editorial_review=true`

Dry-run validation:

```bash
php artisan career:import-occupation-directory-dry-run \
  --input=/absolute/path/to/career_create_import.jsonl \
  --alias-review=/absolute/path/to/career_alias_review.csv \
  --child-role-review=/absolute/path/to/career_child_role_review.csv \
  --manifest=/absolute/path/to/import_manifest.json \
  --json
```

Expected:

- `writes_database=false`
- `dry_run_only=true`
- `gate_failure_count=0`
- `authority_duplicate_count=0`
- `proposed_slug_duplicate_count=0`

Draft staging must remain backend-authority draft state and must not appear in
public APIs until compile and publish-readiness gates approve it.

### Gold Diff Rules

Career batch draft manifests must:

- include required schema keys;
- reject unexpected keys;
- reject forbidden engine-owned keys;
- reject duplicate draft, occupation, and slug identifiers;
- reject overlap with frozen first-wave subjects unless explicitly allowed;
- remain machine-readable JSON.

Editorial-only fields such as aliases, editorial patches, moat tags, prototype
signatures, and notes are draft guidance, not runtime truth.

## First-Wave Release and Override SOP

First-wave release authority stays backend-owned:

- Laravel validator output is release authority.
- Frozen first-wave manifest defines subject scope.
- Blocked registry defines blocked governance state.
- Authority overrides define narrow source-code override surface.
- Readiness API is a read-model confirmation surface.

Release preflight:

```bash
python3 -m json.tool docs/career/first_wave_blocked_registry.json >/dev/null
python3 -m json.tool docs/career/first_wave_authority_overrides.json >/dev/null
```

Validator:

```bash
php artisan career:validate-first-wave-publish-ready \
  --source=/absolute/path/to/authority_source.csv \
  --materialize-missing \
  --compile-missing \
  --repair-safe-partials \
  --json
```

Interpretation:

- `publish_ready`: safe for first-wave release truth.
- `partial`: not publish-ready; remediate before release.
- `blocked_override_eligible`: may be escalated only through narrow override
  SOP.
- `blocked_not_safely_remediable`: must not be forced.

Override SOP:

- supported only for explicit `blocked_override_eligible` cases;
- supported field: `crosswalk_source_code`;
- do not invent source rows, aggregate proxies, synthetic occupations, or broad
  manual truth patches;
- require explicit human review and evidence.

## SEO Ops Read Model and Observability

Ops dashboards and SEO Intel read models must distinguish:

- runtime public Career detail truth (`1046`);
- localized public URL truth (`2092`);
- legacy CMS/Ops `career_jobs` table scope;
- historical baseline/import package scope;
- draft/import-only or human-review assets.

Do not collapse these into one count. A 378 or 342 count in an Ops view does
not override runtime public authority.

Observability should track:

- directory API count parity;
- public job index count;
- sitemap Career URL count;
- `llms.txt` Career URL count;
- `llms-full.txt` complete/degraded state;
- excluded slug absence;
- sampled detail status/canonical/robots;
- Search Channel gate state;
- cache warm duration and payload budget.

## Internal Linking

Internal linking authority should:

- link only to backend-authority public/indexable Career URLs;
- avoid linking excluded/manual-hold/conflict slugs;
- use backend canonical paths;
- keep companion/article/topic/test links as references, not authority;
- preserve bounded career claim language.

## L3 Dynamic Slot Architecture

The future L3 layer is a dynamic slot engine, not a static page explosion.

Do not generate static pages for:

```text
1046 careers x 16 MBTI types
1046 careers x Big Five vectors
1046 careers x RIASEC vectors
```

Instead:

- keep Career detail pages as stable SEO bases;
- load personalized or result-aware explanation slots only when the user has a
  local/session/result vector;
- keep slots backend-authoritative and bounded;
- do not make L3 copy indexable pSEO content unless a separate authority and
  SEO decision approves it.

## Claim Boundary

Allowed Career framing:

- occupation information;
- tasks, skills, work environment;
- exploratory guidance;
- career direction reference;
- workstyle tendency;
- interest signal;
- decision support;
- snapshot explanation.

Forbidden claims:

- best career for you;
- precise career recommendation;
- perfect job match;
- hiring fit;
- job suitability guarantee;
- salary guarantee or salary prediction promise;
- career success prediction;
- diagnosis/treatment/cure;
- MBTI determines career;
- Big Five predicts job performance;
- RIASEC precisely ranks your best career.

## Content and EN/ZH Parity State

Career guides have EN/ZH counterpart baseline parity and can be handled through
controlled import/human review.

Career jobs historically had mismatched EN and ZH baseline families. Do not
merge them by slug guessing. Career job parity requires explicit translation
group/job-code authority.

Career recommendation pages remain decision-support snapshots. They must not
be exposed as precise personalized career recommendation pages without a
separate product and claim-boundary review.

Draft/import packages must not enter sitemap, LLM surfaces, footer/nav, or
public runtime until imported, reviewed, published, and runtime-eligible.

Career guide baseline state:

- EN career guide rows: `36`;
- ZH career guide rows: `36`;
- counterpart key: `guide_code`;
- missing EN/ZH guide-code counterparts in repo baseline: `0`;
- still requires controlled backend/CMS import and runtime exposure
  verification before public publication if production authority is missing.

## Operational Smoke Checklist

Read-only smoke after deploy or rollout:

```bash
curl -sS https://api.fermatmind.com/api/v0.5/career/jobs?locale=en
curl -sS https://api.fermatmind.com/api/v0.5/career/jobs?locale=zh-CN
curl -sS https://fermatmind.com/sitemap.xml
curl -sS https://fermatmind.com/llms.txt
curl -sS https://fermatmind.com/llms-full.txt
```

Verify:

- EN job count is `1046`;
- ZH job count is `1046`;
- dataset/public detail count is `1046`;
- sitemap Career detail URL count is `2092`;
- `llms.txt` Career detail URL count is `2092`;
- `llms-full.txt` returns 200 and, when complete, includes `2092` Career detail
  URLs;
- sampled EN/ZH details return 200, exact canonical, index/follow, title, H1;
- excluded slugs are 404/noindex or absent;
- no staging, private, take, result, share, order, pay, or payment URLs appear;
- Search Channel remains closed unless separately approved.

Sample detail slugs:

- `accountants-and-auditors`
- `actors`
- `actuaries`
- `aerospace-engineers`
- `agricultural-and-food-scientists`
- `administrative-law-judges-adjudicators-and-hearing-officers`
- `acupuncturists`
- `acute-care-nurses`

Excluded slugs:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## Validation Commands for Career Changes

Minimum backend validation for Career authority/ops changes:

```bash
cd backend
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable
```

Focused tests depend on scope. Common choices:

```bash
php artisan test --filter=CareerDirectoryAuthorityApiTest --no-ansi
php artisan test --filter=CareerDirectory10kOpsWarmValidateCommandTest --no-ansi
php artisan test --filter=CareerWarmPublicAuthorityCacheCommandTest --no-ansi
php artisan test --filter=CareerJobPublicApiTest --no-ansi
php artisan test --filter=CareerJobDetailApiTest --no-ansi
```

Docs/artifact validation:

```bash
python3 -m json.tool backend/docs/seo/generated/career-directory-authority-artifact-api-01.v1.json >/dev/null
python3 -m json.tool backend/docs/seo/generated/career-sitemap-exposure-directory-authority-01.v1.json >/dev/null
python3 -m json.tool backend/docs/seo/generated/career-directory-10k-ops-warm-validate-01.v1.json >/dev/null
git diff --check
git diff --cached --check
```

Run `bash backend/scripts/ci_verify_mbti.sh` when the change touches shared
runtime, content packages, result/report contracts, or any MBTI-adjacent path.

## Consolidated and Removed Markdown Sources

The following Markdown files were consolidated into this handbook and removed
to avoid conflicting operational truth:

- `backend/docs/career/audits/directory_detail_pending_display_asset_authority.md`
- `backend/docs/career/audits/directory_list_detail_api_authority.md`
- `backend/docs/career/career-gold-diff-rules.md`
- `backend/docs/career/career-runtime-cohort-and-detail-repair-2026-05-27.md`
- `backend/docs/ops/career-first-wave-override-escalation-sop.md`
- `backend/docs/ops/career-first-wave-release-runbook.md`
- `backend/docs/ops/career-occupation-directory-import-runbook.md`
- `backend/docs/seo/career-1046-internal-linking-authority-01.md`
- `backend/docs/seo/career-1046-observability-slo-01.md`
- `backend/docs/seo/career-1046-ops-read-model-repair-01.md`
- `backend/docs/seo/career-1046-ops-scope-reconciliation-01.md`
- `backend/docs/seo/career-directory-10k-ops-warm-validate-01.md`
- `backend/docs/seo/career-directory-authority-artifact-api-01.md`
- `backend/docs/seo/career-l3-dynamic-slot-architecture-01.md`
- `backend/docs/seo/career-sitemap-exposure-directory-authority-01.md`
- `backend/docs/seo/detail-ready-1046-delta-authority-repair-01.md`
- `backend/docs/seo/detail-ready-1046-rollout-apply-preflight-01.md`
- `backend/docs/seo/detail-ready-1047-delta-authority-repair-01.md`
- `backend/docs/seo/global-career-runtime-cohort-and-detail-repair-01.md`
- `backend/docs/seo/global-career-runtime-cohort-publish-authority-alignment-01.md`
- `backend/docs/seo/global-en-zh-career-asset-batch-01.md`
- `backend/docs/seo/global-en-zh-career-content-batch-05.md`
- `backend/docs/seo/global-en-zh-career-human-review-import-05.md`
- `backend/docs/career/audits/detail_ready_1048_publication_scan.md`
- `backend/docs/career/audits/detail_ready_1048_target_authority.md`
- `backend/docs/seo/detail-ready-1048-replacement-authority-controlled-import-01.md`
- `backend/docs/seo/detail-ready-1048-replacement-authority-import-01.md`
- `backend/docs/seo/detail-ready-1048-replacement-authority-index-state-conflict-01.md`
- `backend/docs/seo/detail-ready-1048-replacement-authority-reselect-01.md`
- `backend/docs/seo/detail-ready-1048-replacement-authority-source-controlled-import-01.md`
- `backend/docs/seo/detail-ready-1048-replacement-authority-source-repair-01.md`
- `backend/docs/seo/detail-ready-1048-rollout-dry-run-01.md`
- `backend/docs/seo/en-parity-05-career-guide-detail-import-package.md`
- all previous Markdown audit notes under `backend/docs/career/audits/`
- `backend/docs/career/cn-authority-mapping-policy.md`

Generated JSON evidence for these workstreams is intentionally retained.
