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
- Production execution depends on GitHub Environment approval (`environment: production`).
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
`run-train` depends on a `production` job gate and uses `environment: production`.

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

## Smoke checks
`smoke.py` supports:
- method/url/timeout/retries
- status expectation
- must contain / must not contain
- optional forbidden marker scan for high-risk URLs

## Sidecar policy
- Required check failures are blocking.
- Non-required checks can be sidecar only when:
  - failure is explicitly external
- `5xx`, `timeout`, private URL exposure, held slug exposure, clinical/depression exposure, core smoke failures are never sidecar.

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

## Recovery guidance
- If deploy wrapper outputs `DEPLOY_COMMAND_NOT_CONFIGURED`, set release-time environment:
  - `ALLOW_REAL_DEPLOY=true`
  - `DEPLOY_COMMAND=<existing production command>`
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

