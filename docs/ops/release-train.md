# Release Train Orchestrator (V1)

## What this does
- Adds a dedicated `release-train` GitHub workflow for manifest-driven release execution.
- Adds a structured release manifest and CLI for:
  - manifest validation
  - dry-run
  - plan rendering
  - run execution with fail-closed behavior
  - sidecar classification
- Adds release safety modules for:
  - risk classification
  - scope validation
  - smoke checks
- Adds backend deployment wrappers (verification only in this repository).

## What this does not do
- Does not modify existing `deploy.yml` behavior.
- Does not implement production push-main auto deploy.
- Does not perform real production deploy by default.
- Does not execute rollback by default.
- Does not run Search Channel actions.
- Does not perform URL submission.
- Does not modify `fap-web` in this phase.

## V1 production model
- New workflow is `workflow_dispatch` only.
- Default and safe mode is dry-run.
- `allow_deploy` gates run behavior.
- Production execution depends on GitHub Environment approval on the actual
  deploy-capable `run-train` job (`environment: production`).
- Wrapper execution is fail-closed:
  - Missing/disabled deploy command -> `DEPLOY_COMMAND_NOT_CONFIGURED`.
  - Missing rollback command -> `ROLLBACK_COMMAND_NOT_CONFIGURED`.

## Workflow inputs
- `manifest_path`
- `mode`: `validate`, `plan`, `dry-run`, `run`
- `train_id`
- `allow_merge`
- `allow_deploy`
- `allow_rollback`
- `dry_run`

## Concurrency
- `group: production-release-train`
- `queue: max`
- No `cancel-in-progress` use.

## Environment and approvals
`validate` and `dry-run` jobs do not use a production environment and must not
request production approval. The deploy-capable `run-train` job is the job that
declares `environment: production`, so GitHub production protection rules and
environment-scoped secrets apply to the same job that can invoke
`release_train.py run`.

`run-train` is guarded by all of the following workflow inputs:

- workflow ref is `refs/heads/main`
- `mode == run`
- `allow_deploy == true`
- `dry_run != true`

This keeps dry-run validation outside production approval while making the
future real backend run path wait for the production environment reviewer before
the job can access production environment secrets.

The main-branch guard is enforced in the repository workflow, not only by
external GitHub Environment settings. A manual dispatch against any non-main ref
must not enter the deploy-capable `run-train` job even if the dispatcher supplies
deployment-oriented inputs.

The workflow passes operator inputs to shell steps through environment variables
and validates the manifest path before use. The manifest path must be a relative
JSON file that exists in the checked-out repository, must not be absolute, and
must not contain `..` path components.

## Manifest
Top-level fields:
- `schema_version`
- `train_id`
- `environment`
- `mode`
- `stop_on_failure`
- `rollback_on_failed_smoke`
- `allow_merge`
- `allow_deploy`
- `allow_rollback`
- `items`

Each item includes:
- `repo` (`fap-api` or `fap-web`)
- `pr_number`
- `expected_head_sha`
- `expected_merge_sha` (optional)
- `component`
- `risk_level`
- `deploy_required`
- `deploy_order`
- `required_checks_policy`
- `allowed_files`
- `allowed_generated_paths`
- `scope_validation`
- `smoke_checks`
- `rollback`
- `sidecar_policy`

Manifest items must not provide deployer override fields. The release train does
not accept `deployer_bin`, `deployer_file`, `DEPLOYER_BIN`, or `DEPLOYER_FILE`
from a manifest, and the run path blocks any item that contains those fields.
The deploy wrapper is responsible for resolving the approved Deployer binary and
deploy file from repository-controlled configuration.

## Smoke checks
`smoke.py` supports:
- method/url/timeout/retries
- status expectation
- must contain / must not contain
- optional forbidden marker scan for high-risk URLs
- optional soft-alert metadata for non-core discoverability artifacts:
  - `surface: llms-full`
  - `soft_alert: true`
  - `hard_block: false`
  - `core_smoke: false`
  - `owner`
  - `recommended_followup`

## Sidecar policy
- Required check failures are blocking.
- Non-required checks can be sidecar only when failure is explicitly external.
- `5xx`, `timeout`, private URL exposure, held slug exposure, clinical/depression exposure, core smoke failures, Search Channel checks, and staging containment checks are hard-blocking by default.
- `llms-full` and equivalent GEO/discoverability artifacts may be downgraded to sidecar only when all are true:
  - the smoke check explicitly sets `soft_alert: true`, `hard_block: false`, and `core_smoke: false`
  - the item `sidecar_policy.allow_discoverability_artifact_soft_alerts` is true
  - the failure is not a private/held URL exposure, Search Channel anomaly, staging containment regression, or core route/API failure
  - a follow-up owner and recommendation are recorded
- Soft-alert sidecars are not a pass for the artifact itself; they only prevent non-core artifact instability from automatically rolling back or stopping unrelated production release flow.

## Risk classifier
High-risk paths require manual approval and are blocked by default:
- `deploy.php`, `.github/workflows/deploy.yml`
- `backend/scripts/deploy/**`
- queue/scheduler deploy tooling paths
- database/auth/order/payment/Search Channel/URL submission/clinical/depression/software-developers/raw career paths
- backend config, CMS controller/service, SEO/search service, content package,
  and secret-looking paths in the production deployment workflow

Production deployment has no `push`, `pull_request`, or `workflow_run` trigger.
It is available only through manual `workflow_dispatch` after exact-SHA approval,
successful staging evidence for an immutable candidate SHA, a safe release ID,
and the exact bounded SHA/release approval phrase. The candidate may trail
`main` without expiring when it remains an ancestor of current `main` and the
staging run is for that exact candidate. Newer main commits are intentionally
excluded rather than silently added to the deployment.

After production credentials are loaded but before any remote mutation, the
workflow re-fetches `main`, reads the current production `REVISION`, and proves
both ancestry edges:

```text
current production REVISION -> staged candidate SHA -> current main SHA
```

The workflow fails closed on a diverged or unresolvable revision, a rollback,
an already-deployed candidate, or non-exact staging evidence for a candidate
that trails `main`. This allows parallel PR trains to continue without turning
production authorization into a moving target. It does not deploy or authorize
newer main commits.

One exact historical production baseline is separately audited because the
isolated Runtime 46 commit is not topologically present on `main`, while its
six resulting files are already present byte-for-byte in the staged candidate:

```text
production baseline: bc0ed833bc9aae1473ab37f1dead2517e1aff618
candidate:           49038deb50cda789e4365ea42068832ed28d6023
staging run:         29977064260
```

The workflow accepts this pair only after proving that the production commit
has exactly one parent, that parent is an ancestor of the candidate, the
production patch has exactly the locked five additions and one modification,
and all six production blobs equal the corresponding candidate blobs. Unknown
paths, missing files, changed statuses, renames, deletions, blob drift, another
production or candidate SHA, or another staging run fail closed. The release
record reports `runtime46_patch_subsumed`; all ordinary standard deployments
continue to report and require `linear_ancestor`.

## fap-web handling in V1
- `fap-web` is a reference only.
- No production write operation is implemented for `fap-web` in this phase.
- frontend deployment actions remain interface placeholders or future extension.

## Wrapper behavior
- `backend/scripts/deploy/deploy_backend.sh`
- `backend/scripts/deploy/rollback_backend.sh`
- `backend/scripts/deploy/readiness.sh`
- `backend/scripts/deploy/post_deploy_validate.sh`

If wrappers are invoked without explicit runtime intent flags they fail closed.

`readiness.sh` and `post_deploy_validate.sh` use separate evidence surfaces and
fail closed unless their required values are explicit:

- `HEALTHCHECK_HOST=<target-node HTTPS vhost hostname>`
- `PUBLIC_API_BASE_URL=<public API HTTPS origin>`
- `PUBLIC_WEB_BASE_URL=<public Web HTTPS origin>` (`post_deploy_validate.sh`)
- `BACKEND_SHA=<40-character release SHA>`
- `RELEASE_NAME=<operator-approved release name>`
- `PROBE_ID=<unique non-sensitive log correlation id>`

The internal health check must run on the target node and resolves the named
vhost to loopback for `GET /api/healthz`. It requires HTTP `200` and JSON
`ok=true`. The scripts never print the internal hostname, loopback mapping, or
raw configuration values. A non-allowlisted public request to `/api/healthz`
continues to return `404` by design and is not a wrapper failure signal.

The protected standard production workflow and Deployer enforce the same split
before and after release activation. The target node's loopback-resolved vhost
must return `200` with `ok=true`; the public health endpoint must return exactly
`404`; and public-DNS business evidence must include successful flags and
zh-CN Big Five Hub Personality API responses. The Personality response must
contain a valid 64-character `source_hash`. Public `/api/healthz` is never used
as a `200` readiness requirement.

The post-symlink public scale lookup smoke uses
`backend/scripts/deploy/verify_scale_lookup.sh`. Each configured slug receives
at most three complete HTTP-and-JSON attempts, with a two-second delay, a
five-second connect timeout, and a forty-second per-attempt timeout. A transient
transport error or `5xx` may recover on a later attempt; persistent transport
failure, malformed JSON, `ok != true`, or a mismatched `primary_slug` remains a
blocking deployment failure. The helper does not print the probed authority or
the response body.

`backend-production-verify-only.yml` is a separate, manual, exact-SHA production
evidence workflow. It runs only from `main`, requires the exact read-only
approval phrase, and verifies the deployed `REVISION`, release directory,
internal health, public health policy, business APIs, content source hash,
scale lookups, RIASEC question counts, schema, web processes, and all discovered
`fap-queue` processes. It creates only a sanitized runner-side artifact retained
for 30 days. It does not deploy, migrate, publish, restart or unlock processes,
write remote files, inspect raw logs, submit search URLs, or change CMS/database
state. A failed verification stops with evidence only and does not repair the
production node. Release directory names may contain ASCII letters in either
case, digits, dots, underscores, and hyphens, up to 128 characters; this includes
the uppercase `T` and `Z` used by existing UTC-stamped production releases.

Public backend evidence comes from `GET /api/v0.3/flags` and the zh-CN Big Five
hub Personality API. Both must return `200`. The Personality request carries
`User-Agent: FermatMindReleaseProbe/<PROBE_ID>` so a later read-only target-node
check can prove that the public request reached the inspected node. Acceptance
also requires the target node's current release SHA to equal `BACKEND_SHA`;
these wrappers validate the supplied evidence contract but do not inspect or
change the current symlink themselves.

`deploy_backend.sh` is connected to the existing backend Deployer contract and
does not accept an arbitrary `DEPLOY_COMMAND`. The guarded command shape is:

```bash
vendor/bin/dep deploy production -f deploy.php -o release_name="$RELEASE_NAME" --no-interaction
```

Required real-deploy environment:

- `DEPLOY_DRY_RUN=false`
- `ALLOW_PRODUCTION_DEPLOY=true`
- `ALLOW_REAL_DEPLOY=true`
- `DEPLOY_ENV=production`
- `BACKEND_DEPLOY_SHA=<current checked-out backend sha>`
- `RELEASE_NAME=<operator-approved release name>`

Safe dry-run example:

```bash
DEPLOY_DRY_RUN=true \
ALLOW_PRODUCTION_DEPLOY=true \
ALLOW_REAL_DEPLOY=false \
DEPLOY_ENV=production \
BACKEND_DEPLOY_SHA="$(git rev-parse HEAD)" \
RELEASE_NAME="adapter-dry-run-test" \
bash backend/scripts/deploy/deploy_backend.sh
```

The dry-run validates inputs and prints the planned command, but it does not
execute Deployer, rollback, frontend deployment, migrations, Search Channel, or
URL submission.

## Recovery guidance
- If deploy wrapper outputs `REAL_DEPLOY_NOT_ALLOWED`, set release-time environment:
  - `ALLOW_REAL_DEPLOY=true`
- If deploy wrapper outputs `PRODUCTION_DEPLOY_NOT_ALLOWED`, set release-time environment:
  - `ALLOW_PRODUCTION_DEPLOY=true`
- If deploy wrapper outputs `BACKEND_DEPLOY_SHA_MISMATCH`, sync the checkout to
  the approved SHA before retrying.
- If rollback wrapper outputs `ROLLBACK_COMMAND_NOT_CONFIGURED`, set release-time environment:
  - `ALLOW_REAL_ROLLBACK=true`
  - `ROLLBACK_COMMAND=<existing rollback command>`

## Required readiness mapping (future)
- Existing process readiness (`local-readiness` and manual checks) still applies before enabling `allow_deploy=true`.
- The previous manual confirmation phrases remain required before any real deploy action.

## CLI
- `validate-manifest`
- `plan`
- `dry-run`
- `run`
- `resume`
- `print-confirmation-phrases`

## Recovery for v1 blockers
- If production deployment command is unknown, run remains blocked and should be handled by manual platform owner with explicit wrapper configuration.
- If high-risk path mismatches appear, update manifest scope or narrow file impact.
