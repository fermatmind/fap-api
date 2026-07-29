# Deployment Efficiency and Test Landing P0 Technical Summary

Evidence date: 2026-07-30

Implementation window: 2026-07-29

Repositories: `fermatmind/fap-web`, `fermatmind/fap-api`

Status: all 16 referenced PRs merged

Scope: technical retrospective and operating boundary; documentation only

## 1. Executive summary

This change set completed two related engineering tracks:

1. A 13-PR deployment-efficiency train that shortened or removed repeated work
   from frontend CI, frontend artifact promotion, backend staging checks, backend
   parity verification, derived-cache refreshes, CMS baseline handling, and
   shared-directory permission handling.
2. A 3-PR Test Landing P0 train that hardened the backend public scale catalog,
   made frontend test-detail reads resilient without inventing local authority,
   and added a read-only post-deploy semantic smoke gate.

The resulting deployment model is:

```text
build or verify once
        ↓
bind evidence to exact SHA and digest
        ↓
reuse the verified artifact or receipt
        ↓
activate with bounded read-only checks
        ↓
record sanitized timing and semantic evidence
```

The train does not weaken exact-SHA approvals, environment protections,
required checks, CMS/backend authority, or fail-closed behavior. It reduces
avoidable repetition while making the remaining work more observable.

No PR in this set proves that a production deployment occurred. Production
activation, server provisioning, CMS/database writes, and live timing
measurement remain separately controlled operations.

## 2. Problems addressed

Before this train, several independent costs accumulated on the critical path:

- frontend contracts ran four deterministic shards serially;
- frontend deployment rebuilt or mutated application releases on target hosts;
- production retry behavior could replay remote business work after a non-zero
  application or build exit;
- backend staging repeated a full parity gate already performed by CI;
- backend Ops asset smoke opened multiple SSH sessions and retried optional
  checks;
- unchanged Career and sitemap derived caches were rebuilt during ordinary
  deploys;
- ordinary deploys still contained CMS baseline-import hooks;
- ordinary deploys repaired shared-directory ownership and modes instead of
  verifying pre-provisioned state;
- test landing detail requests could duplicate authority reads and allow
  optional enrichment latency onto the critical path;
- existing generic health checks did not prove the semantic usability of test
  landing pages and their authoritative question/lookup APIs.

These were primarily orchestration and ownership problems, not a need to remove
validation. The solution was to move work to the correct lifecycle stage and
make evidence reusable.

## 3. Resulting architecture

### 3.1 Frontend CI and artifact promotion

```text
four deterministic contract shards
        ↓ parallel
stable aggregate `contracts` check
        ↓
one exact-SHA standalone build
        ↓
sorted inner SHA-256 manifest
        ↓
uploaded artifact digest + Sigstore attestation
        ↓
staging verifies and activates the immutable artifact
        ↓
staging success receipt binds source, artifact, manifest and run identities
        ↓
production promotes the exact staging-approved artifact digest
```

Important properties:

- the required aggregate check name remains stable;
- matrix cancellation, missing output, skipped children, and failures remain
  blocking;
- staging and production do not rebuild the frontend release;
- the deploy host activates a digest-addressed release through an atomic
  symlink boundary;
- production retry logic may reconnect only for classified SSH transport
  failure and may not replay remote business deployment;
- revision readiness is checked independently through a bounded read-only
  endpoint poll.

### 3.2 Backend CI, staging, and Deployer execution

```text
fail-closed CI tool preflight
        ↓
CI parity run produces exact-SHA receipt
        ↓
staging validates receipt identity, digest, attestation and fingerprints
        ↓
staging reuses the receipt instead of rerunning full parity
        ↓
Deployer emits per-task timing receipts
        ↓
bounded staging smoke and conditional cache work
        ↓
ordinary deploy performs no CMS baseline import or permission repair
```

Important properties:

- missing `rg`, PHP, Composer, or another required guard tool is a hard failure;
- `rg` exit status distinguishes a clean scan from an execution failure;
- task timing records success, failure, skipped state, duration, environment,
  exact SHA and workflow identity without commands, hosts, paths, or secrets;
- Ops asset smoke uses one SSH session and one remote batch;
- Career cache reuse requires an authority/schema/code/environment fingerprint
  and bounded readability checks;
- sitemap cache reuse requires a backend-authority fingerprint and a readable
  backend-generator payload;
- missing, malformed, changed, or unreadable cache evidence rebuilds through
  the existing safe publication path;
- CMS baseline operations are explicit, dry-run by default, mode/environment
  bound, and separately authorized for writes;
- shared permission state is explicit server provisioning state;
- ordinary deploy verifies a fixed 15-path permission manifest read-only and
  fails closed with a sanitized reason and target index.

### 3.3 Test landing read and deploy gate

```text
backend registry generation
        ↓
fresh / stale-safe / LKG public catalog cache
        ↓
single backend-authoritative lookup per frontend detail request
        ↓
validated finite LKG only for retryable failures
        ↓
optional CMS enrichment under a separate bounded budget
        ↓
rendered landing or minimal error shell
        ↓
post-deploy semantic smoke on pages, lookup and question authority
```

Important properties:

- `/v0.3/scales/lookup` remains the authority for canonical identity,
  localized catalog content, forms, indexability and landing projection;
- metadata and page rendering share one request-memoized lookup;
- authoritative absence remains `404`;
- malformed authority fails closed;
- LKG is finite, schema-bound, locale/slug-bound, and allowed only for
  retryable upstream failures;
- the frontend does not add CMS-backed editorial fallback copy;
- optional CMS enrichment has its own short budget and cache boundary;
- the runtime smoke is GET-only, stores no response bodies or question text,
  and retries only transport failures or bounded readiness statuses;
- staging and production call the same smoke implementation and receipt schema.

## 4. Deployment-efficiency PR index

| Order | PR | Repository | Technical change | Primary effect | Merge commit |
|---:|---|---|---|---|---|
| 1 | `#1827` | `fap-web` | Harden production deploy retry boundary | Prevent remote business deploy replay; isolate read-only revision polling | `178e6aea8cea` |
| 2 | `#3392` | `fap-api` | Fail closed when CI guard dependencies are missing | Prevent missing or failed scan tooling from producing false success | `e15d3c9ad530` |
| 3 | `#3394` | `fap-api` | Emit Deployer task timing receipts | Add exact-SHA task duration/result evidence and bounded P50/P95 history | `3ac8dda5f93f` |
| 4 | `#3396` | `fap-api` | Batch staging Ops asset smoke | Replace multi-session retry fan-out with one SSH batch | `af3e9bd8315a` |
| 5 | `#1829` | `fap-web` | Parallelize contract shards | Run four deterministic shards concurrently behind one stable aggregate check | `3c190322a980` |
| 6 | `#1832` | `fap-web` | Build and attest immutable standalone artifacts | Bind attestation to the exact downloadable artifact digest | `9130dd2c4dfd` |
| 7 | `#1833` | `fap-web` | Deploy immutable artifacts to staging | Verify once, transfer once, activate without server-side rebuild | `6f6f57927c00` |
| 8 | `#1834` | `fap-web` | Promote staging-approved artifacts to production | Reuse the exact staging-approved digest under production authorization | `b005fabbea84` |
| 9 | `#3400` | `fap-api` | Reuse exact-SHA parity receipts for staging | Remove the duplicate full parity run from staging deployment | `874626ca2c55` |
| 10 | `#3402` | `fap-api` | Skip unchanged Career authority cache rebuilds | Verify and reuse readable fingerprint-matched derived caches | `b1fbfbd4b4c0` |
| 11 | `#3403` | `fap-api` | Skip unchanged sitemap authority cache warm | Distinguish `verified_unchanged` from `rebuilt` without changing authority | `75365346b9e9` |
| 12 | `#3404` | `fap-api` | Remove baseline imports from ordinary deploys | Move CMS authority mutation to an explicit controlled operation | `b33cf17ac928` |
| 13 | `#3405` | `fap-api` | Move shared permission repair to provisioning | Replace per-deploy mutation with bounded read-only verification | `b748b8745a78` |

## 5. Test Landing P0 PR index

| Order | PR | Repository | Technical change | Primary effect | Merge commit |
|---:|---|---|---|---|---|
| 1 | `#3398` | `fap-api` | Harden public scale catalog cache | Add generation-bound fresh/stale/LKG reads, single-flight refresh and fail-closed `503` | `c96fc49bf598` |
| 2 | `#1835` | `fap-web` | Make test landing reads resilient | Converge detail rendering on one authoritative lookup with finite retryable-only LKG | `72abe83b2a72` |
| 3 | `#1836` | `fap-web` | Gate deploys on test landing smoke | Add shared staging/production semantic smoke for pages, lookup and questions | `24982bab596d` |

## 6. Safety and authority boundaries

The following boundaries remain unchanged:

- Laravel/CMS/public APIs remain authoritative for mutable public content.
- Frontend test landing recovery may reuse only a previously validated
  backend-authoritative snapshot; it may not invent editorial content.
- Exact-SHA approval and GitHub Environment controls remain mandatory for
  production.
- Staging evidence is bound to the exact source SHA and artifact/receipt
  digest; evidence from another SHA is invalid.
- Required business smoke failures remain blocking.
- Optional asset absence may be classified as a warning only where the
  contract explicitly marks the asset optional.
- Timing and smoke receipts omit credentials, private topology, response
  bodies, commands, raw exceptions, and unnecessary server paths.
- Baseline import, provisioning, deployment, CMS write, database write,
  publication and production activation remain separate operations.
- Cache optimization changes derived-cache decisions only; it does not change
  sitemap, Career, test catalog, or CMS authority.

## 7. Validation evidence

The PRs were merged only after their repository checks passed. Their recorded
local validation collectively covered:

- full frontend contract execution with all four deterministic shards;
- focused frontend workflow, artifact, retry, staging, promotion and runtime
  smoke contracts;
- frontend typecheck, build, lint, spacing, action-reference and YAML checks;
- backend Python ops suites for tool preflight, timing receipts, SSH batching,
  parity receipts, immutable-candidate compatibility and permissions;
- backend focused PHPUnit/SRE tests for caches, baseline operations, deploy
  topology and public scale reads;
- the complete backend `ci_verify_mbti.sh` chain for the high-risk backend
  changes;
- Bash syntax, ShellCheck, PHP syntax, Pint, actionlint/YAML parsing and
  `git diff --check`.

The final permission change (`#3405`) additionally recorded a passing complete
backend gate including 1,617 Big Five tests and 195,824 assertions, followed by
successful MBTI, Enneagram, migration, OpenAPI, order-security, share-flow and
dependency-security gates.

This document does not reinterpret those results as live production evidence.
It records repository and CI evidence only.

## 8. Expected engineering impact

### Critical-path reductions

- Four frontend contract shards can use four matrix workers instead of one
  serial worker.
- Frontend build/install work is performed in CI once and reused in staging and
  production.
- Backend staging can validate and reuse the exact-SHA parity receipt instead
  of rerunning the complete parity suite.
- Staging Ops asset smoke uses one SSH handshake and one batch.
- Unchanged Career and sitemap derived caches can return
  `verified_unchanged`.
- Ordinary deploy no longer imports CMS baselines.
- Ordinary deploy no longer recursively repairs or repeatedly mutates shared
  permission state.

### Reliability improvements

- Retry boundaries distinguish transport recovery from application failure.
- Artifact and receipt identities are digest-bound and exact-SHA-bound.
- Cache reuse is permitted only with readable, matching evidence.
- Deployment timing identifies which task is slow, failed, or skipped.
- Test landing failure handling distinguishes authoritative absence, invalid
  contracts, retryable upstream failure and optional enrichment timeout.
- Post-deploy smoke validates semantics rather than only HTTP reachability.

### Claims intentionally not made

- No fixed percentage deployment-time reduction is claimed.
- No production P50/P95 improvement is claimed until enough timing receipts
  exist.
- No production restoration or successful production activation is inferred
  from merge status.
- No cache hit ratio, latency percentile or user conversion improvement is
  inferred without runtime measurements.

## 9. Operational follow-ups

1. Collect at least three completed timing receipts per environment before
   interpreting P50/P95; use a larger sample for operational decisions.
2. Compare `verified_unchanged` and `rebuilt` frequencies for Career and sitemap
   tasks without treating cache reuse as authority evidence.
3. Execute shared-directory provisioning only through the separately approved
   server-provisioning process, then retain ordinary deploy as verification
   only.
4. Keep CMS baseline operations outside ordinary deploy and require their
   explicit mode/environment/write authorization.
5. Review sanitized Test Landing smoke receipts after authorized deployments;
   do not replace the smoke with browser telemetry or private API access.
6. Preserve the exact artifact/receipt digest chain when changing workflow
   versions or artifact retention.
7. Treat any future reintroduction of server-side frontend builds, parity
   reruns, CMS baseline hooks, or recursive permission repair as an architecture
   regression requiring explicit review.

## 10. Repository rule impact

This document introduces no runtime, deployment, content-authority, API,
database, CMS, SEO/GEO, sitemap, `llms`, security, or production-ingress change.
It consolidates the architecture and operating boundaries already merged by the
referenced PRs.

The summarized ownership model is:

```text
CI owns reproducible build and validation evidence.
Deployment owns verification and atomic activation.
Provisioning owns persistent server permission state.
Backend/CMS owns public authority.
Frontend owns rendering, bounded reads and interaction.
```

## 11. Evidence references

Deployment-efficiency train:

```text
fap-web  #1827 #1829 #1832 #1833 #1834
fap-api  #3392 #3394 #3396 #3400 #3402 #3403 #3404 #3405
```

Test Landing P0 train:

```text
fap-api  #3398
fap-web  #1835 #1836
```
