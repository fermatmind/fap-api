# CAREER-10K-ROLLOUT-ARCHITECTURE-SPEC-01

Date: 2026-06-01

Implementation update: 2026-07-11

## Executive Summary

FermatMind Career is production-stable at the current 1046 public detail
cohort, with 2092 EN/ZH detail URLs exposed through sitemap and LLM surfaces.
The next scale target is 10k occupations, but the architecture must preserve a
strict separation between authority, directory rendering, discoverability, and
Search Channel operations.

This specification is documentation-only. It does not publish occupations,
change runtime APIs, mutate CMS/DB state, deploy code, enqueue Search Channel,
submit URLs, or release held slugs.

## Current Authority Baseline

- Public career detail slugs: 1046.
- Public localized career detail URLs: 2092.
- Directory endpoint exists and supports bounded pagination.
- `/career/jobs` is a paginated directory shell, not a full database render.
- Sitemap, `llms.txt`, and `llms-full.txt` consume authority/artifacts instead
  of request-time fanout across all detail pages.
- Search Channel remains HOLD.

Held slugs remain excluded:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## Target Architecture

```text
Career authority source
  -> content/display readiness
  -> runtime projection and release ledger
  -> directory authority service
  -> public directory API
  -> detail API and SEO contract
  -> sitemap source and LLM source
  -> fap-web paginated directory shell and detail renderer
```

The backend remains source of truth. fap-web consumes public contracts and must
not create fallback Career content.

## Rollout Gates

Every future cohort expansion must pass:

1. Authority manifest parse and schema validation.
2. Held/conflict/manual-review slug exclusion.
3. Display/content readiness.
4. Runtime projection dry-run.
5. Candidate-prep dry-run, if candidate state is needed.
6. Runtime promotion dry-run.
7. Explicit apply approval.
8. Post-apply cache warm.
9. Post-deploy smoke for API, details, sitemap, LLM, robots, canonical, and
   claim boundaries.

No ad hoc SQL, tinker write, production migration, Search Channel action, or
URL submission is part of the rollout apply path.

## 2026-07 Career 10k Stability Implementation Train

The architecture above was implemented and hardened through eight scoped PRs
across `fap-api` and `fap-web`. All eight PRs are merged. The train changed
read and delivery architecture, failure semantics, runtime observability,
capacity gates, and rollout controls. It did **not** import or publish 10,000
occupations, mutate production CMS/DB state, trigger production deployment,
or submit URLs to Search Channel.

| Task | Repo | PR / merge commit | Implemented architecture outcome |
| --- | --- | --- | --- |
| `CAREER-DIRECTORY-READ-MODEL-PERFORMANCE-01` | fap-api | `#2952` / `342e17cebd1a` | Replaced request-time full-index processing with a versioned directory read model; bounded pagination, search, and facets to lightweight directory fields. |
| `CAREER-PUBLIC-AUTHORITY-CACHE-RESILIENCE-01` | fap-api | `#2956` / `746d4a1e9d4f` | Added single-flight rebuilds, atomic cache-version switching, last-known-good serving, EN/ZH warming, and observable cache states. |
| `CAREER-DIRECTORY-ERROR-SEMANTICS-01` | fap-web | `#1680` / `6508f2da6fb5` | Separated `success`, `empty`, `stale`, and `unavailable`; backend failures can no longer render as a false zero-career result. |
| `CAREER-RUNTIME-SLO-ALERTING-01` | fap-api | `#2960` / `540a92ef8cc1` | Added runtime directory latency/status sampling, bilingual/public-surface probes, cache-age and rebuild telemetry, and operations-webhook alert evaluation. |
| `CAREER-DETAIL-READ-MODEL-10K-01` | fap-api | `#2962` / `48e73fa88ed7` | Added versioned per-slug/per-locale detail projections, targeted invalidation, active/LKG reads, held-slug negative caching, and bounded resumable warm queues. |
| `CAREER-DETAIL-DELIVERY-10K-01` | fap-web | `#1682` / `5f3c37fb889d` | Added bounded detail-page revalidation, exact locale/slug tags, shared metadata/body authority loads, hard-expiry handling, and request-count budgets. |
| `CAREER-10K-CAPACITY-CHAOS-GATE-01` | fap-api | `#2965` / `db8eff09ae0f` | Replaced the declared synthetic-count check with real 10,000-directory/20,000-bilingual-projection generation, concurrency/fault scenarios, measured query budgets, and CI-enforced latency/memory/payload limits. |
| `CAREER-10K-CONTROLLED-ROLLOUT-01` | fap-api | `#2967` / `c7d6ce157f0a` | Added fail-closed `100 → 500 → 1,000 → 2,500 → 5,000 → 10,000` readiness gates, strict evidence validation, exact-prefix advancement, and usable rollback-version requirements. |

### Resulting Runtime and Release Contract

- Directory warm p95 budgets remain `≤300 ms` for 1,046 rows and `≤500 ms`
  for a generated 10,000-row fixture; the response contains only the current
  bounded page and never fans out into detail assets.
- A cache miss, Redis failure, rebuild failure, or upstream timeout must
  produce stale/LKG or unavailable semantics, never a fabricated empty list.
- Detail authority is projected before public reads. A public request must not
  assemble 10,000 CMS, SEO, scoring, or evidence payloads synchronously.
- Public HTML caching is bounded and precisely invalidated by locale and slug;
  metadata and page body share the same authority load.
- The required CI chain exercises real 10k directory and bilingual detail
  projections, search, facets, deep pagination, 50/100 request windows,
  Redis miss/unavailable behavior, rebuild failure, worker restart, and
  old/new version coexistence.
- Batch advancement is fail closed. API SLO, frontend success, backend
  authority count, EN/ZH parity, canonical/robots/structured data,
  sitemap/LLM completeness, cache warm completion, 404/5xx/504 budgets,
  publication/indexability approval, and rollback evidence must all pass.
- A rollout-gate pass means only
  `ready_for_separate_exact_sha_approval=true`; it never authorizes or executes
  production import, publication, cache warming, deployment, or Search Channel
  submission.

### Operational Entry Points

```bash
php artisan career:validate-directory-10k-scale-readiness \
  --expected-public-count=<current-authority-count> \
  --expected-sitemap-career-urls=<current-authority-count-times-two> \
  --synthetic-count=10000 \
  --json

php artisan career:validate-10k-controlled-rollout \
  --batch=<100|500|1000|2500|5000|10000> \
  --evidence=<immutable-evidence.json> \
  --json
```

The controlled-rollout operating procedure is maintained at
`backend/docs/career/career-10k-controlled-rollout-sop.md`.

## Directory API Budget

The directory API must remain lightweight at 10k scale:

- first page default: 50 items;
- maximum page size: 100 unless a future benchmark proves otherwise;
- fields: slug, localized title, family, canonical path, robots/indexability,
  detail readiness, updated timestamp;
- forbidden fields: full sections, long markdown, FAQ bodies, report snapshots,
  personalized recommendation text, private provenance, and structured-data
  blobs.

## Frontend Contract

fap-web `/career/jobs` must:

- SSR only the first bounded page plus count and facets;
- keep query/filter/search pages noindex with canonical back to the directory
  root unless separately promoted;
- fetch additional pages through the directory API;
- render empty/error states without frontend editorial fallback content;
- preserve held slug absence.

## Sitemap and LLM Policy

- Sitemap enumerates all public indexable detail URLs from backend authority.
- `llms.txt` can list public URL/title/type records from sitemap or directory
  authority.
- `llms-full.txt` must be precomputed/artifact-first and cache-first.
- Request-time fanout across 10k detail APIs is disallowed.
- A degraded `llms-full.txt` response should return HTTP 200 with bounded
  content and explicit degraded metadata instead of timing out.

## Search Channel Policy

Search Channel remains closed until a separate explicit approval. A future
Search Channel PR may only use the readiness gate output as an input and must
perform no submission unless the user provides exact confirmation.

Recommended future staged plan:

1. Canary: 10 EN + 10 ZH paired detail URLs.
2. Observe 24 hours.
3. Expand to a 100 paired URL batch if no stop condition fires.
4. Continue bounded batches with sidecar logging.

Stop conditions include held slug exposure, noindex/canonical drift, staging
contamination, claim-boundary regression, queue anomaly, and external search
API failure.

## Observability and SLO

Track at minimum:

- directory EN/ZH count parity;
- public detail indexable count;
- sitemap Career URL count;
- `llms.txt` Career URL count;
- `llms-full.txt` complete/degraded state and response time;
- held slug absence;
- sampled detail HTTP/canonical/robots/H1 state;
- cache warm duration and payload size;
- legacy `/api/v0.5/career/jobs` consumer usage;
- Search Channel gate state.

Recommended latency posture:

- directory API first page p95 under 800ms after warm cache;
- sampled detail page p95 under 2500ms before edge/CDN optimizations;
- `llms-full.txt` repeated reads should return 200 without gateway timeout.

## Rollback

Each rollout apply must record:

- batch id;
- slug manifest;
- rollback group;
- apply artifact;
- cache warm artifact;
- post-apply smoke artifact.

Rollback must only reverse the approved runtime promotion batch. It must not
alter held slugs, content imports, Search Channel queue state, or fap-web
fallback behavior.

## Future PR Boundaries

Keep future work small and reversible:

- authority source import and review packages;
- candidate-prep runtime support;
- rollout dry-run artifacts;
- rollout apply with explicit approval;
- directory API performance/caching;
- fap-web directory UX;
- sitemap/LLM artifact budgets;
- Search Channel readiness and staged submission.

Do not combine runtime promotion, frontend UX, LLM generation, and Search
Channel actions in one PR.

## Final Decision

`career_10k_stability_architecture_implemented_release_still_requires_exact_sha_authorization`

The system now has the read models, cache resilience, frontend failure
semantics, runtime SLOs, detail delivery budgets, real 10k capacity gate, and
controlled rollout gate required for staged expansion. This is architectural
readiness, not evidence that 10,000 occupations are already published.
Production import, promotion, or deploy remains outside this train and requires
separate exact-SHA authorization plus batch evidence that passes the backend
publication/indexability gate.
