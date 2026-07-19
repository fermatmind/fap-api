# Career detail stability train closeout

Date: 2026-07-18

Status: all seven scoped PRs are merged. This document records the resulting
cross-repository technical contract. Merge completion is not evidence that a
specific release was deployed, that production caches were repaired, or that
the public site was revalidated against an exact deployed SHA.

## Why the pages could work earlier and fail later

Career detail delivery depended on versioned per-slug/per-locale cache
projections. A published route could still be advertised by the directory or
job index after its active, last-known-good, and legacy-migratable detail cache
values had disappeared or become invalid. That state could arise after cache
eviction, version rotation, partial warming, or authority/cache drift. The
request then reached a generic failure path instead of a bounded recovery
response.

The failure was therefore not explained by the frontend route disappearing.
It was a consistency gap between publication/exposure authority and the cache
materialization required by the detail endpoint. Frontend retry behavior,
automatic RSC prefetch fanout, and a large global icon namespace increased the
cost and poor user experience of the failure, but were not content authority.

## Merged PR ledger

| Order | Train item | Repo / PR | Merge commit | Result |
| --- | --- | --- | --- | --- |
| 1 | `CAREER-DETAIL-COLD-MISS-DEGRADE-01` | `fap-api#3163` | `6c2cabc9314473878643e6302bcc8c0d412cd35f` | A published active/LKG/legacy cold miss now returns a backend-authoritative restricted recovery shell and requests one unique warm job instead of producing a generic 500. Hidden, held, unpublished, release-blocked, and locale-mismatched careers remain 404. |
| 2 | `CAREER-DETAIL-CACHE-COVERAGE-01` | `fap-api#3164` | `e5ebe803e7768c5ab01858d8f2dabc2159a80571` | Added dynamic published-slug × locale coverage classification, read-only verification, and resumable/idempotent missing-or-broken-only repair. The current baseline is 1,046 × 2 = 2,092 targets, but target enumeration is not hardcoded. |
| 3 | `CAREER-DETAIL-ATOMIC-EXPOSURE-01` | `fap-api#3166` | `468591b9713cbecf83f906cc998b367ab15973f7` | Exposure now follows build → verify → expose. `detail_route_enabled`, `dataset_visible`, list/directory visibility, and `detail_ready` cannot advertise a detail whose cache projection has not passed the matching validation gate. |
| 4 | `CAREER-DETAIL-DEPLOY-SLO-REPAIR-01` | `fap-api#3176` | `21552d19a6fc76053de43ac87bf1ba3e6db5192c` | Deployment activation checks 100% full-set detail coverage and a configured target floor. Runtime SLO checks inspect the full dynamic cache-key cohort plus a real route smoke. Bounded scheduled repair is explicit opt-in and disabled by default. |
| 5 | `CAREER-JOBS-ERROR-RECOVERY-01` | `fap-web#1784` | `daa2c6e2e55994f0131606eaf12889ed074d893e` | Detail retry performs a real new request and the UI distinguishes 404, temporary recovery, and unavailable states without adding frontend Career content fallback. |
| 6 | `CAREER-JOBS-PREFETCH-BUDGET-01` | `fap-web#1786` | `c915cd6c095a265efe7a00fa8d5d4186d0545cc2` | High-cardinality career rows, status/family/facet links, and pagination disable automatic prefetch while preserving click navigation. Browser evidence reduced automatic RSC requests from 66 to 4 and high-cardinality automatic requests from 58 to 0. |
| 7 | `GLOBAL-FOOTER-ICON-BUNDLE-01` | `fap-web#1787` | `478d3431589c752e5d7c88baab405e3e5cc80b9e` | Replaced the global `simple-icons` namespace import with explicit imports. The measured affected bundle fell from 5,247,799 raw / 2,140,750 gzip bytes to 39,453 / 14,419 bytes without changing footer links, labels, QR, or UTM behavior. |

## Resulting runtime contract

### Public detail read path

The public controller must use this bounded order:

1. serve the verified active projection as `fresh`;
2. serve verified LKG, or promote and serve a valid one-release legacy
   projection, as `stale`;
3. for an otherwise published slug/locale, return the restricted backend-built
   recovery shell as `degraded` and request one idempotent unique warm;
4. return 404 for states that are not publicly authorized.

The degraded shell carries route identity and recovery navigation only. It is
non-indexable and cannot assert full editorial content, salary, market, AI,
scoring, evidence, or structured data. It must not query CMS/DB or invoke the
full detail bundle assembler synchronously. Queue dispatch failure is logged
but must not convert the shell into a 500.

### Publication and list exposure

New exposure is fail-closed and ordered:

```text
build immutable detail projection
  -> verify payload and matching published projection snapshot
  -> commit publication/exposure flags
  -> atomically activate detail pointers
  -> rebuild and activate job-index/directory read models
```

Directory, job index, industry discovery, pagination/counts, and sitemap
enumeration may expose only rows that pass both publication/indexability
authority and verified detail readiness. Cache loss does not rewrite CMS or
occupation authority; it changes runtime readiness and recovery behavior.

### Coverage, deployment, and repair

`career:verify-job-detail-cache-coverage` derives every eligible slug from the
runtime publish projection and classifies every requested locale as ready,
missing, or broken. Verification is read-only. Repair is bounded, resumable,
idempotent, asynchronous, and limited to currently missing or broken targets.

Deployment activation requires complete coverage and the configured minimum
target count before symlink activation. Runtime SLO monitoring checks the full
dynamic cohort rather than treating one successful detail route as coverage
evidence. Scheduled repair remains disabled unless an operator explicitly
enables it and satisfies the production-write controls.

### Frontend consumer boundary

fap-web owns rendering and interaction only:

- retry must initiate a new authority request;
- 404, recovering, and unavailable states remain distinguishable;
- no local Career editorial fallback may be introduced;
- high-cardinality links must not create automatic RSC fanout;
- shared-shell dependencies must use explicit imports where a namespace import
  would place a large package in every Career page bundle.

## Operations references

Read-only coverage verification:

```bash
php artisan career:verify-job-detail-cache-coverage \
  --verify-only \
  --locales=en,zh-CN \
  --json
```

Controlled repair capability and deployment/SLO semantics are maintained in
`backend/docs/career/job-detail-cache-coverage.md`. Atomic publication and
pointer activation semantics are maintained in
`backend/docs/career/job-detail-atomic-exposure.md`.

## Deployment and authority boundary

This closeout records merged code only. The train itself did not:

- deploy staging or production;
- run a production cache warm or repair;
- mutate CMS, database, publication, SEO, sitemap, LLM, canonical, noindex, or
  JSON-LD authority;
- prove that the public site is serving any listed merge SHA.

Production release still requires separate exact-SHA authorization and the
normal deploy/readback evidence. Online recovery must be evaluated against the
deployed SHA and live coverage/SLO results, not inferred from PR merge state.

Repository rule impact: documentation only. Backend authority ownership,
frontend no-fallback rules, production-write controls, and exact-SHA deployment
authorization remain unchanged.
