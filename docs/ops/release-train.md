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
