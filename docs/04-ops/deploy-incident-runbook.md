# Deploy Incident Runbook

This runbook defines the decision tree for stuck or ambiguous deployment operations. It is read-only by default and does not authorize rollback, unlock, process killing, or server mutation.

## First response rule

When a deploy appears stuck, do not immediately rerun deployment. First determine whether the remote release actually completed, whether workers are healthy, and whether the local deploy process is only waiting on a stale worker step.

## Read-only evidence to collect

Collect evidence without mutating local or remote state:

- local deploy command, release name, and target SHA
- current backend symlink and `REVISION`
- queue and supervisor status
- PHP-FPM and nginx status when readable
- Deployer lock status if readable
- recent deploy task name where execution appears stuck
- whether public smoke, internal smoke, or allowlisted smoke passes

Do not print secrets, private key paths, real IP addresses, tokens, passwords, or private topology in incident notes.

## Stuck deploy decision tree

1. If the remote current symlink and `REVISION` match the intended release and backend smoke passes, classify as likely local deploy worker hang.
2. If remote current symlink does not match the intended release, classify as deploy incomplete.
3. If queue workers are not running or are on the wrong release, classify as worker convergence failure.
4. If nginx or PHP-FPM is unhealthy, classify as web runtime failure.
5. If healthz is checked from a non-allowlisted public source and returns `404`, do not classify as healthz failure. Recheck internally or from an allowlisted source.

## Mutation approvals

The following actions require separate explicit approval after read-only evidence is collected:

- rollback
- unlock
- killing local or remote processes
- restarting supervisor, queue workers, PHP-FPM, nginx, or PM2
- rerunning production deploy
- modifying server files or configuration

Approval for one action does not imply approval for another.

## Backend deploy incident classifications

- `complete_and_verified`: intended release is active and smoke passed
- `local_worker_hang`: remote release is active but local Deployer remains blocked
- `deploy_incomplete`: current symlink or `REVISION` does not match intended release
- `worker_convergence_failure`: queue or supervisor failed to converge
- `web_runtime_failure`: PHP-FPM, nginx, or public smoke failed
- `unknown`: evidence is insufficient or conflicting

## Frontend deploy incident classifications

- `complete_and_verified`: Node1 HEAD matches intended SHA, PM2 converged, public smoke passed
- `pm2_convergence_failure`: PM2 process count or online status is wrong
- `stale_process`: PM2 online but serving an older checkout or build
- `build_or_asset_failure`: local chunk or public static asset smoke failed
- `public_route_failure`: route smoke failed after PM2 convergence
- `unknown`: evidence is insufficient or conflicting

## Sidecar handling during incidents

Do not block a completed and verified deploy on unrelated sidecar issues. Record them separately with owner, severity, and whether they require a follow-up PR or operational task.

Sidecar issue record format:

- summary
- evidence
- scope relation: current deploy / external / historical
- production blocking: yes or no
- recommended follow-up

## Rollback recommendation boundary

Recommend rollback only when the deployed release is active and introduces a production-impacting regression that cannot be mitigated safely within the current release. Missing arbitrary public-origin `/api/healthz` `200` is not by itself a rollback condition because production healthz is allowlist-only.
