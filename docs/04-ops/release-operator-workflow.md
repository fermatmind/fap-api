# Release Operator Workflow

This runbook defines the production release operator workflow for FermatMind backend and frontend releases. It is a governance document only; it does not grant production write permission by itself.

## Release-only workspace rule

Production releases must run from a release-only checkout or worktree that is dedicated to deployment operations.

Required state before any production deploy command:

- the checkout is on `main`
- `git status --short` is empty
- `HEAD` equals `origin/main`
- the deployment SHA has a merged PR record
- required local readiness and read-only remote readiness have passed
- the release operator has the exact deploy approval phrase for the resolved SHA

Disallowed release entry points:

- detached HEAD checkouts
- GitHub Desktop parking branches
- feature branches
- PR worktrees
- local development directories with unrelated files
- any checkout that cannot prove `HEAD == origin/main`

## Backend readiness governance

Backend release readiness must resolve and record:

- backend repo path and branch
- backend SHA and latest merged PR
- clean local main aligned with `origin/main`
- required tools and environment variables present without printing secrets
- Deployer availability
- current backend remote symlink and `REVISION`
- queue and supervisor status
- PHP-FPM and nginx status when readable
- deploy necessity and explicit approval phrase

Backend deploy may proceed only after an explicit approval phrase naming the exact SHA and release name.

## Frontend readiness governance

Frontend release readiness must resolve and record:

- frontend repo path and branch
- frontend SHA and latest merged PR
- clean local main aligned with `origin/main`
- CMS API check status
- frontend Node1 HEAD and PM2 status from read-only verification
- public route smoke expectations
- deploy necessity and explicit approval phrase

Frontend deploy may proceed only after backend production smoke has passed unless the release is explicitly frontend-only and backend deploy is not necessary.

## Release ordering

Default production ordering:

1. local readiness
2. read-only remote readiness
3. decide whether deploy is necessary
4. backend explicit approval, if backend deploy is necessary
5. backend deploy and backend smoke
6. frontend explicit approval, if frontend deploy is necessary
7. frontend deploy and frontend smoke
8. release record

Do not deploy a component just because local main changed. Deploy only when the remote runtime target is behind a runtime-impacting or explicitly approved SHA.

## Healthz policy

`/api/healthz` is the only canonical backend health probe path. Production healthz is allowlist-only. Public non-allowlisted requests returning `404` are not a deploy failure. Use internal or allowlisted probes for healthz verification.

## Sidecar issue policy

If a blocker is not introduced by the current PR or release and the current required checks are green, record it as a sidecar issue instead of stopping the train.

Sidecar examples:

- historical ledger noise
- unrelated GitHub Actions platform noise
- stale branch or worktree cleanup outside current scope
- external repository configuration gaps
- non-current-PR warnings

Sidecar issues must include:

- source of evidence
- why it is outside current scope
- risk level
- owner or follow-up path
- whether it blocks production deployment

## Release record

Every production release summary should record:

- backend SHA, PR, release name, and smoke result
- frontend SHA, PR, PM2 status, and smoke result
- healthz verification source: internal or allowlisted
- any sidecar issues
- rollback recommendation, if any
