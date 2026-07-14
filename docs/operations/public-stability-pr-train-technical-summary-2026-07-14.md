# Public Stability 19-PR Train Technical Summary

Date: 2026-07-14

Repositories: `fermatmind/fap-web`, `fermatmind/fap-api`

Scope: completed Public Stability PR train, post-merge architecture and operational handoff

Runtime change: yes, across the train

This document change: documentation only

## Executive Summary

The Public Stability train delivered 19 narrowly scoped pull requests across the public Next.js renderer and Laravel authority layer. The train addresses the failure class where a public route could remain pending, convert a transient upstream problem into a false 404, repeat the same backend read several times, or expose no operational evidence explaining why content was unavailable.

All 19 PRs are merged, their merge commits are contained in the relevant repository `origin/main`, and their required GitHub checks passed. The resulting architecture separates five concerns that were previously coupled:

1. navigation feedback;
2. route-level loading, error, and retry behavior;
3. structured public-read error semantics;
4. backend active/last-known-good delivery and aggregate read models;
5. metrics, controlled verification, fixed probes, publication readback, and a read-only CMS health surface.

The train does not make the frontend a content authority. CMS/backend publication state remains authoritative. Last-known-good data is a bounded delivery mechanism for content that was previously valid and has not been withdrawn; it is not a frontend editorial fallback and cannot override an authoritative absence or withdrawal.

Merge completion is not the same as production deployment. GitHub deployment evidence captured on 2026-07-14 shows the complete train on staging, but only a subset of the backend train and none of the frontend train can be attributed to the latest successful production release records. See [Deployment State](#deployment-state) for the exact boundary.

## Original Failure Model

The train was designed around related but distinct failure modes:

- Client navigation had no shared pending signal, so a click could appear unresponsive while React Server Components and upstream content reads were still pending.
- Page families did not consistently expose bounded loading, error, and retry states.
- Adapters sometimes collapsed timeout, throttling, network, contract, and upstream failures into `null`, allowing a transient failure to render as an authoritative 404.
- A single page render could independently request page body, metadata, SEO, FAQ, and adjacent content, increasing fan-out and making results sensitive to timing.
- Personality navigation prefetch could spend request budget on heavy dynamic routes before the user committed to navigation.
- MBTI and Big Five/Enneagram public reads did not share a versioned active/last-known-good lifecycle.
- Career industries were assembled through multiple reads instead of one backend-owned aggregate read model.
- Runtime delivery lacked a unified measurement, probe, publication-readback, and operator-review path.

The target behavior is:

```text
user navigation
-> immediate bounded pending feedback
-> route loading boundary
-> one authoritative or request-deduplicated public read
-> structured result
   -> content
   -> authoritative absence
   -> retryable transient failure
-> bounded retry/error UI
-> runtime metric and probe evidence
-> read-only CMS health review
```

## PR Inventory And Outcome

The table records GitHub merge truth as of 2026-07-14. Short SHAs are provided for operator readability; the state ledgers retain full SHAs and validation evidence.

| Order | PR ID | Repo | PR | Merge SHA | Delivered scope | Depends on |
| ---: | --- | --- | ---: | --- | --- | --- |
| 1 | `PUBLIC-STABILITY-WEB-01` | web | #1737 | `eabf9a400` | Shared public navigation pending feedback | `GLOBAL-JS-02` |
| 2 | `PUBLIC-STABILITY-WEB-02` | web | #1738 | `47373184e` | Loading, error, and retry boundaries for public page families | WEB-01 |
| 3 | `PUBLIC-STABILITY-WEB-03` | web | #1739 | `021287d0d` | Structured public-read error contract | None |
| 4 | `PUBLIC-STABILITY-API-01` | api | #3036 | `9df5b2ea6` | Public runtime metric collection, aggregation, and query API | `PUBLIC-API-CACHE-01` |
| 5 | `PUBLIC-STABILITY-WEB-04` | web | #1749 | `b135c22af` | Big Five/Enneagram error semantics and request-level read deduplication | WEB-02, WEB-03 |
| 6 | `PUBLIC-STABILITY-WEB-05` | web | #1752 | `df464c5ea` | Personality public-link prefetch budget | WEB-04 |
| 7 | `PUBLIC-STABILITY-WEB-06` | web | #1742 | `a1fa4c1a8` | Article, research, and support transient errors no longer become false 404s | WEB-02, WEB-03, `ARTICLE-CACHE-01` |
| 8 | `PUBLIC-STABILITY-WEB-07` | web | #1754 | `d85fc36ac` | Topic and career-guide detail/SEO bundle reads | WEB-02, WEB-03 |
| 9 | `PUBLIC-STABILITY-WEB-08` | web | #1756 | `b57bd389f` | Career adapter transient-error preservation | WEB-02, WEB-03, `CAREER-CACHE-01` |
| 10 | `PUBLIC-STABILITY-WEB-09` | web | #1759 | `8dca715a3` | Authoritative test catalog and lookup error semantics | WEB-02, WEB-03 |
| 11 | `PUBLIC-STABILITY-API-02` | api | #3046 | `206389ecf` | Versioned MBTI active/LKG reads | API-01, `PERSONALITY-WARMUP-01` |
| 12 | `PUBLIC-STABILITY-WEB-10` | web | #1762 | `76da2cf9b` | MBTI consumption of bounded backend resilience | API-02, WEB-02, WEB-03 |
| 13 | `PUBLIC-STABILITY-API-03` | api | #3051 | `bee5c66f7` | Big Five/Enneagram active/LKG public-asset delivery | API-01, `PERSONALITY-WARMUP-01` |
| 14 | `PUBLIC-STABILITY-API-04` | api | #3058 | `67678722d` | Personality public-asset payload alias removal and payload budget enforcement | API-03 |
| 15 | `PUBLIC-STABILITY-API-05` | api | #3063 | `3cb866741` | Career industries aggregate read model | API-01, `PUBLIC-API-CACHE-01` |
| 16 | `PUBLIC-STABILITY-WEB-11` | web | #1763 | `439a68db1` | Industries page consumes one backend aggregate API | API-05, WEB-02, WEB-03 |
| 17 | `PUBLIC-STABILITY-API-06` | api | #3071 | `41007b205` | Controlled warm, verify, and dry-run commands | API-02, API-03, API-05 |
| 18 | `PUBLIC-STABILITY-API-07` | api | #3077 | `224f10be4` | Fixed allowlist delivery probes and publication readback | API-01, API-03, API-05 |
| 19 | `PUBLIC-STABILITY-API-08` | api | #3080 | `65cc97e92` | Read-only CMS public-content health dashboard | API-01, API-07 |

## Architecture After The Train

### 1. Frontend navigation and page boundaries

WEB-01 and WEB-02 establish the outer user-experience boundary. Navigation begins with visible pending feedback, then hands off to route-level loading. A retryable route error is represented as an error state with a bounded retry action rather than an indefinite spinner or an immediate unavailable page.

This layer owns feedback and recovery presentation. It does not decide whether content exists.

### 2. Structured public-read errors

WEB-03 defines the shared distinction required by all public consumers:

```text
authoritative absence
!= transient upstream failure
!= invalid contract
!= timeout or network failure
```

Consumers may return `null` or `notFound()` only for an authoritative absence. Retryable failures remain errors so that route boundaries can present retry behavior and metrics can record the actual failure class.

This distinction is the central correctness invariant of the train. Without it, loading/error UI only hides false-404 behavior rather than fixing it.

### 3. Page-family convergence

WEB-04 and WEB-06 through WEB-09 apply the shared contract to the page families most exposed to CMS/public API timing:

- Big Five and Enneagram public profiles;
- articles, research, and support;
- topics and career guides;
- Career public adapters;
- public test catalog and scale lookup.

WEB-04 also prevents repeated Big Five/Enneagram reads within the same request. WEB-07 bundles detail and SEO reads where the backend contract can return one coherent projection. These changes reduce fan-out without introducing frontend content authority.

### 4. Prefetch budget

WEB-05 places personality links under an explicit prefetch budget. Heavy dynamic public-profile links no longer consume unbounded background requests simply because they are visible or hovered. The user-initiated navigation remains available; only speculative work is constrained.

The objective is not to disable Next.js navigation optimization globally. It is to prevent speculative personality reads from competing with the route the user actually selected.

### 5. Backend active and last-known-good delivery

API-02 provides a versioned MBTI active/LKG lifecycle. API-03 extends the same delivery principle to Big Five and Enneagram public content assets.

The expected state model is:

```text
active valid asset
-> serve active and refresh delivery state

active transiently unavailable
+ eligible non-withdrawn LKG
-> serve bounded stale content with provenance

authoritatively absent or withdrawn
-> do not serve an older LKG

no active and no eligible LKG
-> structured unavailable/transient result
```

Cache keys and generations must preserve version and withdrawal boundaries. A cache entry cannot make deleted, withdrawn, unpublished, or incompatible content public again.

API-04 removes duplicated compatibility aliases from the personality payload and enforces the runtime payload budget. Compatibility is normalized at a defined boundary instead of returning two copies of the same large asset indefinitely.

### 6. Career industries aggregate read model

API-05 makes the backend responsible for the Career industries directory projection. WEB-11 consumes the single aggregate response instead of assembling industries from multiple independently timed public reads.

The aggregate API remains derived from backend Career authority. It does not make the directory a new publication authority and does not allow a frontend-local industry dataset to override CMS/public-resolution state.

### 7. Metrics and operational verification

API-01 adds the common metric path needed by later operational PRs. The important dimensions include the public surface, outcome class, source/cache state, latency bucket, and bounded error information. Metrics must avoid private payloads, user scores, order identifiers, attempt/report links, or unbounded exception bodies.

API-06 adds controlled warm and verify commands. The commands support dry-run and operate over an explicit bounded surface. They are operational tools, not permission to publish content, clear production caches, or mutate CMS state automatically.

API-07 adds fixed-allowlist probes and publication readback. A successful HTTP response alone is insufficient: the readback must confirm that the public projection corresponds to the intended publication state and contract.

API-08 exposes the collected runtime, probe, and publication evidence in the backend Ops/CMS surface at:

```text
/ops/public-content-health
```

The panel is read-only. It supports authorized operator review and does not publish, warm, purge, edit, or repair content.

## Verification Evidence

### GitHub and repository truth

- All 11 fap-web PRs are `MERGED`.
- All 8 fap-api PRs are `MERGED`.
- Every listed merge commit is contained in the relevant `origin/main`.
- fap-web main closes the train at `439a68db1f44e8c3c438371e73b09f0d91614240`.
- fap-api main closes the train at `65cc97e92d1c0feeff04a01056f6594abf5b52f0`.

### Required checks

The final PR heads passed their repository-required GitHub checks. Across the train these included:

- fap-web build and complete contracts;
- Big Five and Enneagram contract-freeze checks;
- CodeQL and no-new-code-scanning-alert gates;
- fap-api hygiene and supply-chain checks;
- MBTI legacy and V2 verification;
- Big Five verification;
- content-package validation;
- Semgrep security gates.

Focused local tests additionally covered the changed page families, public-read contracts, cache/LKG selection, payload budget, Career industries aggregation, commands, probes/readback, and Filament/Ops permissions and rendering.

Green checks prove the committed contracts and tested behavior. They do not independently prove that a production server is running the merge SHA.

## Deployment State

Deployment evidence was rechecked through GitHub Actions and GitHub deployment records on 2026-07-14. No production deploy, restart, cache clear, warm, CMS write, or SSH write was performed during this verification.

### Staging

| Component | Latest train SHA | Evidence | Verdict |
| --- | --- | --- | --- |
| fap-web | `439a68db1f44e8c3c438371e73b09f0d91614240` | `Deploy Web Staging` completed successfully | Complete train present on staging by workflow evidence |
| fap-api | `65cc97e92d1c0feeff04a01056f6594abf5b52f0` | staging deploy completed with post-deploy health, contract, Ops entry, and Ops asset smoke | Complete train present on staging by workflow evidence |

### Production

| Component | Latest successful production deployment record | Train coverage | Verdict |
| --- | --- | --- | --- |
| fap-web | `f89d27f484859eb9c6790b803dd99b3a15781ed6` | Predates WEB-01 through WEB-11 | None of the 11 web train PRs may be claimed deployed from the current GitHub production record |
| fap-api | `811e43c0b9c1c1f0f5e5556ad31d3ae3b02126e2` | Contains API-01 and API-02; predates API-03 through API-08 | Only API-01 and API-02 are included in the latest verified production release record |

The latest fap-web production workflow for train-closing SHA `439a68db1...` completed with an overall `success`, but the actual `Deploy fap-web production Node1` job was `skipped`. The policy guard blocked automatic deployment because the accumulated change range contained production-sensitive SEO/workflow paths. Therefore the workflow-level green result must not be reported as a production deployment.

The latest fap-api `Deploy Application` workflow for `65cc97e92...` deployed staging. It is separate from the manually gated `Deploy API Production` workflow.

An independent read-only SSH verification of the currently active server revisions was not performed. Until exact server revision readback is authorized and completed, the GitHub deployment records above are the deployment truth used by this document.

## Operational Use

### When a public page appears unavailable

1. Reproduce direct load and client navigation separately.
2. Confirm whether the UI shows pending, retryable error, or authoritative unavailable.
3. Inspect the public-read error classification rather than treating every non-content result as 404.
4. Review `/ops/public-content-health` for the affected surface, locale, cache state, probe result, and publication readback.
5. Determine whether the backend served active, LKG, or no eligible asset.
6. Use controlled verify/dry-run before considering any warm operation.
7. Treat CMS publication and withdrawal state as authoritative.

### What operators must not infer

- A route returning 200 does not prove that the intended revision is published.
- An LKG hit does not prove the current active asset is healthy.
- A green PR check does not prove production deployment.
- A green policy-guard workflow does not prove the deploy job ran.
- A cache miss does not authorize frontend fallback content.
- A probe failure does not authorize automatic CMS mutation, cache purge, or publication.

## Residual Risks And Follow-Up

The train closes the declared implementation scopes, but long-term operation still requires evidence outside the individual PR contracts:

1. **Production rollout:** the complete 19-PR train has not been proven on production by current GitHub deployment records.
2. **Exact server readback:** production Node1 and backend release revisions should be confirmed through the separately authorized read-only deployment-readiness procedure.
3. **Post-production journey smoke:** after an authorized rollout, verify repeated Personality navigation, Big Five/Enneagram transitions, article/support false-404 behavior, test lookup, Career industries, and browser back/forward behavior.
4. **Metric baselines:** define normal fresh/LKG/miss/error ratios and stale-age thresholds from observed traffic before adding alert thresholds.
5. **Alert ownership:** the CMS panel provides visibility; escalation, paging, and incident ownership remain an operations-policy decision.
6. **Content-readiness separation:** runtime health should not be conflated with editorial quality, evidence completeness, or indexability readiness.
7. **Withdrawal drills:** active/LKG invalidation and publication readback should be periodically tested in a non-production or controlled environment.

These follow-ups do not authorize production deployment, CMS mutation, publishing, cache clearing, warm execution, or search/indexability changes.

## Repository Rule Impact

No repository authority rule changes are introduced by this summary.

- fap-api remains the CMS, content, publication, cache-policy, metrics, probe, and public API authority.
- fap-web remains the rendering, interaction, route-boundary, and API-consumer layer.
- CMS-backed public content must not gain a frontend editorial fallback.
- Private assessment, order, attempt, report, and payment data must not enter public metrics or logs.
- Sitemap, `llms.txt`, canonical, indexability, publishing, and production deployment remain outside this train's authorization.

## Final State

```text
implementation: complete and merged
required GitHub checks: passed
staging deployment evidence: complete for web and api train-closing SHAs
production deployment evidence:
  web: none of WEB-01..WEB-11 in latest successful production deployment record
  api: API-01 and API-02 only in latest successful production deployment record
CMS mutation performed by train execution: no
production write performed by this documentation task: no
publish or indexability change: no
```
