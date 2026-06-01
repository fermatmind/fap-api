# CAREER-10K-ROLLOUT-ARCHITECTURE-SPEC-01

Date: 2026-06-01

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

`career_10k_rollout_architecture_spec_completed_ready_for_future_scoped_prs`

Next task: none for this train. Future 10k work needs a new scoped train and
explicit manifest/state authorization.
